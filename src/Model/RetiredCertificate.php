<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

use ETFusion\VARI\Internal\Dates;

/**
 * The certificate a confirmed rotation replaced.
 */
final class RetiredCertificate
{
    /**
     * @param \DateTimeImmutable $validUntil When it stopped (or stops) being accepted.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $fingerprint,
        public readonly \DateTimeImmutable $validUntil,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['fingerprint'],
            Dates::parse((string) $data['validUntil']),
        );
    }
}
