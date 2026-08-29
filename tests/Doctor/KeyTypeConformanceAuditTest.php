<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\KeyTypeConformanceAudit;
use Splicewire\Beam\Doctor\Support\SchemaKeyIndex;
use Splicewire\Beam\Tests\TestCase;

/**
 * The key-type conformance check. Its predicates answer different questions, and the estate needed each
 * of them: **agreement** caught `~/Herd/splicewire` (uuid column, model with no `HasUuids`);
 * **convention** caught the seven sites that were perfectly self-consistent and consistently bigint — a
 * state agreement alone can never report, and the state the estate was actually in; **third-party
 * binding** caught `splicewire/tower`, where both authored statements are first-party and correct-looking
 * and the model that reads them is in `vendor/`, out of every scan's reach.
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
    public function test_a_model_declaring_has_uuids_against_a_uuid_column_is_clean(): void
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
            'database/migrations/2020_01_01_000000_create_activity_log_table.php' => "<?php\n\nSchema::create('activity_log', function (Blueprint \$table) {\n \$table->id();\n \$table->string('log_name');\n});\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    // ---- Predicate 3: foreign keys ------------------------------------------------

    public function test_it_flags_an_integer_foreign_key_pointing_at_a_uuid_key(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2024_01_01_000000_create_passkeys_table.php' => "<?php\n\nSchema::create('passkeys', function (Blueprint \$table) {\n \$table->foreignId('user_id')->constrained();\n});\n",
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
            'database/migrations/2024_01_01_000000_create_passkeys_table.php' => "<?php\n\nSchema::create('passkeys', function (Blueprint \$table) {\n \$table->foreignIdFor(User::class, 'user_id')->constrained();\n});\n",
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

    // ---- Predicate 4: third-party bindings ----------------------------------------
    //
    // The blind spot: `SchemaKeyIndex` excludes `vendor/`, so it sees every first-party model and no
    // other — and the estate's worst shape is an estate-PUBLISHED migration whose reading model is
    // third-party. `splicewire/tower` is the live instance and the fixture below is its exact shape.

    /** spatie's published form: the table name is a config lookup, never a literal. */
    private function permissionMigration(string $key): string
    {
        return "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  \$tableNames = config('permission.table_names');\n\n"
            ."  Schema::create(\$tableNames['roles'], function (Blueprint \$table) {\n"
            .'   '.$key."\n   \$table->string('name');\n  });\n }\n};\n";
    }

    /** @param  array<string, string>  $files */
    private function permissionSite(array $files, ?array $bindings = null): KeyTypeConformanceAudit
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();

        foreach ($files as $rel => $contents) {
            @mkdir(dirname($this->root.'/'.$rel), 0777, true);
            file_put_contents($this->root.'/'.$rel, $contents);
        }

        return new KeyTypeConformanceAudit(
            new SchemaKeyIndex([$this->root]),
            [],
            $bindings ?? ['roles' => ['class' => VendorRole::class, 'config' => 'permission.models.role']],
        );
    }

    /**
     * The `splicewire/tower` defect, verbatim: `roles` published uuid by
     * `splicewire/laravel-beam-accounts`, `config('permission.models.role')` never set, so the model that
     * actually inserts is spatie's integer-keyed one in `vendor/` and the write dies with
     * `null value in column "id" of relation "roles"`. Nothing first-party disagrees with anything, so no
     * other predicate can see it.
     */
    public function test_it_flags_a_uuid_table_whose_bound_model_is_third_party_and_integer_keyed(): void
    {
        $audit = $this->permissionSite([
            'database/migrations/create_permission_tables.php.stub' => $this->permissionMigration("\$table->uuid('id')->primary();"),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('third-party-key-binding', $rows[0]['kind']);
        $this->assertSame('roles', $rows[0]['table']);
        $this->assertStringContainsString(VendorRole::class, $rows[0]['detail']);
    }

    /**
     * The tower subtlety that a short-name match would have swallowed: tower DOES have a first-party
     * `Splicewire\Tower\Models\Role` with `HasUuids` — it is simply never wired into
     * `config('permission.models.role')`, so spatie instantiates its own. A same-short-name first-party
     * model must not be read as "this table is already governed", and the finding should name the model
     * that exists as the fix.
     */
    public function test_an_unwired_first_party_model_of_the_same_short_name_does_not_suppress_the_finding(): void
    {
        $audit = $this->permissionSite([
            'database/migrations/create_permission_tables.php.stub' => $this->permissionMigration("\$table->uuid('id')->primary();"),
            'src/Models/Role.php' => "<?php\n\nnamespace Splicewire\\Tower\\Models;\n\n"
                ."use Illuminate\\Database\\Eloquent\\Model;\n\nclass Role extends Model\n{\n    use HasUuids;\n}\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('third-party-key-binding', $rows[0]['kind']);
        $this->assertStringContainsString('Splicewire\Tower\Models\Role', $rows[0]['detail']);
        $this->assertStringContainsString('not wired', $rows[0]['detail']);
    }

    /**
     * The false positive that would switch this check off on day one, and the same rule the
     * `activity_log` test defends one predicate over: a package's own integer shape, published verbatim
     * and read by its own integer-keyed model, is not a defect. Six estate hosts are in exactly this
     * state — `fable`, `audiostud`, `schemastud`, `numero`, `standwell`, `thingsontv` — and all six must
     * stay silent.
     */
    public function test_an_integer_published_table_read_by_an_integer_vendor_model_is_clean(): void
    {
        $audit = $this->permissionSite([
            'database/migrations/create_permission_tables.php.stub' => $this->permissionMigration('$table->id();'),
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /**
     * The other measured false positive: a host that DID wire its own uuid model. `~/Herd/splicewire`,
     * `~/Herd/splicewire-app` and `~/Herd/beam` all point `permission.models.role` at a first-party
     * `HasUuids` class, and the predicate must read that config rather than assume the package default.
     */
    public function test_a_host_that_binds_its_own_uuid_model_in_config_is_clean(): void
    {
        config(['permission.models.role' => HostRole::class]);

        $audit = $this->permissionSite([
            'database/migrations/create_permission_tables.php.stub' => $this->permissionMigration("\$table->uuid('id')->primary();"),
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /**
     * Elimination — "a uuid table no first-party model binds must be bound by a third-party one" — is the
     * generalisation this predicate deliberately refuses. The index leaves 70–160 models per host unbound
     * simply because `ComplianceEvidence` does not pluralize to `compliance_evidence`, so every uuid table
     * one of them owns would be a fresh false positive. Only registry tables are reachable here.
     */
    public function test_a_uuid_table_with_no_model_and_no_registry_entry_is_not_flagged(): void
    {
        $audit = $this->permissionSite([
            'database/migrations/2024_01_01_000000_create_compliance_evidence_table.php' => "<?php\n\nSchema::create('compliance_evidence', function (Blueprint \$table) {\n \$table->uuid('id')->primary();\n});\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /** A registry entry naming a class this host cannot load is a skip, and the Pass line says so. */
    public function test_an_unloadable_binding_is_counted_in_the_pass_line_not_hidden(): void
    {
        $audit = $this->permissionSite(
            ['database/migrations/create_permission_tables.php.stub' => $this->permissionMigration("\$table->uuid('id')->primary();")],
            ['roles' => ['class' => 'Vendor\Absent\Role', 'config' => 'permission.models.role']],
        );

        $findings = $audit->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 third-party binding(s) skipped', $findings[0]->detail);
    }

    // ---- The index's two new resolutions ------------------------------------------

    /**
     * `Schema::create($tableNames['roles'], ...)` is indexed under the array key. Not the `Beam::table()`
     * guess the index refuses: that one invents a prefix it cannot spell, this one reads a literal key
     * that is one-to-one with the table.
     */
    public function test_the_index_resolves_a_config_array_table_name_from_its_key(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/create_permission_tables.php.stub',
            $this->permissionMigration("\$table->uuid('id')->primary();"),
        );

        $index = new SchemaKeyIndex([$this->root]);

        $this->assertSame('uuid', $index->keyTypeOf('roles'));
        $this->assertTrue($index->tables()['roles']['inferred_name']);
    }

    /**
     * Reflection reads a `vendor/` model by the same three rules the source scan uses — and must see
     * `HasUuids`, which overrides `getKeyType()` rather than setting the `$keyType` property, so a
     * property-only read would call every uuid model an integer one.
     */
    public function test_the_index_reads_a_loaded_classes_key_type_by_reflection(): void
    {
        $this->assertSame('int', SchemaKeyIndex::keyTypeOfClass(VendorRole::class));
        $this->assertSame('uuid', SchemaKeyIndex::keyTypeOfClass(HostRole::class));
        $this->assertNull(SchemaKeyIndex::keyTypeOfClass('Vendor\Absent\Role'));
    }

    // ---- Predicate 5: morph-key holder --------------------------------------------

    /** Spatie's published `model_has_roles`, in the config-array spelling every host in the estate uses. */
    private function morphPivot(string $morphKey): string
    {
        return "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  Schema::create(\$tableNames['model_has_roles'], function (Blueprint \$table) use (\$columnNames) {\n"
            ."   \$table->unsignedBigInteger(\$pivotRole);\n"
            ."   \$table->string('model_type');\n"
            .'   '.$morphKey."\n  });\n }\n};\n";
    }

    /**
     * The `~/Herd/fable` and `~/Herd/numero` defect, measured 2026-08-26: a bigint morph key against a
     * uuid-keyed holder. Nothing declares the join, so `fk-target-disagreement` sees no foreign key to
     * walk and every other predicate passes — the pivot has no primary key of its own to disagree about,
     * and `users` agrees with its own model perfectly.
     */
    public function test_it_flags_a_bigint_morph_key_against_a_uuid_keyed_holder(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_07_09_000000_create_permission_tables.php' => $this->morphPivot("\$table->unsignedBigInteger(\$columnNames['model_morph_key']);"),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('morph-key-holder-disagreement', $rows[0]['kind']);
        $this->assertSame('model_has_roles', $rows[0]['table']);
        $this->assertStringContainsString('model_has_roles.model_id', $rows[0]['detail']);
    }

    /** `~/Herd/audiostud`'s hand patch, which is the shape the other two were repaired to. */
    public function test_a_uuid_morph_key_against_a_uuid_keyed_holder_is_clean(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_07_09_000000_create_permission_tables.php' => $this->morphPivot("\$table->uuid(\$columnNames['model_morph_key']);"),
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /**
     * Widening to `string` is the repair this predicate must refuse, and the reason is the whole point of
     * the check: Postgres coerces a *bound* value happily but has no cast between two joined **columns**.
     */
    public function test_widening_the_morph_key_to_string_is_still_a_finding(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_07_09_000000_create_permission_tables.php' => $this->morphPivot("\$table->string(\$columnNames['model_morph_key']);"),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('morph-key-holder-disagreement', $rows[0]['kind']);
    }

    /**
     * The generalisation this predicate deliberately does not make. `activity_log.subject_id` is
     * polymorphic on purpose — it holds the key of any model in the estate, so there is no holder to
     * compare it against and a blanket rule would flag it on day one.
     */
    public function test_an_open_polymorphic_column_is_not_flagged(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2020_01_01_000000_create_activity_log_table.php' => "<?php\n\nSchema::create('activity_log', function (Blueprint \$table) {\n \$table->id();\n \$table->string('subject_type');\n \$table->unsignedBigInteger('subject_id');\n});\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /** No `_type` sibling means no polymorphic relation — an ordinary column that merely ends in `_id`. */
    public function test_an_id_column_without_a_type_sibling_is_not_a_morph_key(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/2026_07_09_000000_create_permission_tables.php',
            "<?php\n\nSchema::create(\$tableNames['model_has_roles'], function (Blueprint \$table) use (\$columnNames) {\n \$table->unsignedBigInteger('model_id');\n});\n",
        );

        $this->assertSame([], (new SchemaKeyIndex([$this->root]))->morphKeys());
    }

    /** A host that genuinely lets a differently-keyed model hold roles makes that a visible decision. */
    public function test_the_morph_holder_registry_is_configurable(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        @mkdir($this->root.'/app/Models', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/0001_01_01_000000_create_users_table.php',
            $this->usersMigration("\$table->uuid('id')->primary();"),
        );
        file_put_contents($this->root.'/app/Models/User.php', $this->userModel('HasFactory, HasUuids'));
        file_put_contents(
            $this->root.'/database/migrations/2026_07_09_000000_create_permission_tables.php',
            $this->morphPivot("\$table->unsignedBigInteger(\$columnNames['model_morph_key']);"),
        );

        $audit = new KeyTypeConformanceAudit(new SchemaKeyIndex([$this->root]), ['users'], [], []);

        $this->assertSame([], $audit->disagreements());
    }

    /** Laravel's own helpers declare both halves at once, and their type is fixed by which one was called. */
    public function test_the_index_reads_the_morphs_helpers(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/2026_07_09_000000_create_permission_tables.php',
            "<?php\n\nSchema::create(\$tableNames['model_has_roles'], function (Blueprint \$table) {\n \$table->uuidMorphs('model');\n});\n",
        );

        $keys = (new SchemaKeyIndex([$this->root]))->morphKeys();

        $this->assertCount(1, $keys);
        $this->assertSame('model_id', $keys[0]['column']);
        $this->assertSame('uuid', $keys[0]['type']);
    }

    // ---- The second pass: a create is not the last word (ticket 144) --------------

    /** The `~/Herd/fable-legacy` shape verbatim: a raw DROP, a Blueprint re-add, and a `down()` that lies. */
    private function permissionUuidFix(): string
    {
        return "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  DB::statement('ALTER TABLE model_has_roles DROP COLUMN model_id');\n"
            ."  Schema::table('model_has_roles', function (Blueprint \$table) {\n"
            ."   \$table->uuid('model_id');\n"
            ."   \$table->primary(['role_id', 'model_id', 'model_type']);\n  });\n }\n"
            ." public function down(): void {\n"
            ."  Schema::table('model_has_roles', function (Blueprint \$table) {\n"
            ."   \$table->unsignedBigInteger('model_id');\n  });\n }\n};\n";
    }

    /** @param  array<string, string>  $extra */
    private function morphSite(string $morphKey, array $extra = []): KeyTypeConformanceAudit
    {
        return $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2025_09_05_060524_create_permission_tables.php' => $this->morphPivot($morphKey),
            ...$extra,
        ]);
    }

    /**
     * The defect this pass exists for. Without it the audit reports a gap the database does not have, at
     * a host with nothing to repair — the one failure mode that reliably gets a check switched off.
     */
    public function test_a_follow_on_alter_settles_a_morph_key_the_create_got_wrong(): void
    {
        $audit = $this->morphSite("\$table->unsignedBigInteger(\$columnNames['model_morph_key']);", [
            'database/migrations/2026_05_15_011723_fix_permission_tables_for_uuid.php' => $this->permissionUuidFix(),
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /** Order is the filename's stamp, not the alphabet: an *older* repair cannot overrule a newer create. */
    public function test_an_alter_older_than_the_create_does_not_win(): void
    {
        $audit = $this->morphSite("\$table->unsignedBigInteger(\$columnNames['model_morph_key']);", [
            'database/migrations/2024_01_01_000000_fix_permission_tables_for_uuid.php' => $this->permissionUuidFix(),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('morph-key-holder-disagreement', $rows[0]['kind']);
    }

    /** A repair that only exists in `down()` is not a repair — it is the statement that undoes one. */
    public function test_a_declaration_in_down_is_not_read(): void
    {
        $audit = $this->morphSite("\$table->uuid(\$columnNames['model_morph_key']);", [
            'database/migrations/2026_05_15_011723_revert.php' => "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n  //\n }\n"
                ." public function down(): void {\n"
                ."  Schema::table('model_has_roles', function (Blueprint \$table) {\n"
                ."   \$table->unsignedBigInteger('model_id');\n  });\n }\n};\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    /** A `->change()` says the same thing a drop-and-re-add says, and is read the same way. */
    public function test_a_change_call_is_read_as_a_declaration(): void
    {
        $audit = $this->morphSite("\$table->uuid(\$columnNames['model_morph_key']);", [
            'database/migrations/2026_05_15_011723_widen.php' => "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
                ."  Schema::table('model_has_roles', function (Blueprint \$table) {\n"
                ."   \$table->unsignedBigInteger('model_id')->change();\n  });\n }\n};\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('morph-key-holder-disagreement', $rows[0]['kind']);
        $this->assertStringContainsString('2026_05_15_011723_widen.php', $rows[0]['detail']);
    }

    /** Raw DDL that states a type outright is readable, and it is read. */
    public function test_a_raw_alter_column_type_is_read(): void
    {
        $audit = $this->morphSite("\$table->uuid(\$columnNames['model_morph_key']);", [
            'database/migrations/2026_05_15_011723_downgrade.php' => "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
                ."  DB::statement('ALTER TABLE model_has_roles ALTER COLUMN model_id TYPE bigint');\n }\n};\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('morph-key-holder-disagreement', $rows[0]['kind']);
    }

    /**
     * The honest limit, and the whole reason this pass is not a half-replay: `~/Herd/prahsys-gateway`
     * migrates the `organizations` primary key by renaming a different column into its place. No reader
     * short of an evaluator can say what type that leaves behind — so the column is **un-known** and
     * skipped, rather than reported from a create the same file just contradicted.
     */
    public function test_an_unclassifiable_raw_alter_unknows_the_column_instead_of_guessing(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/0001_01_01_000000_create_users_table.php',
            $this->usersMigration("\$table->uuid('id')->primary();"),
        );
        file_put_contents(
            $this->root.'/database/migrations/2026_04_02_200000_change_users_pk_to_string.php',
            "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  DB::statement('ALTER TABLE users DROP COLUMN id');\n"
            ."  DB::statement('ALTER TABLE users RENAME COLUMN external_id TO id');\n }\n};\n",
        );

        $index = new SchemaKeyIndex([$this->root]);

        $this->assertNull($index->keyTypeOf('users'));
        $this->assertSame(1, $index->unreadableAlterationCount());
        $this->assertSame([], (new KeyTypeConformanceAudit($index, ['users']))->disagreements());
    }

    /** An alteration amends what a create already indexed; it never invents a table nobody creates. */
    public function test_an_alter_on_an_uncreated_table_adds_nothing(): void
    {
        $this->root = sys_get_temp_dir().'/beam-keytype-'.uniqid();
        @mkdir($this->root.'/database/migrations', 0777, true);
        file_put_contents(
            $this->root.'/database/migrations/2026_04_02_200000_touch_a_stranger.php',
            "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  Schema::table('somebody_elses_table', function (Blueprint \$table) {\n"
            ."   \$table->uuid('id')->change();\n  });\n }\n};\n",
        );

        $index = new SchemaKeyIndex([$this->root]);

        $this->assertSame([], $index->tables());
        $this->assertNull($index->keyTypeOf('somebody_elses_table'));
    }

    /** A primary key repaired by ALTER is a repaired primary key, under the convention predicate too. */
    public function test_an_altered_primary_key_settles_the_convention_predicate(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration('$table->id();'),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_05_15_011723_users_to_uuid.php' => "<?php\n\nreturn new class extends Migration {\n public function up(): void {\n"
                ."  DB::statement('ALTER TABLE users ALTER COLUMN id TYPE uuid');\n }\n};\n",
        ]);

        $this->assertSame([], $audit->disagreements());
    }

    // ---- Reach: the second create verb, the stated FK target, and the unparsed counter (191) -------
    //
    // Every one of these is a case where the reader **succeeded and looked at nothing**. The estate's
    // conformance program tells every root to migrate `Schema::create` → `ConvergentTable::named`, and
    // this reader knew only the first verb — so compliance removed a root from the audit's reach and the
    // most compliant root was the blindest: 28 tables indexed of 164 at `~/Herd/splicewire-app`, 5 of 29
    // at `~/Herd/tower`, both reported as a clean Pass.

    private function convergentUsersMigration(string $name, string $key): string
    {
        return "<?php\n\nuse Rushing\\SchemaConvergence\\ConvergentTable;\n\nreturn new class extends Migration {\n public function up(): void {\n"
            ."  ConvergentTable::named({$name})\n   ->define(function (Blueprint \$table) {\n"
            .'    '.$key."\n    \$table->string('email');\n   })\n   ->matches();\n }\n};\n";
    }

    /** The verb the whole estate is being told to migrate to, which this reader could not see at all. */
    public function test_it_indexes_a_convergent_table_create(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->convergentUsersMigration("'users'", "\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory'),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('pk-model-disagreement', $rows[0]['kind']);
        $this->assertSame('users', $rows[0]['table']);
    }

    /** The config-array name form, for the convergent verb as well as `Schema::create`. */
    public function test_it_indexes_a_convergent_table_create_named_from_a_config_array(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->convergentUsersMigration("\$tableNames['users']", "\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory'),
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('users', $rows[0]['table']);
    }

    /**
     * `~/Herd/numero`'s three bigint columns explicitly constrained to a uuid `users.id`. The target was
     * derived by pluralising the column prefix — `purchaser_users`, a table that does not exist — so the
     * predicate skipped on an unknown target and reported nothing, while the migration named `users` on
     * the same line.
     */
    public function test_it_reads_the_constrained_argument_rather_than_pluralising_the_prefix(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_07_01_000200_create_commerce_intents_table.php' => "<?php\n\nSchema::create('commerce_intents', function (Blueprint \$table) {\n"
                ." \$table->foreignId('purchaser_user_id')->nullable()->constrained('users')->nullOnDelete();\n});\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('fk-target-disagreement', $rows[0]['kind']);
        $this->assertStringContainsString('commerce_intents.purchaser_user_id', $rows[0]['detail']);
        $this->assertStringContainsString('`users`', $rows[0]['detail']);
    }

    /** A stated target with no `_id` suffix — `invited_by`, `signed_by`, `created_by` are a third of them. */
    public function test_a_stated_target_needs_no_id_suffix(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_07_01_000200_create_invitations_table.php' => "<?php\n\nSchema::create('invitations', function (Blueprint \$table) {\n"
                ." \$table->foreignId('invited_by')->constrained('users');\n});\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('invitations.invited_by', $rows[0]['detail']);
    }

    /**
     * The half of this repair that widening reach *found*: a column that constrains nothing states no
     * target, and the pluralised guess names a real but **different** table. `~/Herd/splicewire-app`'s
     * `stripe_subscription_items.subscription_id` references `stripe_subscriptions` (bigint), its own
     * docblock says so in words, and the guess pointed at the uuid-keyed `subscriptions` — a live,
     * shipping false positive before this change.
     */
    public function test_a_foreign_id_that_constrains_nothing_states_no_target_and_is_counted(): void
    {
        $audit = $this->site([
            'database/migrations/2026_08_13_222429_create_subscriptions_table.php' => "<?php\n\nSchema::create('subscriptions', function (Blueprint \$table) {\n \$table->uuid('id')->primary();\n});\n",
            'database/migrations/2026_08_13_222434_create_stripe_subscriptions_table.php' => "<?php\n\nSchema::create('stripe_subscriptions', function (Blueprint \$table) {\n \$table->id();\n});\n",
            'database/migrations/2026_08_13_222435_create_stripe_subscription_items_table.php' => "<?php\n\nSchema::create('stripe_subscription_items', function (Blueprint \$table) {\n"
                ." \$table->id();\n \$table->foreignId('subscription_id');\n});\n",
        ]);

        $this->assertSame([], $audit->disagreements());

        $this->assertStringContainsString(
            '1 foreignId-family column(s) skipped',
            $audit->run()[0]->detail,
        );
    }

    /** A bare `constrained()` is Laravel's own derivation, not this reader's guess, so it still counts. */
    public function test_a_bare_constrained_call_still_derives_the_target_the_way_laravel_does(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2024_01_01_000000_create_passkeys_table.php' => "<?php\n\nSchema::create('passkeys', function (Blueprint \$table) {\n \$table->foreignId('user_id')->constrained();\n});\n",
        ]);

        $rows = $audit->disagreements();

        $this->assertCount(1, $rows);
        $this->assertSame('fk-target-disagreement', $rows[0]['kind']);
    }

    /**
     * The durable half. A create whose name is a method call was in **none** of the counts: not checked,
     * and not reported as unchecked. The estate's own `create_teams_table` — the very table ticket 191
     * was opened about — is `ConvergentTable::named($this->target())`, and it is named here now.
     */
    public function test_a_create_it_cannot_name_is_reported_rather_than_absorbed(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'database/migrations/2026_08_25_135336_create_teams_table.php' => $this->convergentUsersMigration('$this->target()', "\$table->uuid('id')->primary();"),
        ]);

        $findings = $audit->run();

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 create(s) skipped', $findings[0]->detail);
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertStringContainsString('2026_08_25_135336_create_teams_table.php', $findings[1]->detail);
    }

    /**
     * The counter must not cry about the instrument reading itself. An unscoped version of it counted 24
     * hits in the estate's own scanners, whose regex literals spell the verb without creating anything.
     */
    public function test_the_unparsed_counter_ignores_source_that_merely_names_the_verb(): void
    {
        $audit = $this->site([
            'database/migrations/0001_01_01_000000_create_users_table.php' => $this->usersMigration("\$table->uuid('id')->primary();"),
            'app/Models/User.php' => $this->userModel('HasFactory, HasUuids'),
            'src/MigrationTableScanner.php' => "<?php\n\nclass MigrationTableScanner {\n public \$pattern = '/Schema::create\\(/';\n public \$other = '/ConvergentTable::named\\(/';\n}\n",
        ]);

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('0 create(s) skipped', $findings[0]->detail);
    }
}

/** Stands in for `Spatie\Permission\Models\Role`: a plain Eloquent model, auto-incrementing by default. */
class VendorRole extends Model
{
    protected $table = 'roles';
}

/** Stands in for `App\Models\Role` at `~/Herd/splicewire`: the host's own uuid-keyed override. */
class HostRole extends VendorRole
{
    use HasUuids;
}
