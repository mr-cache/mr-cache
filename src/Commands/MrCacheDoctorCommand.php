<?php

declare(strict_types=1);

namespace MrCache\Commands;

use Illuminate\Console\Command;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use MrCache\Services\QueryLock;

final class MrCacheDoctorCommand extends Command
{
    protected $signature = 'mrcache:doctor';
    protected $description = 'Probe required Redis commands with isolated, expiring keys; never prints credentials.';

    public function handle(CacheClientInterface $client, KeyGeneratorInterface $keys): int
    {
        $key = $keys->getMetricsKey('doctor_'.bin2hex(random_bytes(8)));
        $lock = new QueryLock($client, $key.':lock', 1000);
        $token = null;
        try {
            $ok = $client->set($key, 'probe', 5) && $client->get($key) === 'probe';
            $token = $lock->acquire();
            $ok = $ok && $token !== null && $lock->publish($token, $key, 'published', 5) && $client->getTtl($key) > 0;
            if ($ok) {
                $this->info('Redis GET/SETEX/EVAL/TTL and ownership checks passed.');
                $this->line('Caching: '.(config('mrcache.enabled') ? 'enabled' : 'disabled').'; verify a unique application/environment prefix.');
                $this->line('Outbox requires its migration on each application database and a scheduled drain.');
                return self::SUCCESS;
            }
            $this->error('Redis probe failed; inspect connectivity, ACLs and cache warnings.');
            return self::FAILURE;
        } finally {
            if ($token !== null) {
                $lock->release($token);
            }
            $client->del($key);
        }
    }
}
