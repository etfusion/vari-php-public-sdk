<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests\Support;

use ETFusion\VARI\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Builds a Client whose requests are answered from a queue and recorded.
 */
final class MockApi
{
    public readonly MockHandler $mock;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    public function __construct()
    {
        $this->mock = new MockHandler();
    }

    public function client(TestCertificates $certs, ?string $tenantId = 'tenant-1'): Client
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new Client(
            $certs->certificatePath,
            $certs->privateKeyPath,
            tenantId: $tenantId,
            newCertificateRetrySeconds: 0,
            handler: $stack,
        );
    }

    /**
     * @param array<mixed>|null $body
     */
    public function respond(int $status, ?array $body = null): void
    {
        $this->mock->append(new Response(
            $status,
            $body !== null ? ['Content-Type' => 'application/json'] : [],
            $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : '',
        ));
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    public function count(): int
    {
        return count($this->history);
    }

    public function body(int $index): string
    {
        return (string) $this->request($index)->getBody();
    }
}
