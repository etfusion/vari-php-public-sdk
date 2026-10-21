<?php

declare(strict_types=1);

namespace ETFusion\VARI\Internal;

use ETFusion\VARI\Exception\ConfigurationException;

/**
 * Validity window of a PEM client certificate.
 *
 * @internal
 */
final class CertificateValidity
{
    private function __construct(
        public readonly \DateTimeImmutable $notBefore,
        public readonly \DateTimeImmutable $notAfter,
    ) {
    }

    /**
     * @param string $source Names the certificate in the error message.
     */
    public static function parse(string $pem, string $source = 'The client certificate'): self
    {
        $parsed = @openssl_x509_parse($pem);
        if ($parsed === false || !isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            throw new ConfigurationException(sprintf('%s is not a PEM certificate.', $source));
        }

        return new self(
            (new \DateTimeImmutable())->setTimestamp((int) $parsed['validFrom_time_t']),
            (new \DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']),
        );
    }
}
