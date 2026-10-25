<?php

declare(strict_types=1);

namespace MrCache\Traits;

/**
 * Uses the package builder for conservative, opt-in query caching.
 * Invalidation is registered centrally by the service provider.
 */
trait CacheableModel
{
    public static function bootCacheableModel(): void
    {
        // SQL events in the provider cover model, bulk, pivot and builder writes.
        // Never invoke arbitrary model methods to discover relationships.
    }

    public function newEloquentBuilder($query): \Illuminate\Database\Eloquent\Builder
    {
        return new \MrCache\Services\CacheBuilder($query);
    }

    /**
     * Get the cache TTL for the model.
     *
     * @return int|null
     */
    public function getCacheTTL(): ?int
    {
        if (! isset($this->cacheTTL) ) {
            return null;
        }
        
        return $this->cacheTTL;
    }
    
    /**
     * @version: 4.x
     */
    final public function getIndependentKeys(): array 
    {
        if (! isset($this->independentKeys) || ! is_array($this->independentKeys) ) {
            return [];
        }
        
        return $this->independentKeys;
    }

    /**
     * @version: 4.x
     */
    final public function getCompositeKeys(): array 
    {
        if (! isset($this->compositeKeys) || ! is_array($this->compositeKeys) ) {
            return [];
        }
        
        return $this->compositeKeys;
    }

    /**
     * @version: 4.x
     */
    final public function isContaintIKs(): bool 
    {
        return count($this->getIndependentKeys()) > 0;
    }
    
    final public function getIndexes(): array
    {
        if (! isset($this->indexes) || ! is_array($this->indexes) ) {
            return [];
        }
        
        return $this->indexes;
    }
    
    final public function containsIndexes(): bool 
    {
        return count($this->getIndexes()) > 0;
    }
}

