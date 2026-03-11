<?php

namespace SwooInc\Attribution\Models;

use Illuminate\Database\Eloquent\Model;

class AttributionRecord extends Model
{
    public $timestamps = false;

    protected $casts = [
        'is_multi_touch' => 'boolean',
        'converted_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',

        // Initial touch
        'initial_gclid',
        'initial_fbclid',
        'initial_utm_source',
        'initial_utm_medium',
        'initial_utm_campaign',
        'initial_utm_content',
        'initial_utm_term',
        'initial_landing_page',
        'initial_referrer',
        'initial_referring_domain',
        'initial_source',
        'initial_medium',
        'initial_device_type',
        'initial_promo_code',
        'initial_captured_at',

        // Last touch
        'last_gclid',
        'last_fbclid',
        'last_utm_source',
        'last_utm_medium',
        'last_utm_campaign',
        'last_utm_content',
        'last_utm_term',
        'last_landing_page',
        'last_referrer',
        'last_referring_domain',
        'last_source',
        'last_medium',
        'last_device_type',
        'last_promo_code',
        'last_captured_at',

        // Converting touch
        'converting_gclid',
        'converting_fbclid',
        'converting_source',
        'converting_medium',
        'converting_utm_campaign',
        'converting_device_type',

        // Aggregate
        'total_visits',
        'distinct_sources',
        'is_multi_touch',

        // Metadata
        'source_type',
        'converted_at',
    ];

    public function getTable(): string
    {
        return config('attribution.table', 'attribution_records');
    }
}
