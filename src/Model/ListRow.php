<?php

declare(strict_types=1);

namespace ETFusion\VARI\Model;

use ETFusion\VARI\Internal\Dates;

/**
 * A row of a custom list.
 *
 * Construct one to write it; rows read back from the API also carry
 * `createdAt` and `updatedAt`. Writing a row replaces every field of any
 * row with the same `externalUniqueIdentifier`: a field left null is cleared.
 */
final class ListRow
{
    /**
     * @param string $externalUniqueIdentifier Your identifier for the row (1-128 chars). Rows are keyed by it.
     * @param string $title Up to 200 chars.
     * @param ?string $displayIdentifier Short identifier shown alongside the title, e.g. a reference number (up to 64 chars).
     * @param list<string> $subtitles Up to 5, each up to 200 chars.
     * @param ?\DateTimeInterface $timestamp Required when the list's showRelativeDate is on.
     * @param ?string $badge Required when the list has badges, and must be one of them.
     * @param int|float|null $rank Ordering hint: lower ranks show first, ahead of unranked rows.
     */
    public function __construct(
        public readonly string $externalUniqueIdentifier,
        public readonly string $title,
        public readonly ?string $displayIdentifier = null,
        public readonly array $subtitles = [],
        public readonly ?\DateTimeInterface $timestamp = null,
        public readonly ?string $badge = null,
        public readonly int|float|null $rank = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rank = $data['rank'] ?? null;

        return new self(
            (string) $data['externalUniqueIdentifier'],
            (string) $data['title'],
            isset($data['displayIdentifier']) ? (string) $data['displayIdentifier'] : null,
            array_values(array_map('strval', (array) ($data['subtitles'] ?? []))),
            Dates::parseOptional($data['timestamp'] ?? null),
            isset($data['badge']) ? (string) $data['badge'] : null,
            is_int($rank) || is_float($rank) ? $rank : null,
            Dates::parseOptional($data['createdAt'] ?? null),
            Dates::parseOptional($data['updatedAt'] ?? null),
        );
    }

    /**
     * The row's writable fields, without its identifier.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        $fields = ['title' => $this->title];
        if ($this->displayIdentifier !== null) {
            $fields['displayIdentifier'] = $this->displayIdentifier;
        }
        if ($this->subtitles !== []) {
            $fields['subtitles'] = array_values($this->subtitles);
        }
        if ($this->timestamp !== null) {
            $fields['timestamp'] = Dates::format($this->timestamp);
        }
        if ($this->badge !== null) {
            $fields['badge'] = $this->badge;
        }
        if ($this->rank !== null) {
            $fields['rank'] = $this->rank;
        }

        return $fields;
    }

    /**
     * The row as written in a batch: its identifier and fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['externalUniqueIdentifier' => $this->externalUniqueIdentifier] + $this->fields();
    }
}
