<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * The request never got an HTTP response: DNS, connection, TLS handshake
 * (including a client certificate the load balancer rejected) or timeout.
 */
class TransportException extends \RuntimeException implements VariException
{
}
