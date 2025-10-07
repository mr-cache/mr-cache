<?php

declare(strict_types=1);

namespace MrCache\Exceptions;

/**
 * Thrown when the direct Redis client fails to connect or execute a command
 * and the package is configured in 'strict_mode'.
 */
class RedisConnectionException extends \Exception
{
    // Custom logic can be added here if needed in the future.
}
