<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

use ETFusion\VARI\Internal\Dates;

/**
 * A list this client defined, shown by customList widgets on the tenant's boards.
 * Rows are read separately.
 */
final class CustomList
{
    /**
     * @param string $id The identifier a customList widget references, `custom.<clientID>.<key>`.
     * @param array<string, string> $badges Badge name to `#RRGGBB` colour (stored upper case).
     * @param bool $showRelativeDate When on, rows render their timestamp as a relative date and must carry one.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $id,
        public readonly string $title,
        public readonly array $badges,
        public readonly bool $showRelativeDate,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $badges = [];
        foreach ((array) ($data['badges'] ?? []) as $name => $colour) {
            $badges[(string) $name] = (string) $colour;
        }

        return new self(
            (string) $data['key'],
            (string) $data['id'],
            (string) $data['title'],
            $badges,
            (bool) ($data['showRelativeDate'] ?? false),
            Dates::parse((string) $data['createdAt']),
            Dates::parse((string) $data['updatedAt']),
        );
    }
}
