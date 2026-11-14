<?php

namespace Splicewire\Beam\Localization\Client;

use Illuminate\Http\Request;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogAddressData;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogData;
use Splicewire\Beam\Localization\Client\Data\ClientLocalizationData;
use Splicewire\Beam\Localization\Client\Data\ClientMessageLineData;

/**
 * The flattened, laddered client catalog (localization 16; ruling 02 §2 and §4).
 *
 * Every admitted discovered key walks ruling 05 §2's ladder ONCE (07's BeamTranslator). The line carries the text and
 * the locale that ANSWERED it. A key no rung answers keeps its discovered English default, presented for the active
 * locale, with `sourceLocale: en`. The hash is sha256(JCS(catalog)).
 *
 * **Memoized** in the cache store under ruling 02's full key: (host, collector payload digest, locale, realm,
 * translation fingerprint, config fingerprint), all from {@see LangFingerprint}. It is never a worker-lifetime
 * singleton, and a changed line, config or catalog yields a new key.
 *
 * Amend (B11-B2, B11-B5):
 * - The key also carries the effective namespace admission and the loader's source mapping, so a namespace refused
 *   after a warm build can never be served from the memo.
 * - On a miss, a translator whose loaded groups predate the current translation fingerprint has those groups dropped
 *   before the build (they reload lazily from the files), so a changed line is never rebuilt from stale state.
 * - A build is stored only when the fingerprint did not move while it ran.
 */
final class ClientCatalogResolver
{
    private const RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'dv', 'yi', 'ku', 'sd', 'ug', 'ckb'];

    private ?DiscoveredCatalog $discovered = null;

    /** @var array<string, string>|null */
    private ?array $refused = null;

    /** @var \WeakReference<object>|null the translator the admission above was made on (its hints live there) */
    private ?\WeakReference $admittedOn = null;

    /** @var \WeakMap<Translator, string>|null the translation fingerprint each translator instance last loaded under */
    private static ?\WeakMap $loadedUnder = null;

    public function __construct(private ClientNamespaces $namespaces) {}

    public static function path(): string
    {
        $path = (string) config('beam.localization.client_catalog', 'resources/beam/messages.json');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public function discovered(): DiscoveredCatalog
    {
        return $this->discovered ??= DiscoveredCatalog::read(self::path());
    }

    /** @return array<string, string> refused namespace => why (empty when the catalog is not valid) */
    public function refused(): array
    {
        $discovered = $this->discovered();
        // Admission hints the translator it runs on, so a different translator instance is admitted again.
        $translator = app('translator');
        if ($this->refused === null || $this->admittedOn?->get() !== $translator) {
            $this->refused = $discovered->valid() ? $this->namespaces->admit($discovered) : [];
            $this->admittedOn = \WeakReference::create($translator);
        }

        return $this->refused;
    }

    /** @return array<string, string> the admitted client keys and their English defaults, sorted by key */
    public function defaults(): array
    {
        $refused = $this->refused();
        $defaults = [];
        foreach ($this->discovered()->defaults as $key => $entry) {
            $namespace = str_contains($key, '::') ? strstr($key, '::', true) : null;
            if ($namespace === null || ! isset($refused[$namespace])) {
                $defaults[$key] = $entry['default'];
            }
        }
        ksort($defaults, SORT_STRING);

        return $defaults;
    }

    public function resolve(string $locale, ?string $realm): ClientCatalogData
    {
        $discovered = $this->discovered();
        $refused = $this->refused();
        $fingerprint = LangFingerprint::translations();
        $key = 'beam.client-catalog:'.hash('sha256', implode("\0", [
            self::host(), $discovered->payloadDigest ?? $discovered->state->value, $locale, $realm ?? '',
            $fingerprint, LangFingerprint::config(), self::admission($refused),
        ]));
        $stored = cache()->get($key);
        if (! is_array($stored)) {
            self::refreshTranslator($fingerprint);
            $stored = $this->build($locale, $realm);
            if (LangFingerprint::translations() === $fingerprint) {
                cache()->put($key, $stored, (int) config('beam.localization.client_catalog_ttl', 86400));
            }
        }

        return new ClientCatalogData(
            version: 1,
            locale: $stored['locale'],
            realm: $stored['realm'],
            direction: $stored['direction'],
            messages: array_map(static fn (array $line): ClientMessageLineData => new ClientMessageLineData($line[0], $line[1]), $stored['messages']),
        );
    }

    public function address(string $locale, ?string $realm, ?ClientCatalogData $catalog = null): ClientCatalogAddressData
    {
        $hash = ($catalog ?? $this->resolve($locale, $realm))->hash();
        $url = route('beam.localization.catalog', array_filter(['locale' => $locale, 'realm' => $realm], static fn ($v) => $v !== null), false);

        return new ClientCatalogAddressData(url: $url.'?h='.$hash, hash: $hash, version: 1, locale: $locale, realm: $realm);
    }

    /** The bootstrap for the request's resolved locale and realm. `initial` is null without a valid discovered catalog. */
    public function bootstrap(Request $request): ClientLocalizationData
    {
        $translator = app('translator');
        $locale = app()->getLocale();
        $realm = $translator instanceof BeamTranslator ? $translator->requestRealm() : null;
        $catalog = $this->resolve($locale, $realm);

        return new ClientLocalizationData(
            address: $this->address($locale, $realm, $catalog),
            initial: $this->discovered()->valid() ? $catalog : null,
        );
    }

    public static function direction(string $locale): string
    {
        $language = strtolower(strtok($locale, '-_') ?: $locale);

        return in_array($language, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** @return array{locale: string, realm: ?string, direction: string, messages: array<string, array{0: string, 1: string}>} */
    private function build(string $locale, ?string $realm): array
    {
        $translator = app('translator');
        $messages = [];
        foreach ($this->defaults() as $key => $default) {
            $resolved = $translator instanceof BeamTranslator ? $translator->resolve($key, $locale, true, $realm) : null;
            $messages[$key] = $resolved !== null && is_string($resolved->line)
                ? [$resolved->line, $resolved->sourceLocale]
                : [$translator instanceof BeamTranslator ? $translator->present($default, $locale) : $default, 'en'];
        }

        return ['locale' => $locale, 'realm' => $realm, 'direction' => self::direction($locale), 'messages' => $messages];
    }

    /**
     * The effective admission identity: the refused namespaces, and the loader's namespace hints, base paths and JSON
     * paths exactly as configured (a hint to a missing directory included), in order.
     *
     * @param  array<string, string>  $refused
     */
    private static function admission(array $refused): string
    {
        $loader = app('translator')->getLoader();

        return hash('sha256', json_encode([
            'refused' => array_keys($refused),
            'hints' => $loader instanceof FileLoader ? $loader->namespaces() : [],
            'paths' => $loader instanceof FileLoader ? $loader->paths() : [],
            'json' => $loader instanceof FileLoader ? $loader->jsonPaths() : [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Drop the translator's loaded groups when they were loaded under another translation fingerprint (B11-B2). */
    private static function refreshTranslator(string $fingerprint): void
    {
        /** @var Translator $translator */
        $translator = app('translator');
        self::$loadedUnder ??= new \WeakMap;
        if ((self::$loadedUnder[$translator] ?? null) !== $fingerprint) {
            $translator->setLoaded([]);
            self::$loadedUnder[$translator] = $fingerprint;
        }
    }

    private static function host(): string
    {
        $request = request();

        return $request->getHost() !== '' ? $request->getSchemeAndHttpHost() : (string) config('app.url');
    }
}
