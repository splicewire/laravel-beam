<?php

namespace Splicewire\Beam\Localization\Client;

use Illuminate\Http\Request;
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
 */
final class ClientCatalogResolver
{
    private const RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'dv', 'yi', 'ku', 'sd', 'ug', 'ckb'];

    private ?DiscoveredCatalog $discovered = null;

    /** @var array<string, string>|null */
    private ?array $refused = null;

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

        return $this->refused ??= $discovered->valid() ? $this->namespaces->admit($discovered) : [];
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
        $this->refused();
        $key = 'beam.client-catalog:'.hash('sha256', implode("\0", [
            self::host(), $discovered->payloadDigest ?? $discovered->state->value, $locale, $realm ?? '',
            LangFingerprint::translations(), LangFingerprint::config(),
        ]));
        $ttl = (int) config('beam.localization.client_catalog_ttl', 86400);
        $stored = cache()->remember($key, $ttl, fn (): array => $this->build($locale, $realm));

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

    private static function host(): string
    {
        $request = request();

        return $request->getHost() !== '' ? $request->getSchemeAndHttpHost() : (string) config('app.url');
    }
}
