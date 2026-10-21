<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests\Support;

use ETFusion\VARI\Client;
use ETFusion\VARI\Credentials;
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

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function __construct()
    {
        $this->mock = new MockHandler();
    }

    public function client(TestCertificates $certs, ?string $tenantId = 'tenant-1'): Client
    {
        return $this->clientFromCredentials(Credentials::fromPem($certs->certificatePem(), $certs->privateKeyPem()), $tenantId);
    }

    /**
     * @param array<string, mixed> $httpOptions
     */
    public function clientFromCredentials(Credentials $credentials, ?string $tenantId = 'tenant-1', array $httpOptions = []): Client
    {
        return new Client(
            $credentials,
            tenantId: $tenantId,
            newCertificateRetrySeconds: 0,
            handler: $this->stack(),
            httpOptions: $httpOptions,
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

    /**
     * The request options the handler received, e.g. `curl`.
     *
     * @return array<string, mixed>
     */
    public function options(int $index): array
    {
        return $this->history[$index]['options'];
    }

    public function count(): int
    {
        return count($this->history);
    }

    public function body(int $index): string
    {
        return (string) $this->request($index)->getBody();
    }

    private function stack(): HandlerStack
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return $stack;
    }
}
