<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Tests\Support\MockApi;
use ETFusion\VARI\Tests\Support\TestCertificates;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    private TestCertificates $certs;
    private MockApi $api;

    protected function setUp(): void
    {
        $this->certs = new TestCertificates();
        $this->api = new MockApi();
    }

    protected function tearDown(): void
    {
        $this->certs->cleanUp();
    }

    public function testList(): void
    {
        $this->api->respond(200, ['metrics' => [Fixtures::metric(), Fixtures::metric(['key' => 'uptime', 'value' => null, 'valueUpdatedAt' => null])]]);

        $metrics = $this->api->client($this->certs)->metrics()->list();

        self::assertSame('/stable/v1/tenants/tenant-1/streams/metrics', $this->api->request(0)->getUri()->getPath());
        self::assertSame(42, $metrics[0]->value);
        self::assertSame('count', $metrics[0]->unit);
        self::assertNull($metrics[1]->value);
        self::assertNull($metrics[1]->valueUpdatedAt);
    }

    public function testCreate(): void
    {
        $this->api->respond(201, Fixtures::metric());

        $this->api->client($this->certs)->metrics()->create('open-tickets', 'Open tickets', unit: 'count', value: 0);

        self::assertSame('POST', $this->api->request(0)->getMethod());
        self::assertSame('{"key":"open-tickets","label":"Open tickets","unit":"count","value":0}', $this->api->body(0));
    }

    public function testUpdate(): void
    {
        $this->api->respond(200, Fixtures::metric());

        $this->api->client($this->certs)->metrics()->update('open-tickets', description: '');

        self::assertSame('PATCH', $this->api->request(0)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/metrics/open-tickets', $this->api->request(0)->getUri()->getPath());
        self::assertSame('{"description":""}', $this->api->body(0));
    }

    public function testSetValue(): void
    {
        $this->api->respond(200, Fixtures::metric(['value' => 2.0]));
        $this->api->respond(200, Fixtures::metric(['value' => null]));

        $metrics = $this->api->client($this->certs)->metrics();
        $metrics->setValue('open-tickets', 2.0);
        $metrics->setValue('open-tickets', null);

        self::assertSame('PUT', $this->api->request(0)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/metrics/open-tickets/value', $this->api->request(0)->getUri()->getPath());
        self::assertSame('{"value":2.0}', $this->api->body(0));
        self::assertSame('{"value":null}', $this->api->body(1));
    }

    public function testDelete(): void
    {
        $this->api->respond(204);

        $this->api->client($this->certs)->metrics()->delete('open-tickets');

        self::assertSame('DELETE', $this->api->request(0)->getMethod());
    }
}
