<?php

declare(strict_types=1);

namespace ETFusion\VARI;

/**
 * Where your integration keeps its credentials, so certificate rotation can
 * replace them. Implement it over your secret manager; see the README for an
 * AWS Secrets Manager example.
 */
interface CredentialStore
{
    /**
     * The current credentials.
     */
    public function load(): Credentials;

    /**
     * Replaces the stored certificate and key together: a reader must never
     * see the new certificate with the old key, or the reverse. Storing both
     * in one secret does this.
     */
    public function save(Credentials $credentials): void;
}
