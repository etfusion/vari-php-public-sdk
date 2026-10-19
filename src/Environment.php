<?php

declare(strict_types=1);

namespace ETFusion\VARI;

/**
 * Base URLs of the V-ARI Stable API.
 *
 * A certificate is only accepted by the environment that issued it.
 */
final class Environment
{
    public const PRODUCTION = 'https://api.production.v-ari.com:8443/stable/v1';
    public const STAGING = 'https://api.staging.v-ari.com:8443/stable/v1';

    private function __construct()
    {
    }
}
