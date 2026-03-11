<?php

namespace SwooInc\Attribution\Tests\Unit;

use SwooInc\Attribution\AttributionRules;
use SwooInc\Attribution\Tests\TestCase;

class AttributionRulesTest extends TestCase
{
    /**
     * @test
     */
    public function it_returns_an_array(): void
    {
        $this->assertIsArray(AttributionRules::rules());
    }

    /**
     * @test
     */
    public function it_contains_root_and_touch_keys(): void
    {
        $keys = array_keys(AttributionRules::rules());

        $expected = [
            'attribution',
            'attribution.initial',
            'attribution.last',
            'attribution.total_visits',
            'attribution.sources',
            'attribution.sources.*',
            'attribution.initial.gclid',
            'attribution.initial.fbclid',
            'attribution.initial.utm_source',
            'attribution.initial.utm_medium',
            'attribution.initial.utm_campaign',
            'attribution.initial.utm_content',
            'attribution.initial.utm_term',
            'attribution.initial.landing_page',
            'attribution.initial.referrer',
            'attribution.initial.referring_domain',
            'attribution.initial.device_type',
            'attribution.initial.promo_code',
            'attribution.initial.captured_at',
            'attribution.last.gclid',
            'attribution.last.utm_source',
        ];

        foreach ($expected as $key) {
            $this->assertContains($key, $keys, "Missing rule key: {$key}");
        }
    }

    /**
     * @test
     */
    public function attribution_root_accepts_null(): void
    {
        $rules = AttributionRules::rules()['attribution'];

        $this->assertContains('nullable', $rules);
    }

    /**
     * @test
     */
    public function attribution_root_must_be_an_array(): void
    {
        $rules = AttributionRules::rules()['attribution'];

        $this->assertContains('array', $rules);
    }

    /**
     * @test
     * @dataProvider touchFieldProvider
     */
    public function touch_fields_are_nullable_strings(string $field): void
    {
        $rules = AttributionRules::rules()[$field];

        $this->assertContains('nullable', $rules, "{$field} should be nullable");
        $this->assertContains('string', $rules, "{$field} should be a string");
    }

    public static function touchFieldProvider(): array
    {
        $fields = [];
        foreach (['initial', 'last'] as $touch) {
            foreach ([
                'gclid', 'fbclid', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_content', 'utm_term',
                'landing_page', 'referrer', 'referring_domain',
                'device_type', 'promo_code', 'captured_at',
            ] as $field) {
                $key = "attribution.{$touch}.{$field}";
                $fields[$key] = [$key];
            }
        }

        return $fields;
    }

    /**
     * @test
     * @dataProvider maxLengthProvider
     */
    public function fields_enforce_correct_max_length(
        string $field,
        string $expectedMax
    ): void {
        $rules = AttributionRules::rules()[$field];

        $this->assertContains(
            $expectedMax,
            $rules,
            "{$field} should have rule {$expectedMax}"
        );
    }

    public static function maxLengthProvider(): array
    {
        return [
            'initial gclid max 255' => ['attribution.initial.gclid', 'max:255'],
            'initial fbclid max 255' => ['attribution.initial.fbclid', 'max:255'],
            'initial utm_source max 255' => ['attribution.initial.utm_source', 'max:255'],
            'initial utm_medium max 255' => ['attribution.initial.utm_medium', 'max:255'],
            'initial utm_campaign max 500' => ['attribution.initial.utm_campaign', 'max:500'],
            'initial utm_content max 500' => ['attribution.initial.utm_content', 'max:500'],
            'initial utm_term max 255' => ['attribution.initial.utm_term', 'max:255'],
            'initial landing_page max 2048' => ['attribution.initial.landing_page', 'max:2048'],
            'initial referrer max 2048' => ['attribution.initial.referrer', 'max:2048'],
            'initial domain max 255' => ['attribution.initial.referring_domain', 'max:255'],
            'initial promo_code max 100' => ['attribution.initial.promo_code', 'max:100'],
            'last gclid max 255' => ['attribution.last.gclid', 'max:255'],
            'last utm_campaign max 500' => ['attribution.last.utm_campaign', 'max:500'],
        ];
    }

    /**
     * @test
     */
    public function device_type_is_restricted_to_known_values(): void
    {
        $rules = AttributionRules::rules()['attribution.initial.device_type'];

        $this->assertContains('in:desktop,mobile,tablet', $rules);
    }

    /**
     * @test
     */
    public function last_device_type_is_restricted_to_known_values(): void
    {
        $rules = AttributionRules::rules()['attribution.last.device_type'];

        $this->assertContains('in:desktop,mobile,tablet', $rules);
    }

    /**
     * @test
     */
    public function rules_can_be_merged_into_an_array(): void
    {
        $base   = ['email' => ['required', 'email']];
        $merged = array_merge($base, AttributionRules::rules());

        $this->assertArrayHasKey('email', $merged);
        $this->assertArrayHasKey('attribution', $merged);
        $this->assertArrayHasKey('attribution.initial.gclid', $merged);
        $this->assertArrayHasKey('attribution.last.gclid', $merged);
    }
}
