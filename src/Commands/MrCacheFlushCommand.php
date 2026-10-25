<?php

declare(strict_types=1);

namespace MrCache\Commands;

use Illuminate\Console\Command;
use MrCache\Contracts\InvalidationInterface;

class MrCacheFlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mrcache:flush
                            {--model= : The model class to flush (e.g., "App\\Models\\User")}
                            {--pk= : The primary key of the row to flush (requires --model)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Invalidate the MrCache namespace (model/row selectors are conservative).';

    /**
     * Execute the console command.
     *
     * @param \MrCache\Contracts\InvalidationInterface $invalidator
     * @return int
     */
    public function handle(InvalidationInterface $invalidator): int
    {
        $modelClass = $this->option('model');
        $pk = $this->option('pk');

        if ($pk !== null && !$modelClass) {
            $this->error('--pk requires --model.');
            return self::FAILURE;
        }
        if ($modelClass && (!class_exists($modelClass) || !is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class))) {
            $this->error('The --model option must name an Eloquent model class.');
            return self::FAILURE;
        }
        if ($modelClass && $pk !== null) {
            $this->flushRow($invalidator, $modelClass, $pk);
        } elseif ($modelClass) {
            $this->flushModel($invalidator, $modelClass);
        } else {
            $this->flushAll($invalidator);
        }

        return self::SUCCESS;
    }

    private function flushAll(InvalidationInterface $invalidator): void
    {
        $this->info('Invalidating the MrCache namespace...');
        $invalidator->flushAll();
        $this->info('MrCache namespace invalidated; old entries expire by TTL.');
    }

    private function flushModel(InvalidationInterface $invalidator, string $modelClass): void
    {
        if (!class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] not found.");
            return;
        }

        $model = new $modelClass();
        $table = $model->getTable();

        $this->info("Flushing MrCache for model [{$modelClass}] (table: {$table})...");
        $invalidator->invalidateTable($table);
        $this->info('MrCache namespace invalidated; model selectors are conservative.');
    }

    private function flushRow(InvalidationInterface $invalidator, string $modelClass, string|int $pk): void
    {
        if (!class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] not found.");
            return;
        }

        $model = new $modelClass();
        $table = $model->getTable();

        $this->info("Flushing MrCache for row in [{$modelClass}] with PK [{$pk}]...");
        $invalidator->invalidateRow($table, $pk);
        $this->info('MrCache namespace invalidated; row selectors are conservative.');
    }
}
