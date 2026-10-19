<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\ConfigurationException;
use ETFusion\VARI\Model\RetiredCertificate;
use ETFusion\VARI\Model\RotatedCertificate;

/**
 * Rotates a client's certificate end to end: generates a new key locally,
 * has V-ARI sign it, installs the new certificate and key over the old files,
 * and confirms the rotation with the new certificate so the old one stops
 * working immediately.
 *
 * Run it from a scheduled job well before the certificate expires:
 *
 *     if ($vari->certificateExpiresWithin(30)) {
 *         $vari = CertificateRotation::run($vari)->client;
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
     * @param ?string $certificatePath Where to write the new certificate. Defaults to the client's current file.
     * @param ?string $privateKeyPath Where to write the new key (mode 0600). Defaults to the client's current file.
     * @param ?string $passphrase Encrypts the new private key when given.
     *
     * If confirmation fails, the new certificate is already installed and the
     * old one keeps working until its grace period ends; call
     * `certificates()->confirmRotation()` with a client for the new files.
     */
    public static function run(
        Client $client,
        ?string $certificatePath = null,
        ?string $privateKeyPath = null,
        #[\SensitiveParameter] ?string $passphrase = null,
    ): self {
        $certificatePath ??= $client->certificatePath();
        $privateKeyPath ??= $client->privateKeyPath();

        $keyPair = KeyPair::generate($passphrase);
        $issued = $client->certificates()->rotate($keyPair->csrPem);

        // Stage both files first so a failed write leaves the old pair intact.
        $stagedKey = self::stage($privateKeyPath, $keyPair->privateKeyPem, 0600);
        $stagedCertificate = self::stage($certificatePath, $issued->certificatePem, 0644);
        self::install($stagedKey, $privateKeyPath);
        self::install($stagedCertificate, $certificatePath);

        $rotated = $client->withCertificate($certificatePath, $privateKeyPath, $passphrase);
        $retired = $rotated->certificates()->confirmRotation();

        return new self($rotated, $issued, $retired);
    }

    private static function stage(string $path, string $contents, int $mode): string
    {
        $staged = $path . '.new';
        if (@file_put_contents($staged, '') === false || !@chmod($staged, $mode)
            || @file_put_contents($staged, $contents) === false) {
            @unlink($staged);
            throw new ConfigurationException(sprintf('Cannot write "%s".', $staged));
        }

        return $staged;
    }

    private static function install(string $staged, string $path): void
    {
        if (!@rename($staged, $path)) {
            throw new ConfigurationException(sprintf('Cannot move "%s" to "%s".', $staged, $path));
        }
    }
}
