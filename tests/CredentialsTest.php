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

    public function testFileStoreRoundTripsEncryptedKey(): void
    {
        $store = new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath, 'secret');
        $pair = $this->certs->newPair('secret');

        $store->save(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem'], 'secret'));

        $loaded = $store->load();
        self::assertSame($pair['privateKeyPem'], $loaded->privateKeyPem);
        self::assertSame('secret', $loaded->passphrase);
    }

    public function testFileStoreRefusesKeyItCannotReload(): void
    {
        $oldKey = $this->certs->privateKeyPem();
        $store = new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath, 'other');
        $pair = $this->certs->newPair('secret');

        try {
            $store->save(Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem'], 'secret'));
            self::fail('expected an exception');
        } catch (ConfigurationException) {
        }

        self::assertSame($oldKey, $this->certs->privateKeyPem());
        self::assertSame([], $this->leftovers());
    }

    public function testFileStoreReadersNeverSeeAHalfReplacedPair(): void
    {
        // Another process saves alternating pairs while this one loads.
        $pairs = [$this->certs->newPair(), $this->certs->newPair()];
        file_put_contents($this->certs->dir . '/pairs.json', json_encode($pairs, JSON_THROW_ON_ERROR));
        $writer = $this->certs->dir . '/writer.php';
        file_put_contents($writer, sprintf(<<<'PHP'
            <?php
            require %s;
            $pairs = json_decode(file_get_contents(%s), true);
            $store = new ETFusion\VARI\FileCredentialStore(%s, %s);
            for ($i = 0; $i < 200; $i++) {
                $pair = $pairs[$i %% 2];
                $store->save(ETFusion\VARI\Credentials::fromPem($pair['certificatePem'], $pair['privateKeyPem']));
            }
            PHP,
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($this->certs->dir . '/pairs.json', true),
            var_export($this->certs->certificatePath, true),
            var_export($this->certs->privateKeyPath, true),
        ));

        $process = proc_open([PHP_BINARY, $writer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $store = new FileCredentialStore($this->certs->certificatePath, $this->certs->privateKeyPath);
        do {
            // Credentials::fromPem() throws if the key does not belong to the certificate.
            $store->load();
        } while (proc_get_status($process)['running']);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', $output);
        self::assertContains($this->certs->certificatePem(), array_column($pairs, 'certificatePem'));
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
