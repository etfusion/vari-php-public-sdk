<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\CertificateRotation;
use ETFusion\VARI\CredentialStore;
use ETFusion\VARI\Credentials;
use ETFusion\VARI\Exception\RotationException;
use ETFusion\VARI\FileCredentialStore;
use ETFusion\VARI\Exception\ServerException;
use ETFusion\VARI\KeyPair;
use ETFusion\VARI\Tests\Support\MockApi;
use ETFusion\VARI\Tests\Support\TestCertificates;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class CertificateRotationTest extends TestCase
{
    private TestCertificates $certs;
    private MockApi $api;

    protected function setUp(): void
    {
        $this->certs = new TestCertificates();
        $this->api = new MockApi();
    }

    protected function tearDown(): void
    {
        $this->certs->cleanUp();
    }

    public function testKeyPairCsrMatchesKey(): void
    {
        $pair = KeyPair::generate();

        $csrKey = openssl_csr_get_public_key($pair->csrPem);
        $privateKey = openssl_pkey_get_private($pair->privateKeyPem);
        self::assertNotFalse($csrKey);
        self::assertNotFalse($privateKey);
        self::assertSame(openssl_pkey_get_details($csrKey)['key'], openssl_pkey_get_details($privateKey)['key']);
    }

    public function testEncryptedKeyPair(): void
    {
        $pair = KeyPair::generate('secret');

        self::assertFalse(openssl_pkey_get_private($pair->privateKeyPem));
        self::assertNotFalse(openssl_pkey_get_private($pair->privateKeyPem, 'secret'));
    }

    public function testRotateWithoutCsrSendsEmptyObject(): void
    {
        $this->api->respond(201, $this->rotated('-----BEGIN CERTIFICATE-----', '-----BEGIN PRIVATE KEY-----'));

        $issued = $this->api->client($this->certs)->certificates()->rotate();

        self::assertSame('/stable/v1/certificates/rotate', $this->api->request(0)->getUri()->getPath());
        self::assertSame('{}', $this->api->body(0));
        self::assertSame('-----BEGIN PRIVATE KEY-----', $issued->privateKeyPem);
    }

    public function testRunWithFileStoreInstallsNewPair(): void
    {
        $oldCertificate = (string) file_get_contents($this->certs->certificatePath);
        $oldKey = (string) file_get_contents($this->certs->privateKeyPath);

        $this->respondWithSignedCertificate();
        $this->api->respond(200, ['id' => 'cert-1', 'fingerprint' => 'old', 'validUntil' => '2026-10-19T12:00:00Z']);

        $store = new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath);
        $result = CertificateRotation::run($this->api->client($this->certs), $store);

        self::assertSame('/stable/v1/certificates/rotate/confirm', $this->api->request(1)->getUri()->getPath());
        self::assertSame('old', $result->retired->fingerprint);

        $newCertificate = (string) file_get_contents($this->certs->certificatePath);
        $newKey = (string) file_get_contents($this->certs->privateKeyPath);
        self::assertNotSame($oldCertificate, $newCertificate);
        self::assertNotSame($oldKey, $newKey);
        self::assertSame($result->issued->certificatePem, $newCertificate);
        self::assertTrue(openssl_x509_check_private_key($newCertificate, $newKey));
        self::assertSame(0600, fileperms($this->certs->privateKeyPath) & 0777);
        self::assertFileDoesNotExist($this->certs->privateKeyPath . '.new');
        self::assertSame($newCertificate, $result->client->credentials()->certificatePem);
    }

    public function testRunSavesAndConfirms(): void
    {
        $store = $this->store();
        $this->respondWithSignedCertificate();
        $this->api->respond(200, ['id' => 'cert-1', 'fingerprint' => 'old', 'validUntil' => '2026-10-19T12:00:00Z']);

        $client = $this->api->clientFromCredentials($store->load());
        $result = CertificateRotation::run($client, $store, 'secret');

        $saved = $store->load();
        self::assertSame($result->issued->certificatePem, $saved->certificatePem);
        self::assertSame('secret', $saved->passphrase);
        self::assertFalse(openssl_pkey_get_private($saved->privateKeyPem), 'the new key is encrypted');
        self::assertSame('old', $result->retired->fingerprint);
        self::assertSame('/stable/v1/certificates/rotate/confirm', $this->api->request(1)->getUri()->getPath());
        self::assertSame($saved->certificatePem, $this->api->options(1)['curl'][CURLOPT_SSLCERT_BLOB], 'confirmed with the new certificate');
    }

    public function testRunKeepsNewCredentialsWhenSaveFails(): void
    {
        $store = $this->store(failSave: true);
        $this->respondWithSignedCertificate();

        try {
            CertificateRotation::run($this->api->clientFromCredentials($store->load()), $store);
            self::fail('expected an exception');
        } catch (RotationException $e) {
            self::assertTrue(openssl_x509_check_private_key($e->credentials->certificatePem, $e->credentials->privateKeyPem));
            self::assertSame($e->issued->certificatePem, $e->credentials->certificatePem);
            self::assertStringContainsString('2026-10-20T00:00:00+00:00', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }

        self::assertSame(1, $this->api->count(), 'not confirmed');
    }

    public function testConfirmationFailureLeavesNewCredentialsSaved(): void
    {
        $store = $this->store();
        $this->respondWithSignedCertificate();
        $this->api->respond(503, ['error' => 'unavailable', 'message' => 'try again']);

        try {
            CertificateRotation::run($this->api->clientFromCredentials($store->load()), $store);
            self::fail('expected an exception');
        } catch (ServerException) {
        }

        $saved = $store->load();
        self::assertTrue(openssl_x509_check_private_key($saved->certificatePem, $saved->privateKeyPem));
        self::assertNotSame($this->certs->certificatePem(), $saved->certificatePem);
    }

    public function testRunWithFileStoreLeavesOldPairWhenInstallFails(): void
    {
        $oldKey = $this->certs->privateKeyPem();
        $this->respondWithSignedCertificate();

        try {
            $store = new FileCredentialStore($this->certs->dir . '/missing/client.crt', $this->certs->privateKeyPath);
            CertificateRotation::run($this->api->client($this->certs), $store);
            self::fail('expected an exception');
        } catch (RotationException $e) {
            self::assertSame($e->issued->certificatePem, $e->credentials->certificatePem);
        }

        self::assertSame($oldKey, $this->certs->privateKeyPem());
        self::assertFileDoesNotExist($this->certs->privateKeyPath . '.new');
    }

    private function respondWithSignedCertificate(): void
    {
        $this->api->mock->append(function (RequestInterface $request): Response {
            $csr = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR)['csr'];

            return new Response(201, [], json_encode($this->rotated($this->certs->sign($csr, 90)), JSON_THROW_ON_ERROR));
        });
    }

    /**
     * A store in memory, holding the test certificate.
     */
    private function store(bool $failSave = false): CredentialStore
    {
        return new class (Credentials::fromPem($this->certs->certificatePem(), $this->certs->privateKeyPem()), $failSave) implements CredentialStore {
            public function __construct(private Credentials $credentials, private readonly bool $failSave)
            {
            }

            public function load(): Credentials
            {
                return $this->credentials;
            }

            public function save(Credentials $credentials): void
            {
                if ($this->failSave) {
                    throw new \RuntimeException('Secret manager unavailable.');
                }
                $this->credentials = $credentials;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function rotated(string $certificatePem, ?string $privateKeyPem = null): array
    {
        return array_filter([
            'id' => 'cert-2',
            'fingerprint' => 'new',
            'notAfter' => '2027-01-17T00:00:00Z',
            'certificatePem' => $certificatePem,
            'chainPem' => $this->certs->caPem(),
            'privateKeyPem' => $privateKeyPem,
            'previousValidUntil' => '2026-10-20T00:00:00Z',
        ], static fn (?string $v): bool => $v !== null);
    }
}
