<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Contracts\Events\Dispatcher;
use MrCache\Events\CacheEvent;

final class CacheObserver
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    public function record(string $operation, ?string $reason = null, array $metadata = []): void
    {
        $this->events->dispatch(new CacheEvent($operation, $reason, $metadata));
    }
}
