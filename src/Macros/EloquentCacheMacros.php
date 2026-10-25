<?php

declare(strict_types=1);

namespace MrCache\Macros;

use Illuminate\Database\Eloquent\Builder;

/**
 * Provides chainable macro methods for the Eloquent Query Builder.
 *
 * These methods allow fine-grained control over the caching behavior on a per-query basis.
 * The methods are registered onto the Builder via the MrCacheServiceProvider.
 *
 * @mixin Builder
 */
final class EloquentCacheMacros
{
    /**
     * Excludes the current query from being cached.
     *
     * @return \Closure
     */
    public function withoutCaching(): \Closure
    {
        return function () {
            /** @var Builder $this */
            if ($this instanceof \MrCache\Services\CacheBuilder) {
                $this->mrcache_without_caching = true;
            }
            return $this;
        };
    }

    /**
     * Sets a custom Time-To-Live (in seconds) for this specific query.
     * This overrides any model-level or global TTL configuration.
     *
     * @return \Closure
     */
    public function withCustomTTL(): \Closure
    {
        return function (int $seconds) {
            /** @var Builder $this */
            if ($seconds < 0 || $seconds > 2147483647) {
                throw new \InvalidArgumentException('MrCache TTL must be between 0 and 2147483647 seconds.');
            }
            if ($this instanceof \MrCache\Services\CacheBuilder) {
                $this->mrcache_custom_ttl = $seconds;
            }
            return $this;
        };
    }

    /**
     * A semantic, chainable method to indicate caching is desired.
     *
     * This has no functional effect and does not enable caching.
     *
     * @return \Closure
     */
    public function cache(): \Closure
    {
        return function () {
            /** @var Builder $this */
            return $this;
        };
    }
}
