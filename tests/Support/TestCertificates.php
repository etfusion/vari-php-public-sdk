<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests\Support;

/**
 * A throwaway CA and client certificate on disk.
 */
final class TestCertificates
{
    public readonly string $dir;
    public readonly string $certificatePath;
    public readonly string $privateKeyPath;

    private \OpenSSLAsymmetricKey $caKey;
    private \OpenSSLCertificate $caCertificate;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/vari-sdk-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);

        $this->caKey = self::newKey();
        $caCsr = openssl_csr_new(['commonName' => 'Test CA'], $this->caKey, ['digest_alg' => 'sha256']);
        \assert($caCsr instanceof \OpenSSLCertificateSigningRequest);
        $ca = openssl_csr_sign($caCsr, null, $this->caKey, 3650, ['digest_alg' => 'sha256']);
        \assert($ca instanceof \OpenSSLCertificate);
        $this->caCertificate = $ca;

        $key = self::newKey();
        $csr = openssl_csr_new(['commonName' => 'client'], $key, ['digest_alg' => 'sha256']);
        \assert($csr instanceof \OpenSSLCertificateSigningRequest);

        $this->certificatePath = $this->dir . '/client.crt';
        $this->privateKeyPath = $this->dir . '/client.key';
        file_put_contents($this->certificatePath, $this->sign($csr, 90));
        openssl_pkey_export($key, $keyPem);
        file_put_contents($this->privateKeyPath, $keyPem);
    }

    /**
     * Signs a CSR with the test CA and returns the certificate PEM.
     */
    public function sign(\OpenSSLCertificateSigningRequest|string $csr, int $days): string
    {
        $certificate = openssl_csr_sign($csr, $this->caCertificate, $this->caKey, $days, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        \assert($certificate instanceof \OpenSSLCertificate);
        openssl_x509_export($certificate, $pem);

        return $pem;
    }

    public function certificatePem(): string
    {
        return (string) file_get_contents($this->certificatePath);
    }

    public function privateKeyPem(): string
    {
        return (string) file_get_contents($this->privateKeyPath);
    }

    /**
     * A new key and a certificate for it, signed by the test CA.
     *
     * @return array{certificatePem: string, privateKeyPem: string}
     */
    public function newPair(?string $passphrase = null): array
    {
        $key = self::newKey();
        $csr = openssl_csr_new(['commonName' => 'client'], $key, ['digest_alg' => 'sha256']);
        \assert($csr instanceof \OpenSSLCertificateSigningRequest);
        openssl_pkey_export($key, $keyPem, $passphrase);

        return ['certificatePem' => $this->sign($csr, 90), 'privateKeyPem' => $keyPem];
    }

    public function caPem(): string
    {
        openssl_x509_export($this->caCertificate, $pem);

        return $pem;
    }

    public function cleanUp(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->dir);
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key instanceof \OpenSSLAsymmetricKey);

        return $key;
    }
}
