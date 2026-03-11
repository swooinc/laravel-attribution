<?php

namespace SwooInc\Attribution\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use SwooInc\Attribution\AttributionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [AttributionServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    private function setUpDatabase(): void
    {
        // Minimal users table — the package does not own this,
        // but the foreign key requires it to exist.
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email')->unique();
        });

        // Define the schema inline rather than loading the migration file.
        // This avoids class-name collisions when the migration has also been
        // published to the host app's database/migrations/ directory.
        $tableName = config('attribution.table', 'attribution_records');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();

            // Initial touch
            $table->string('initial_gclid')->nullable();
            $table->string('initial_fbclid')->nullable();
            $table->string('initial_utm_source')->nullable();
            $table->string('initial_utm_medium')->nullable();
            $table->string('initial_utm_campaign', 500)->nullable();
            $table->string('initial_utm_content', 500)->nullable();
            $table->string('initial_utm_term')->nullable();
            $table->text('initial_landing_page')->nullable();
            $table->text('initial_referrer')->nullable();
            $table->string('initial_referring_domain')->nullable();
            $table->string('initial_source', 100)->nullable();
            $table->string('initial_medium', 100)->nullable();
            $table->string('initial_device_type', 50)->nullable();
            $table->string('initial_promo_code', 100)->nullable();
            $table->timestamp('initial_captured_at')->nullable();

            // Last touch
            $table->string('last_gclid')->nullable();
            $table->string('last_fbclid')->nullable();
            $table->string('last_utm_source')->nullable();
            $table->string('last_utm_medium')->nullable();
            $table->string('last_utm_campaign', 500)->nullable();
            $table->string('last_utm_content', 500)->nullable();
            $table->string('last_utm_term')->nullable();
            $table->text('last_landing_page')->nullable();
            $table->text('last_referrer')->nullable();
            $table->string('last_referring_domain')->nullable();
            $table->string('last_source', 100)->nullable();
            $table->string('last_medium', 100)->nullable();
            $table->string('last_device_type', 50)->nullable();
            $table->string('last_promo_code', 100)->nullable();
            $table->timestamp('last_captured_at')->nullable();

            // Converting touch
            $table->string('converting_gclid')->nullable();
            $table->string('converting_fbclid')->nullable();
            $table->string('converting_source', 100)->nullable();
            $table->string('converting_medium', 100)->nullable();
            $table->string('converting_utm_campaign', 500)->nullable();
            $table->string('converting_device_type', 50)->nullable();

            // Aggregate
            $table->unsignedInteger('total_visits')->default(0);
            $table->unsignedInteger('distinct_sources')->default(0);
            $table->boolean('is_multi_touch')->default(false);

            // Metadata
            $table->string('source_type', 50)->default('website_capture');
            $table->timestamp('converted_at')->nullable();

            $table->index('initial_gclid');
            $table->index('initial_fbclid');
            $table->index('initial_utm_source');
            $table->index('source_type');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Insert a bare user row and return its auto-increment ID.
     *
     * @param  string  $email
     * @return int
     */
    protected function createUserId(string $email): int
    {
        return (int) DB::table('users')->insertGetId(['email' => $email]);
    }

    /**
     * Generate a unique email address for use in a single test.
     *
     * @param  string  $prefix
     * @return string
     */
    protected function uniqueEmail(string $prefix = 'user'): string
    {
        return $prefix.'-'.uniqid('', true).'@test.com';
    }
}
