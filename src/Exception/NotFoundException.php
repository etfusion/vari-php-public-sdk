<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * HTTP 404: the metric, list or row does not exist (or belongs to another client).
 */
class NotFoundException extends ApiException
{
}
