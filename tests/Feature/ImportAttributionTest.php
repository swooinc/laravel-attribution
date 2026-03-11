<?php

namespace SwooInc\Attribution\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use SwooInc\Attribution\Jobs\ImportAttributionChunk;
use SwooInc\Attribution\Models\AttributionRecord;
use SwooInc\Attribution\Tests\TestCase;

class ImportAttributionTest extends TestCase
{
    /** @var string[] */
    private const HEADERS = [
        'External ID',
        'Initial Source',
        'Initial Source Medium',
        'Initial Source Campaign',
        'Initial Source Content',
        'Initial Source First Page',
        'Initial Source Referrer',
        'Initial Referring Domain',
        // Optional last-touch columns
        'Last Source',
        'Last Source Medium',
        'Last Source Campaign',
        'Last Source First Page',
        'Last Referring Domain',
        'UTM Source',
        'UTM Medium',
        'UTM Campaign',
    ];

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    // =========================================================================
    // handle() — validation and file resolution
    // =========================================================================

    /**
     * @test
     */
    public function it_exits_with_error_for_unsupported_format(): void
    {
        $path = $this->csv([]);

        $this->artisan('attribution:import', [
            'file'     => $path,
            '--format' => 'ga4',
        ])->assertExitCode(1);
    }

    /**
     * @test
     */
    public function it_exits_with_error_when_file_is_not_found(): void
    {
        $this->artisan('attribution:import', [
            'file' => '/tmp/attribution_does_not_exist.csv',
        ])->assertExitCode(1);
    }

    /**
     * @test
     */
    public function it_exits_with_error_when_csv_has_no_headers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attribution_');
        $this->tempFiles[] = $path;
        // Write an empty file — no headers
        file_put_contents($path, '');

        $this->artisan('attribution:import', [
            'file' => $path,
        ])->assertExitCode(1);
    }

    // =========================================================================
    // Basic synchronous import
    // =========================================================================

    /**
     * @test
     */
    public function it_imports_valid_rows_synchronously(): void
    {
        $userA = $this->createUserId($this->uniqueEmail('a'));
        $userB = $this->createUserId($this->uniqueEmail('b'));

        $path = $this->csv([
            $this->row(['External ID' => (string) $userA]),
            $this->row(['External ID' => (string) $userB]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertSame(2, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function it_sets_source_type_to_klaviyo_backfill(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'     => $userId,
            'source_type' => 'klaviyo_backfill',
        ]);
    }

    /**
     * @test
     */
    public function it_skips_rows_with_empty_external_id(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => '']),
            $this->row(['External ID' => '0']),
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertSame(1, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function it_exits_zero_when_all_rows_are_skipped(): void
    {
        $path = $this->csv([
            $this->row(['External ID' => '']),
            $this->row(['External ID' => '-1']),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertSame(0, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function it_does_not_overwrite_existing_records(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => '(organic)',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        // Second import for same user — silently skipped
        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertSame(1, AttributionRecord::where('user_id', $userId)->count());
    }

    // =========================================================================
    // Dry run
    // =========================================================================

    /**
     * @test
     */
    public function it_does_not_write_to_db_on_dry_run(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'      => $path,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, AttributionRecord::count());
    }

    // =========================================================================
    // Field extraction from landing page URL
    // =========================================================================

    /**
     * @test
     */
    public function it_extracts_gclid_from_landing_page_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source First Page' =>
                    'https://example.ca/?gclid=Cj0KCQ123',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'       => $userId,
            'initial_gclid' => 'Cj0KCQ123',
        ]);
    }

    /**
     * @test
     */
    public function it_extracts_fbclid_from_landing_page_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source First Page' =>
                    'https://example.ca/?fbclid=IwAR1abc',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'        => $userId,
            'initial_fbclid' => 'IwAR1abc',
        ]);
    }

    /**
     * @test
     */
    public function it_extracts_utm_source_from_landing_page_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source First Page' =>
                    'https://example.ca/?utm_source=google',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
        ]);
    }

    /**
     * @test
     */
    public function utm_source_from_url_takes_priority_over_klaviyo_field(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source'            => 'newsletter',
                'Initial Source First Page' =>
                    'https://example.ca/?utm_source=google',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
        ]);
    }

    /**
     * @test
     */
    public function it_extracts_utm_medium_from_landing_page_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source Medium'     => 'email',
                'Initial Source First Page' =>
                    'https://example.ca/?utm_medium=cpc',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_medium' => 'cpc',
        ]);
    }

    /**
     * @test
     */
    public function it_falls_back_to_klaviyo_medium_when_no_utm_in_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'           => (string) $userId,
                'Initial Source Medium' => 'email',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_medium' => 'email',
        ]);
    }

    /**
     * @test
     */
    public function it_extracts_utm_campaign_from_landing_page_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source Campaign'   => 'klaviyo-camp',
                'Initial Source First Page' =>
                    'https://example.ca/?utm_campaign=url-camp',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'              => $userId,
            'initial_utm_campaign' => 'url-camp',
        ]);
    }

    /**
     * @test
     */
    public function it_falls_back_to_klaviyo_campaign_when_no_utm_in_url(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'             => (string) $userId,
                'Initial Source Campaign' => 'summer-sale',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'              => $userId,
            'initial_utm_campaign' => 'summer-sale',
        ]);
    }

    /**
     * @test
     */
    public function it_truncates_utm_campaign_to_500_chars(): void
    {
        $userId   = $this->createUserId($this->uniqueEmail());
        $longSlug = str_repeat('a', 600);

        $path = $this->csv([
            $this->row([
                'External ID'             => (string) $userId,
                'Initial Source Campaign' => $longSlug,
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $record = AttributionRecord::where('user_id', $userId)->first();

        $this->assertSame(500, strlen($record->initial_utm_campaign));
    }

    /**
     * @test
     */
    public function it_stores_the_referrer_field(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'              => (string) $userId,
                'Initial Source Referrer'  => 'https://google.com',
                'Initial Referring Domain' => 'google.com',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'                  => $userId,
            'initial_referrer'         => 'https://google.com',
            'initial_referring_domain' => 'google.com',
        ]);
    }

    // =========================================================================
    // normaliseKlaviyoSource — all branches
    // =========================================================================

    /**
     * @test
     */
    public function it_normalises_organic_klaviyo_source_to_google(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => '(organic)',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
        ]);
    }

    /**
     * @test
     */
    public function it_normalises_referral_source_to_referring_domain(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'              => (string) $userId,
                'Initial Source'           => '(referral)',
                'Initial Referring Domain' => 'reddit.com',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'reddit.com',
        ]);
    }

    /**
     * @test
     */
    public function it_normalises_referral_source_to_referral_when_no_domain(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => '(referral)',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'referral',
        ]);
    }

    /**
     * @test
     */
    public function it_normalises_direct_klaviyo_source_to_direct(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => '(direct)',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'        => $userId,
            'initial_source' => '(direct)',
        ]);
    }

    /**
     * @test
     */
    public function it_normalises_none_klaviyo_source_to_direct(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => '(none)',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'        => $userId,
            'initial_source' => '(direct)',
        ]);
    }

    /**
     * @test
     */
    public function it_uses_plain_klaviyo_source_value_as_utm_source(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => 'klaviyo',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'klaviyo',
        ]);
    }

    /**
     * @test
     */
    public function it_lowercases_plain_klaviyo_source_values(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => 'KLAVIYO',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'klaviyo',
        ]);
    }

    // =========================================================================
    // Multi-touch backfill
    // =========================================================================

    /**
     * @test
     */
    public function it_detects_multi_touch_when_last_source_differs(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source'            => '(organic)',
                'Last Source First Page'    =>
                    'https://example.ca/?utm_source=facebook',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
            'last_utm_source'    => 'facebook',
            'is_multi_touch'     => true,
        ]);
    }

    /**
     * @test
     */
    public function last_defaults_to_initial_when_no_last_columns(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'               => (string) $userId,
                'Initial Source First Page' =>
                    'https://example.ca/?utm_source=google',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
            'last_utm_source'    => 'google',
            'is_multi_touch'     => false,
        ]);
    }

    // =========================================================================
    // Async dispatch
    // =========================================================================

    /**
     * @test
     */
    public function it_dispatches_jobs_when_async_flag_is_set(): void
    {
        Queue::fake();

        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'    => $path,
            '--async' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(ImportAttributionChunk::class);
    }

    /**
     * @test
     */
    public function it_does_not_write_to_db_when_async(): void
    {
        Queue::fake();

        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'    => $path,
            '--async' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function it_does_not_dispatch_jobs_on_dry_run_with_async(): void
    {
        Queue::fake();

        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'      => $path,
            '--async'   => true,
            '--dry-run' => true,
        ])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * @test
     */
    public function it_dispatches_to_queue_from_config(): void
    {
        Queue::fake();

        $this->app['config']->set('attribution.queue.name', 'imports');

        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'    => $path,
            '--async' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(
            ImportAttributionChunk::class,
            fn ($job) => $job->queue === 'imports'
        );
    }

    /**
     * @test
     */
    public function it_dispatches_to_queue_from_flag_override(): void
    {
        Queue::fake();

        $this->app['config']->set('attribution.queue.name', 'default');

        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'    => $path,
            '--async' => true,
            '--queue' => 'override-queue',
        ])->assertExitCode(0);

        Queue::assertPushed(
            ImportAttributionChunk::class,
            fn ($job) => $job->queue === 'override-queue'
        );
    }

    /**
     * @test
     */
    public function it_dispatches_multiple_jobs_when_chunk_size_is_exceeded(): void
    {
        Queue::fake();

        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[] = $this->createUserId($this->uniqueEmail());
        }

        $rows = array_map(
            fn ($id) => $this->row(['External ID' => (string) $id]),
            $users
        );

        $path = $this->csv($rows);

        $this->artisan('attribution:import', [
            'file'    => $path,
            '--async' => true,
            '--chunk' => '2',
        ])->assertExitCode(0);

        Queue::assertPushed(ImportAttributionChunk::class, 2);
    }

    // =========================================================================
    // Connection flag (flush — $connection !== null branch)
    // =========================================================================

    /**
     * @test
     */
    public function it_passes_connection_flag_to_dispatched_job(): void
    {
        Queue::fake();

        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row(['External ID' => (string) $userId]),
        ]);

        $this->artisan('attribution:import', [
            'file'         => $path,
            '--async'      => true,
            '--connection' => 'redis',
        ])->assertExitCode(0);

        Queue::assertPushed(
            ImportAttributionChunk::class,
            fn ($job) => $job->connection === 'redis'
        );
    }

    // =========================================================================
    // Storage path resolution (file_exists($storagePath) branch)
    // =========================================================================

    /**
     * @test
     */
    public function it_resolves_file_from_storage_imports_directory(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $dir = storage_path('app/imports');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'attribution_test_' . uniqid() . '.csv';
        $fullPath = $dir . '/' . $filename;

        $handle = fopen($fullPath, 'w');
        fputcsv($handle, self::HEADERS);
        fputcsv($handle, $this->row(['External ID' => (string) $userId]));
        fclose($handle);

        $this->artisan('attribution:import', ['file' => $filename])
            ->assertExitCode(0);

        unlink($fullPath);

        $this->assertSame(1, AttributionRecord::count());
    }

    // =========================================================================
    // Last Source field without Last Source First Page
    // ($lastSourceField !== null branch)
    // =========================================================================

    /**
     * @test
     */
    public function it_builds_last_touch_from_source_field_when_no_last_page(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $path = $this->csv([
            $this->row([
                'External ID'    => (string) $userId,
                'Initial Source' => '(organic)',
                'Last Source'    => 'facebook',
            ]),
        ]);

        $this->artisan('attribution:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => $userId,
            'initial_utm_source' => 'google',
            'last_utm_source'    => 'facebook',
            'is_multi_touch'     => true,
        ]);
    }

    // =========================================================================
    // Fixture file
    // =========================================================================

    /**
     * @test
     *
     * Imports the bundled sample CSV and verifies each row is parsed correctly.
     * The fixture uses fixed IDs (1001-1004); row with empty External ID is
     * skipped, leaving exactly 4 records.
     */
    public function it_imports_the_fixture_file(): void
    {
        // Insert users with the IDs used in the fixture
        foreach ([1001, 1002, 1003, 1004] as $id) {
            \Illuminate\Support\Facades\DB::table('users')->insert([
                'id'    => $id,
                'email' => "user{$id}@example.com",
            ]);
        }

        $fixture = __DIR__ . '/../Fixtures/klaviyo_sample.csv';

        $this->artisan('attribution:import', ['file' => $fixture])
            ->assertExitCode(0);

        $this->assertSame(4, AttributionRecord::count());

        // 1001: gclid in landing page URL
        $this->assertDatabaseHas('attribution_records', [
            'user_id'              => 1001,
            'initial_gclid'        => 'Cj0KCQabc',
            'initial_utm_medium'   => 'cpc',
            'initial_utm_campaign' => 'spring-sale',
            'initial_source'       => 'google',
            'initial_medium'       => 'cpc',
        ]);

        // 1002: organic search - no last columns, last = initial
        $this->assertDatabaseHas('attribution_records', [
            'user_id'            => 1002,
            'initial_utm_source' => 'google',
            'last_utm_source'    => 'google',
            'is_multi_touch'     => false,
        ]);

        // 1003: direct first touch, then Facebook retargeting (multi-touch)
        $this->assertDatabaseHas('attribution_records', [
            'user_id'              => 1003,
            'initial_source'       => '(direct)',
            'last_utm_source'      => 'facebook',
            'last_utm_campaign'    => 'retarget-2026',
            'converting_source'    => 'facebook',
            'is_multi_touch'       => true,
        ]);

        // 1004: referral from reddit
        $this->assertDatabaseHas('attribution_records', [
            'user_id'                  => 1004,
            'initial_utm_source'       => 'reddit.com',
            'initial_referring_domain' => 'reddit.com',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Write a Klaviyo-format CSV to a temp file and return its path.
     *
     * @param  array  $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $path   = tempnam(sys_get_temp_dir(), 'attribution_');
        $this->tempFiles[] = $path;
        $handle = fopen($path, 'w');

        fputcsv($handle, self::HEADERS);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    /**
     * Build a Klaviyo CSV data row, defaulting all columns to empty string.
     *
     * @param  array  $fields  Column name => value
     * @return array
     */
    private function row(array $fields = []): array
    {
        return array_map(
            fn ($header) => $fields[$header] ?? '',
            self::HEADERS
        );
    }
}
