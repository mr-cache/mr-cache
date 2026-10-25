<?php

declare(strict_types=1);

namespace MrCache\Testing;

use Closure;
use MrCache\Events\CacheEvent;

/** Consumer test helpers use real package events, not mock cache semantics. */
trait InteractsWithMrCache
{
    public function assertMrCacheOperation(string $operation, Closure $work, ?string $reason = null): mixed
    {
        $seen = [];
        $active = true;
        // No global forget(): existing application listeners must remain registered.
        app('events')->listen(CacheEvent::class, function (CacheEvent $event) use (&$seen, &$active) {
            if ($active) {
                $seen[] = $event;
            }
        });
        try {
            $result = $work();
        } finally {
            $active = false;
        }
        $matching = array_filter($seen, fn ($event) => $event->operation === $operation && ($reason === null || $reason === $event->reason));
        $this->assertNotEmpty($matching, "Expected MrCache operation {$operation} was not observed.");
        return $result;
    }
}
