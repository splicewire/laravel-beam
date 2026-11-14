<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\DiscoveryState;

/**
 * The host's discovered client catalog (localization 16; ruling 02 §3). It reports, never silently:
 * - whether `resources/beam/messages.json` is valid, missing or invalid (and why);
 * - which client namespaces were refused under ruling 01 §3;
 * - how many client keys it admits.
 *
 * A missing or invalid catalog is a WARN, because the client then renders compiled English that never counts as
 * coverage. Advisory, like the other localization checks.
 */
final class LocalizationClientCatalogAudit implements DoctorAudit
{
    public const CHECK = 'localization.client-catalog';

    public function __construct(private ClientCatalogResolver $client) {}

    public function run(): array
    {
        $discovered = $this->client->discovered();
        if ($discovered->state !== DiscoveryState::Valid) {
            return [Finding::warn(self::CHECK, sprintf(
                'The discovered client catalog is %s: %s Client keys are not in the key catalog, its exports or its coverage until it is valid; the client renders compiled English, which never counts as coverage.',
                $discovered->state->value, (string) $discovered->problem,
            ))];
        }
        $findings = [];
        foreach ($this->client->refused() as $namespace => $why) {
            $findings[] = Finding::warn(self::CHECK, "Client namespace [{$namespace}] was refused: {$why} Its keys are not served or listed.");
        }
        $findings[] = Finding::pass(self::CHECK, sprintf(
            '%d client key(s) from %d package(s) admitted from %s (digest %s).',
            count($this->client->defaults()), count($discovered->packages), $discovered->path, (string) $discovered->payloadDigest,
        ));

        return $findings;
    }
}
