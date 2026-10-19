<?php

declare(strict_types=1);

namespace ETFusion\VARI\Internal;

use ETFusion\VARI\Exception\ConfigurationException;

/**
 * Validity window of a PEM client certificate on disk.
 *
 * @internal
 */
final class CertificateFile
{
    private function __construct(
        public readonly \DateTimeImmutable $notBefore,
        public readonly \DateTimeImmutable $notAfter,
    ) {
    }

    public static function read(string $path): self
    {
        $pem = @file_get_contents($path);
        if ($pem === false) {
            throw new ConfigurationException(sprintf('Cannot read client certificate "%s".', $path));
        }

        $parsed = @openssl_x509_parse($pem);
        if ($parsed === false || !isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            throw new ConfigurationException(sprintf('"%s" is not a PEM certificate.', $path));
        }

        return new self(
            (new \DateTimeImmutable())->setTimestamp((int) $parsed['validFrom_time_t']),
            (new \DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']),
        );
    }
}
