<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Exceptions;

/**
 * HTTP 5xx: the server failed to handle the request.
 */
class InternalServerException extends ApiException {}
