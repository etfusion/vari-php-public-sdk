<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Client;
use ETFusion\VARI\Exception\ConfigurationException;
use ETFusion\VARI\Exception\TransportException;
use ETFusion\VARI\Tests\Support\MockApi;
use ETFusion\VARI\Tests\Support\TestCertificates;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
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

    public function testIdentity(): void
    {
        $this->api->respond(200, Fixtures::identity());

        $identity = $this->api->client($this->certs)->identity();

        self::assertSame('GET', $this->api->request(0)->getMethod());
        self::assertSame('/stable/v1/identity', $this->api->request(0)->getUri()->getPath());
        self::assertSame('api.production.v-ari.com', $this->api->request(0)->getUri()->getHost());
        self::assertSame('client-1', $identity->client->id);
        self::assertSame('generic', $identity->client->kind);
        self::assertTrue($identity->hasScope('stream:write'));
        self::assertSame('2026-12-30T00:00:00.123456+00:00', $identity->certificate->notAfter->format('Y-m-d\TH:i:s.uP'));
        self::assertNull($identity->certificate->graceUntil);
    }

    public function testTenantIdComesFromIdentityWhenNotConfigured(): void
    {
        $this->api->respond(200, Fixtures::identity());
        $this->api->respond(200, ['metrics' => []]);
        $this->api->respond(200, ['lists' => []]);

        $client = $this->api->client($this->certs, tenantId: null);
        $client->metrics()->list();
        $client->lists()->list();

        self::assertSame(3, $this->api->count(), 'identity is fetched once');
        self::assertSame('/stable/v1/tenants/tenant-from-identity/streams/lists', $this->api->request(2)->getUri()->getPath());
    }

    public function testCertificateExpiry(): void
    {
        $client = $this->api->client($this->certs);

        self::assertFalse($client->certificateExpiresWithin(30));
        self::assertTrue($client->certificateExpiresWithin(120));
    }

    public function testMissingKeyFile(): void
    {
        $this->expectException(ConfigurationException::class);

        new Client($this->certs->certificatePath, $this->certs->dir . '/missing.key');
    }

    public function testNotACertificate(): void
    {
        $this->expectException(ConfigurationException::class);

        new Client($this->certs->privateKeyPath, $this->certs->privateKeyPath);
    }

    public function testConnectionFailure(): void
    {
        $this->api->mock->append(new ConnectException('handshake failed', new Request('GET', 'identity')));

        $this->expectException(TransportException::class);

        $this->api->client($this->certs)->identity();
    }

    public function testStagingBaseUrl(): void
    {
        $this->api->respond(200, Fixtures::identity());
        $stack = \GuzzleHttp\HandlerStack::create($this->api->mock);
        $history = [];
        $stack->push(\GuzzleHttp\Middleware::history($history));

        $client = new Client($this->certs->certificatePath, $this->certs->privateKeyPath, \ETFusion\VARI\Environment::STAGING, handler: $stack);
        $client->identity();

        self::assertSame('https://api.staging.v-ari.com:8443/stable/v1/identity', (string) $history[0]['request']->getUri());
    }
}
