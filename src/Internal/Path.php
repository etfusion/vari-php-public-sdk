<?php

declare(strict_types=1);

namespace ETFusion\VARI\Internal;

use ETFusion\VARI\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Path
{
    /**
     * Joins segments into a path relative to the base URL, encoding each.
     *
     * @throws InvalidArgumentException A segment is empty, "." or "..". Encoding leaves those as they are and
     *                                  the URL resolver then drops or climbs them, addressing another resource.
     */
    public static function build(string ...$segments): string
    {
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('"%s" cannot be used as a key or identifier.', $segment));
            }
        }

        return implode('/', array_map('rawurlencode', $segments));
    }
}
