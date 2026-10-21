<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\RotationException;
use ETFusion\VARI\Model\RetiredCertificate;
use ETFusion\VARI\Model\RotatedCertificate;

/**
 * Rotates a client's certificate end to end: generates a new key locally,
 * has V-ARI sign it, saves the new certificate and key to your credential
 * store, and confirms the rotation with the new certificate so the old one
 * stops working immediately.
 *
 * Run it from a scheduled job well before the certificate expires:
 *
 *     if ($vari->certificateExpiresWithin(30)) {
 *         $vari = CertificateRotation::run($vari, $store)->client;
 *     }
 */
final class CertificateRotation
{
    private function __construct(
        /** A client presenting the new certificate. */
        public readonly Client $client,
        public readonly RotatedCertificate $issued,
        /** The certificate that was replaced and when it stopped being accepted. */
        public readonly RetiredCertificate $retired,
    ) {
    }

    /**
     * @param ?string $passphrase Encrypts the new private key when given.
     *
     * If confirmation fails, the new certificate is already saved and the old
     * one keeps working until its grace period ends; call
     * `certificates()->confirmRotation()` with a client for the new credentials.
     *
     * The new credentials are read back from `$store` before confirming, so the
     * old certificate is never disabled while the store holds something the
     * next process cannot load.
     *
     * @throws RotationException The new certificate was issued but `$store` could not save it, or did not load
     *                           it back. The exception holds the new credentials; save them before the old
     *                           certificate's grace period ends.
     */
    public static function run(
        Client $client,
        CredentialStore $store,
        #[\SensitiveParameter] ?string $passphrase = null,
    ): self {
        $keyPair = KeyPair::generate($passphrase);
        $issued = $client->certificates()->rotate($keyPair->csrPem);
        $credentials = Credentials::fromPem($issued->certificatePem, $keyPair->privateKeyPem, $passphrase);

        try {
            $store->save($credentials);
            $saved = $store->load();
            if (openssl_x509_fingerprint($saved->certificatePem, 'sha256') !== openssl_x509_fingerprint($credentials->certificatePem, 'sha256')) {
                throw new \UnexpectedValueException('The store loaded a different certificate than it saved.');
            }
        } catch (\Throwable $e) {
            throw new RotationException($credentials, $issued, $e);
        }

        $rotated = $client->withCredentials($saved);

        return new self($rotated, $issued, $rotated->certificates()->confirmRotation());
    }
}
