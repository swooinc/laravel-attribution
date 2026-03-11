<?php

namespace SwooInc\Attribution\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SwooInc\Attribution\AttributionService;

class ImportAttributionChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Pre-built DB rows ready for insertOrIgnore.
     *
     * @var array
     */
    private $rows;

    /**
     * @param  array  $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;

        $connection = config('attribution.queue.connection');

        if ($connection !== null) {
            $this->onConnection((string) $connection);
        }

        $this->onQueue(
            (string) (config('attribution.queue.name') ?: 'default')
        );
    }

    public function handle(AttributionService $service): void
    {
        $service->importChunk($this->rows);
    }
}
