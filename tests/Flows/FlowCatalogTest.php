<?php

use Rushing\Popcorn\Registries\Registrant;
use Splicewire\Beam\Flows\DeclaresFlows;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\FlowState;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Flows\ListsFlows;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/** A fixture package's static declaration class — pure, built with `new`, no app. */
class FixtureFlows implements DeclaresFlows
{
    use ListsFlows;

    public static function flows(): array
    {
        return [
            new FlowDeclaration('account', 'Account', [], AlwaysOn::class, null, []),
            new FlowDeclaration('account.security', 'Security', ['security.edit'], AlwaysOn::class, null, ['owner', 'solo']),
            new FlowDeclaration('account.security.passkeys', 'Passkeys', ['security.edit'], AlwaysOn::class, null, ['owner']),
        ];
    }
}

// ── FlowDeclaration: grammar, required fields, surface rule, parent ──────────────────────────────

it('accepts a valid dotted key and derives its parent', function () {
    $flow = new FlowDeclaration('account.security.passkeys', 'Passkeys', ['r'], AlwaysOn::class, null, ['owner']);

    expect($flow->key)->toBe('account.security.passkeys')
        ->and($flow->parent())->toBe('account.security')
        ->and($flow->registryKey())->toBe('account.security.passkeys');
});

it('gives a surface a null parent', function () {
    expect((new FlowDeclaration('account', 'Account', [], AlwaysOn::class, null, []))->parent())->toBeNull();
});

it('refuses a key outside the grammar', function (string $key) {
    new FlowDeclaration($key, 'x', [], AlwaysOn::class, null, []);
})->throws(InvalidArgumentException::class)->with([
    'uppercase' => ['Account'],
    'double dash' => ['account.sec--x'],
    'underscore' => ['account.two_factor'],
    'colon' => ['account:security'],
    'leading digit' => ['account.2fa'],
    'empty' => [''],
]);

it('refuses a surface that declares entry routes', function () {
    new FlowDeclaration('account', 'Account', ['some.route'], AlwaysOn::class, null, []);
})->throws(InvalidArgumentException::class);

it('requires every constructor parameter (no defaults)', function () {
    // All six are required positional params with no defaults — reflection proves it, so an omission
    // is a fatal, not a silent default (browser-journeys 02 R1-Q3).
    $params = (new ReflectionMethod(FlowDeclaration::class, '__construct'))->getParameters();
    expect($params)->toHaveCount(6)
        ->and(collect($params)->every(fn (ReflectionParameter $p) => ! $p->isOptional()))->toBeTrue();
});

// ── FlowCatalog: declare, registrant, duplicates, exact-key reads ────────────────────────────────

it('registers every flow a DeclaresFlows class ships, under a ScopesRegistrant scope', function () {
    $catalog = new FlowCatalog;

    Registrant::scoped('fixture/pkg', fn () => $catalog->declare(FixtureFlows::class));

    expect($catalog->has('account'))->toBeTrue()
        ->and($catalog->has('account.security.passkeys'))->toBeTrue()
        ->and($catalog->registrantOf('account.security'))->toBe('fixture/pkg');
});

it('records an explicit registrant through by:', function () {
    $catalog = new FlowCatalog;
    $catalog->declare(FixtureFlows::class, by: 'explicit/pkg');

    expect($catalog->registrantOf('account'))->toBe('explicit/pkg');
});

it('refuses to declare with no registrant in force', function () {
    (new FlowCatalog)->declare(FixtureFlows::class);
})->throws(LogicException::class);

it('reads exactly the keyed declaration, never a surface with children as ambiguous', function () {
    $catalog = new FlowCatalog;
    $catalog->declare(FixtureFlows::class, by: 'fixture/pkg');

    // `account` has children; declarations('account') is the one exact entry, not the subtree.
    expect($catalog->declarations('account'))->toHaveCount(1)
        ->and($catalog->declarations('account')[0]->key)->toBe('account')
        ->and($catalog->declarations('account.security')[0]->label)->toBe('Security');
});

it('admits a duplicate key from two registrants, retaining both and naming each (never thrown, never superseded)', function () {
    $catalog = new FlowCatalog;

    $catalog->register('account', new FlowDeclaration('account', 'A', [], AlwaysOn::class, null, []), 'pkg/a');
    $catalog->register('account', new FlowDeclaration('account', 'A-too', [], AlwaysOn::class, null, []), 'pkg/b');

    // admitted: both retained, boot never throws.
    expect($catalog->declarations('account'))->toHaveCount(2)
        // both registrants named — neither supersedes the other.
        ->and(array_map('strval', $catalog->keysBy('pkg/a')))->toContain('beam.flows.account')
        ->and(array_map('strval', $catalog->keysBy('pkg/b')))->toContain('beam.flows.account');
});

// ── Static, boot-free accessor; gate and state primitives ───────────────────────────────────────

it('answers flows() and flow($key)->roles with no booted app', function () {
    // A pure static call (what a Pest dataset resolves at collection time, before setUp): it autoloads
    // the declaration class and nothing else.
    expect(FixtureFlows::flows())->toHaveCount(3)
        ->and(FixtureFlows::flow('account.security')->roles)->toBe(['owner', 'solo']);

    expect(fn () => FixtureFlows::flow('account.nope'))->toThrow(InvalidArgumentException::class);
});

it('has an AlwaysOn gate that answers On, and a tri-state FlowState', function () {
    expect((new AlwaysOn)->state())->toBe(FlowState::On)
        ->and(FlowState::cases())->toBe([FlowState::On, FlowState::Off, FlowState::Absent]);
});

it('reads a flow whose parent is undeclared without throwing (the doctor lists it structurally absent)', function () {
    // Whether a parent is installed is a fact about the host; the catalog stores and reads the key
    // regardless, and ticket 11's doctor classifies the missing parent as a structural-absence note.
    $catalog = new FlowCatalog;
    $catalog->register('orphan.child', new FlowDeclaration('orphan.child', 'Child', ['r'], AlwaysOn::class, null, []), 'pkg/x');

    expect($catalog->has('orphan.child'))->toBeTrue()
        ->and($catalog->declarations('orphan.child'))->toHaveCount(1)
        ->and($catalog->declarations('orphan.child')[0]->parent())->toBe('orphan')
        ->and($catalog->has('orphan'))->toBeFalse();
});
