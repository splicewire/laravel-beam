<?php

namespace Splicewire\Beam\Tests\Particle\Backing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\PaginationState;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Particle\Backing\CompositeBacking;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Tests\TestCase;

class EloquentBackingCursorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cursor_arm_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('arm');
            $table->integer('rank');
        });

        foreach ([['alpha', 9], ['alpha', 8], ['beta', 7], ['beta', 6]] as [$arm, $rank]) {
            CursorArmRow::query()->create(compact('arm', 'rank'));
        }
    }

    protected function tearDown(): void
    {
        PaginationState::resolveUsing($this->app);

        parent::tearDown();
    }

    public function test_an_explicit_first_page_ignores_the_outer_request_cursor(): void
    {
        CursorPaginator::currentCursorResolver(fn () => new Cursor(['outer' => null]));

        $page = (new CursorArmBacking('alpha'))->records([], null, 1);

        $this->assertSame([9], array_map(fn ($row) => $row->rank, $page->items()));
        $this->assertNotNull($page->nextCursor());
    }

    public function test_an_explicit_arm_cursor_advances_despite_an_unrelated_outer_cursor(): void
    {
        $backing = new CursorArmBacking('alpha');
        $cursor = $backing->records([], null, 1)->nextCursor()?->encode();
        $this->assertNotNull($cursor);
        CursorPaginator::currentCursorResolver(fn () => new Cursor(['outer' => null]));

        $page = $backing->records([], $cursor, 1);

        $this->assertSame([8], array_map(fn ($row) => $row->rank, $page->items()));
        $this->assertNull($page->nextCursor());
    }

    public function test_composite_paging_keeps_untouched_and_empty_eloquent_arms_independent(): void
    {
        $composite = new CompositeBacking([
            'alpha' => new CursorArmBacking('alpha'),
            'beta' => new CursorArmBacking('beta'),
            'empty' => new CursorArmBacking('empty'),
        ], 'rank');
        $cursor = null;
        $pages = [];

        do {
            // Laravel resolves the current request's outer cursor when a paginator is not given one.
            CursorPaginator::currentCursorResolver(fn () => Cursor::fromEncoded($cursor));
            $page = $composite->records([], $cursor, 1);
            $pages[] = array_map(fn ($row) => $row->rank, $page->items());
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null && count($pages) < 6);

        $this->assertSame([[9], [8], [7], [6]], $pages);
        $this->assertNull($cursor);
    }
}

class CursorArmRow extends Model
{
    public $timestamps = false;

    protected $table = 'cursor_arm_rows';

    protected $guarded = [];
}

class CursorArmBacking extends EloquentBacking
{
    public function __construct(private readonly string $arm)
    {
        parent::__construct(CursorArmRow::class);
    }

    public function query(array $filters): Builder
    {
        return parent::query($filters)->where('arm', $this->arm)->orderByDesc('rank')->orderByDesc('id');
    }
}
