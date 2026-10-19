<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * HTTP 5xx: the API failed; safe to retry idempotent calls.
 */
class ServerException extends ApiException
{
}
