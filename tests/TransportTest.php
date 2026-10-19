<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Exception\AuthenticationException;
use ETFusion\VARI\Internal\Transport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    public function testRetries401ForANewCertificate(): void
    {
        $mock = new MockHandler([new Response(401), new Response(401), new Response(200, [], '{"ok":true}')]);
        $slept = 0;

        $transport = new Transport(
            new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]),
            new \DateTimeImmutable(),
            15,
            static function (int $seconds) use (&$slept): void {
                $slept += $seconds;
            },
        );

        self::assertSame(['ok' => true], $transport->request('GET', 'identity'));
        self::assertSame(2, $slept);
    }

    public function testDoesNotRetry401ForAnOldCertificate(): void
    {
        $mock = new MockHandler([new Response(401, [], '{"code":"Unauthorized","message":"not authenticated"}')]);

        $transport = new Transport(
            new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]),
            new \DateTimeImmutable('-1 hour'),
            15,
            static function (): void {
                self::fail('should not sleep');
            },
        );

        $this->expectException(AuthenticationException::class);
        $transport->request('GET', 'identity');
    }

    public function testGivesUpRetryingAfterTheWindow(): void
    {
        $mock = new MockHandler(array_fill(0, 10, new Response(401)));

        $transport = new Transport(
            new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]),
            new \DateTimeImmutable(),
            3,
            static function (): void {
            },
        );

        try {
            $transport->request('GET', 'identity');
            self::fail('expected an exception');
        } catch (AuthenticationException) {
            self::assertSame(6, $mock->count(), 'one attempt plus three retries');
        }
    }
}
