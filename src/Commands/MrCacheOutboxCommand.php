<?php

declare(strict_types=1);

namespace MrCache\Commands;

use Illuminate\Console\Command;
use MrCache\Services\InvalidationOutbox;
use MrCache\Services\QueryPolicy;

final class MrCacheOutboxCommand extends Command
{
    protected $signature = 'mrcache:outbox {--connection= : Database connection containing the outbox} {--batch=100}';
    protected $description = 'Replay a bounded batch of durable invalidations; failed records remain for retry.';

    public function handle(InvalidationOutbox $outbox): int
    {
        $batch = QueryPolicy::integer($this->option('batch'), 1, 1000, 'outbox_batch');
        $result = $outbox->drain($this->option('connection'), $batch);
        $this->info("Processed: {$result['processed']}; failed: {$result['failed']}.");
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
