<?php

namespace SwooInc\Attribution;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttributionService
{
    /**
     * Save attribution for a user.
     * Silently skips if a record already exists for this user.
     *
     * Expected $data structure (from JS payload):
     *   [
     *     'initial'      => [ touch fields ],
     *     'last'         => [ touch fields ],  // optional, defaults to initial
     *     'total_visits' => int,
     *   ]
     *
     * @param  int  $userId
     * @param  array  $data
     * @return void
     */
    public function saveForUser(int $userId, array $data): void
    {
        DB::table(config('attribution.table', 'attribution_records'))
            ->insertOrIgnore(
                $this->buildRow($userId, $data, 'website_capture')
            );
    }

    /**
     * Build a fully-prepared DB row array for a given user and data payload.
     * Used by saveForUser() and by the import command for bulk inserts.
     *
     * @param  int  $userId
     * @param  array  $data
     * @param  string  $sourceType  website_capture|klaviyo_backfill|ga4_backfill
     * @return array
     */
    public function buildRow(
        int $userId,
        array $data,
        string $sourceType
    ): array {
        $initial = $data['initial'] ?? [];
        // Last defaults to initial when not separately provided
        $last = $data['last'] ?? $initial;

        $totalVisits = (int) ($data['total_visits'] ?? 0);

        // Multi-touch is based on resolved sources, not raw utm_source values.
        // This correctly handles cases where initial touch is (direct) and
        // has no utm_source but last touch came from a paid channel.
        $resolvedInitialSource = $this->resolveSource($initial);
        $resolvedLastSource = $this->resolveSource($last);
        $distinctSources = count(array_unique([$resolvedInitialSource, $resolvedLastSource]));
        $isMultiTouch = $resolvedInitialSource !== $resolvedLastSource;

        return [
            'user_id' => $userId,

            // Initial touch
            'initial_gclid' => $initial['gclid'] ?? null,
            'initial_fbclid' => $initial['fbclid'] ?? null,
            'initial_utm_source' => $initial['utm_source'] ?? null,
            'initial_utm_medium' => $initial['utm_medium'] ?? null,
            'initial_utm_campaign' => $this->truncate(
                $initial['utm_campaign'] ?? null,
                500
            ),
            'initial_utm_content' => $initial['utm_content'] ?? null,
            'initial_utm_term' => $initial['utm_term'] ?? null,
            'initial_landing_page' => $initial['landing_page'] ?? null,
            'initial_referrer' => $initial['referrer'] ?? null,
            'initial_referring_domain' => $initial['referring_domain'] ?? null,
            'initial_source' => $resolvedInitialSource,
            'initial_medium' => $this->resolveMedium($initial),
            'initial_device_type' => $initial['device_type'] ?? null,
            'initial_promo_code' => $initial['promo_code'] ?? null,
            'initial_captured_at' => $this->parseTimestamp(
                $initial['captured_at'] ?? null
            ),

            // Last touch
            'last_gclid' => $last['gclid'] ?? null,
            'last_fbclid' => $last['fbclid'] ?? null,
            'last_utm_source' => $last['utm_source'] ?? null,
            'last_utm_medium' => $last['utm_medium'] ?? null,
            'last_utm_campaign' => $this->truncate(
                $last['utm_campaign'] ?? null,
                500
            ),
            'last_utm_content' => $last['utm_content'] ?? null,
            'last_utm_term' => $last['utm_term'] ?? null,
            'last_landing_page' => $last['landing_page'] ?? null,
            'last_referrer' => $last['referrer'] ?? null,
            'last_referring_domain' => $last['referring_domain'] ?? null,
            'last_source' => $resolvedLastSource,
            'last_medium' => $this->resolveMedium($last),
            'last_device_type' => $last['device_type'] ?? null,
            'last_promo_code' => $last['promo_code'] ?? null,
            'last_captured_at' => $this->parseTimestamp(
                $last['captured_at'] ?? null
            ),

            // Converting touch — subset of last, snapshot at signup
            'converting_gclid' => $last['gclid'] ?? null,
            'converting_fbclid' => $last['fbclid'] ?? null,
            'converting_source' => $resolvedLastSource,
            'converting_medium' => $this->resolveMedium($last),
            'converting_utm_campaign' => $this->truncate(
                $last['utm_campaign'] ?? null,
                500
            ),
            'converting_device_type' => $last['device_type'] ?? null,

            // Aggregate
            'total_visits' => $totalVisits,
            'distinct_sources' => $distinctSources,
            'is_multi_touch' => $isMultiTouch,

            // Metadata
            'source_type' => $sourceType,
        ];
    }

    /**
     * Update the converting touch for a user on their first purchase.
     * Silently skips if no provenance record exists for this user.
     *
     * Call this once when the user places their first order. The $data
     * payload has the same shape as saveForUser() — the converting touch
     * is derived from the last touch in that payload.
     *
     * @param  int  $userId
     * @param  array  $data
     * @return void
     */
    public function updateConvertingForUser(int $userId, array $data): void
    {
        $touch = $data['last'] ?? $data['initial'] ?? [];

        DB::table(config('attribution.table', 'attribution_records'))
            ->where('user_id', $userId)
            ->whereNull('converted_at')
            ->update([
                'converting_gclid' => $touch['gclid'] ?? null,
                'converting_fbclid' => $touch['fbclid'] ?? null,
                'converting_source' => $this->resolveSource($touch),
                'converting_medium' => $this->resolveMedium($touch),
                'converting_utm_campaign' => $this->truncate(
                    $touch['utm_campaign'] ?? null,
                    500
                ),
                'converting_device_type' => $touch['device_type'] ?? null,
                'converted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Copy last touch to converting touch and set converted_at.
     * Used when the user converts in the same session as signup so no new
     * attribution data is available in localStorage.
     *
     * @param  int  $userId
     * @return void
     */
    public function updateConvertingFromLastTouch(int $userId): void
    {
        $table = config('attribution.table', 'attribution_records');

        DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('converted_at')
            ->update([
                'converting_gclid' => DB::raw('last_gclid'),
                'converting_fbclid' => DB::raw('last_fbclid'),
                'converting_source' => DB::raw('last_source'),
                'converting_medium' => DB::raw('last_medium'),
                'converting_utm_campaign' => DB::raw('last_utm_campaign'),
                'converting_device_type' => DB::raw('last_device_type'),
                'converted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Bulk-insert pre-built rows, silently skipping any user_id that already
     * has a record (INSERT IGNORE via insertOrIgnore).
     *
     * @param  array  $rows  Array of buildRow() results
     * @return void
     */
    public function importChunk(array $rows): void
    {
        DB::table(config('attribution.table', 'attribution_records'))
            ->insertOrIgnore($rows);
    }

    /**
     * Resolve the marketing source from a touch's data.
     * Priority: utm_source > gclid > fbclid > referring_domain > (direct)
     *
     * @param  array  $touch
     * @return string
     */
    private function resolveSource(array $touch): string
    {
        if (filled($touch['utm_source'] ?? null)) {
            return (string) $touch['utm_source'];
        }

        if (filled($touch['gclid'] ?? null)) {
            return 'google';
        }

        if (filled($touch['fbclid'] ?? null)) {
            return 'facebook';
        }

        $domain = strtolower($touch['referring_domain'] ?? '');

        if (blank($domain)) {
            return '(direct)';
        }

        $domainMap = [
            'google' => 'google',
            'facebook' => 'facebook',
            'fb.com' => 'facebook',
            'bing' => 'bing',
            'tiktok' => 'tiktok',
        ];

        foreach ($domainMap as $needle => $source) {
            if (Str::contains($domain, $needle)) {
                return $source;
            }
        }

        return $domain;
    }

    /**
     * Resolve the marketing medium from a touch's data.
     * Priority: utm_medium > click ID > referring_domain > (none)
     *
     * @param  array  $touch
     * @return string
     */
    private function resolveMedium(array $touch): string
    {
        if (filled($touch['utm_medium'] ?? null)) {
            return (string) $touch['utm_medium'];
        }

        if (
            filled($touch['gclid'] ?? null)
            || filled($touch['fbclid'] ?? null)
        ) {
            return 'cpc';
        }

        if (filled($touch['referring_domain'] ?? null)) {
            return 'referral';
        }

        return '(none)';
    }

    /**
     * Parse a timestamp string from JS (ISO 8601) into MySQL format.
     * Returns null if the value is null or unparseable.
     *
     * @param  string|null  $value
     * @return string|null
     */
    private function parseTimestamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Truncate a nullable string to a maximum length.
     *
     * @param  string|null  $value
     * @param  int  $max
     * @return string|null
     */
    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr($value, 0, $max);
    }
}
