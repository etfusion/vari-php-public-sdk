<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * HTTP 401: the certificate is unknown, revoked, or past its rotation grace period.
 */
class AuthenticationException extends ApiException
{
}
