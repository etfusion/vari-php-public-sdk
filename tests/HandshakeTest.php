<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Client;
use ETFusion\VARI\Credentials;
use ETFusion\VARI\Exception\TransportException;
use ETFusion\VARI\Tests\Support\TestCa;
use ETFusion\VARI\Tests\Support\TestTlsServer;
use PHPUnit\Framework\TestCase;

/**
 * Real mutual-TLS handshakes through cURL against a server on 127.0.0.1.
 * Every certificate and key is generated for the test and trusted nowhere
 * else; the client's key never touches disk.
 */
final class HandshakeTest extends TestCase
{
    private string $dir;
    private TestCa $ca;
    private TestTlsServer $server;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vari-sdk-tls-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->ca = new TestCa($this->dir, 'test-ca');
        $this->server = new TestTlsServer($this->ca);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testPresentsCertificateFromMemory(): void
    {
        $pair = $this->ca->issueClient();

        $identity = $this->client(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']))->identity();

        self::assertSame(openssl_x509_fingerprint($pair['certificatePem'], 'sha256'), $identity->certificate->fingerprint);
    }

    public function testPresentsEncryptedKey(): void
    {
        $pair = $this->ca->issueClient('secret');

        $identity = $this->client(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem'], 'secret'))->identity();

        self::assertSame(openssl_x509_fingerprint($pair['certificatePem'], 'sha256'), $identity->certificate->fingerprint);
    }

    public function testServerRefusesCertificateFromAnotherCa(): void
    {
        $pair = (new TestCa($this->dir, 'other-ca'))->issueClient();

        $this->expectException(TransportException::class);

        $this->client(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']))->identity();
    }

    public function testClientRefusesServerItDoesNotTrust(): void
    {
        $pair = $this->ca->issueClient();
        $otherCa = new TestCa($this->dir, 'other-ca');

        $this->expectException(TransportException::class);

        $this->client(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']), $otherCa->certificatePath)->identity();
    }

    private function client(Credentials $credentials, ?string $trustedCa = null): Client
    {
        return new Client(
            $credentials,
            $this->server->baseUrl,
            timeout: 10.0,
            newCertificateRetrySeconds: 0,
            // Trust only the test CA for this client, never the system store.
            httpOptions: ['verify' => $trustedCa ?? $this->ca->certificatePath],
        );
    }
}
