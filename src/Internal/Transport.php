<?php

declare(strict_types=1);

namespace ETFusion\VARI\Internal;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use ETFusion\VARI\Exception\ApiException;
use ETFusion\VARI\Exception\TransportException;

/**
 * Sends JSON requests and turns error responses into exceptions.
 *
 * @internal
 */
final class Transport
{
    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param int $newCertificateRetrySeconds A 401 is retried for this many seconds after the certificate was issued.
     * @param (\Closure(int): void)|null $sleep Sleeps for the given number of seconds.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly \DateTimeImmutable $certificateIssuedAt,
        private readonly int $newCertificateRetrySeconds,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>|null The decoded body, or null for an empty response.
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): ?array
    {
        $options = ['headers' => ['Accept' => 'application/json']];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            // An empty body is a JSON object, never [].
            $options['body'] = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }
        if ($query !== []) {
            $options['query'] = $query;
        }

        $retries = 0;
        while (true) {
            $response = $this->send($method, $path, $options);
            if ($response->getStatusCode() === 401
                && $retries++ < $this->newCertificateRetrySeconds
                && $this->withinNewCertificateWindow()) {
                // A freshly issued certificate takes a few seconds to reach every region.
                ($this->sleep)(1);
                continue;
            }
            break;
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw ApiException::fromResponse($response);
        }

        $contents = (string) $response->getBody();
        if ($status === 204 || $contents === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(sprintf('Invalid JSON from %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new TransportException(sprintf('Unexpected JSON from %s %s.', $method, $path));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        try {
            return $this->http->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new TransportException(sprintf('%s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }
    }

    private function withinNewCertificateWindow(): bool
    {
        if ($this->newCertificateRetrySeconds <= 0) {
            return false;
        }

        return time() - $this->certificateIssuedAt->getTimestamp() < $this->newCertificateRetrySeconds;
    }
}
