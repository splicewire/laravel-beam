<?php

namespace Splicewire\Beam\Tests\Doctor;

use PHPUnit\Framework\AssertionFailedError;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Doctor\BeamCoreMigrationsAudit;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Tests\Doctor\Support\Fixtures\CleanStub\Src\CleanStubProvider;
use Splicewire\Beam\Tests\TestCase;

/**
 * make-surface 04: a package declares its publish-only stub migrations with
 * {@see BeamDoctorManifest::stubMigrations()} instead of shipping a two-method subclass. The claim that
 * licenses the migration is that the doctor's output does not move, so the equivalence case runs the
 * declaration and the subclass it replaces side by side.
 */
class StubMigrationsDeclarationTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_each_package_gets_its_own_registration_of_the_one_audit_class(): void
    {
        $manifest = new BeamDoctorManifest;
        $manifest->stubMigrations('acme/laravel-one', CleanStubProvider::class);
        $manifest->stubMigrations('acme/laravel-two', BeamServiceProvider::class);

        $registrations = $manifest->registrations();

        $this->assertCount(2, $registrations);
        $this->assertSame([StubMigrationsAudit::class, StubMigrationsAudit::class], array_map(
            fn (DoctorRegistration $r): string => $r->audit,
            $registrations,
        ));
        $this->assertSame(
            [
                ['package' => 'acme/laravel-one', 'provider' => CleanStubProvider::class],
                ['package' => 'acme/laravel-two', 'provider' => BeamServiceProvider::class],
            ],
            array_map(fn (DoctorRegistration $r): array => $r->with, $registrations),
        );
        $this->assertSame('acme/laravel-one', $registrations[0]->package);
        $this->assertFalse($registrations[0]->gate);
    }

    public function test_a_provider_that_boots_twice_supersedes_its_own_declaration(): void
    {
        $manifest = new BeamDoctorManifest;
        $manifest->stubMigrations('acme/laravel-one', CleanStubProvider::class);
        $manifest->stubMigrations('acme/laravel-one', CleanStubProvider::class);

        $this->assertCount(1, $manifest->registrations());
        $this->assertTrue($manifest->has(BeamDoctorManifest::stubMigrationsKey('acme/laravel-one')));
    }

    /**
     * The doctor renders in registration order, so a declaration has to land where the `register()`
     * call it replaces did.
     */
    public function test_a_declaration_keeps_its_place_among_ordinary_registrations(): void
    {
        $manifest = new BeamDoctorManifest;
        $manifest->register('acme/laravel-one', BeamCoreMigrationsAudit::class);
        $manifest->stubMigrations('acme/laravel-two', CleanStubProvider::class);
        $manifest->register('acme/laravel-three', StubMigrationsDeclarationTestAudit::class);

        $this->assertSame(
            ['acme/laravel-one', 'acme/laravel-two', 'acme/laravel-three'],
            array_map(fn (DoctorRegistration $r): string => $r->package, $manifest->registrations()),
        );
    }

    /**
     * The migration's licence: the declaration's findings are the subclass's findings, field for
     * field, when both name the same package and provider. Every one of the twenty subclasses returned
     * exactly its own package name and provider (measured, make-surface 04), so this pair stands for all.
     */
    public function test_the_declaration_reports_exactly_what_the_subclass_it_replaces_reported(): void
    {
        $manifest = new BeamDoctorManifest;
        $manifest->stubMigrations('splicewire/laravel-beam', BeamServiceProvider::class);

        $declared = $this->app->make(DoctorRunner::class)->run($manifest->registrations())->findings;
        $subclassed = (new BeamCoreMigrationsAudit)->run();

        $shape = fn (Finding $f): array => [$f->status, $f->check, $f->detail, $f->conclusive];

        $this->assertNotEmpty($declared);
        $this->assertSame(array_map($shape, $subclassed), array_map($shape, $declared));
        $this->assertSame('migrations are publish-only stubs (splicewire/laravel-beam)', $declared[0]->check);
    }

    public function test_an_argument_free_audit_names_the_declaration_it_is_missing_rather_than_guessing(): void
    {
        $findings = $this->app->make(StubMigrationsAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('BeamDoctorManifest::stubMigrations(', $findings[0]->detail);
    }

    public function test_the_shared_assertion_checks_the_declaration_where_beam_boots(): void
    {
        $this->app->make(BeamDoctorManifest::class)->stubMigrations('fixture/package', CleanStubProvider::class);

        $this->assertMigrationsArePublishOnlyStubs();
    }

    public function test_the_shared_assertion_fails_a_package_that_never_declared(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('fixture/package does not declare its stub migrations');

        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsPackage(): ?string
    {
        return 'fixture/package';
    }

    protected function stubMigrationsProvider(): ?string
    {
        return CleanStubProvider::class;
    }
}

/** A distinct audit class, so the ordering case has three registrations under three keys. */
class StubMigrationsDeclarationTestAudit extends StubMigrationsAudit {}
