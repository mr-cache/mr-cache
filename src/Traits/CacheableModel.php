<?php

declare(strict_types=1);

namespace MrCache\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MrCache\Contracts\InvalidationInterface;
use MrCache\Services\CacheManager;

/**
 * Enables automatic Eloquent query caching for a model.
 *
 * To use, simply `use CacheableModel;` in your Eloquent model class.
 * This trait overrides the query builder to intercept `get()` calls and
 * automatically handles cache invalidation on model `saved` and `deleted` events.
 */
trait CacheableModel
{
    /**
     * The default cache Time-To-Live for this model, in seconds.
     * This can be overridden by the global config or a per-query TTL.
     *
     * @var int|null
     */
    protected ?int $cacheTTL = null;

    /**
     * Boot the trait.
     * This method registers the model event listeners for automatic cache invalidation.
     */
    public static function bootCacheableModel(): void
    {
        $invalidator = app(\MrCache\Contracts\InvalidationInterface::class);

        static::saved(function (\Illuminate\Database\Eloquent\Model $model) use ($invalidator) {
            $invalidator->invalidateRow($model->getTable(), $model->getKey());
        });

        static::deleted(function (\Illuminate\Database\Eloquent\Model $model) use ($invalidator) {
            $invalidator->invalidateRow($model->getTable(), $model->getKey());

            $reflection = new \ReflectionClass($model);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->class !== $reflection->getName() || $method->getNumberOfParameters() > 0) {
                    continue;
                }

                try {
                    $return = $method->invoke($model);

                    if ($return instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relatedModels = $return->getResults();

                        if ($relatedModels instanceof \Illuminate\Database\Eloquent\Model) {
                            $invalidator->invalidateRow($relatedModels->getTable(), $relatedModels->getKey());
                        } elseif ($relatedModels instanceof \Illuminate\Support\Collection) {
                            foreach ($relatedModels as $child) {
                                if ($child instanceof \Illuminate\Database\Eloquent\Model) {
                                    $invalidator->invalidateRow($child->getTable(), $child->getKey());
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Logic
                }
            }
        });
    }

    /**
     * Override the default Eloquent builder to intercept query execution.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newEloquentBuilder($query): \Illuminate\Database\Eloquent\Builder
    {
        /**
         * We create an anonymous class that extends the default Eloquent Builder.
         * This allows us to override the `get()` method, which is the final step
         * in executing a read query. All other builder methods remain unchanged.
         */
        return new class($query) extends \Illuminate\Database\Eloquent\Builder {
            /**
             * Execute the query as a "select" statement, with caching.
             *
             * @param  array|string  $columns
             * @return \Illuminate\Database\Eloquent\Collection|static[]
             */
            public function get($columns = ['*'])
            {
                if (!config('mrcache.enabled', true) ||
                    (property_exists($this, 'mrcache_without_caching') && $this->mrcache_without_caching)
                ) {
                    return parent::get($columns);
                }

                /** @var CacheManager $cacheManager */
                $cacheManager = app(\MrCache\Services\CacheManager::class);

                return $cacheManager->rememberQuery($this, fn() => parent::get($columns));
            }
        };
    }

    /**
     * Get the cache TTL for the model.
     *
     * @return int|null
     */
    public function getCacheTTL(): ?int
    {
        return $this->cacheTTL;
    }
}

