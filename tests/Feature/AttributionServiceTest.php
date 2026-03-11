<?php

namespace SwooInc\Attribution\Tests\Feature;

use SwooInc\Attribution\AttributionService;
use SwooInc\Attribution\Models\AttributionRecord;
use SwooInc\Attribution\Tests\TestCase;

class AttributionServiceTest extends TestCase
{
    private AttributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttributionService();
    }

    // =========================================================================
    // Save mechanics
    // =========================================================================

    /**
     * @test
     */
    public function it_saves_an_attribution_record_for_a_new_user(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, $this->baseData());

        $this->assertDatabaseHas('attribution_records', ['user_id' => $userId]);
    }

    /**
     * @test
     */
    public function it_saves_all_fields_correctly(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => [
                'gclid' => 'Cj0KCQ123',
                'fbclid' => 'IwAR1abc',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer_sale',
                'utm_content' => 'banner_a',
                'utm_term' => 'meal+kit',
                'landing_page' => 'https://example.ca/?gclid=Cj0KCQ123',
                'referrer' => 'https://google.com',
                'referring_domain' => 'google.com',
                'device_type' => 'mobile',
                'promo_code' => 'SAVE20',
                'captured_at' => '2026-03-01T10:00:00.000Z',
            ],
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_gclid' => 'Cj0KCQ123',
            'initial_fbclid' => 'IwAR1abc',
            'initial_utm_source' => 'google',
            'initial_utm_medium' => 'cpc',
            'initial_utm_campaign' => 'summer_sale',
            'initial_utm_content' => 'banner_a',
            'initial_utm_term' => 'meal+kit',
            'initial_referring_domain' => 'google.com',
            'initial_device_type' => 'mobile',
            'initial_promo_code' => 'SAVE20',
        ]);
    }

    /**
     * @test
     */
    public function it_stores_initial_captured_at_from_input(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());
        $timestamp = '2026-03-01T10:00:00.000Z';

        $this->service->saveForUser($userId, [
            'initial' => ['captured_at' => $timestamp],
        ]);

        $record = AttributionRecord::where('user_id', $userId)->first();

        $this->assertNotNull($record->initial_captured_at);
    }

    /**
     * @test
     */
    public function it_sets_source_type_to_website_capture(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, $this->baseData());

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'source_type' => 'website_capture',
        ]);
    }

    /**
     * @test
     */
    public function it_does_not_overwrite_an_existing_record(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => ['utm_source' => 'google'],
        ]);

        $this->service->saveForUser($userId, [
            'initial' => ['utm_source' => 'facebook'],
        ]);

        $this->assertSame(
            1,
            AttributionRecord::where('user_id', $userId)->count()
        );

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_utm_source' => 'google',
        ]);
    }

    /**
     * @test
     */
    public function it_handles_completely_empty_data(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, []);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_source' => '(direct)',
            'initial_medium' => '(none)',
        ]);
    }

    /**
     * @test
     */
    public function null_fields_are_stored_as_null(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => [
                'gclid' => null,
                'fbclid' => null,
                'utm_term' => null,
            ],
        ]);

        $record = AttributionRecord::where('user_id', $userId)->first();

        $this->assertNull($record->initial_gclid);
        $this->assertNull($record->initial_fbclid);
        $this->assertNull($record->initial_utm_term);
    }

    /**
     * @test
     */
    public function each_user_gets_their_own_independent_record(): void
    {
        $userA = $this->createUserId($this->uniqueEmail('a'));
        $userB = $this->createUserId($this->uniqueEmail('b'));

        $this->service->saveForUser($userA, [
            'initial' => ['utm_source' => 'google'],
        ]);
        $this->service->saveForUser($userB, [
            'initial' => ['utm_source' => 'facebook'],
        ]);

        $this->assertSame(2, AttributionRecord::count());
        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userA,
            'initial_utm_source' => 'google',
        ]);
        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userB,
            'initial_utm_source' => 'facebook',
        ]);
    }

    // =========================================================================
    // Multi-touch
    // =========================================================================

    /**
     * @test
     */
    public function it_detects_multi_touch_when_sources_differ(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => ['utm_source' => 'facebook'],
            'last' => ['utm_source' => 'google'],
            'total_visits' => 3,
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'is_multi_touch' => true,
            'distinct_sources' => 2,
        ]);
    }

    /**
     * @test
     */
    public function it_stores_total_visits_from_input(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'total_visits' => 5,
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'total_visits' => 5,
        ]);
    }

    /**
     * @test
     */
    public function converting_touch_mirrors_last_touch(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => [
                'utm_source' => 'facebook',
                'gclid' => null,
            ],
            'last' => [
                'gclid' => 'Cj0KCQ123',
                'utm_campaign' => 'retarget',
            ],
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'converting_gclid' => 'Cj0KCQ123',
            'converting_utm_campaign' => 'retarget',
            'converting_source' => 'google',
        ]);
    }

    // =========================================================================
    // buildRow
    // =========================================================================

    /**
     * @test
     */
    public function build_row_returns_a_complete_db_row(): void
    {
        $row = $this->service->buildRow(42, $this->baseData(), 'website_capture');

        $this->assertSame(42, $row['user_id']);
        $this->assertSame('website_capture', $row['source_type']);
        $this->assertArrayHasKey('initial_source', $row);
        $this->assertArrayHasKey('initial_medium', $row);
        $this->assertArrayHasKey('last_source', $row);
        $this->assertArrayHasKey('converting_source', $row);
        $this->assertArrayHasKey('is_multi_touch', $row);
    }

    /**
     * @test
     */
    public function build_row_respects_the_given_source_type(): void
    {
        $row = $this->service->buildRow(1, [], 'klaviyo_backfill');

        $this->assertSame('klaviyo_backfill', $row['source_type']);
    }

    /**
     * @test
     */
    public function build_row_last_defaults_to_initial_when_absent(): void
    {
        $row = $this->service->buildRow(1, [
            'initial' => ['utm_source' => 'newsletter'],
        ], 'website_capture');

        $this->assertSame('newsletter', $row['initial_utm_source']);
        $this->assertSame('newsletter', $row['last_utm_source']);
        $this->assertSame('newsletter', $row['converting_source']);
    }

    // =========================================================================
    // importChunk
    // =========================================================================

    /**
     * @test
     */
    public function import_chunk_inserts_all_rows(): void
    {
        $userA = $this->createUserId($this->uniqueEmail('a'));
        $userB = $this->createUserId($this->uniqueEmail('b'));

        $this->service->importChunk([
            $this->service->buildRow($userA, [], 'klaviyo_backfill'),
            $this->service->buildRow($userB, [], 'klaviyo_backfill'),
        ]);

        $this->assertSame(2, AttributionRecord::count());
    }

    /**
     * @test
     */
    public function import_chunk_silently_skips_duplicate_user_ids(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->importChunk([
            $this->service->buildRow($userId, [
                'initial' => ['utm_source' => 'google'],
            ], 'klaviyo_backfill'),
        ]);

        // Second import for the same user — should be ignored
        $this->service->importChunk([
            $this->service->buildRow($userId, [
                'initial' => ['utm_source' => 'facebook'],
            ], 'klaviyo_backfill'),
        ]);

        $this->assertSame(
            1,
            AttributionRecord::where('user_id', $userId)->count()
        );

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_utm_source' => 'google',
        ]);
    }

    /**
     * @test
     */
    public function import_chunk_and_save_for_user_both_respect_first_touch(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        // First touch via website capture
        $this->service->saveForUser($userId, [
            'initial' => ['utm_source' => 'google'],
        ]);

        // Later backfill attempt for same user — must be ignored
        $this->service->importChunk([
            $this->service->buildRow($userId, [
                'initial' => ['utm_source' => 'klaviyo'],
            ], 'klaviyo_backfill'),
        ]);

        $this->assertSame(
            1,
            AttributionRecord::where('user_id', $userId)->count()
        );

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_utm_source' => 'google',
            'source_type' => 'website_capture',
        ]);
    }

    // =========================================================================
    // updateConvertingForUser
    // =========================================================================

    /**
     * @test
     */
    public function update_converting_skips_silently_when_no_record_exists(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        // No saveForUser call — record does not exist
        $this->service->updateConvertingForUser($userId, [
            'last' => ['utm_source' => 'klaviyo'],
        ]);

        $this->assertDatabaseMissing(
            'attribution_records',
            ['user_id' => $userId]
        );
    }

    /**
     * @test
     */
    public function update_converting_updates_only_converting_fields(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
            ],
            'last' => [
                'utm_source' => 'klaviyo',
                'utm_medium' => 'email',
            ],
        ]);

        $this->service->updateConvertingForUser($userId, [
            'last' => [
                'utm_source' => 'promo_email',
                'utm_medium' => 'email',
                'utm_campaign' => 'first_order',
                'gclid' => null,
                'fbclid' => null,
                'device_type' => 'mobile',
            ],
        ]);

        $record = AttributionRecord::where('user_id', $userId)->first();

        // Converting fields updated
        $this->assertSame('promo_email', $record->converting_source);
        $this->assertSame('email', $record->converting_medium);
        $this->assertSame('first_order', $record->converting_utm_campaign);
        $this->assertSame('mobile', $record->converting_device_type);

        // Initial and last fields untouched
        $this->assertSame('google', $record->initial_source);
        $this->assertSame('klaviyo', $record->last_source);
    }

    /**
     * @test
     */
    public function update_converting_uses_last_touch_preferentially(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());
        $this->service->saveForUser($userId, $this->baseData());

        $this->service->updateConvertingForUser($userId, [
            'initial' => ['utm_source' => 'initial_src'],
            'last' => ['utm_source' => 'last_src'],
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'converting_source' => 'last_src',
        ]);
    }

    /**
     * @test
     */
    public function update_converting_falls_back_to_initial_when_last_absent(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());
        $this->service->saveForUser($userId, $this->baseData());

        $this->service->updateConvertingForUser($userId, [
            'initial' => ['utm_source' => 'fallback_src'],
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'converting_source' => 'fallback_src',
        ]);
    }

    /**
     * @test
     */
    public function update_converting_resolves_gclid_to_google(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());
        $this->service->saveForUser($userId, $this->baseData());

        $this->service->updateConvertingForUser($userId, [
            'last' => ['gclid' => 'Cj0KCQ123'],
        ]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'converting_gclid' => 'Cj0KCQ123',
            'converting_source' => 'google',
            'converting_medium' => 'cpc',
        ]);
    }

    // =========================================================================
    // updateConvertingFromLastTouch
    // =========================================================================

    /**
     * @test
     */
    public function update_converting_from_last_touch_copies_last_to_converting(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'initial' => ['utm_source' => 'facebook', 'utm_medium' => 'cpc'],
            'last' => [
                'gclid' => 'Cj0KCQ123',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'retarget',
                'device_type' => 'mobile',
            ],
        ]);

        $this->service->updateConvertingFromLastTouch($userId);

        $record = AttributionRecord::where('user_id', $userId)->first();

        $this->assertSame('Cj0KCQ123', $record->converting_gclid);
        $this->assertSame('google', $record->converting_source);
        $this->assertSame('cpc', $record->converting_medium);
        $this->assertSame('retarget', $record->converting_utm_campaign);
        $this->assertSame('mobile', $record->converting_device_type);
        $this->assertNotNull($record->converted_at);

        // Initial and last must be untouched
        $this->assertSame('facebook', $record->initial_source);
        $this->assertSame('google', $record->last_source);
    }

    /**
     * @test
     */
    public function update_converting_from_last_touch_skips_when_no_record(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->updateConvertingFromLastTouch($userId);

        $this->assertDatabaseMissing(
            'attribution_records',
            ['user_id' => $userId]
        );
    }

    /**
     * @test
     */
    public function update_converting_from_last_touch_does_not_reconvert(): void
    {
        $userId = $this->createUserId($this->uniqueEmail());

        $this->service->saveForUser($userId, [
            'last' => ['utm_source' => 'google'],
        ]);

        $this->service->updateConvertingFromLastTouch($userId);

        $firstConvertedAt = AttributionRecord::where('user_id', $userId)
            ->value('converted_at');

        // Second call — WHERE converted_at IS NULL guard should skip it
        $this->service->updateConvertingFromLastTouch($userId);

        $secondConvertedAt = AttributionRecord::where('user_id', $userId)
            ->value('converted_at');

        $this->assertSame((string) $firstConvertedAt, (string) $secondConvertedAt);
    }

    // =========================================================================
    // Source resolution
    // =========================================================================

    /**
     * @test
     */
    public function direct_visit_resolves_to_direct_source(): void
    {
        $this->assertSavedSource('(direct)', []);
    }

    /**
     * @test
     */
    public function utm_source_takes_priority_over_everything_else(): void
    {
        $this->assertSavedSource('newsletter', [
            'utm_source' => 'newsletter',
            'gclid' => 'some-gclid',
            'fbclid' => 'some-fbclid',
            'referring_domain' => 'google.com',
        ]);
    }

    /**
     * @test
     */
    public function gclid_resolves_to_google_source(): void
    {
        $this->assertSavedSource('google', ['gclid' => 'Cj0KCQ123']);
    }

    /**
     * @test
     */
    public function fbclid_resolves_to_facebook_source(): void
    {
        $this->assertSavedSource('facebook', ['fbclid' => 'IwAR1abc']);
    }

    /**
     * @test
     */
    public function utm_source_wins_over_gclid(): void
    {
        $this->assertSavedSource('bing', [
            'utm_source' => 'bing',
            'gclid' => 'some-gclid',
        ]);
    }

    /**
     * @test
     */
    public function gclid_wins_over_referring_domain(): void
    {
        $this->assertSavedSource('google', [
            'gclid' => 'Cj0KCQ123',
            'referring_domain' => 'facebook.com',
        ]);
    }

    /**
     * @test
     */
    public function google_referring_domain_resolves_to_google(): void
    {
        $this->assertSavedSource('google', [
            'referring_domain' => 'www.google.com',
        ]);
    }

    /**
     * @test
     */
    public function google_subdomain_referring_domain_resolves_to_google(): void
    {
        $this->assertSavedSource('google', [
            'referring_domain' => 'news.google.ca',
        ]);
    }

    /**
     * @test
     */
    public function facebook_referring_domain_resolves_to_facebook(): void
    {
        $this->assertSavedSource('facebook', [
            'referring_domain' => 'www.facebook.com',
        ]);
    }

    /**
     * @test
     */
    public function fb_com_referring_domain_resolves_to_facebook(): void
    {
        $this->assertSavedSource('facebook', [
            'referring_domain' => 'l.fb.com',
        ]);
    }

    /**
     * @test
     */
    public function bing_referring_domain_resolves_to_bing(): void
    {
        $this->assertSavedSource('bing', [
            'referring_domain' => 'www.bing.com',
        ]);
    }

    /**
     * @test
     */
    public function tiktok_referring_domain_resolves_to_tiktok(): void
    {
        $this->assertSavedSource('tiktok', [
            'referring_domain' => 'www.tiktok.com',
        ]);
    }

    /**
     * @test
     */
    public function unknown_referring_domain_returns_the_domain_itself(): void
    {
        $this->assertSavedSource('reddit.com', [
            'referring_domain' => 'reddit.com',
        ]);
    }

    // =========================================================================
    // Medium resolution
    // =========================================================================

    /**
     * @test
     */
    public function direct_visit_resolves_to_none_medium(): void
    {
        $this->assertSavedMedium('(none)', []);
    }

    /**
     * @test
     */
    public function utm_medium_takes_priority_over_everything_else(): void
    {
        $this->assertSavedMedium('email', [
            'utm_medium' => 'email',
            'gclid' => 'some-gclid',
            'referring_domain' => 'google.com',
        ]);
    }

    /**
     * @test
     */
    public function gclid_resolves_to_cpc_medium(): void
    {
        $this->assertSavedMedium('cpc', ['gclid' => 'Cj0KCQ123']);
    }

    /**
     * @test
     */
    public function fbclid_resolves_to_cpc_medium(): void
    {
        $this->assertSavedMedium('cpc', ['fbclid' => 'IwAR1abc']);
    }

    /**
     * @test
     */
    public function both_click_ids_present_resolves_to_cpc_medium(): void
    {
        $this->assertSavedMedium('cpc', [
            'gclid' => 'Cj0KCQ123',
            'fbclid' => 'IwAR1abc',
        ]);
    }

    /**
     * @test
     */
    public function referring_domain_resolves_to_referral_medium(): void
    {
        $this->assertSavedMedium('referral', [
            'referring_domain' => 'reddit.com',
        ]);
    }

    /**
     * @test
     */
    public function utm_medium_wins_over_click_id(): void
    {
        $this->assertSavedMedium('organic', [
            'utm_medium' => 'organic',
            'gclid' => 'some-gclid',
        ]);
    }

    /**
     * @test
     */
    public function click_id_wins_over_referring_domain_for_medium(): void
    {
        $this->assertSavedMedium('cpc', [
            'gclid' => 'some-gclid',
            'referring_domain' => 'reddit.com',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Assert that saving a touch with the given flat signal fields
     * resolves initial_source to the expected value.
     */
    private function assertSavedSource(string $expected, array $touch): void
    {
        $userId = $this->createUserId($this->uniqueEmail('src'));

        $this->service->saveForUser($userId, ['initial' => $touch]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_source' => $expected,
        ]);
    }

    /**
     * Assert that saving a touch with the given flat signal fields
     * resolves initial_medium to the expected value.
     */
    private function assertSavedMedium(string $expected, array $touch): void
    {
        $userId = $this->createUserId($this->uniqueEmail('med'));

        $this->service->saveForUser($userId, ['initial' => $touch]);

        $this->assertDatabaseHas('attribution_records', [
            'user_id' => $userId,
            'initial_medium' => $expected,
        ]);
    }

    private function baseData(): array
    {
        return [
            'initial' => [
                'gclid' => null,
                'fbclid' => null,
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
                'utm_content' => null,
                'utm_term' => null,
                'landing_page' => 'https://example.ca/',
                'referrer' => null,
                'referring_domain' => null,
                'device_type' => 'desktop',
                'promo_code' => null,
                'captured_at' => null,
            ],
            'total_visits' => 1,
        ];
    }
}
