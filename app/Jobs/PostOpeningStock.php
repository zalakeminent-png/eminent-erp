<?php

namespace App\Jobs;

use App\Models\Inventory\OpeningStockImport;
use App\Services\Inventory\OpeningStockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Posts large opening stock imports on the queue (needs a queue worker). */
class PostOpeningStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $importId) {}

    public function handle(OpeningStockService $service): void
    {
        $import = OpeningStockImport::query()->find($this->importId);
        if ($import?->status === OpeningStockImport::POSTING) {
            $service->run($import);
        }
    }
}
