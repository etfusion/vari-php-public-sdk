<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests\Support;

/**
 * A throwaway certificate authority for TLS tests, created per test in a
 * temporary directory. Nothing it issues is trusted outside the test.
 */
final class TestCa
{
    public readonly string $certificatePath;

    private readonly string $config;
    private \OpenSSLAsymmetricKey $key;
    private \OpenSSLCertificate $certificate;

    public function __construct(private readonly string $dir, string $name)
    {
        // Explicit extensions, so the result does not depend on the system's openssl.cnf.
        $this->config = $dir . '/' . $name . '-openssl.cnf';
        file_put_contents($this->config, <<<'CNF'
            [req]
            distinguished_name = dn
            [dn]
            [ca]
            basicConstraints = critical, CA:TRUE
            keyUsage = critical, keyCertSign, cRLSign
            subjectKeyIdentifier = hash
            [server]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            extendedKeyUsage = serverAuth
            subjectAltName = IP:127.0.0.1
            [client]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            extendedKeyUsage = clientAuth
            CNF);

        $this->key = self::newKey();
        $csr = openssl_csr_new(['commonName' => $name], $this->key, $this->options('ca'));
        \assert($csr instanceof \OpenSSLCertificateSigningRequest);
        $certificate = openssl_csr_sign($csr, null, $this->key, 1, $this->options('ca'), random_int(1, PHP_INT_MAX));
        \assert($certificate instanceof \OpenSSLCertificate);
        $this->certificate = $certificate;

        $this->certificatePath = $dir . '/' . $name . '.crt';
        openssl_x509_export($certificate, $pem);
        file_put_contents($this->certificatePath, $pem);
    }

    /**
     * A certificate for a server on 127.0.0.1, written to files the server reads.
     *
     * @return array{certificatePath: string, privateKeyPath: string}
     */
    public function issueServer(): array
    {
        ['certificatePem' => $certificatePem, 'privateKeyPem' => $privateKeyPem] = $this->issue('server', 'server', null);
        $paths = ['certificatePath' => $this->dir . '/server.crt', 'privateKeyPath' => $this->dir . '/server.key'];
        file_put_contents($paths['certificatePath'], $certificatePem);
        file_put_contents($paths['privateKeyPath'], $privateKeyPem);
        chmod($paths['privateKeyPath'], 0600);

        return $paths;
    }

    /**
     * A client certificate and key, kept in memory.
     *
     * @return array{certificatePem: string, privateKeyPem: string}
     */
    public function issueClient(?string $passphrase = null): array
    {
        return $this->issue('client', 'client', $passphrase);
    }

    /**
     * @return array{certificatePem: string, privateKeyPem: string}
     */
    private function issue(string $commonName, string $extensions, ?string $passphrase): array
    {
        $key = self::newKey();
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $this->options($extensions));
        \assert($csr instanceof \OpenSSLCertificateSigningRequest);
        $certificate = openssl_csr_sign($csr, $this->certificate, $this->key, 1, $this->options($extensions), random_int(1, PHP_INT_MAX));
        \assert($certificate instanceof \OpenSSLCertificate);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $privateKeyPem, $passphrase);

        return ['certificatePem' => $certificatePem, 'privateKeyPem' => $privateKeyPem];
    }

    /**
     * @return array<string, string>
     */
    private function options(string $extensions): array
    {
        return ['config' => $this->config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key instanceof \OpenSSLAsymmetricKey);

        return $key;
    }
}
