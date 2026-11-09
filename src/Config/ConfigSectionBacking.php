<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;

/**
 * Backs `beam-config` (ADR-0245): the registered Config Sections, read through {@see ConfigSectionReader}.
 * Scaffolded by `splicewire:beam:make:particle-backing ConfigSectionBacking --stream --resolves`.
 *
 * A section is not a row in any table, so it lists by stream and resolves one by root; it declines
 * `QueriesRecords` (no builder) and `WritesRecords` (nothing is writable until the override store exists). Rows
 * are ordered by root and paged with a keyset cursor on it.
 *
 * Each row is read on demand: a section reads its files and `config()` when asked, so a list costs one read per
 * section on the page and a resolve costs one.
 */
class ConfigSectionBacking implements ResolvesRecord, StreamsRecords
{
    public function __construct(
        protected ConfigSectionRegistry $sections,
        protected ConfigSectionReader $reader,
    ) {}

    /** @return CursorPaginatorContract<int, ConfigSectionData> */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $perPage = max(1, $perPage);
        $sections = $this->sections->all();
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        if ($search !== '') {
            $sections = array_values(array_filter(
                $sections,
                fn (RegisteredConfigSection $section): bool => str_contains(mb_strtolower($section->root().' '.$section->section::declaration()->title.' '.$section->owner), $search),
            ));
        }

        $decoded = Cursor::fromEncoded($cursor);
        $after = $decoded === null ? null : (string) $decoded->parameter('id');
        $start = 0;

        if ($after !== null) {
            foreach ($sections as $index => $section) {
                if (strcmp($section->root(), $after) > 0) {
                    break;
                }

                $start = $index + 1;
            }
        }

        // perPage + 1 so the paginator can report a following page.
        $page = array_map(
            fn (RegisteredConfigSection $section): ConfigSectionData => $this->reader->read($section),
            array_slice($sections, $start, $perPage + 1),
        );

        return new CursorPaginator($page, $perPage, $decoded, ['parameters' => ['id']]);
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        $section = $this->sections->get($id);

        return $section === null ? null : new ResolvedRecord(record: $this->reader->read($section));
    }
}
