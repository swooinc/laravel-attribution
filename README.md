# laravel-attribution

Captures marketing attribution for every user: UTM parameters, click IDs
(gclid, fbclid), landing page, referrer, and device type.

Tracks **four attribution events** per user — two captured by the JavaScript
snippet, two triggered by your application:

| Event | What it captures | When |
|---|---|---|
| **Initial touch** | How they first discovered you | First visit ever. Never overwritten. |
| **Last touch** | What brought them back most recently | Updated on every return visit that carries a marketing signal (UTM / gclid / fbclid). Direct visits are ignored. |
| **Converting touch — signup snapshot** | The last touch at the moment the account was created | Set once at registration. `converted_at` is null. |
| **Converting touch — purchase update** | The touch at the time of the first order | Overwrites the signup snapshot. `converted_at` is set. |

The `converting_*` columns go through two phases: they start as a copy of
`last_*` at signup, then get overwritten when the user places their first
order. `converted_at` is null until purchase — that is the only way to tell
which phase you are looking at.

**Concrete example:**

```
Day 0  — User clicks a Google Ad → initial = google/cpc
Day 3  — User comes back via a Klaviyo email → last = klaviyo/email
           User creates an account → converting = klaviyo/email (signup snapshot)
           converted_at = null
Day 7  — User comes back via a promo email → last = klaviyo/email (same)
           User places first order → converting = klaviyo/email (purchase update)
           converted_at = <timestamp>
```

A lightweight JavaScript snippet captures initial and last touches in
`localStorage` and tracks visit counts. When the user signs up, the data is
sent with the registration payload and persisted to a dedicated
`attribution_records` table. **First record wins**: existing rows are never
overwritten.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^7.4 \| ^8.0 \| ^8.1 \| ^8.2` |
| Laravel | `^8.0 \| ^9.0 \| ^10.0 \| ^11.0 \| ^12.0` |

---

## Installation

```bash
composer require swooinc/laravel-attribution
```

Laravel's auto-discovery registers the service provider automatically.

Publish and run the migration:

```bash
php artisan vendor:publish --tag=attribution-migrations
php artisan migrate
```

Optionally publish the config (only needed if you want to change the table
name, storage key, or queue settings: most queue settings can be set via
`.env` without publishing):

```bash
php artisan vendor:publish --tag=attribution-config
```

Optionally publish the JavaScript snippet as a standalone asset:

```bash
php artisan vendor:publish --tag=attribution-assets
```

---

## Route registration

The package does **not** register routes automatically. Call
`Attribution::routes()` from inside your own authenticated route group so it
inherits whatever middleware (auth, throttle, etc.) you need:

```php
use SwooInc\Attribution\Attribution;

// routes/web.php
Route::middleware('auth')->group(function () {
    Route::prefix('me')->group(function () {
        Attribution::routes();
    });
});
```

This registers two endpoints under the given prefix:

| Method | URI | Name |
|---|---|---|
| `POST` | `{prefix}/{route_path}` | `attribution.save` |
| `POST` | `{prefix}/{route_path}/converting` | `attribution.converting` |

The `route_path` segment defaults to `touchpoint`. Change it in
`config/attribution.php` or via `.env` if it conflicts with an existing route:

```env
ATTRIBUTION_ROUTE_PATH=marketing
```

---

## Configuration

`config/attribution.php`

```php
return [
    // The database table where attribution records are stored.
    'table' => 'attribution_records',

    // The localStorage key used by the JavaScript snippet.
    // Must match the key read in your frontend signup flow.
    'storage_key' => 'wc_attribution',

    // The path segment used for the package's two endpoints:
    //   POST {prefix}/{route_path}             → save initial/last touch
    //   POST {prefix}/{route_path}/converting  → save converting touch
    // Override if the default conflicts with an existing route in your app.
    'route_path' => 'touchpoint',

    // Queue settings for async CSV imports.
    // Override via .env: no need to publish the config for this.
    'queue' => [
        'connection' => env('ATTRIBUTION_QUEUE_CONNECTION', null), // null = app default
        'name' => env('ATTRIBUTION_QUEUE', 'default'),
    ],
];
```

**`.env` variables**

| Variable | Default | Description |
|---|---|---|
| `ATTRIBUTION_ROUTE_PATH` | `touchpoint` | Path segment for the package's endpoints |
| `ATTRIBUTION_QUEUE_CONNECTION` | *(app default)* | Queue connection for import jobs |
| `ATTRIBUTION_QUEUE` | `default` | Queue name for import jobs |

Setting `ATTRIBUTION_QUEUE=imports` is enough to route jobs to a
Horizon-supervised queue: no flag required at runtime.

---

## How it works

```
Visitor lands on site
        │
        ▼
attribution.js captures initial touch (gclid, fbclid, UTMs, promo, device…)
Stores as { initial, last, total_visits, sources } in localStorage
        │
        ▼
Visitor returns with a new signal (e.g. retargeted ad)
attribution.js updates `last` touch only — initial is preserved
Increments total_visits, tracks distinct sources
Direct visits (no signal) are ignored: last touch is not overwritten
        │
        ▼
User creates an account
Frontend reads localStorage → attaches full payload to signup request
Your controller calls AttributionService::saveForUser()
Saves initial / last / converting (= copy of last at this moment)
converted_at = null   ← not yet a customer
        │
        ▼
User places their first order
Frontend calls POST /me/touchpoint/converting with current localStorage
Your controller calls AttributionService::updateConvertingForUser()  (or
updateConvertingFromLastTouch() if localStorage was cleared at signup)
converting_* fields are overwritten with purchase-session attribution
converted_at = now    ← now a customer
```

---

## Usage

### 1. Load the JavaScript snippet

Add the snippet to your main layout **before** your application JavaScript.

**Option A: published asset:**

```html
<script>window.__attributionKey = '{{ config('attribution.storage_key', 'wc_attribution') }}';</script>
<script src="{{ asset('vendor/attribution/attribution.js') }}"></script>
```

**Option B: inline via Blade (recommended, key injected automatically):**

```html
@include('attribution::attribution')
```

The snippet tracks:
- **First visit:** stores both `initial` and `last` in localStorage
- **Return visit with signal:** updates `last` only, increments `total_visits`,
  tracks distinct `sources`
- **Return visit without signal:** no-op: existing data preserved

### 2. Attach data to the signup request

Read the stored payload in your signup method and include it in the request:

```js
const key = window.__attributionKey || 'wc_attribution';
const raw = localStorage.getItem(key);

if (raw) {
    try {
        payload.attribution = JSON.parse(raw);
    } catch (e) {
        // malformed entry: omit it
    }
}

// Parsed payload shape:
// {
//   initial:      { gclid, fbclid, utm_source, utm_medium, utm_campaign,
//                   utm_content, utm_term, promo_code, landing_page,
//                   referrer, referring_domain, device_type, captured_at },
//   last:         { ...same fields... },
//   total_visits: 3,
//   sources:      ['google', 'facebook'],
// }
```

After a successful signup, clear the stored entry:

```js
localStorage.removeItem(window.__attributionKey || 'wc_attribution');
```

### 3. Validate the incoming payload

Merge `AttributionRules::rules()` into your registration `FormRequest`:

```php
use SwooInc\Attribution\AttributionRules;

class CreateAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge(
            $this->myExistingRules(),
            AttributionRules::rules()
        );
    }
}
```

### 4. Save on registration

Resolve `AttributionService` and call it after the user is created:

```php
use SwooInc\Attribution\AttributionService;

protected function registered(Request $request, $user): void
{
    $attribution = $request->input('attribution', []);

    if (is_array($attribution) && !empty($attribution)) {
        app(AttributionService::class)
            ->saveForUser($user->id, $attribution);
    }

    // ... rest of your registration logic
}
```

`saveForUser()` is idempotent: safe to call more than once for the same user.

### 5. Update converting touch on first purchase

Call this once from your frontend after the first subscription or order
succeeds. Send the current `localStorage` payload as `attribution`:

```js
const key = window.__attributionKey || 'wc_attribution';
const raw = localStorage.getItem(key);
const attribution = raw ? JSON.parse(raw) : null;

await axios.post('/me/touchpoint/converting', { attribution });
```

- If `attribution` is present: `converting_*` is updated to reflect the
  purchase-session touch and `converted_at` is set.
- If `attribution` is null (localStorage was cleared at signup, i.e. the user
  subscribed in the same session): the server copies `last_*` into
  `converting_*` automatically and sets `converted_at`.

The routes are provided by the package and require an authenticated user. The
path segment (`touchpoint` by default) is configurable — see
[Route registration](#route-registration) below.

### 6. Reading attribution data

```php
use SwooInc\Attribution\Models\AttributionRecord;

$record = AttributionRecord::where('user_id', $user->id)->first();

// Initial (first) touch
$record->initial_source;        // 'google', 'facebook', '(direct)', ...
$record->initial_medium;        // 'cpc', 'referral', '(none)', ...
$record->initial_utm_campaign;
$record->initial_gclid;
$record->initial_landing_page;
$record->initial_promo_code;

// Last touch (most recent before signup)
$record->last_source;
$record->last_utm_source;

// Converting touch
// — before first purchase: snapshot of last at signup, converted_at is null
// — after first purchase:  updated to purchase-session attribution, converted_at is set
$record->converting_source;
$record->converting_medium;
$record->converting_gclid;
$record->converted_at;      // null = signed up but never purchased

// Aggregate
$record->total_visits;      // visits tracked before signup
$record->distinct_sources;  // number of unique sources
$record->is_multi_touch;    // true if 2+ different sources

$record->source_type;       // 'website_capture' | 'klaviyo_backfill'
```

---

## Table schema

### Initial touch columns

| Column | Type | Notes |
|---|---|---|
| `initial_gclid` | `varchar(255)` | Google click ID |
| `initial_fbclid` | `varchar(255)` | Meta click ID |
| `initial_utm_source` | `varchar(255)` | |
| `initial_utm_medium` | `varchar(255)` | |
| `initial_utm_campaign` | `varchar(500)` | |
| `initial_utm_content` | `varchar(500)` | |
| `initial_utm_term` | `varchar(255)` | |
| `initial_landing_page` | `text` | Full URL of the first page visited |
| `initial_referrer` | `text` | Full referrer URL |
| `initial_referring_domain` | `varchar(255)` | Hostname extracted from referrer |
| `initial_source` | `varchar(100)` | Resolved source (see below) |
| `initial_medium` | `varchar(100)` | Resolved medium (see below) |
| `initial_device_type` | `varchar(50)` | `desktop`, `mobile`, or `tablet` |
| `initial_promo_code` | `varchar(100)` | Promo code from `?promo=` URL param |
| `initial_captured_at` | `timestamp` | When the first visit was recorded |

### Last touch columns

Same set of columns prefixed with `last_`:
`last_gclid`, `last_fbclid`, `last_utm_source`, `last_utm_medium`,
`last_utm_campaign`, `last_utm_content`, `last_utm_term`, `last_landing_page`,
`last_referrer`, `last_referring_domain`, `last_source`, `last_medium`,
`last_device_type`, `last_promo_code`, `last_captured_at`.

### Converting touch columns (subset of last)

| Column | Type |
|---|---|
| `converting_gclid` | `varchar(255)` | |
| `converting_fbclid` | `varchar(255)` | |
| `converting_source` | `varchar(100)` | |
| `converting_medium` | `varchar(100)` | |
| `converting_utm_campaign` | `varchar(500)` | |
| `converting_device_type` | `varchar(50)` | |
| `converted_at` | `timestamp` | null until first purchase |

### Aggregate + metadata columns

| Column | Type | Notes |
|---|---|---|
| `total_visits` | `int` | Visits tracked before signup |
| `distinct_sources` | `int` | Unique sources seen |
| `is_multi_touch` | `boolean` | `true` if 2+ different sources |
| `source_type` | `varchar(50)` | `website_capture` or `klaviyo_backfill` |

### Source resolution

`initial_source` (and `last_source`, `converting_source`) are resolved in
this priority order:

| Priority | Condition | Result |
|---|---|---|
| 1 | `utm_source` is present | value of `utm_source` |
| 2 | `gclid` is present | `google` |
| 3 | `fbclid` is present | `facebook` |
| 4 | `referring_domain` contains a known name | `google`, `facebook`, `bing`, `tiktok` |
| 5 | `referring_domain` is present but unknown | domain value as-is |
| 6 | No signal | `(direct)` |

`initial_medium` follows the same priority:

| Priority | Condition | Result |
|---|---|---|
| 1 | `utm_medium` is present | value of `utm_medium` |
| 2 | `gclid` or `fbclid` is present | `cpc` |
| 3 | `referring_domain` is present | `referral` |
| 4 | No signal | `(none)` |

---

## Backfill: Klaviyo CSV import

The `source_type` column supports non-website records. The package ships
a `attribution:import` Artisan command for backfilling historical data
from a Klaviyo profile export.

### Exporting from Klaviyo

Lists & Segments → All Profiles → Export with columns:

**Required:**
`External ID`, `Initial Source`, `Initial Source Medium`,
`Initial Source Campaign`, `Initial Source Content`,
`Initial Source First Page`, `Initial Source Referrer`,
`Initial Referring Domain`

**Optional (adds last-touch and multi-touch detection):**
`Last Source`, `Last Source Medium`, `Last Source Campaign`,
`Last Source First Page`, `Last Referring Domain`,
`UTM Source`, `UTM Medium`, `UTM Campaign`

Place the file in `storage/app/imports/`.

### Running the import

Dry-run first to check row counts:

```bash
php artisan attribution:import klaviyo_export.csv --dry-run
```

Synchronous (recommended for first run):

```bash
php artisan attribution:import klaviyo_export.csv
```

Async: jobs are dispatched to the queue configured in
`config/attribution.php` (or `.env`):

```bash
php artisan attribution:import klaviyo_export.csv --async
```

One-off queue override (bypasses config):

```bash
php artisan attribution:import klaviyo_export.csv --async --queue=imports
```

### Options

| Option | Default | Description |
|---|---|---|
| `--format` | `klaviyo` | CSV format. Only `klaviyo` is supported. |
| `--async` | off | Dispatch jobs instead of processing inline |
| `--chunk` | `500` | Rows per batch or per dispatched job |
| `--queue` | from config | Override queue name for this run |
| `--connection` | from config | Override queue connection for this run |
| `--dry-run` | off | Parse and count without writing |

The `UNIQUE` constraint on `user_id` means the import is safe to re-run.
Existing records are silently skipped.

A sample CSV with all supported columns is available at
`tests/Fixtures/klaviyo_sample.csv`.

---

## Running the tests

```bash
cd packages/laravel-attribution
composer install
composer test
```

With coverage report (requires Xdebug or PCOV):

```bash
composer test -- --coverage-text
```

---

## Changelog

### 0.0.1
- Initial release

---

## License

Proprietary: WeCook internal use.
