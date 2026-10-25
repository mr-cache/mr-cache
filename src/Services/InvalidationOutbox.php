<?php

declare(strict_types=1);

namespace MrCache\Services;

use Closure;
use Illuminate\Database\DatabaseManager;
use MrCache\Exceptions\RedisConnectionException;

/** Explicit durable invalidation, atomically recorded with application writes. */
final class InvalidationOutbox
{
    public function __construct(private readonly DatabaseManager $database, private readonly CacheInvalidator $invalidator,
        private readonly CacheObserver $observer, private readonly array $config)
    {
    }

    public function transaction(?string $connection, Closure $work, array $tables = []): mixed
    {
        return $this->database->connection($connection)->transaction(function ($database) use ($work, $tables) {
            $result = $work($database);
            $this->record($database->getName(), $tables);
            return $result;
        });
    }

    public function record(?string $connection = null, array $tables = []): void
    {
        $database = $this->database->connection($connection);
        if ($database->transactionLevel() < 1) {
            throw new \LogicException('MrCache outbox records must be written inside the application database transaction.');
        }
        $database->table($this->table())->insert([
            'dependencies' => json_encode(TableNames::list($tables), JSON_THROW_ON_ERROR),
            'attempts' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->observer->record('outbox_recorded');
    }

    public function drain(?string $connection = null, int $batch = 100): array
    {
        QueryPolicy::integer($batch, 1, 1000, 'outbox_batch');
        $database = $this->database->connection($connection);
        if ($database->transactionLevel() > 0) {
            throw new \LogicException('MrCache outbox draining must run outside application transactions.');
        }
        $rows = $database->table($this->table())->useWritePdo()->orderBy('attempts')->orderBy('id')->limit($batch)->get();
        $processed = $failed = 0;
        foreach ($rows as $row) {
            try {
                $tables = json_decode($row->dependencies, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($tables)) {
                    throw new \JsonException('Invalid outbox dependencies.');
                }
                // Records can be replayed concurrently: repeated rotations are safe.
                $ok = $tables === [] ? $this->invalidator->invalidateAll() : $this->invalidator->invalidateTables($tables);
            } catch (RedisConnectionException) {
                $ok = false;
            } catch (\JsonException|\InvalidArgumentException) {
                // Keep malformed records visible/retryable without starving later rows in this batch.
                $this->observer->record('outbox_failed', 'invalid_dependencies');
                $ok = false;
            }
            if ($ok) {
                $database->table($this->table())->where('id', $row->id)->delete();
                $processed++;
            } else {
                $database->table($this->table())->where('id', $row->id)->increment('attempts');
                $failed++;
            }
        }
        return ['processed' => $processed, 'failed' => $failed];
    }

    private function table(): string
    {
        return TableNames::normalize($this->config['outbox']['table'] ?? 'mrcache_outbox');
    }
}
