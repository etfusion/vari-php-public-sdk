<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * The client was configured with something unusable, such as a missing
 * certificate file.
 */
class ConfigurationException extends \InvalidArgumentException implements VariException
{
}
