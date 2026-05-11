<?php

namespace SwooInc\Attribution;

class AttributionRules
{
    /**
     * Validation rules for the nested attribution payload.
     * Merge into any FormRequest's rules() array.
     *
     * Expected payload shape:
     *   attribution.initial.*  — first-touch fields
     *   attribution.last.*     — last-touch fields (optional, defaults to initial)
     *   attribution.total_visits
     *   attribution.sources[]
     *
     * @return array
     */
    public static function rules(): array
    {
        return array_merge(
            [
                'attribution' => ['nullable', 'array'],
                'attribution.initial' => ['nullable', 'array'],
                'attribution.last' => ['nullable', 'array'],
                'attribution.total_visits' => ['nullable', 'integer', 'min:0'],
                'attribution.sources' => ['nullable', 'array'],
                'attribution.sources.*' => ['nullable', 'string', 'max:255'],
            ],
            self::touchRules('initial'),
            self::touchRules('last')
        );
    }

    /**
     * Build the validation rules for one touch (initial or last).
     *
     * @param  string  $prefix  'initial' or 'last'
     * @return array
     */
    private static function touchRules(string $prefix): array
    {
        $p = "attribution.{$prefix}";

        return [
            "{$p}.gclid" => ['nullable', 'string', 'max:255'],
            "{$p}.fbclid" => ['nullable', 'string', 'max:255'],
            "{$p}.ttclid" => ['nullable', 'string', 'max:255'],
            "{$p}.utm_source" => ['nullable', 'string', 'max:255'],
            "{$p}.utm_medium" => ['nullable', 'string', 'max:255'],
            "{$p}.utm_campaign" => ['nullable', 'string', 'max:500'],
            "{$p}.utm_content" => ['nullable', 'string', 'max:500'],
            "{$p}.utm_term" => ['nullable', 'string', 'max:255'],
            "{$p}.landing_page" => ['nullable', 'string', 'max:2048'],
            "{$p}.referrer" => ['nullable', 'string', 'max:2048'],
            "{$p}.referring_domain" => ['nullable', 'string', 'max:255'],
            "{$p}.device_type" => [
                'nullable', 'string', 'in:desktop,mobile,tablet',
            ],
            "{$p}.promo_code" => ['nullable', 'string', 'max:100'],
            "{$p}.captured_at" => ['nullable', 'string', 'max:64'],
        ];
    }
}
