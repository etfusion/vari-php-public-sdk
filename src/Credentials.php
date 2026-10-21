<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\ConfigurationException;
use ETFusion\VARI\Internal\CertificateValidity;

/**
 * A client certificate and its private key, held in memory.
 *
 * Load them from a secret manager (Vault, AWS Secrets Manager, GCP Secret
 * Manager, ...) rather than keeping the key in a file on the server:
 *
 *     $secret = json_decode($secrets->get('vari-client'), true);
 *     $vari = new Client(Credentials::fromPem($secret['certificate'], $secret['privateKey']));
 *
 * The key is handed to cURL in memory and never written to disk. var_dump()
 * and print_r() show it redacted.
 */
final class Credentials
{
    private function __construct(
        public readonly string $certificatePem,
        public readonly string $privateKeyPem,
        public readonly ?string $passphrase,
    ) {
    }

    /**
     * @param string $certificatePem The client certificate V-ARI issued (PEM).
     * @param string $privateKeyPem Its private key (PEM).
     * @param ?string $passphrase For an encrypted private key.
     *
     * @throws ConfigurationException The certificate or key is not PEM, the passphrase is wrong, or they do not match.
     */
    public static function fromPem(
        string $certificatePem,
        #[\SensitiveParameter] string $privateKeyPem,
        #[\SensitiveParameter] ?string $passphrase = null,
    ): self {
        CertificateValidity::parse($certificatePem);

        // Never null: without a passphrase OpenSSL asks for one on the terminal and waits.
        $key = @openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        $matches = $key !== false && @openssl_x509_check_private_key($certificatePem, $key);
        // Leave nothing behind for the next caller of openssl_error_string().
        while (openssl_error_string() !== false);

        if ($key === false) {
            throw new ConfigurationException('The private key is not PEM, or its passphrase is wrong.');
        }
        if (!$matches) {
            throw new ConfigurationException('The private key does not belong to the client certificate.');
        }

        return new self($certificatePem, $privateKeyPem, $passphrase);
    }

    /**
     * Reads the certificate and key from PEM files. Prefer {@see fromPem()}
     * with a secret manager outside local development.
     *
     * @throws ConfigurationException A file is unreadable, or see {@see fromPem()}.
     */
    public static function fromFiles(
        string $certificatePath,
        string $privateKeyPath,
        #[\SensitiveParameter] ?string $passphrase = null,
    ): self {
        $certificatePem = @file_get_contents($certificatePath);
        if ($certificatePem === false) {
            throw new ConfigurationException(sprintf('Cannot read client certificate "%s".', $certificatePath));
        }
        $privateKeyPem = @file_get_contents($privateKeyPath);
        if ($privateKeyPem === false) {
            throw new ConfigurationException(sprintf('Cannot read private key "%s".', $privateKeyPath));
        }

        return self::fromPem($certificatePem, $privateKeyPem, $passphrase);
    }

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return [
            'certificatePem' => $this->certificatePem,
            'privateKeyPem' => '[redacted]',
            'passphrase' => $this->passphrase !== null ? '[redacted]' : null,
        ];
    }
}
