<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

/**
 * API response bodies.
 */
final class Fixtures
{
    /** @return array<string, mixed> */
    public static function identity(): array
    {
        return [
            'certificate' => [
                'id' => 'cert-1',
                'serial' => '0a1b',
                'fingerprint' => str_repeat('ab', 32),
                'subject' => 'CN=client',
                'issuer' => 'CN=V-ARI Stable Client CA',
                'uris' => ['urn:vari:client:client-1'],
                'notBefore' => '2026-10-01T00:00:00Z',
                'notAfter' => '2026-12-30T00:00:00.123456789Z',
                'keyAlgorithm' => 'ECDSA-P256',
                'source' => 'csr',
                'status' => 'active',
            ],
            'client' => [
                'id' => 'client-1',
                'tenantID' => 'tenant-from-identity',
                'name' => 'Ticketing integration',
                'roleID' => 'role-1',
                'kind' => 'generic',
            ],
            'scopes' => ['stream:read', 'stream:write'],
        ];
    }

    /** @return array<string, mixed> */
    public static function list(array $overrides = []): array
    {
        return $overrides + [
            'key' => 'incidents',
            'id' => 'custom.client-1.incidents',
            'title' => 'Open incidents',
            'badges' => ['P1' => '#FF0000'],
            'showRelativeDate' => true,
            'createdAt' => '2026-10-19T10:00:00Z',
            'updatedAt' => '2026-10-19T10:00:00Z',
        ];
    }

    /** @return array<string, mixed> */
    public static function row(string $id, array $overrides = []): array
    {
        return $overrides + [
            'externalUniqueIdentifier' => $id,
            'title' => 'Row ' . $id,
            'timestamp' => '2026-10-19T09:30:00Z',
            'badge' => 'P1',
            'createdAt' => '2026-10-19T10:00:00Z',
            'updatedAt' => '2026-10-19T10:00:00Z',
        ];
    }

    /** @return array<string, mixed> */
    public static function metric(array $overrides = []): array
    {
        return $overrides + [
            'key' => 'open-tickets',
            'name' => 'custom.client-1.open-tickets',
            'label' => 'Open tickets',
            'unit' => 'count',
            'value' => 42,
            'valueUpdatedAt' => '2026-10-19T10:00:00Z',
            'createdAt' => '2026-10-19T09:00:00Z',
            'updatedAt' => '2026-10-19T09:00:00Z',
        ];
    }
}
