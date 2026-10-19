<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

use ETFusion\VARI\Internal\Dates;

/**
 * The client certificate used for a request, as the registry knows it.
 */
final class PresentedCertificate
{
    public const SOURCE_CSR = 'csr';
    public const SOURCE_GENERATED = 'generated';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @param list<string> $uris
     * @param string $serial Hex serial number.
     * @param string $fingerprint Lowercase hex SHA-256 of the certificate DER.
     * @param string $source "csr" if the client generated the key, "generated" if V-ARI did.
     * @param string $status "active", or "superseded" once rotated away from.
     * @param ?\DateTimeImmutable $graceUntil For a superseded certificate, when it stops being accepted.
     * @param ?string $replacesId Set when issued by rotation: the certificate a rotation confirm will disable.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $serial,
        public readonly string $fingerprint,
        public readonly string $subject,
        public readonly string $issuer,
        public readonly array $uris,
        public readonly \DateTimeImmutable $notBefore,
        public readonly \DateTimeImmutable $notAfter,
        public readonly string $keyAlgorithm,
        public readonly string $source,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $graceUntil,
        public readonly ?string $replacesId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['serial'],
            (string) $data['fingerprint'],
            (string) $data['subject'],
            (string) $data['issuer'],
            array_values(array_map('strval', (array) ($data['uris'] ?? []))),
            Dates::parse((string) $data['notBefore']),
            Dates::parse((string) $data['notAfter']),
            (string) $data['keyAlgorithm'],
            (string) $data['source'],
            (string) $data['status'],
            Dates::parseOptional($data['graceUntil'] ?? null),
            isset($data['replacesID']) ? (string) $data['replacesID'] : null,
        );
    }
}
