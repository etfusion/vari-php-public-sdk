<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

/**
 * The presented certificate, the client it maps to, and that client's scopes.
 */
final class Identity
{
    /**
     * @param list<string> $scopes Scopes the client's role grants on its tenant, e.g. "stream:write".
     */
    public function __construct(
        public readonly PresentedCertificate $certificate,
        public readonly CertificateClient $client,
        public readonly array $scopes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $certificate */
        $certificate = $data['certificate'];
        /** @var array<string, mixed> $client */
        $client = $data['client'];

        return new self(
            PresentedCertificate::fromArray($certificate),
            CertificateClient::fromArray($client),
            array_values(array_map('strval', (array) ($data['scopes'] ?? []))),
        );
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
