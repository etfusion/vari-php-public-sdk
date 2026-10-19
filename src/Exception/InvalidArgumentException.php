<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * A method was called with input the API is known to refuse, so no request was sent.
 */
class InvalidArgumentException extends \InvalidArgumentException implements VariException
{
}
