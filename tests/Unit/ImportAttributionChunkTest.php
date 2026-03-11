<?php

namespace SwooInc\Attribution\Tests\Unit;

use SwooInc\Attribution\AttributionService;
use SwooInc\Attribution\Jobs\ImportAttributionChunk;
use SwooInc\Attribution\Models\AttributionRecord;
use SwooInc\Attribution\Tests\TestCase;

class ImportAttributionChunkTest extends TestCase
{
    // =========================================================================
    // handle()
    // =========================================================================

    /**
     * @test
     */
    public function it_inserts_rows_when_handled(): void
    {
        $userA   = $this->createUserId($this->uniqueEmail('a'));
        $userB   = $this->createUserId($this->uniqueEmail('b'));
        $service = app(AttributionService::class);

        $job = new ImportAttributionChunk([
            $service->buildRow($userA, [], 'klaviyo_backfill'),
            $service->buildRow($userB, [], 'klaviyo_backfill'),
        ]);

        $job->handle($service);

        $this->assertSame(2, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function it_handles_an_empty_rows_array_without_error(): void
    {
        $job = new ImportAttributionChunk([]);

        $job->handle(app(AttributionService::class));

        $this->assertSame(0, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function it_skips_duplicate_user_ids_silently(): void
    {
        $userId  = $this->createUserId($this->uniqueEmail());
        $service = app(AttributionService::class);

        (new ImportAttributionChunk([
            $service->buildRow($userId, [
                'initial' => ['utm_source' => 'google'],
            ], 'klaviyo_backfill'),
        ]))->handle($service);

        (new ImportAttributionChunk([
            $service->buildRow($userId, [
                'initial' => ['utm_source' => 'facebook'],
            ], 'klaviyo_backfill'),
        ]))->handle($service);

        $this->assertSame(
            1,
            AttributionRecord::where('user_id', $userId)->count()
        );

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
        ]);
    }

    // =========================================================================
    // Constructor — queue / connection from config
    // =========================================================================

    /**
     * @test
     */
    public function it_reads_queue_name_from_config(): void
    {
        $this->app['config']->set('attribution.queue.name', 'imports');

        $job = new ImportAttributionChunk([]);

        $this->assertSame('imports', $job->queue);
    }

    /**
     * @test
     */
    public function queue_name_defaults_to_default_when_config_is_missing(): void
    {
        $this->app['config']->set('attribution.queue.name', null);

        $job = new ImportAttributionChunk([]);

        $this->assertSame('default', $job->queue);
    }

    /**
     * @test
     */
    public function it_reads_connection_from_config_when_set(): void
    {
        $this->app['config']->set('attribution.queue.connection', 'redis');

        $job = new ImportAttributionChunk([]);

        $this->assertSame('redis', $job->connection);
    }

    /**
     * @test
     */
    public function it_leaves_connection_null_when_config_is_null(): void
    {
        $this->app['config']->set('attribution.queue.connection', null);

        $job = new ImportAttributionChunk([]);

        $this->assertNull($job->connection);
    }
}
