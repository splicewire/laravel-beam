<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * Record-versioning seam test transform — a deterministic local custom-transform
 * invocable the eager drain's custom-transform rung resolves and runs. It derives
 * the new non-nullable `slug` (which no cheap rung could fill) from the title,
 * producing a candidate that conforms to {@see FixtureTransformV2}.
 *
 * Pinned on the v2 schema via #[MigrateWith(self::class)] and registered into the
 * migrator's TransformRegistry by the test — array in, array out (the popcorn
 * contract). Fully deterministic: NO LLM, so the eager drain is reproducible.
 */
final class FixtureSlugTransform implements Invocable
{
    public function name(): string
    {
        return self::class;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    public function invoke(array $input): array
    {
        $title = (string) ($input['title'] ?? '');

        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? '', '-'));

        return array_merge($input, [
            'slug' => $slug !== '' ? $slug : 'untitled',
        ]);
    }
}
