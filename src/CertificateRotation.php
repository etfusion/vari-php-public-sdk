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
     * @throws RotationException The new certificate was issued but `$store` could not save it. The exception
     *                           holds the new credentials; save them before the old certificate's grace period ends.
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
        } catch (\Throwable $e) {
            throw new RotationException($credentials, $issued, $e);
        }

        $rotated = $client->withCredentials($credentials);

        return new self($rotated, $issued, $rotated->certificates()->confirmRotation());
    }
}
