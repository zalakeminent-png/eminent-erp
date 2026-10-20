<?php

namespace App\Jobs;

use App\Models\MasterData\ImportBatch;
use App\Services\MasterData\Import\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs large master data imports on the queue (needs a queue worker). */
class ProcessImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $batchId) {}

    public function handle(ImportService $imports): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch?->status === ImportBatch::PROCESSING) {
            $imports->run($batch);
        }
    }
}
