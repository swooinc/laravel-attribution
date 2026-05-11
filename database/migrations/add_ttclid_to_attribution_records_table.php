<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTtclidToAttributionRecordsTable extends Migration
{
    public function up(): void
    {
        $table = config('attribution.table', 'attribution_records');

        Schema::table($table, function (Blueprint $table) {
            $table->string('initial_ttclid')->nullable()->after('initial_fbclid');
            $table->string('last_ttclid')->nullable()->after('last_fbclid');
            $table->string('converting_ttclid')->nullable()->after('converting_fbclid');

            $table->index('initial_ttclid');
        });
    }

    public function down(): void
    {
        $table = config('attribution.table', 'attribution_records');

        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex(['initial_ttclid']);
            $table->dropColumn([
                'initial_ttclid',
                'last_ttclid',
                'converting_ttclid',
            ]);
        });
    }
}
