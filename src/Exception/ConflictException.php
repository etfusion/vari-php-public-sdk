<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * HTTP 409: the key is already in use, a limit was reached, or a list change would break its existing rows.
 */
class ConflictException extends ApiException
{
}
