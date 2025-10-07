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
    protected $description = 'Flush the MrCache for the application, a specific model, or a specific row.';

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

        if ($modelClass && $pk) {
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
        $this->info('Flushing all MrCache keys...');
        $invalidator->flushAll();
        $this->info('MrCache completely flushed.');
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
        $this->info("Cache for model [{$modelClass}] flushed.");
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
        $this->info("Cache for row [{$pk}] in model [{$modelClass}] flushed.");
    }
}
