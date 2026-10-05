<?php

namespace Splicewire\Beam\Tests\Testing;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Splicewire\Beam\Testing\AssertsCommercialSeams;
use Splicewire\Beam\Tests\TestCase;

/**
 * The commercial-seam ratchet's own rules (purchase-walkthrough BUY-01): the static sweep reads code and not comments,
 * the Stripe adapter is exempt, a failed sweep fails rather than reading clean, the ratchet fails on an unlisted
 * violation AND on a stale entry, and R1 names a money path that does not settle or does not exist.
 */
class AssertsCommercialSeamsTest extends TestCase
{
    use AssertsCommercialSeams;

    private string $dir;

    /** @var array<string, string> */
    private array $ratchet = [];

    /** @var array<string, string>|null */
    private ?array $violations = null;

    /** @var array<string, array{route: ?string, drive: ?\Closure}> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/commercial-seams-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/app/Billing', 0777, true);
        mkdir($this->dir.'/adapter/Stripe', 0777, true);
        file_put_contents($this->dir.'/app/Billing/Gateway.php', "<?php\n\$account->checkoutCharge(500, 'Credits');\n");
        file_put_contents($this->dir.'/app/Billing/Documented.php', "<?php\n// it used to call \$tenant->newSubscription(...)\n /* ->invoice( */\n * ->tab( in prose\n");
        file_put_contents($this->dir.'/adapter/Stripe/Client.php', "<?php\nuse Stripe\\StripeClient;\n");
    }

    protected function tearDown(): void
    {
        @chmod($this->dir.'/app/Locked', 0755);
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    protected function commercialSeamRatchet(): array
    {
        return $this->ratchet;
    }

    protected function commercialSweepRoots(): array
    {
        return [$this->dir.'/app', $this->dir.'/adapter'];
    }

    protected function commercialAdapterPattern(): string
    {
        return '#/adapter/Stripe/#';
    }

    /** @var list<string> */
    private array $seedRoots = [];

    protected function commercialSeedRoots(): array
    {
        return $this->seedRoots;
    }

    protected function commercialMoneyPaths(): array
    {
        return $this->paths;
    }

    protected function commercialSeamViolations(): array
    {
        return $this->violations ?? [...$this->commercialS1(), ...$this->commercialR1()];
    }

    public function test_s1_finds_a_cashier_call_in_code_and_not_in_comments_or_the_adapter(): void
    {
        $this->assertSame(["S1 {$this->dir}/app/Billing/Gateway.php checkoutCharge("], array_keys($this->commercialS1()));
    }

    public function test_i3_finds_a_recurring_stripe_start_anywhere_even_in_the_adapter(): void
    {
        // BUY-04 (I3, one engine per fee): BillGenerator bills the fee, so a Stripe recurring subscription is a second
        // engine wherever it starts. newSubscription( stays S1's.
        file_put_contents($this->dir.'/adapter/Stripe/Recurring.php', "<?php\n\$s->checkout->sessions->create(['mode' => 'subscription']);\n// 'mode' => 'subscription' in prose\n");
        // review-r1: spacing and quote variants are the same start.
        file_put_contents($this->dir.'/app/Billing/Tight.php', "<?php\n\$a = ['mode'=>\"subscription\"];\n\$s->subscriptions->create (\$p);\n\$b = ['mode' => 'payment'];\n");

        $this->assertSame([
            "I3 recurring {$this->dir}/adapter/Stripe/Recurring.php mode=subscription",
            "I3 recurring {$this->dir}/app/Billing/Tight.php mode=subscription",
            "I3 recurring {$this->dir}/app/Billing/Tight.php subscriptions->create",
        ], array_keys($this->commercialI3()));
    }

    public function test_i3_flags_a_bound_subscription_binder(): void
    {
        // laravel-commerce's StripeDriver starts a Stripe subscription for a recurring Order through the host's binder.
        $this->app->instance('Rushing\\Commerce\\Contracts\\SubscriptionBinder', new \stdClass);

        $this->assertArrayHasKey('I3 subscription-binder-bound', $this->commercialI3());
    }

    public function test_i3_flags_a_seeded_stripe_price_id(): void
    {
        // C-4: no seeded price id until BQ-2. The seed sweep reads only the database roots.
        mkdir($this->dir.'/database/seeders', 0777, true);
        file_put_contents($this->dir.'/database/seeders/PlanSeeder.php', "<?php\n\$settings = ['stripe' => ['price_id' => 'price_123']];\n");
        file_put_contents($this->dir.'/app/Billing/Reads.php', "<?php\nreturn \$plan->settings['stripe']['price_id'];\n");
        // build.qa: a JSON or YAML fixture seeds as surely as a seeder.
        mkdir($this->dir.'/database/fixtures', 0777, true);
        file_put_contents($this->dir.'/database/fixtures/plans.json', '{"stripe": {"price_id": "price_123"}}');
        file_put_contents($this->dir.'/database/fixtures/plans.yaml', "stripe:\n  price_id: price_123\n");
        $this->seedRoots = [$this->dir.'/database'];

        $this->assertSame([
            "I3 seeded-price-id {$this->dir}/database/fixtures/plans.json",
            "I3 seeded-price-id {$this->dir}/database/fixtures/plans.yaml",
            "I3 seeded-price-id {$this->dir}/database/seeders/PlanSeeder.php",
        ], array_keys($this->commercialI3()));
    }

    public function test_a_failed_sweep_fails_instead_of_reading_clean(): void
    {
        mkdir($this->dir.'/app/Locked');
        file_put_contents($this->dir.'/app/Locked/Hidden.php', "<?php\n\$a->tab(1);\n");
        chmod($this->dir.'/app/Locked', 0000);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('grep exit 2');

        $this->commercialS1();
    }

    public function test_an_unlisted_violation_fails(): void
    {
        $this->violations = ['S1 app/Billing/Gateway.php checkoutCharge(' => 'line 2'];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unlisted commercial-seam violations');

        $this->assertCommercialSeamRatchet();
    }

    public function test_a_stale_entry_fails_so_the_list_only_shrinks(): void
    {
        $this->violations = [];
        $this->ratchet = ['S1 app/Billing/Gone.php checkoutCharge(' => 'BUY-02: moved onto the rail'];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Stale ratchet entries');

        $this->assertCommercialSeamRatchet();
    }

    public function test_an_exact_match_passes(): void
    {
        $this->violations = ['R2 posture-missing' => 'no posture()'];
        $this->ratchet = ['R2 posture-missing' => 'BUY-02: MoneyIn::posture()'];

        $this->assertCommercialSeamRatchet();
    }

    public function test_a_drive_restores_the_host_s_stray_request_setting(): void
    {
        // build.qa on BUY-01: the drive forced prevention OFF afterwards, which stripped a host's own setting.
        Http::preventStrayRequests();
        $this->paths = ['credit-checkout' => ['route' => null, 'drive' => fn () => null]];

        $this->commercialR1();

        $this->assertTrue(Http::preventingStrayRequests());
    }

    /** Bind a stand-in for beam-accounts' AccountDoors (laravel-beam does not depend on it) with one registration mode. */
    private function doorsSay(string $registration, bool $declared = true): void
    {
        $this->app->instance('Splicewire\\Beam\\Accounts\\Doors\\AccountDoors', new class($registration, $declared)
        {
            public function __construct(private string $registration, private bool $declared) {}

            public function declared(): bool
            {
                return $this->declared;
            }

            public function policy(): object
            {
                return (object) ['registration' => $this->registration];
            }
        });
    }

    /** BUY-05: R4 judges the declared policy. The register route is mounted iff registration is open, and Fortify agrees. */
    public function test_r4_holds_the_register_route_to_the_declared_door(): void
    {
        $this->doorsSay('closed');
        config(['fortify.features' => []]);
        $this->assertSame([], $this->commercialR4(), 'Closed and unmounted is the clean case.');

        Route::post('register', fn () => 'x');
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertArrayHasKey('R4 register-mounted-while-closed', $this->commercialR4());

        $this->doorsSay('open');
        config(['fortify.features' => ['registration']]);
        $this->assertSame([], $this->commercialR4(), 'Open, mounted, and Fortify agrees.');

        config(['fortify.features' => []]);
        $this->assertArrayHasKey('R4 fortify-registration-disagrees', $this->commercialR4());
    }

    /** Until a host declares its door, R4 reports what it did before the policy existed (no live host changes). */
    public function test_r4_is_structural_while_the_host_has_not_declared_its_door(): void
    {
        $this->doorsSay('closed', declared: false);
        Route::post('register', fn () => 'x');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertSame(['R4 account-doors-missing', 'R4 register-mounted-undeclared'], array_keys($this->commercialR4()));
    }

    public function test_r4_reports_an_open_door_with_no_route(): void
    {
        $this->doorsSay('open');
        config(['fortify.features' => ['registration']]);

        $this->assertArrayHasKey('R4 register-open-unmounted', $this->commercialR4());
    }

    public function test_r1_names_a_path_that_does_not_settle_and_one_that_does_not_exist(): void
    {
        $this->paths = [
            'credit-checkout' => ['route' => 'credits.checkout', 'drive' => fn () => null],
            'tower-listing' => ['route' => null, 'drive' => null],
        ];

        $this->assertSame([
            'R1 credit-checkout unsettled' => 'credits.checkout',
            'R1 tower-listing missing' => 'no route',
        ], $this->commercialR1());
    }

    public function test_r1_holds_a_management_path_clean_without_a_purchase(): void
    {
        // purchase-walkthrough BUY-02: the billing portal is MoneyIn::manage(), which settles no purchase; its clean
        // outcome is "nothing outbound, nothing refused". A path declares that with `settles => false`.
        $this->paths = [
            'billing-portal' => ['route' => 'subscription.portal', 'drive' => fn () => null, 'settles' => false],
            'credit-checkout' => ['route' => 'credits.checkout', 'drive' => fn () => null],
        ];

        $this->assertSame(['R1 credit-checkout unsettled' => 'credits.checkout'], $this->commercialR1());
    }
}
