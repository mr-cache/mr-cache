<?php

declare(strict_types=1);

namespace MrCache\Events;

/** No SQL, bindings, context values, row data or lock tokens are emitted. */
final class CacheEvent
{
    public function __construct(
        public readonly string $operation,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {
    }
}
