<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\AssertionFailedError;
use Splicewire\Beam\Localization\Ratchet\LiteralTextCensus;
use Splicewire\Beam\Localization\Ratchet\TextRatchet;
use Splicewire\Beam\Testing\AssertsTextRatchet;
use Splicewire\Beam\Tests\TestCase;

/**
 * localization 12 — the PHP ratchet. User-facing literals in a package's response-reaching calls are counted per file
 * against its committed `lang-ratchet.json`; a count may only fall. Beam's own baseline is committed and compared here.
 */
class TextRatchetTest extends TestCase
{
    use AssertsTextRatchet;

    private function package(string $source): string
    {
        $root = sys_get_temp_dir().'/beam-ratchet-'.getmypid().'-'.bin2hex(random_bytes(4));
        @mkdir($root.'/src', 0777, true);
        file_put_contents($root.'/src/Thing.php', $source);

        return $root;
    }

    public function test_beams_own_literals_never_rise(): void
    {
        $this->assertTextRatchet(dirname(__DIR__, 2));
    }

    public function test_it_counts_the_closed_list_and_never_a_key(): void
    {
        $source = <<<'PHP'
        <?php
        use Illuminate\Notifications\Messages\MailMessage;
        class Paid extends Notification {
            public function f() {
                abort(404, 'Not here');
                abort_unless($ok, 403, __('beam::errors.denied'));
                throw new NotFoundHttpException('Gone');
                throw ValidationException::withMessages(['email' => 'Bad email', 'name' => [__('x::y.z')]]);
                return response()->json(['message' => 'Saved', 'id' => 'abc']);
            }
            public function toMail($n) {
                return (new MailMessage)->subject('Invoice paid')->greeting('Hello!')->line('Thanks '.$n)->action('View', 'https://x');
            }
            public function toArray($n) { return ['type' => 'invoice.paid', 'title' => 'Invoice paid', 'k' => __('a::b.c')]; }
        }
        PHP;

        // abort 1, HttpException 1, withMessages 1, json message 1, mail 4 (subject, greeting, line, action's text),
        // notification values 2; the four keys are never counted.
        $this->assertSame(10, LiteralTextCensus::countFile($source));
    }

    public function test_raising_a_count_fails_and_a_fall_must_be_rebaselined(): void
    {
        $root = $this->package("<?php abort(404, 'Missing');");
        TextRatchet::write($root);
        $this->assertTextRatchet($root);

        file_put_contents($root.'/src/Thing.php', "<?php abort(404, 'Missing'); abort(403, 'Denied');");
        $this->assertSame(['src/Thing.php' => [1, 2]], TextRatchet::compare($root)['rose']);
        try {
            $this->assertTextRatchet($root);
            $this->fail('A raised count must fail the comparison.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('ROSE', $e->getMessage());
        }

        file_put_contents($root.'/src/Thing.php', "<?php abort(404, __('pkg::errors.missing'));");
        $this->assertSame(['src/Thing.php' => [1, 0]], TextRatchet::compare($root)['fell']);

        TextRatchet::write($root);
        $this->assertTextRatchet($root);
    }

    public function test_the_command_compares_without_writing_and_names_its_population(): void
    {
        $root = $this->package("<?php abort(404, 'Missing');");

        $this->assertSame(1, Artisan::call('beam:lang:ratchet', ['root' => $root]));
        $this->assertFileDoesNotExist($root.'/'.TextRatchet::FILE);

        $this->assertSame(0, Artisan::call('beam:lang:ratchet', ['root' => $root, '--write' => true]));
        $this->assertStringContainsString('Population: ', Artisan::output());
        $this->assertSame(0, Artisan::call('beam:lang:ratchet', ['root' => $root]));
        $this->assertStringContainsString('Blade views are not read', (string) file_get_contents($root.'/'.TextRatchet::FILE));
    }
}
