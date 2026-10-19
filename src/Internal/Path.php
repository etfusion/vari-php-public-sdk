<?php

declare(strict_types=1);

namespace ETFusion\VARI\Internal;

/**
 * @internal
 */
final class Path
{
    /**
     * Joins segments into a path relative to the base URL, encoding each.
     */
    public static function build(string ...$segments): string
    {
        return implode('/', array_map('rawurlencode', $segments));
    }
}
