<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests;

use ETFusion\VARI\Exception\ApiException;
use ETFusion\VARI\Exception\AuthenticationException;
use ETFusion\VARI\Exception\ConflictException;
use ETFusion\VARI\Exception\NotFoundException;
use ETFusion\VARI\Exception\PermissionDeniedException;
use ETFusion\VARI\Exception\RateLimitException;
use ETFusion\VARI\Exception\ServerException;
use ETFusion\VARI\Exception\ValidationException;
use ETFusion\VARI\Exception\VariException;
use ETFusion\VARI\Tests\Support\MockApi;
use ETFusion\VARI\Tests\Support\TestCertificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, PermissionDeniedException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '422' => [422, ValidationException::class];
        yield '429' => [429, RateLimitException::class];
        yield '503' => [503, ServerException::class];
        yield '418' => [418, ApiException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('statuses')]
    public function testStatusMapsToException(int $status, string $class): void
    {
        $certs = new TestCertificates();
        $api = new MockApi();
        $api->respond($status, ['code' => 'Some Code', 'message' => 'it went wrong']);

        try {
            $api->client($certs)->lists()->get('incidents');
            self::fail('expected an exception');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertInstanceOf(VariException::class, $e);
            self::assertSame($status, $e->getStatusCode());
            self::assertSame('Some Code', $e->getErrorCode());
            self::assertSame('it went wrong', $e->getMessage());
        } finally {
            $certs->cleanUp();
        }
    }

    public function testNonJsonErrorBody(): void
    {
        $certs = new TestCertificates();
        $api = new MockApi();
        $api->mock->append(new \GuzzleHttp\Psr7\Response(502, [], 'Bad Gateway'));

        try {
            $api->client($certs)->lists()->list();
            self::fail('expected an exception');
        } catch (ServerException $e) {
            self::assertSame('Bad Gateway', $e->getMessage());
        } finally {
            $certs->cleanUp();
        }
    }
}
