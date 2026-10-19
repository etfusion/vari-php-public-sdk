<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

use ETFusion\VARI\Internal\Dates;

/**
 * A metric this client defined, shown by metric widgets on the tenant's boards.
 */
final class Metric
{
    /**
     * @param string $name The identifier a metric widget references, `custom.<clientID>.<key>`.
     * @param int|float|string|bool|null $value Null until a value is set.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly ?string $description,
        public readonly ?string $unit,
        public readonly int|float|string|bool|null $value,
        public readonly ?\DateTimeImmutable $valueUpdatedAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $value = $data['value'] ?? null;

        return new self(
            (string) $data['key'],
            (string) $data['name'],
            (string) $data['label'],
            isset($data['description']) ? (string) $data['description'] : null,
            isset($data['unit']) ? (string) $data['unit'] : null,
            is_scalar($value) ? $value : null,
            Dates::parseOptional($data['valueUpdatedAt'] ?? null),
            Dates::parse((string) $data['createdAt']),
            Dates::parse((string) $data['updatedAt']),
        );
    }
}
