<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Surgeon\HouseStyleStripOperation;

/**
 * Determinism proof for the estate house-style strip (relocated DOWN from surgeon — it hard-codes the
 * "no strict_types/final/readonly" estate opinion, so it is beam POLICY over surgeon's generic byte-splice
 * mechanism). A fixture carrying all three forbidden constructs (`declare(strict_types=1);`,
 * `final readonly class`, a `public readonly int $x` promoted param, and a `final public function bar()`)
 * plans + applies to source that has all three removed and NOTHING else changed — the byte-for-byte
 * preservation property the token-aware planner + shared splicer guarantee.
 *
 * Pure unit test (no Laravel boot): the operation is model-in → source-out, run over a disposable temp
 * tree so it never mutates the committed fixtures.
 */
class HouseStyleStripTest extends TestCase
{
    private function fixture(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain;

use App\Contracts\Bar;

final readonly class Foo extends Bar
{
    public int $count = 0;

    public function __construct(
        public readonly int $x,
        protected readonly Bar $bar,
    ) {}

    final public function bar(): int
    {
        return $this->x;
    }

    public function readonly(): bool
    {
        return $this->readonly;
    }
}
PHP;
    }

    private function tmp(string $label): string
    {
        $dir = sys_get_temp_dir().'/beam-house-style-'.$label.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    public function test_it_strips_all_three_constructs_and_changes_nothing_else(): void
    {
        $op = new HouseStyleStripOperation;
        $source = $this->fixture();

        $plan = $op->planSource($source);
        $result = $op->applyToSource($source, $plan);

        // All three constructs gone.
        $this->assertStringNotContainsString('declare(strict_types=1);', $result);
        $this->assertStringNotContainsString('final ', $result);
        $this->assertStringNotContainsString('readonly int', $result);
        $this->assertStringNotContainsString('readonly Bar', $result);

        // The specific `final readonly class` → `class` collapse (no residual double space).
        $this->assertStringContainsString('class Foo extends Bar', $result);
        $this->assertStringNotContainsString('final readonly', $result);
        $this->assertStringNotContainsString('readonly class', $result);

        // Promoted params keep visibility + type, lose only readonly.
        $this->assertStringContainsString('public int $x', $result);
        $this->assertStringContainsString('protected Bar $bar', $result);

        // Method-level final gone, method body/signature intact.
        $this->assertStringContainsString('public function bar(): int', $result);
        $this->assertStringNotContainsString('final public function', $result);

        // Everything NOT a forbidden construct is byte-identical — the strip only deleted the four spans.
        $this->assertStringContainsString('namespace App\Domain;', $result);
        $this->assertStringContainsString('use App\Contracts\Bar;', $result);
        $this->assertStringContainsString('public int $count = 0;', $result);
        $this->assertStringContainsString('return $this->x;', $result);
        $this->assertStringContainsString('return $this->readonly;', $result);

        // The method NAMED readonly is NOT touched (the keyword-token modifier guard) — no over-strip.
        $this->assertStringContainsString('public function readonly(): bool', $result);

        // Result is still valid PHP.
        $tmp = tempnam(sys_get_temp_dir(), 'hstyle').'.php';
        file_put_contents($tmp, $result);
        $lint = shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1');
        @unlink($tmp);
        $this->assertStringContainsString('No syntax errors', (string) $lint);
    }

    public function test_it_reconstructs_the_exact_expected_source(): void
    {
        $op = new HouseStyleStripOperation;
        $source = $this->fixture();

        $result = $op->applyToSource($source, $op->planSource($source));

        $expected = <<<'PHP'
<?php

namespace App\Domain;

use App\Contracts\Bar;

class Foo extends Bar
{
    public int $count = 0;

    public function __construct(
        public int $x,
        protected Bar $bar,
    ) {}

    public function bar(): int
    {
        return $this->x;
    }

    public function readonly(): bool
    {
        return $this->readonly;
    }
}
PHP;

        $this->assertSame($expected, $result);
    }

    public function test_it_leaves_declare_ticks_alone(): void
    {
        $op = new HouseStyleStripOperation;
        $source = "<?php\n\ndeclare(ticks=1);\n\nfunction f() {}\n";

        $result = $op->applyToSource($source, $op->planSource($source));

        $this->assertSame($source, $result);
    }

    public function test_it_leaves_readonly_named_arguments_alone(): void
    {
        // `readOnly:` in a named argument still tokenizes as T_READONLY (keywords are case-insensitive),
        // but it is an argument NAME, not a modifier — the guard's `:` rejection must leave it untouched
        // (the real-world shape: `new ParticleResource(..., readOnly: true)` in a host provider).
        $op = new HouseStyleStripOperation;
        $source = "<?php\n\nnew Foo(\n    key: 'tokens',\n    readOnly: true,\n);\n\nbar(readonly: false, final: 1);\n";

        $plan = $op->planSource($source);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame($source, $op->applyToSource($source, $plan));
    }

    public function test_it_is_idempotent(): void
    {
        $op = new HouseStyleStripOperation;
        $once = $op->applyToSource($this->fixture(), $op->planSource($this->fixture()));

        $plan = $op->planSource($once);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame($once, $op->applyToSource($once, $plan));
    }

    public function test_audit_emits_one_fixable_finding_per_offending_file(): void
    {
        $dir = $this->tmp('audit');

        try {
            $this->write($dir.'/Offending.php', $this->fixture());
            $this->write($dir.'/Clean.php', "<?php\n\nnamespace App;\n\nclass Clean {}\n");

            $findings = (new HouseStyleAudit([$dir]))->suggestOperations();
            $fixable = array_values(array_filter($findings, fn ($f) => $f->isFixable()));

            $this->assertCount(1, $fixable);
            $this->assertSame('house-style.forbidden-modifier', $fixable[0]->finding->check);
            $this->assertSame('house-style-strip', $fixable[0]->suggestion->kind);
            $this->assertSame($dir.'/Offending.php', $fixable[0]->suggestion->payload['file']);
        } finally {
            $this->rrmdir($dir);
        }
    }
}
