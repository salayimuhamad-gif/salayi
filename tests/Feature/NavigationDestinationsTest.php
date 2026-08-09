<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repair instructions §2.4.
 *
 * The defect this exists to prevent: `PublicLayout.vue` rendered seven
 * top-level links, two of which (`/areas`, `/news`) had `flag: null` — meaning
 * "always render" — and no public route behind them. Every page of the site,
 * in all three languages, carried two links that 404'd. Nothing caught it,
 * because nothing had ever requested a navigation destination.
 *
 * The rule enforced here is narrow and total: **if the navigation can render a
 * link, the server must answer it.** A surface may be unbuilt; it may not be
 * unbuilt and linked.
 */
final class NavigationDestinationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seven items of File one §4, mirroring the `navigation` array in
     * resources/js/Layouts/PublicLayout.vue.
     *
     * Duplicated across the PHP/Vue boundary, so drift is the obvious risk —
     * which is why {@see test_the_vue_navigation_matches_this_list} parses the
     * component and fails if the two ever disagree.
     *
     * @var array<string, array{path: string, flag: string|null, built: bool}>
     */
    private const NAVIGATION = [
        'market' => ['path' => '/market', 'flag' => 'market.intelligence', 'built' => true],
        'map' => ['path' => '/map', 'flag' => 'map.explorer', 'built' => true],
        'invest' => ['path' => '/invest', 'flag' => 'map.investment', 'built' => true],
        'projects' => ['path' => '/projects', 'flag' => null, 'built' => true],
        'areas' => ['path' => '/areas', 'flag' => 'geography.areas', 'built' => true],
        /*
         * `requires_verified`: /advisor sits behind the `verified` middleware,
         * so a guest is redirected into verification rather than reaching it.
         * That is the intended contract — the advisor holds a personal,
         * consent-bearing conversation — so the destination is visited as the
         * audience it is built for. It is NOT excused from resolving: the
         * assertion below is identical, only the visitor differs.
         */
        'advisor' => ['path' => '/advisor', 'flag' => 'advisor.residential', 'built' => true, 'requires_verified' => true],
        'offers' => ['path' => '/offers', 'flag' => 'marketplace.offers', 'built' => true],
        'news' => ['path' => '/news', 'flag' => 'content.news', 'built' => false],
    ];

    /**
     * Every built destination answers successfully once its flag is on, in
     * every enabled locale.
     *
     * Locale coverage is the point rather than a thoroughness flourish: under
     * `prefix_except_default` the Sorani URL is bare and the others are
     * prefixed, so `/market` passing says nothing whatsoever about `/ar/market`
     * — which is precisely the asymmetry that let the hreflang alternates
     * point at 404s for as long as they did.
     */
    public function test_every_built_navigation_destination_resolves_in_every_locale(): void
    {
        $default = (string) config('localization.default', 'ckb');

        foreach (self::NAVIGATION as $key => $item) {
            if (! $item['built']) {
                continue;
            }

            $this->enableFlag($item['flag']);

            if ($item['requires_verified'] ?? false) {
                /*
                 * `EnsureVerifiedAccount` requires a proven PHONE, not a
                 * verified email — an unverified visitor is sent to
                 * telegram.login to start that flow. So the audience this link
                 * is built for is a phone-verified account.
                 */
                /*
                 * v6 merge: /advisor now sits behind `telegram.linked`,
                 * not `verified` — the Telegram work made a PROVEN
                 * identity the gate for personal surfaces, and a typed
                 * phone is not a proven one. The audience this link is
                 * built for is therefore a linked, active account, so the
                 * fixture is the account the contract actually describes.
                 */
                $this->actingAs(
                    User::factory()->create([
                        'phone_verified' => true,
                        'telegram_id' => '900100',
                        'telegram_verified_at' => now(),
                        'is_active' => true,
                    ])
                );
            }

            foreach (enabled_locales() as $locale) {
                $url = $locale === $default
                    ? $item['path']
                    : '/'.$locale.$item['path'];

                $response = $this->get($url);

                $this->assertTrue(
                    $response->isSuccessful(),
                    sprintf(
                        'Navigation item "%s" links to %s, which returned %d. '
                        .'A rendered link must not be a 404.',
                        $key,
                        $url,
                        $response->getStatusCode(),
                    ),
                );
            }
        }
    }

    /**
     * An unbuilt surface must be flagged OFF by default.
     *
     * This is the assertion that fails the build if somebody re-adds `/areas`
     * or `/news` to the navigation before the route exists. When the public
     * Area and News modules ship, flip `built` to true above and delete the
     * entry from this test's expectations — the first test will then hold the
     * route to the same standard as every other destination.
     */
    public function test_unbuilt_navigation_destinations_are_flagged_off_by_default(): void
    {
        foreach (self::NAVIGATION as $key => $item) {
            if ($item['built']) {
                continue;
            }

            // `!== null` rather than assertNotNull: the table's literals let
            // the analyser see the value, and an assertion that cannot fail
            // documents nothing. What must hold is that an unbuilt item names
            // a flag, so the flag name itself is asserted.
            $this->assertNotSame(
                '',
                (string) $item['flag'],
                sprintf('Unbuilt navigation item "%s" must be gated by a feature flag.', $key),
            );

            $this->assertFalse(
                /*
                 * READ FROM THE ARRAY, NOT THROUGH A DOTTED PATH.
                 *
                 * `config('features.defaults.map.explorer')` resolves to null,
                 * because the flag is a literal array key containing dots and
                 * the config getter splits on them. `assertFalse((bool) null)`
                 * then passed for every flag whatever its real value, so this
                 * guard proved nothing at all.
                 */
                (bool) (((array) config('features.defaults', []))[$item['flag']] ?? false),
                sprintf(
                    'Feature flag "%s" gates the unbuilt %s surface and must default to OFF. '
                    .'Enabling it renders a link to a route that does not exist.',
                    $item['flag'],
                    $item['path'],
                ),
            );
        }
    }

    /**
     * A disabled flag removes the surface, not merely the link.
     *
     * Appendix D: an OFF flag means the route is absent. Asserting only that
     * the link disappears would leave the URL reachable by anyone who typed it.
     */
    public function test_a_disabled_flag_makes_its_destination_unreachable(): void
    {
        foreach (self::NAVIGATION as $key => $item) {
            if ($item['flag'] === null || ! $item['built']) {
                continue;
            }

            $this->disableFlag($item['flag']);

            $this->get($item['path'])->assertNotFound();
        }
    }

    /**
     * The Vue component and this list describe the same navigation.
     *
     * Parsing a template from a PHP test is ugly. It is less ugly than the
     * alternative, which is a CI gate that silently stops covering an item
     * somebody added to the menu.
     */
    public function test_the_vue_navigation_matches_this_list(): void
    {
        $component = base_path('resources/js/Layouts/PublicLayout.vue');

        $this->assertFileExists($component);

        $source = (string) file_get_contents($component);

        preg_match_all("/\{\s*key:\s*'([^']+)',\s*href:\s*'([^']+)'/", $source, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'Could not parse the navigation array out of PublicLayout.vue.');

        $inComponent = [];

        foreach ($matches as $match) {
            $inComponent[$match[1]] = $match[2];
        }

        $expected = array_map(static fn (array $item): string => $item['path'], self::NAVIGATION);

        $this->assertSame(
            $expected,
            $inComponent,
            'PublicLayout.vue and NavigationDestinationsTest disagree about the public navigation. '
            .'Update both, or the untested item is the one that will 404.',
        );
    }

    private function enableFlag(?string $flag): void
    {
        if ($flag !== null) {
            $this->setFeatures([''.$flag => true,
            ]);
        }
    }

    private function disableFlag(string $flag): void
    {
        $this->setFeatures([''.$flag => false,
        ]);
    }
}
