<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Credentials;
use ETFusion\VARI\Exception\ConfigurationException;
use ETFusion\VARI\FileCredentialStore;
use ETFusion\VARI\Tests\Support\TestCertificates;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    private TestCertificates $certs;

    protected function setUp(): void
    {
        $this->certs = new TestCertificates();
    }

    protected function tearDown(): void
    {
        $this->certs->cleanUp();
    }

    public function testFromPem(): void
    {
        $credentials = Credentials::fromPem($this->certs->certificatePem(), $this->certs->privateKeyPem());

        self::assertSame($this->certs->certificatePem(), $credentials->certificatePem);
        self::assertSame($this->certs->privateKeyPem(), $credentials->privateKeyPem);
        self::assertNull($credentials->passphrase);
    }

    public function testEncryptedKey(): void
    {
        $pair = $this->certs->newPair('secret');

        $credentials = Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem'], 'secret');
        self::assertSame('secret', $credentials->passphrase);

        $this->expectException(ConfigurationException::class);
        Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem'], 'wrong');
    }

    public function testKeyMustMatchCertificate(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not belong');

        Credentials::fromPem($this->certs->certificatePem(), $this->certs->newPair()['privateKeyPem']);
    }

    public function testNotACertificate(): void
    {
        $this->expectException(ConfigurationException::class);

        Credentials::fromPem($this->certs->privateKeyPem(), $this->certs->privateKeyPem());
    }

    public function testFromFiles(): void
    {
        $credentials = Credentials::fromFiles($this->certs->certificatePath, $this->certs->privateKeyPath);
        self::assertSame($this->certs->privateKeyPem(), $credentials->privateKeyPem);

        $this->expectException(ConfigurationException::class);
        Credentials::fromFiles($this->certs->certificatePath, $this->certs->dir . '/missing.key');
    }

    public function testDumpsRedactKey(): void
    {
        $pair = $this->certs->newPair('secret');
        $credentials = Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem'], 'secret');

        $dump = print_r($credentials, true);

        self::assertStringNotContainsString('PRIVATE KEY', $dump);
        self::assertStringNotContainsString('secret', $dump);
        self::assertStringContainsString('BEGIN CERTIFICATE', $dump);
    }

    public function testFileStoreRoundTrip(): void
    {
        $store = new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath);
        $pair = $this->certs->newPair();

        $store->save(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']));

        self::assertSame($pair['privateKeyPem'], $store->load()->privateKeyPem);
        self::assertSame(0600, fileperms($this->certs->privateKeyPath) & 0777);
        self::assertSame([], $this->leftovers());
    }

    public function testFileStoreStagingFailureLeavesOldPair(): void
    {
        $oldKey = $this->certs->privateKeyPem();
        $store = new FileCredentialStore($this->certs->dir . '/missing/client.crt', $this->certs->privateKeyPath);
        $pair = $this->certs->newPair();

        try {
            $store->save(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']));
            self::fail('expected an exception');
        } catch (ConfigurationException) {
        }

        self::assertSame($oldKey, $this->certs->privateKeyPem());
        self::assertSame([], $this->leftovers());
    }

    public function testFileStoreRestoresKeyWhenCertificateCannotBeInstalled(): void
    {
        $oldKey = $this->certs->privateKeyPem();
        // A directory in the certificate's place makes the second rename fail.
        $certificatePath = $this->certs->dir . '/certificate-dir';
        mkdir($certificatePath);
        touch($certificatePath . '/placeholder');
        $store = new FileCredentialStore($certificatePath, $this->certs->privateKeyPath);
        $pair = $this->certs->newPair();

        try {
            $store->save(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']));
            self::fail('expected an exception');
        } catch (ConfigurationException) {
        }
        unlink($certificatePath . '/placeholder');

        self::assertSame($oldKey, $this->certs->privateKeyPem());
        self::assertSame([], $this->leftovers());
    }

    public function testFileStoreLoadFinishesInterruptedSave(): void
    {
        // As if the process stopped after installing the key but before the certificate.
        $pair = $this->certs->newPair();
        rename($this->certs->privateKeyPath, $this->certs->privateKeyPath . '.old');
        file_put_contents($this->certs->privateKeyPath, $pair['privateKeyPem']);
        file_put_contents($this->certs->certificatePath . '.new', $pair['certificatePem']);

        $credentials = (new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath))->load();

        self::assertSame($pair['certificatePem'], $credentials->certificatePem);
        self::assertSame($pair['certificatePem'], $this->certs->certificatePem());
        self::assertSame([], $this->leftovers());
    }

    public function testFileStoreLoadFinishesSaveStoppedBeforeKeyInstall(): void
    {
        $pair = $this->certs->newPair();
        file_put_contents($this->certs->privateKeyPath . '.new', $pair['privateKeyPem']);
        file_put_contents($this->certs->certificatePath . '.new', $pair['certificatePem']);

        $credentials = (new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath))->load();

        self::assertSame($pair['privateKeyPem'], $credentials->privateKeyPem);
        self::assertSame($pair['certificatePem'], $credentials->certificatePem);
        self::assertSame([], $this->leftovers());
    }

    /**
     * @return list<string> Staged or backup files left in the test directory.
     */
    private function leftovers(): array
    {
        return array_values(array_filter(
            glob($this->certs->dir . '/*') ?: [],
            static fn (string $file): bool => str_ends_with($file, '.new') || str_ends_with($file, '.old'),
        ));
    }
}
