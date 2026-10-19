<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

use ETFusion\VARI\Internal\Dates;

/**
 * A certificate issued by rotation.
 */
final class RotatedCertificate
{
    /**
     * @param string $certificatePem The new leaf certificate. Present this one.
     * @param string $chainPem The V-ARI client CA above it.
     * @param ?string $privateKeyPem Only when the server generated the key. Returned once; not stored by V-ARI.
     * @param \DateTimeImmutable $previousValidUntil When the certificate used for the rotate call stops being accepted.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $fingerprint,
        public readonly \DateTimeImmutable $notAfter,
        public readonly string $certificatePem,
        public readonly string $chainPem,
        public readonly ?string $privateKeyPem,
        public readonly \DateTimeImmutable $previousValidUntil,
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
            Dates::parse((string) $data['notAfter']),
            (string) $data['certificatePem'],
            (string) $data['chainPem'],
            isset($data['privateKeyPem']) ? (string) $data['privateKeyPem'] : null,
            Dates::parse((string) $data['previousValidUntil']),
        );
    }
}
