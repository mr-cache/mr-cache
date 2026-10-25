<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Database\Eloquent\Builder;

final class PolicyResolver
{
    public function __construct(private readonly array $config)
    {
    }

    public function resolve(Builder $builder): QueryPolicy
    {
        $values = [
            'ttl' => $this->config['default_ttl'] ?? 3600,
            'max_payload_bytes' => $this->config['max_payload_bytes'] ?? 1048576,
            'consistency' => $this->config['consistency'] ?? 'bounded',
            'refresh_ahead_seconds' => $this->config['refresh_ahead_seconds'] ?? 0,
            'relations' => [],
        ];
        $model = $builder->getModel();
        if (method_exists($model, 'getCacheTTL') && $model->getCacheTTL() !== null) {
            $values['ttl'] = $model->getCacheTTL();
        }
        $modelPolicy = $this->config['policies'][$model::class] ?? [];
        $overrides = $builder->mrcache_policy ?? [];
        foreach ([$modelPolicy, $overrides] as $policy) {
            if (!is_array($policy) || array_diff(array_keys($policy), array_keys($values))) {
                throw new \InvalidArgumentException('MrCache policy contains unsupported options.');
            }
            $values = array_replace($values, $policy);
        }
        if (($builder->mrcache_custom_ttl ?? null) !== null) {
            $values['ttl'] = $builder->mrcache_custom_ttl;
        }
        return new QueryPolicy($values);
    }
}
