<?php

namespace SwooInc\Attribution\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use SwooInc\Attribution\AttributionService;
use SwooInc\Attribution\Jobs\ImportAttributionChunk;

class ImportAttribution extends Command
{
    protected $signature = 'attribution:import
        {file : Filename in storage/app/imports/ or absolute path}
        {--format=klaviyo : CSV format to expect. Supported: klaviyo}
        {--async : Dispatch queue jobs instead of processing synchronously}
        {--chunk=500 : Rows per DB batch (sync) or per dispatched job (async)}
        {--queue= : Override the queue name from config/attribution.php}
        {--connection= : Override the queue connection from config/attribution.php}
        {--dry-run : Parse and report without writing to the database}';

    protected $description = 'Import attribution records from a CSV export (e.g. Klaviyo backfill)';

    private AttributionService $service;

    public function __construct(AttributionService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if ($format !== 'klaviyo') {
            $this->error("Unsupported format \"{$format}\". Supported: klaviyo");

            return 1;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $isAsync = (bool) $this->option('async');
        $chunkSize = max(1, (int) $this->option('chunk'));

        // Flags override config; config overrides framework default.
        $queue = $this->option('queue')
            ?: (string) config('attribution.queue.name', 'default');

        $connection = $this->option('connection')
            ?: config('attribution.queue.connection');

        if ($isAsync && $isDryRun) {
            $this->warn(
                '--dry-run with --async: jobs will not be dispatched ' .
                'and nothing will be written.'
            );
        }

        $path = $this->resolvePath((string) $this->argument('file'));

        if ($path === null) {
            return 1;
        }

        [$headers, $total] = $this->readHeadersAndCount($path);

        if ($headers === null) {
            $this->error('Could not read CSV headers — file may be empty.');

            return 1;
        }
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunk = [];
        $processed = 0;
        $skipped = 0;
        $jobs = 0;

        foreach ($this->rows($path) as $rawRow) {
            $bar->advance();

            $row = $this->parseKlaviyoRow($rawRow, $headers);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $chunk[] = $row;
            $processed++;

            if (count($chunk) >= $chunkSize) {
                $this->flush($chunk, $isDryRun, $isAsync, $queue, $connection);
                $jobs++;
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $this->flush($chunk, $isDryRun, $isAsync, $queue, $connection);
            $jobs++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->printSummary(
            $processed,
            $skipped,
            $jobs,
            $isDryRun,
            $isAsync,
            $queue,
            $connection
        );

        return 0;
    }

    /**
     * Either insert the chunk directly or dispatch it as a job.
     *
     * Queue and connection are already applied from config inside the
     * job constructor. The $queue/$connection values here are the
     * resolved final values (config defaults merged with CLI flags) so
     * we just pass them through unconditionally for async dispatch.
     *
     * @param  array  $chunk
     * @param  bool  $isDryRun
     * @param  bool  $isAsync
     * @param  string  $queue
     * @param  string|null  $connection
     * @return void
     */
    private function flush(
        array $chunk,
        bool $isDryRun,
        bool $isAsync,
        string $queue,
        ?string $connection
    ): void {
        if ($isDryRun) {
            return;
        }

        if (!$isAsync) {
            $this->service->importChunk($chunk);

            return;
        }

        $job = (new ImportAttributionChunk($chunk))->onQueue($queue);

        if ($connection !== null) {
            $job = $job->onConnection($connection);
        }

        dispatch($job);
    }

    /**
     * Parse a raw Klaviyo CSV row into a attribution_records-ready array.
     * Returns null if the row has no usable user ID.
     *
     * Supports both the minimal export (Initial Source columns only) and
     * the full export that also includes Last Source columns.
     *
     * @param  array  $raw
     * @param  array  $headers
     * @return array|null
     */
    private function parseKlaviyoRow(array $raw, array $headers): ?array
    {
        $get = function (string $col) use ($raw, $headers): ?string {
            $idx = array_search($col, $headers, true);

            if ($idx === false) {
                return null;
            }

            $val = trim($raw[$idx] ?? '');

            return $val === '' ? null : $val;
        };

        $userId = (int) $get('External ID');

        if ($userId <= 0) {
            return null;
        }

        // Initial touch
        $initialPage = $get('Initial Source First Page');
        $initial = [
            'gclid' => $this->extractParam($initialPage, 'gclid'),
            'fbclid' => $this->extractParam($initialPage, 'fbclid'),
            'utm_source' => $this->extractParam($initialPage, 'utm_source')
                ?? $this->normaliseKlaviyoSource(
                    $get('Initial Source'),
                    $get('Initial Referring Domain')
                ),
            'utm_medium' => $this->extractParam($initialPage, 'utm_medium')
                ?? $get('Initial Source Medium'),
            'utm_campaign' => $this->extractParam($initialPage, 'utm_campaign')
                ?? $get('Initial Source Campaign'),
            'utm_content' => $get('Initial Source Content'),
            'utm_term' => null,
            'landing_page' => $initialPage,
            'referrer' => $get('Initial Source Referrer'),
            'referring_domain' => $get('Initial Referring Domain'),
            'device_type' => null,
            'promo_code' => null,
            'captured_at' => null,
        ];

        // Last touch
        // If Last Source columns exist in this export, use them.
        // Otherwise fall back to initial (single-touch record).
        $lastPage = $get('Last Source First Page');
        $lastSourceField = $get('Last Source');

        if ($lastPage !== null || $lastSourceField !== null) {
            $lastReferringDomain = $get('Last Referring Domain')
                ?? $get('Initial Referring Domain');

            $last = [
                'gclid' => $this->extractParam($lastPage, 'gclid'),
                'fbclid' => $this->extractParam($lastPage, 'fbclid'),
                'utm_source' => $this->extractParam($lastPage, 'utm_source')
                    ?? $get('UTM Source')
                    ?? $this->normaliseKlaviyoSource(
                        $lastSourceField,
                        $lastReferringDomain
                    ),
                'utm_medium' => $this->extractParam($lastPage, 'utm_medium')
                    ?? $get('UTM Medium')
                    ?? $get('Last Source Medium'),
                'utm_campaign' => $this->extractParam($lastPage, 'utm_campaign')
                    ?? $get('UTM Campaign')
                    ?? $get('Last Source Campaign'),
                'utm_content' => null,
                'utm_term' => null,
                'landing_page' => $lastPage,
                'referrer' => null,
                'referring_domain' => $lastReferringDomain,
                'device_type' => null,
                'promo_code' => null,
                'captured_at' => null,
            ];
        } else {
            $last = $initial;
        }

        return $this->service->buildRow($userId, [
            'initial' => $initial,
            'last' => $last,
            'total_visits' => 0, // visit count unknown from backfill
        ], 'klaviyo_backfill');
    }

    /**
     * Translate Klaviyo's source format to a plain source string.
     *
     * Klaviyo uses values like "(organic)", "(referral)", "(direct)",
     * which are not the same as UTM source values.
     *
     * @param  string|null  $klaviyoSource
     * @param  string|null  $referringDomain
     * @return string|null
     */
    private function normaliseKlaviyoSource(
        ?string $klaviyoSource,
        ?string $referringDomain
    ): ?string {
        if ($klaviyoSource === null) {
            return null;
        }

        $source = strtolower(trim($klaviyoSource));

        if ($source === '' || $source === '(direct)' || $source === '(none)') {
            return null;
        }

        if ($source === '(organic)') {
            return 'google';
        }

        if ($source === '(referral)') {
            return $referringDomain ?? 'referral';
        }

        return $source;
    }

    /**
     * Extract a single query parameter from a URL string.
     *
     * @param  string|null  $url
     * @param  string  $param
     * @return string|null
     */
    private function extractParam(?string $url, string $param): ?string
    {
        if ($url === null) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if ($query === null || $query === false) {
            return null;
        }

        parse_str($query, $params);

        $value = $params[$param] ?? null;

        return ($value !== null && $value !== '') ? (string) $value : null;
    }

    /**
     * Read CSV headers and count data rows in a single pass.
     * Returns [headers, count] — headers is null if the file is unreadable.
     *
     * @param  string  $path
     * @return array{0: array|null, 1: int}
     */
    private function readHeadersAndCount(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [null, 0];
        }

        $headers = fgetcsv($handle) ?: null;
        $count = 0;

        while (fgetcsv($handle) !== false) {
            $count++;
        }

        fclose($handle);

        return [$headers, $count];
    }

    /**
     * Yield each data row as a raw array, skipping the header row.
     *
     * @param  string  $path
     * @return \Illuminate\Support\LazyCollection
     */
    private function rows(string $path): LazyCollection
    {
        return LazyCollection::make(static function () use ($path) {
            $handle = fopen($path, 'r');

            if ($handle === false) {
                return;
            }

            fgetcsv($handle); // skip header

            while (($row = fgetcsv($handle)) !== false) {
                yield $row;
            }

            fclose($handle);
        });
    }

    /**
     * Resolve the file path from storage/app/imports/ or as absolute.
     *
     * @param  string  $file
     * @return string|null
     */
    private function resolvePath(string $file): ?string
    {
        if (file_exists($file)) {
            return $file;
        }

        $storagePath = storage_path('app/imports/' . $file);

        if (file_exists($storagePath)) {
            return $storagePath;
        }

        $this->error(
            "File not found: \"{$file}\".\n" .
            'Place it in storage/app/imports/ or provide an absolute path.'
        );

        return null;
    }

    /**
     * Print a summary of the import run.
     *
     * @param  int  $processed
     * @param  int  $skipped
     * @param  int  $batches
     * @param  bool  $isDryRun
     * @param  bool  $isAsync
     * @param  string  $queue
     * @param  string|null  $connection
     * @return void
     */
    private function printSummary(
        int $processed,
        int $skipped,
        int $batches,
        bool $isDryRun,
        bool $isAsync,
        string $queue,
        ?string $connection
    ): void {
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        $this->info(
            "{$prefix}{$processed} row(s) processed, " .
            "{$skipped} skipped (no valid user ID)."
        );

        if ($isDryRun) {
            return;
        }

        if ($isAsync) {
            $connectionLabel = $connection ?? 'app default';

            $this->info(
                "{$batches} job(s) dispatched " .
                "→ queue: {$queue}, connection: {$connectionLabel}."
            );
        } else {
            $this->info("{$batches} batch(es) inserted synchronously.");
        }
    }
}
