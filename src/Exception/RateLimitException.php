<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * HTTP 429: too many requests; back off and retry.
 */
class RateLimitException extends ApiException
{
}
