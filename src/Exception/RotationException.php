<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

use ETFusion\VARI\Credentials;
use ETFusion\VARI\Model\RotatedCertificate;

/**
 * V-ARI issued a new certificate but the credential store could not save it.
 *
 * The old certificate keeps working until `$issued->previousValidUntil`, and
 * cannot be rotated again. Save `$credentials` some other way before then, or
 * ask a tenant admin for a new certificate.
 */
final class RotationException extends \RuntimeException implements VariException
{
    public function __construct(
        public readonly Credentials $credentials,
        public readonly RotatedCertificate $issued,
        \Throwable $previous,
    ) {
        parent::__construct(sprintf(
            'A new certificate was issued but could not be saved: %s The old certificate keeps working until %s.',
            $previous->getMessage(),
            $issued->previousValidUntil->format(\DateTimeInterface::ATOM),
        ), 0, $previous);
    }
}
