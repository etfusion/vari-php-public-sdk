<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

/**
 * The API client a certificate belongs to.
 */
final class CertificateClient
{
    public const KIND_GENERIC = 'generic';
    public const KIND_STREAM_DEVICE = 'streamDevice';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $name,
        public readonly string $roleId,
        public readonly string $kind,
        public readonly ?string $streamId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['tenantID'],
            (string) $data['name'],
            (string) $data['roleID'],
            (string) $data['kind'],
            isset($data['streamID']) ? (string) $data['streamID'] : null,
        );
    }
}
