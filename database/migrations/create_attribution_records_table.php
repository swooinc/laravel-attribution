<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttributionRecordsTable extends Migration
{
    public function up(): void
    {
        $table = config('attribution.table', 'attribution_records');

        Schema::create($table, function (Blueprint $table) {
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
            $table->timestamp('converted_at')->nullable();

            // Aggregate
            $table->unsignedInteger('total_visits')->default(0);
            $table->unsignedInteger('distinct_sources')->default(0);
            $table->boolean('is_multi_touch')->default(false);

            // Metadata
            $table->string('source_type', 50)->default('website_capture');

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

    public function down(): void
    {
        Schema::dropIfExists(
            config('attribution.table', 'attribution_records')
        );
    }
}
