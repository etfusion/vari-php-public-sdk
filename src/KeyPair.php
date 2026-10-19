<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\ConfigurationException;

/**
 * A locally generated P-256 key and a certificate signing request for it, so
 * the private key never leaves your machine during rotation.
 */
final class KeyPair
{
    private function __construct(
        public readonly string $privateKeyPem,
        public readonly string $csrPem,
    ) {
    }

    /**
     * @param ?string $passphrase Encrypts the exported private key when given.
     */
    public static function generate(#[\SensitiveParameter] ?string $passphrase = null): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new ConfigurationException('Could not generate a key pair: ' . self::opensslErrors());
        }

        // V-ARI ignores the CSR's subject; OpenSSL just needs one.
        $csr = openssl_csr_new(['commonName' => 'vari-client'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest || !openssl_csr_export($csr, $csrPem)) {
            throw new ConfigurationException('Could not create a CSR: ' . self::opensslErrors());
        }

        if (!openssl_pkey_export($key, $keyPem, $passphrase)) {
            throw new ConfigurationException('Could not export the private key: ' . self::opensslErrors());
        }

        return new self($keyPem, $csrPem);
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors !== [] ? implode('; ', $errors) : 'unknown OpenSSL error';
    }
}
