<?php

declare(strict_types=1);

namespace ETFusion\VARI\Exception;

/**
 * HTTP 403: the certificate's role lacks the required scope, or the tenant/stream in the path is not the certificate's.
 */
class PermissionDeniedException extends ApiException
{
}
