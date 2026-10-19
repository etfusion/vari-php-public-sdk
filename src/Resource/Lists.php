<?php

declare(strict_types=1);

namespace ETFusion\VARI\Resource;

use ETFusion\VARI\Exception\InvalidArgumentException;
use ETFusion\VARI\Internal\Path;
use ETFusion\VARI\Internal\Transport;
use ETFusion\VARI\Model\CustomList;
use ETFusion\VARI\Model\ListRow;

/**
 * Custom lists this client defines and pushes rows into, for customList
 * widgets on the tenant's boards. Other clients cannot see or change them.
 *
 * A list sets the rules its rows follow: with badges, every row must name one
 * of them; with showRelativeDate, every row must carry a timestamp. A list
 * holds at most 50 rows. Rows are keyed by your externalUniqueIdentifier, and
 * writing a row with an existing identifier replaces it.
 *
 * Reads need the `stream:read` scope; writes need `stream:write`.
 */
final class Lists
{
    /** Most rows a list holds, and so the most one upsertRows() call accepts. */
    public const MAX_ROWS = 50;

    /**
     * @param \Closure(): string $tenantId
     *
     * @internal
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly \Closure $tenantId,
    ) {
    }

    /**
     * This client's lists, without their rows.
     *
     * @return list<CustomList>
     */
    public function list(): array
    {
        /** @var array{lists: list<array<string, mixed>>} $data */
        $data = $this->transport->request('GET', $this->path());

        return array_map(CustomList::fromArray(...), $data['lists']);
    }

    /**
     * A list, without its rows.
     *
     * @throws \ETFusion\VARI\Exception\NotFoundException
     */
    public function get(string $key): CustomList
    {
        /** @var array<string, mixed> $data */
        $data = $this->transport->request('GET', $this->path($key));

        return CustomList::fromArray($data);
    }

    /**
     * Defines a list. A client can define at most 50.
     *
     * @param string $key Unique per client: `^[a-z0-9][a-z0-9_-]{0,63}$`.
     * @param string $title Display title, also shown in the admin's board editor (up to 100 chars).
     * @param array<string, string> $badges Badge name to `#RRGGBB` colour; at most 20.
     * @param bool $showRelativeDate Render each row's timestamp as a relative date. Rows then require one.
     *
     * @throws \ETFusion\VARI\Exception\ConflictException The key is in use or the limit is reached.
     */
    public function create(string $key, string $title, array $badges = [], bool $showRelativeDate = false): CustomList
    {
        $body = ['key' => $key, 'title' => $title];
        if ($badges !== []) {
            $body['badges'] = (object) $badges;
        }
        if ($showRelativeDate) {
            $body['showRelativeDate'] = true;
        }

        /** @var array<string, mixed> $data */
        $data = $this->transport->request('POST', $this->path(), $body);

        return CustomList::fromArray($data);
    }

    /**
     * Changes a list's title, badges or relative-date setting. Null leaves a
     * field unchanged. `$badges` replaces the whole set; `[]` removes every badge.
     *
     * @param ?array<string, string> $badges
     *
     * @throws \ETFusion\VARI\Exception\ConflictException The change would leave an existing row breaking
     *                                                the list's rules; update or clear the rows first.
     */
    public function update(string $key, ?string $title = null, ?array $badges = null, ?bool $showRelativeDate = null): CustomList
    {
        $body = [];
        if ($title !== null) {
            $body['title'] = $title;
        }
        if ($badges !== null) {
            $body['badges'] = (object) $badges;
        }
        if ($showRelativeDate !== null) {
            $body['showRelativeDate'] = $showRelativeDate;
        }

        /** @var array<string, mixed> $data */
        $data = $this->transport->request('PATCH', $this->path($key), $body);

        return CustomList::fromArray($data);
    }

    /**
     * Deletes a list and its rows. Boards showing it render it as deleted
     * until an admin removes the widget.
     */
    public function delete(string $key): void
    {
        $this->transport->request('DELETE', $this->path($key));
    }

    /**
     * Removes every row of a list, keeping the list itself.
     */
    public function clear(string $key): void
    {
        $this->transport->request('POST', $this->path($key, 'clear'));
    }

    /**
     * A list's rows in display order: ranked rows first (ascending), then
     * newest timestamp first, then the order they were first written in.
     *
     * @return list<ListRow>
     */
    public function rows(string $key): array
    {
        /** @var array{rows: list<array<string, mixed>>} $data */
        $data = $this->transport->request('GET', $this->path($key, 'rows'));

        return array_map(ListRow::fromArray(...), $data['rows']);
    }

    /**
     * Creates or replaces one row. A field left null is cleared.
     */
    public function upsertRow(string $key, ListRow $row): ListRow
    {
        /** @var array<string, mixed> $data */
        $data = $this->transport->request(
            'PUT',
            $this->path($key, 'rows', $row->externalUniqueIdentifier),
            $row->fields(),
        );

        return ListRow::fromArray($data);
    }

    /**
     * Creates or replaces up to 50 rows in one all-or-nothing request: one
     * invalid row rejects them all. Boards are told to refetch once. Returns
     * the rows as written, in request order.
     *
     * @param iterable<ListRow> $rows
     *
     * @return list<ListRow>
     *
     * @throws InvalidArgumentException More than 50 rows were given.
     * @throws \ETFusion\VARI\Exception\ConflictException The list would hold more than 50 rows.
     */
    public function upsertRows(string $key, iterable $rows): array
    {
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row->toArray();
        }
        if ($batch === []) {
            return [];
        }
        if (count($batch) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('A list holds at most %d rows; %d were given.', self::MAX_ROWS, count($batch)));
        }

        /** @var array{rows: list<array<string, mixed>>} $data */
        $data = $this->transport->request('PUT', $this->path($key, 'rows'), ['rows' => $batch]);

        return array_map(ListRow::fromArray(...), $data['rows']);
    }

    /**
     * Removes one row.
     */
    public function deleteRow(string $key, string $externalUniqueIdentifier): void
    {
        $this->transport->request('DELETE', $this->path($key, 'rows', $externalUniqueIdentifier));
    }

    private function path(string ...$segments): string
    {
        return Path::build('tenants', ($this->tenantId)(), 'streams', 'lists', ...$segments);
    }
}
