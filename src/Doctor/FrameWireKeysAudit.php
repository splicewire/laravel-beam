<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\Frame\Vocabulary\WidgetVocabulary;
use Schemastud\Frame\Wire\EmittedWireKeys;

/** Compares PHP's client wire keys with the host's committed reader union, without a request or schema walk. */
class FrameWireKeysAudit implements DoctorAudit
{
    public const CHECK_WIRE_KEYS = 'frame.wire-keys';

    /** @return list<Finding> */
    public function run(): array
    {
        $vocabulary = WidgetVocabulary::fromConfig();
        if (! $vocabulary->isReadable()) {
            return [Finding::inconclusive(self::CHECK_WIRE_KEYS, "Widget vocabulary [{$vocabulary->path}] is {$vocabulary->state->value}; run pnpm frame:vocabulary --write to generate it. No widget names were checked. The file does not establish freshness against the running JS build.")];
        }
        $reads = $vocabulary->wireKeys();
        if ($reads === null) {
            return [Finding::inconclusive(self::CHECK_WIRE_KEYS, "Widget vocabulary [{$vocabulary->path}] has no reads list; run pnpm frame:vocabulary --write to generate it. No wire keys were compared.")];
        }
        $missing = array_diff(EmittedWireKeys::clientKeys(), $reads);
        $findings = [];
        foreach (array_unique($missing) as $key) {
            $findings[] = Finding::fail(self::CHECK_WIRE_KEYS, "Client wire key [{$key}] has no reader in widget vocabulary [{$vocabulary->path}]. Include the host's --readers modules and run pnpm frame:vocabulary --write.");
        }

        return $findings ?: [Finding::pass(self::CHECK_WIRE_KEYS, 'Every PHP client wire key has a reader in the host vocabulary; freshness against the running JS build is not checked.')];
    }
}
