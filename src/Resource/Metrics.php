<?php

declare(strict_types=1);

namespace ETFusion\VARI\Resource;

use ETFusion\VARI\Internal\Path;
use ETFusion\VARI\Internal\Transport;
use ETFusion\VARI\Model\Metric;

/**
 * Metrics this client defines and pushes values into, for metric widgets on
 * the tenant's boards. Other clients cannot see or change them.
 *
 * Reads need the `stream:read` scope; writes need `stream:write`.
 */
final class Metrics
{
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
     * @return list<Metric>
     */
    public function list(): array
    {
        /** @var array{metrics: list<array<string, mixed>>} $data */
        $data = $this->transport->request('GET', $this->path());

        return array_map(Metric::fromArray(...), $data['metrics']);
    }

    /**
     * Defines a metric. A client can define at most 100.
     *
     * @param string $key Unique per client: `^[a-z0-9][a-z0-9_-]{0,63}$`.
     * @param string $label Caption shown in the admin's board editor (up to 100 chars).
     * @param ?string $unit Unit hint such as "°C", "%" or "count" (up to 16 chars).
     * @param int|float|string|bool|null $value Initial value; strings up to 256 bytes.
     *
     * @throws \ETFusion\VARI\Exception\ConflictException The key is in use or the limit is reached.
     */
    public function create(
        string $key,
        string $label,
        ?string $description = null,
        ?string $unit = null,
        int|float|string|bool|null $value = null,
    ): Metric {
        $body = ['key' => $key, 'label' => $label];
        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($unit !== null) {
            $body['unit'] = $unit;
        }
        if ($value !== null) {
            $body['value'] = $value;
        }

        /** @var array<string, mixed> $data */
        $data = $this->transport->request('POST', $this->path(), $body);

        return Metric::fromArray($data);
    }

    /**
     * Changes a metric's label, description or unit. Null leaves a field
     * unchanged; an empty description or unit clears it.
     */
    public function update(string $key, ?string $label = null, ?string $description = null, ?string $unit = null): Metric
    {
        $body = array_filter(
            ['label' => $label, 'description' => $description, 'unit' => $unit],
            static fn (?string $v): bool => $v !== null,
        );

        /** @var array<string, mixed> $data */
        $data = $this->transport->request('PATCH', $this->path($key), $body);

        return Metric::fromArray($data);
    }

    /**
     * Sets the metric's current value. Boards showing it refetch within seconds.
     *
     * @param int|float|string|bool|null $value Strings up to 256 bytes; null clears the value.
     */
    public function setValue(string $key, int|float|string|bool|null $value): Metric
    {
        /** @var array<string, mixed> $data */
        $data = $this->transport->request('PUT', $this->path($key, 'value'), ['value' => $value]);

        return Metric::fromArray($data);
    }

    /**
     * Deletes a metric. Boards showing it render it as deleted until an admin
     * removes the widget; creating the same key again brings it back.
     */
    public function delete(string $key): void
    {
        $this->transport->request('DELETE', $this->path($key));
    }

    private function path(string ...$segments): string
    {
        return Path::build('tenants', ($this->tenantId)(), 'streams', 'metrics', ...$segments);
    }
}
