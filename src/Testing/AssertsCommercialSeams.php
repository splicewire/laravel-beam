<?php

namespace Splicewire\Beam\Testing;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Router;
use ReflectionFunction;
use Stripe\HttpClient\ClientInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The commercial seams, as one architecture test (purchase-walkthrough SPEC §5.1, ticket BUY-01): the commercial twin of
 * {@see AssertsHostIaSeam}.
 *
 * A host's `tests/Architecture/CommercialSeamTest.php` uses this trait and calls {@see assertCommercialSeamRatchet()}.
 * The trait computes every violation of S1–S5 and R1–R5 at that host and compares the set with the host's RATCHET, the
 * list of known violations, each owned by the BUY ticket that removes it:
 *
 * - a violation that is not listed fails (a regression);
 * - a listed entry whose violation is gone fails too (stale), so the list only shrinks;
 * - an exact match passes, and every listed entry is printed with its owner, so the red stays visible.
 *
 * It never writes a file or an artifact. R1 drives money paths, so the rows its fixtures create live and die in the host
 * suite's own test database.
 *
 * Violation ids are stable across edits (no line numbers).
 *
 * The static sweeps use `grep -rIF`, which keeps `rg -F`'s exit contract (1 = clean, 0 = matches, 2 = a failed sweep,
 * which fails the test rather than reading as clean). A hit on a comment line is not code and is not a violation.
 *
 * Structural until their modules exist, as the SPEC's BUY-01 row allows:
 * - R1 classifies each path's outcome (settled, refused off-rail, outbound, error). "The effect landed" (wallet funded,
 *   subscription funded) is BUY-03's.
 * - R2 is `posture-missing` until BUY-02 gives `MoneyIn` a `posture()`.
 * - R4 is `account-doors-missing` until BUY-05's `AccountDoors`, plus the concrete fact that a registration route is
 *   mounted with no declared policy.
 * - R5 is `onboarding-missing` until BUY-06's `Onboarding`.
 */
trait AssertsCommercialSeams
{
    /**
     * The host's known violations: violation id => "BUY-NN: why", owned by the ticket that removes it.
     *
     * @return array<string, string>
     */
    abstract protected function commercialSeamRatchet(): array;

    /**
     * R1's dataset: the money paths this host drives under the fake rail. label => ['route' => the route name this path
     * covers (R3 reads it) or null, 'drive' => a closure that performs the path, or null where the path does not exist
     * yet (reported as `missing`)].
     *
     * @return array<string, array{route: ?string, drive: ?Closure}>
     */
    protected function commercialMoneyPaths(): array
    {
        return [];
    }

    /**
     * Route handlers that collect money, as `Class` or `Class@method`. R3 also treats as money any handler whose file is an
     * S1 hit or names the rail (`MoneyIn`) or the credit gateway.
     *
     * @return list<string>
     */
    protected function commercialMoneyHandlers(): array
    {
        return [
            'Splicewire\\Beam\\Commerce\\Ops\\CheckoutPlan',
            'Splicewire\\Beam\\Commerce\\Http\\Controllers\\SubscriptionController@portal',
            'Splicewire\\Beam\\Commerce\\Http\\Controllers\\CreditsController@checkout',
            'Splicewire\\Beam\\Market\\Extensions\\Ops\\PurchaseExtension',
            'Rushing\\Commerce\\Acp\\Http\\CheckoutSessionController@complete',
        ];
    }

    /**
     * Roots the static sweeps read, relative to the host's base path: the host's own code, then every family package's
     * source. A glob is expanded; a package that is a symlink is read through it and reported by its vendor path.
     *
     * @return list<string>
     */
    protected function commercialSweepRoots(): array
    {
        return ['app', 'database', 'vendor/splicewire/*/src', 'vendor/rushing/*/src'];
    }

    /** The Stripe adapter itself, where the rail is allowed to talk to Stripe (BUY-1). */
    protected function commercialAdapterPattern(): string
    {
        return '#^vendor/rushing/laravel-commerce/src/(Drivers/StripeDriver\.php|Stripe/)#';
    }

    protected function assertCommercialSeamRatchet(): void
    {
        $found = $this->commercialSeamViolations();
        $ratchet = $this->commercialSeamRatchet();
        $unlisted = array_diff_key($found, $ratchet);
        $stale = array_diff_key($ratchet, $found);

        $lines = [];
        foreach ($ratchet as $id => $owner) {
            $lines[] = (isset($found[$id]) ? '  known  ' : '  STALE  ')."{$id}  →  {$owner}";
        }
        foreach ($unlisted as $id => $detail) {
            $lines[] = "  NEW    {$id}  ({$detail})";
        }
        fwrite(STDERR, "\nCommercial seam ratchet at ".basename(base_path()).': '.count($found).' violation(s), '
            .count($ratchet).' listed, '.count($unlisted).' unlisted, '.count($stale)." stale\n".implode("\n", $lines)."\n");

        $this->assertSame([], $unlisted, 'Unlisted commercial-seam violations (a regression, or a ratchet entry is missing).');
        $this->assertSame([], $stale, 'Stale ratchet entries: the violation is gone, so delete the entry.');
    }

    /**
     * Every S1–S5 and R1–R5 violation at this host: id => detail.
     *
     * @return array<string, string>
     */
    protected function commercialSeamViolations(): array
    {
        $violations = [
            ...$this->commercialS1(),
            ...$this->commercialS2(),
            ...$this->commercialS3(),
            ...$this->commercialS4(),
            ...$this->commercialS5(),
            ...$this->commercialR1(),
            ...$this->commercialR2(),
            ...$this->commercialR3(),
            ...$this->commercialR4(),
            ...$this->commercialR5(),
        ];
        ksort($violations);

        return $violations;
    }

    /** S1, one rail (BUY-1): no Cashier money call or Stripe SDK use outside the Stripe adapter. */
    protected function commercialS1(): array
    {
        $out = [];
        foreach ($this->commercialSweep($this->commercialRailPatterns()) as [$rel, $line, $pattern]) {
            if (! preg_match($this->commercialAdapterPattern(), $rel)) {
                $out["S1 {$rel} {$pattern}"] = "line {$line}";
            }
        }

        return $out;
    }

    /** @return list<string> BUY-1's pattern list, plus PF-04's card-saving call. */
    protected function commercialRailPatterns(): array
    {
        return [
            'newSubscription(', 'checkoutCharge(', 'billingPortalUrl(', 'createOrGetStripeCustomer(', 'createSetupIntent(',
            '->tab(', '->invoice(', 'use Stripe\\', 'new StripeDriver', 'StripeDriver $', "driver: '",
        ];
    }

    /** S2, one rail key (BUY-2): the market's rail equals `commerce.driver` with only COMMERCE_DRIVER set, and with neither. */
    protected function commercialS2(): array
    {
        $market = $this->commercialPackageConfig('vendor/splicewire/laravel-beam-market/config/beam/market.php');
        $commerce = $this->commercialPackageConfig('vendor/rushing/laravel-commerce/config/commerce.php');
        if ($market === null || $commerce === null) {
            return [];
        }

        $out = [];
        foreach (['only-commerce-set' => ['COMMERCE_DRIVER' => 'stripe'], 'neither-set' => []] as $population => $env) {
            [$marketDriver, $commerceDriver] = $this->commercialWithEnv(
                ['COMMERCE_DRIVER' => null, 'BEAM_MARKET_CHECKOUT_DRIVER' => null, ...$env],
                fn () => [(require $market)['checkout']['driver'] ?? null, (require $commerce)['driver'] ?? null],
            );
            $effective = $marketDriver ?? $commerceDriver;
            if ($effective !== $commerceDriver) {
                $out["S2 market-driver {$population}"] = "market collects on `{$effective}`, the rail is `{$commerceDriver}`";
            }
        }

        return $out;
    }

    /** S3, account doors (BUY-5): user-model creation only in AccountDoors (seeders and factories excepted); no `env(` in a controller. */
    protected function commercialS3(): array
    {
        $out = [];
        $creates = ['User::create(', 'User::forceCreate(', 'User::firstOrCreate(', 'User::updateOrCreate('];
        foreach ($this->commercialSweep($creates) as [$rel, $line, $pattern]) {
            if (! preg_match('#/(database|Database)/|/(Seeders|seeders|Factories|factories|Testing)/|^database/|/Doors/#', $rel)) {
                $out["S3 user-create {$rel} {$pattern}"] = "line {$line}";
            }
        }
        foreach ($this->commercialSweep(['env(']) as [$rel, $line, , $code]) {
            if (str_contains($rel, '/Http/Controllers/') && preg_match('/(?<![\w>$:])env\(/', $code)) {
                $out["S3 env-in-controller {$rel}"] = "line {$line}";
            }
        }

        return $out;
    }

    /** S4, one onboarding (BUY-6): `Tenant::create(` and `new Tenant(` only in Onboarding. */
    protected function commercialS4(): array
    {
        $out = [];
        foreach ($this->commercialSweep(['Tenant::create(', 'new Tenant(']) as [$rel, $line, $pattern]) {
            if (! str_contains($rel, '/Onboarding/') && ! str_contains($rel, '/Testing/')) {
                $out["S4 tenant-create {$rel} {$pattern}"] = "line {$line}";
            }
        }

        return $out;
    }

    /** S5, licences (BUY-13): a licence is issued (`LicenseIssuer` and `->issue(`) only at the market's four sites. */
    protected function commercialS5(): array
    {
        $allowed = [
            'vendor/splicewire/laravel-beam-market/src/Checkout/Entitlements.php',
            'vendor/splicewire/laravel-beam-market/src/Review/Effects/RecordReviewOutcome.php',
            'vendor/splicewire/laravel-beam-market/src/Federation/IssueSiteConnection.php',
            'vendor/splicewire/laravel-beam-market/src/Federation/GrantSiteAcquisition.php',
        ];
        $out = [];
        $issuing = array_unique(array_column($this->commercialSweep(['->issue(']), 0));
        foreach ($this->commercialSweep(['LicenseIssuer']) as [$rel, $line]) {
            if (in_array($rel, $issuing, true) && ! in_array($rel, $allowed, true)
                && ! str_starts_with($rel, 'vendor/splicewire/laravel-beam-licenser/')) {
                $out["S5 licence-issuer {$rel}"] = "line {$line}";
            }
        }

        return $out;
    }

    /**
     * R1, the rail at runtime: each money path driven under `commerce.driver=fake` with stray HTTP refused and stripe-php's
     * transport recording. Clean = no request left the process and `PurchaseCompleted` fired.
     */
    protected function commercialR1(): array
    {
        $out = [];
        foreach ($this->commercialMoneyPaths() as $label => $path) {
            [$outcome, $why] = $path['drive'] === null ? ['missing', ''] : $this->commercialDrive($path['drive']);
            if ($outcome !== null) {
                $out["R1 {$label} {$outcome}"] = trim(($path['route'] ?? 'no route').' '.$why);
            }
        }

        return $out;
    }

    /** R2, posture parity (BUY-2): structural until `MoneyIn::posture()` exists. */
    protected function commercialR2(): array
    {
        $moneyIn = 'Rushing\\Commerce\\MoneyIn';
        if (! class_exists($moneyIn) || method_exists($moneyIn, 'posture')) {
            return [];
        }

        return ['R2 posture-missing' => 'MoneyIn has no posture(); the surfaces cannot read custody'];
    }

    /** R3, money routes: every route whose handler collects money is in R1's dataset. */
    protected function commercialR3(): array
    {
        $driven = array_filter(array_column($this->commercialMoneyPaths(), 'route'));
        $s1Files = array_unique(array_map(fn (string $id) => explode(' ', $id)[1], array_keys($this->commercialS1())));

        $out = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && ! in_array($name, $driven, true) && $this->commercialIsMoneyRoute($route, $s1Files)) {
                $out["R3 {$name} not-driven"] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        return $out;
    }

    /** R4, account doors (BUY-5): structural until AccountDoors exists; a mounted registration route has no declared policy. */
    protected function commercialR4(): array
    {
        if (interface_exists('Splicewire\\Beam\\Accounts\\Doors\\AccountDoors')) {
            return [];
        }

        $out = ['R4 account-doors-missing' => 'no AccountDoors policy declares which doors are open'];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'register' && in_array('POST', $route->methods(), true)) {
                $out['R4 register-mounted-undeclared'] = 'POST register is mounted with no declared door policy';
            }
        }

        return $out;
    }

    /** R5, door parity (BUY-6): structural until Onboarding exists. Onboarding is tower's (M11), so a host without tower has no R5. */
    protected function commercialR5(): array
    {
        if (! class_exists('Splicewire\\Tower\\Provisioning\\TenantProvisioning')
            || interface_exists('Splicewire\\Tower\\Onboarding\\Onboarding')) {
            return [];
        }

        return ['R5 onboarding-missing' => 'no Onboarding module; each door creates tenants its own way'];
    }

    /**
     * Drive one money path under the fake rail: [outcome, why]. Outcome is null when clean; otherwise `off-rail` (it calls
     * Stripe directly and the guard refused it), `outbound`, `unsettled` (no PurchaseCompleted), or `error <class>`.
     *
     * @return array{0: ?string, 1: string}
     */
    protected function commercialDrive(Closure $drive): array
    {
        $requestor = 'Stripe\\ApiRequestor';
        $guard = 'Rushing\\Commerce\\Stripe\\FakeRailStripeHttpClient';
        $refusal = 'Rushing\\Commerce\\Exceptions\\StripeDisabledOnFakeRail';
        $completed = 'Rushing\\Commerce\\Events\\PurchaseCompleted';

        $sent = [];
        $previous = class_exists($requestor) ? (new \ReflectionProperty($requestor, '_httpClient'))->getValue() : null;
        $previousDriver = config('commerce.driver');
        // Dummy TEST credentials, so a path that calls Stripe directly gets as far as the transport (where the fake rail
        // refuses it) rather than failing on a missing key, which would hide what it does. Nothing is sent either way.
        $previousKeys = ['cashier.secret' => config('cashier.secret'), 'cashier.key' => config('cashier.key'), 'commerce.stripe.secret' => config('commerce.stripe.secret')];
        config(['commerce.driver' => 'fake', 'cashier.secret' => 'sk_test_commercial_seam', 'cashier.key' => 'pk_test_commercial_seam', 'commerce.stripe.secret' => 'sk_test_commercial_seam']);
        Http::preventStrayRequests();
        if (class_exists($requestor) && class_exists($guard)) {
            $requestor::setHttpClient(new $guard($this->commercialRecordingTransport($sent)));
        }
        $settled = 0;
        Event::listen($completed, function () use (&$settled) {
            $settled++;
        });

        try {
            $drive();
            $outcome = $sent !== [] ? 'outbound' : ($settled === 0 ? 'unsettled' : null);
            $why = $sent !== [] ? implode(', ', $sent) : '';
        } catch (Throwable $e) {
            $why = '('.class_basename($e).': '.str($e->getMessage())->limit(160).')';
            $outcome = match (true) {
                $sent !== [] => 'outbound',
                is_a($e, $refusal) => 'off-rail',
                str_contains($e::class, 'StrayRequest') => 'outbound',
                default => 'error '.class_basename($e),
            };
        } finally {
            if (class_exists($requestor)) {
                $requestor::setHttpClient($previous);
            }
            config(['commerce.driver' => $previousDriver, ...$previousKeys]);
            Http::preventStrayRequests(false);
        }

        return [$outcome, $why];
    }

    /** A stripe-php transport that records every request it would send; under the fake rail it must stay empty. */
    private function commercialRecordingTransport(array &$sent): object
    {
        return new class($sent) implements ClientInterface
        {
            public function __construct(private array &$sent) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->sent[] = strtoupper((string) $method).' '.$absUrl;

                return ['{"id":"stripe_obj","object":"customer"}', 200, []];
            }
        };
    }

    /** @param  list<string>  $s1Files */
    private function commercialIsMoneyRoute(Route $route, array $s1Files): bool
    {
        [$class, $method, $file] = $this->commercialRouteHandler($route);
        if ($class === null) {
            return false;
        }
        foreach ($this->commercialMoneyHandlers() as $handler) {
            if ($handler === $class || $handler === "{$class}@{$method}") {
                return true;
            }
        }
        // The file heuristic is class-level, so it judges only routes that write: a GET on a money controller reads.
        if ($file === null || array_diff($route->methods(), ['GET', 'HEAD']) === []) {
            return false;
        }
        $rel = $this->commercialRelative($file);
        if (in_array($rel, $s1Files, true)) {
            return true;
        }
        $source = (string) @file_get_contents($file);

        return str_contains($source, 'MoneyIn') || str_contains($source, 'CreditCheckoutGateway');
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} the handling class, method and file; a particle op resolves to its declaring class. */
    private function commercialRouteHandler(Route $route): array
    {
        $resource = $route->defaults['_particle_op_resource'] ?? null;
        $name = $route->defaults['_particle_op_name'] ?? null;
        if ($resource !== null && $name !== null) {
            try {
                $op = app('Splicewire\\Beam\\Particle\\ParticleOperationRegistry')->get($resource, $name);
                $fn = new ReflectionFunction($op->handle);
                $class = $fn->getClosureScopeClass()?->getName();

                return [$class, 'handle', $fn->getFileName() ?: null];
            } catch (Throwable) {
                return [null, null, null];
            }
        }

        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return [class_exists($action) ? $action : null, '__invoke', $this->commercialClassFile($action)];
        }
        [$class, $method] = explode('@', $action, 2);

        return [$class, $method, $this->commercialClassFile($class)];
    }

    private function commercialClassFile(string $class): ?string
    {
        try {
            return class_exists($class) ? ((new \ReflectionClass($class))->getFileName() ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `grep -rIF` for the patterns over every sweep root. Each code hit: [relative path, line, the pattern it
     * matched, the line's code]. A comment line is not a hit. A failed sweep (exit 2) fails the test.
     *
     * @param  list<string>  $patterns
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    protected function commercialSweep(array $patterns): array
    {
        $hits = [];
        foreach ($this->commercialResolvedRoots() as $display => $real) {
            $args = ['grep', '-rIFn', '--include=*.php'];
            foreach ($patterns as $pattern) {
                $args[] = '-e';
                $args[] = $pattern;
            }
            $args[] = $real;
            $process = new Process($args);
            $process->run();
            if ($process->getExitCode() === 2) {
                $this->fail("Commercial seam sweep failed over {$display} (grep exit 2): ".trim($process->getErrorOutput()));
            }
            foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $row) {
                if ($row === '' || ! preg_match('/^(.*?):(\d+):(.*)$/', $row, $m)) {
                    continue;
                }
                $code = trim($m[3]);
                if (preg_match('#^(//|\*|/\*|\#)#', $code)) {
                    continue;
                }
                if (realpath($m[1]) === realpath(__FILE__)) {
                    continue; // this file names every pattern it sweeps for
                }
                $rel = $display.substr($m[1], strlen($real));
                foreach ($patterns as $pattern) {
                    if (str_contains($code, $pattern)) {
                        $hits[] = [$rel, (int) $m[2], $pattern, $code];
                    }
                }
            }
        }

        return $hits;
    }

    /** @return array<string, string> display root (relative to the base path) => the real directory grep reads */
    private function commercialResolvedRoots(): array
    {
        $roots = [];
        foreach ($this->commercialSweepRoots() as $root) {
            $absolute = str_starts_with($root, '/') ? $root : base_path($root);
            foreach (glob($absolute, GLOB_ONLYDIR) ?: [] as $dir) {
                $real = realpath($dir);
                if ($real !== false) {
                    $roots[$this->commercialBaseRelative($dir)] = $real;
                }
            }
        }

        return $roots;
    }

    /** A path relative to the host's base path when it lies under it, else unchanged. */
    private function commercialBaseRelative(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** A file's id-path: relative to the base path, or to the vendor root it was read through. */
    private function commercialRelative(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        foreach ($this->commercialResolvedRoots() as $display => $real) {
            if (str_starts_with($path, $real.'/')) {
                return $display.substr($path, strlen($real));
            }
        }

        return $path;
    }

    private function commercialPackageConfig(string $relative): ?string
    {
        $path = base_path($relative);

        return is_file($path) ? $path : null;
    }

    /**
     * Evaluate a config file under a temporary environment: null unsets a variable for the call.
     *
     * @param  array<string, ?string>  $env
     */
    private function commercialWithEnv(array $env, Closure $read): mixed
    {
        $saved = [];
        foreach ($env as $key => $value) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        try {
            return $read();
        } finally {
            foreach ($saved as $key => [$getenv, $envValue, $serverValue]) {
                $getenv === false ? putenv($key) : putenv("{$key}={$getenv}");
                if ($envValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envValue;
                }
                if ($serverValue === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverValue;
                }
            }
        }
    }
}
