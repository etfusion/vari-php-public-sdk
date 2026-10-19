<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Model\ListRow;
use ETFusion\VARI\Tests\Support\MockApi;
use ETFusion\VARI\Tests\Support\TestCertificates;
use PHPUnit\Framework\TestCase;

final class ListsTest extends TestCase
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

    public function testCreate(): void
    {
        $this->api->respond(201, Fixtures::list());

        $list = $this->api->client($this->certs)->lists()->create(
            'incidents',
            'Open incidents',
            badges: ['P1' => '#ff0000'],
            showRelativeDate: true,
        );

        $request = $this->api->request(0);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(
            '{"key":"incidents","title":"Open incidents","badges":{"P1":"#ff0000"},"showRelativeDate":true}',
            $this->api->body(0),
        );
        self::assertSame('custom.client-1.incidents', $list->id);
        self::assertSame(['P1' => '#FF0000'], $list->badges);
        self::assertTrue($list->showRelativeDate);
    }

    public function testCreateWithoutOptionalFields(): void
    {
        $this->api->respond(201, Fixtures::list(['badges' => [], 'showRelativeDate' => false]));

        $list = $this->api->client($this->certs)->lists()->create('incidents', 'Open incidents');

        self::assertSame('{"key":"incidents","title":"Open incidents"}', $this->api->body(0));
        self::assertSame([], $list->badges);
    }

    public function testUpdateRemovingAllBadgesSendsEmptyObject(): void
    {
        $this->api->respond(200, Fixtures::list(['badges' => []]));

        $this->api->client($this->certs)->lists()->update('incidents', badges: []);

        self::assertSame('PATCH', $this->api->request(0)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents', $this->api->request(0)->getUri()->getPath());
        self::assertSame('{"badges":{}}', $this->api->body(0));
    }

    public function testUpdateLeavesOutUnchangedFields(): void
    {
        $this->api->respond(200, Fixtures::list());

        $this->api->client($this->certs)->lists()->update('incidents', title: 'Incidents', showRelativeDate: false);

        self::assertSame('{"title":"Incidents","showRelativeDate":false}', $this->api->body(0));
    }

    public function testGetDeleteAndClear(): void
    {
        $this->api->respond(200, Fixtures::list());
        $this->api->respond(204);
        $this->api->respond(204);

        $lists = $this->api->client($this->certs)->lists();
        self::assertSame('Open incidents', $lists->get('incidents')->title);
        $lists->delete('incidents');
        $lists->clear('incidents');

        self::assertSame('DELETE', $this->api->request(1)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents', $this->api->request(1)->getUri()->getPath());
        self::assertSame('POST', $this->api->request(2)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents/clear', $this->api->request(2)->getUri()->getPath());
    }

    public function testRows(): void
    {
        $this->api->respond(200, ['rows' => [
            Fixtures::row('a', ['rank' => 1, 'subtitles' => ['Line 1', 'Line 2'], 'displayIdentifier' => 'INC-1']),
            Fixtures::row('b'),
        ]]);

        $rows = $this->api->client($this->certs)->lists()->rows('incidents');

        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents/rows', $this->api->request(0)->getUri()->getPath());
        self::assertCount(2, $rows);
        self::assertSame('a', $rows[0]->externalUniqueIdentifier);
        self::assertSame(1, $rows[0]->rank);
        self::assertSame(['Line 1', 'Line 2'], $rows[0]->subtitles);
        self::assertSame('INC-1', $rows[0]->displayIdentifier);
        self::assertNull($rows[1]->rank);
        self::assertNotNull($rows[1]->createdAt);
    }

    public function testUpsertRowEncodesIdentifierInPath(): void
    {
        $this->api->respond(200, Fixtures::row('INC/42 #1'));

        $row = $this->api->client($this->certs)->lists()->upsertRow('incidents', new ListRow(
            externalUniqueIdentifier: 'INC/42 #1',
            title: 'Printer on fire',
            subtitles: ['Floor 3'],
            timestamp: new \DateTimeImmutable('2026-10-19T05:30:00-04:00'),
            badge: 'P1',
            rank: 1.5,
        ));

        $request = $this->api->request(0);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents/rows/INC%2F42%20%231', $request->getUri()->getPath());
        self::assertSame(
            '{"title":"Printer on fire","subtitles":["Floor 3"],"timestamp":"2026-10-19T09:30:00.000000Z","badge":"P1","rank":1.5}',
            $this->api->body(0),
        );
        self::assertSame('INC/42 #1', $row->externalUniqueIdentifier);
    }

    public function testUpsertRowsSendsBatchesOfOneHundred(): void
    {
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = new ListRow((string) $i, 'Row ' . $i);
        }
        foreach ([[0, 100], [100, 100], [200, 50]] as [$offset, $length]) {
            $this->api->respond(200, ['rows' => array_map(
                static fn (int $i): array => Fixtures::row((string) $i),
                range($offset, $offset + $length - 1),
            )]);
        }

        $written = $this->api->client($this->certs)->lists()->upsertRows('incidents', $rows);

        self::assertSame(3, $this->api->count());
        self::assertCount(250, $written);
        self::assertSame('249', $written[249]->externalUniqueIdentifier);

        $first = json_decode($this->api->body(0), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(100, $first['rows']);
        self::assertSame(['externalUniqueIdentifier' => '0', 'title' => 'Row 0'], $first['rows'][0]);
        $last = json_decode($this->api->body(2), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(50, $last['rows']);
        self::assertSame('PUT', $this->api->request(2)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents/rows', $this->api->request(2)->getUri()->getPath());
    }

    public function testUpsertRowsWithNoRowsSendsNothing(): void
    {
        self::assertSame([], $this->api->client($this->certs)->lists()->upsertRows('incidents', []));
        self::assertSame(0, $this->api->count());
    }

    public function testDeleteRow(): void
    {
        $this->api->respond(204);

        $this->api->client($this->certs)->lists()->deleteRow('incidents', 'a');

        self::assertSame('DELETE', $this->api->request(0)->getMethod());
        self::assertSame('/stable/v1/tenants/tenant-1/streams/lists/incidents/rows/a', $this->api->request(0)->getUri()->getPath());
    }
}
