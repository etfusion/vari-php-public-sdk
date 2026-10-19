<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * The API answered with an error status. Subclasses narrow it by status.
 */
class ApiException extends \RuntimeException implements VariException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly ?ResponseInterface $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $errorCode = $response->getReasonPhrase();
        $message = '';

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $errorCode = is_string($decoded['code'] ?? null) ? $decoded['code'] : $errorCode;
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
        }
        if ($message === '') {
            $message = $body !== '' ? $body : sprintf('HTTP %d %s', $status, $errorCode);
        }

        $class = match (true) {
            $status === 400, $status === 422 => ValidationException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class($status, $errorCode, $message, $response);
    }

    /** HTTP status code. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** The `code` field of the error body, e.g. "Conflict". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
