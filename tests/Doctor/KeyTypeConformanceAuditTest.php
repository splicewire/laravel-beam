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
