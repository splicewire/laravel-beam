<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\KeyTypeConformanceAudit;
use Splicewire\Beam\Doctor\Support\SchemaKeyIndex;
use Splicewire\Beam\Tests\TestCase;

/**
 * The key-type conformance check. Its two predicates answer two different questions, and the estate
 * needed both: **agreement** caught `~/Herd/splicewire` (uuid column, model with no `HasUuids`), while
 * **convention** caught the seven sites that were perfectly self-consistent and consistently bigint —
 * a state agreement alone can never report, and the state the estate was actually in.
 */
class KeyTypeConformanceAuditTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*/*') as $f) {
                @unlink((string) $f);
            }
            foreach ((array) glob($this->root.'/*') as $d) {
                @rmdir((string) $d);
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    /** @param  array<string, string>  $files  relative path => contents */
    private function site(array $files): KeyTypeConformanceAudit
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();

        foreach ($files as $rel => $contents) {
            @mkdir(dirname($this->root.'/'.$rel), 0777, true);
            file_put_contents($this->root.'/'.$rel, $contents);
        }

        return new KeyTypeConformanceAudit(new SchemaKeyIndex([$this->root]), ['users']);
    }

    private function usersMigration(string $key): string
    {
        return "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  Schema::create('users', function (Blueprint \$table) {\n"
            .'   '.$key."\n   \$table->string('email');\n  });\n }\n};\n";
    }

    private function userModel(string $traits): string
    {
        return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\n"
            ."class User extends Model\n{\n    use {$traits};\n}\n";
    }

    // ---- Predicate 1: agreement ---------------------------------------------------

    /** The `~/Herd/splicewire` defect: uuid column, model silently assuming an auto-incrementing key. */
    public function test_it_flags_a_uuid_column_whose_model_declares_no_key_type(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory'),
        ]);

        $rows = $audit->disagreements();

        $this->assertNotEmpty($rows);
        $this->assertSame('pk-model-disagreement', $rows[0]['kind']);
        $this->assertStringContainsString('User', $rows[0]['detail']);
    }

    /**
     * Silence is read as `int`, not as unknown — that is Eloquent's documented default, and treating it
     * as unknown would have made this audit blind to the only live instance the estate had.
     */
    public function test_a_model_declaring_HasUuids_against_a_uuid_column_is_clean(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    // ---- Predicate 2: convention --------------------------------------------------

    /**
     * The load-bearing case. Column and model agree perfectly — and are both wrong. Seven sites were in
     * exactly this state, inherited from a starter that shipped `$table->id()`, and no agreement-based
     * check could ever have said a word about them.
     */
    public function test_it_flags_a_consistently_bigint_users_table(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration('$table->id();'),
            'app/Models/User.php' => $this->userModel('HasFactory'),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('identity-key-convention', $rows[0]['kind']);
    }

    /** A host genuinely retrofitting onto a foreign bigint table opts out in config — a visible decision. */
    public function test_the_convention_list_is_configurable(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/0001_01_01_000000_create_users_table.php',
            $this->usersMigration('$table->id();'),
        );

        $audit = new KeyTypeConformanceAudit(new SchemaKeyIndex([$this->root]), []);

        $this->assertSame([], $audit->disagreements());
    }

    /**
     * Why the convention list is a list and not "everything must be uuid": `activity_log` is spatie's
     * shape, vendored verbatim, and legitimately auto-increments. Mandating uuid estate-wide would flag
     * every third-party table on day one, which is how a check gets switched off.
     */
    public function test_a_legitimately_bigint_vendor_table_is_not_flagged(): void
    {
        $audit = $this->site([
            'database/migrations/2020_01_01_000000_create_activity_log_table.php' =>
                "<?php\n\nSchema::create('activity_log', function (Blueprint \$table) {\n \$table->id();\n \$table->string('log_name');\n});\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    // ---- Predicate 3: foreign keys ------------------------------------------------

    public function test_it_flags_an_integer_foreign_key_pointing_at_a_uuid_key(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2024_01_01_000000_create_passkeys_table.php' =>
                "<?php\n\nSchema::create('passkeys', function (Blueprint \$table) {\n \$table->foreignId('user_id')->constrained();\n});\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('fk-target-disagreement', $rows[0]['kind']);
        $this->assertStringContainsString('passkeys.user_id', $rows[0]['detail']);
    }

    /**
     * `foreignIdFor(Model::class)` derives its column type FROM the model, so it is correct exactly when
     * the model is — which predicate 1 already governs. Flagging it too would report one defect twice and
     * point the reader at the wrong file.
     */
    public function test_it_does_not_flag_foreign_id_for_which_derives_from_the_model(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2024_01_01_000000_create_passkeys_table.php' =>
                "<?php\n\nSchema::create('passkeys', function (Blueprint \$table) {\n \$table->foreignIdFor(User::class, 'user_id')->constrained();\n});\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    public function test_a_clean_site_passes_and_states_what_it_checked(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
        ]);

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('table(s) and', $findings[0]->detail);
    }
}
