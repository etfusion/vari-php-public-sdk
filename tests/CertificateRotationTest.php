<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\CertificateRotation;
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

    public function testRunInstallsNewCertificateAndConfirms(): void
    {
        $oldCertificate = (string) file_get_contents($this->certs->certificatePath);
        $oldKey = (string) file_get_contents($this->certs->privateKeyPath);

        $this->api->mock->append(function (RequestInterface $request): Response {
            $csr = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR)['csr'];

            return new Response(201, [], json_encode($this->rotated($this->certs->sign($csr, 90)), JSON_THROW_ON_ERROR));
        });
        $this->api->respond(200, ['id' => 'cert-1', 'fingerprint' => 'old', 'validUntil' => '2026-10-19T12:00:00Z']);

        $result = CertificateRotation::run($this->api->client($this->certs));

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
        self::assertSame($this->certs->certificatePath, $result->client->certificatePath());
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
