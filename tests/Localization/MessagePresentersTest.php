<?php

use Illuminate\Support\Facades\Log;
use Splicewire\Beam\Data\MessageReferenceData;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Localization\Presentation\InvalidMessageReference;
use Splicewire\Beam\Localization\Presentation\MessagePresenters;
use Splicewire\Beam\Localization\Presentation\PresentedLine;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 37 (RULING-04 §4–§5; SIGNATURES-37a): the shared server presentation. A persisted or broadcast product
 * line renders for its reader from a stored reference or a known identity, else the stored text stays literal.
 */

beforeEach(function (): void {
    $this->lang = sys_get_temp_dir().'/beam-presenters-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach (['en' => ['saved' => 'Saved :name', 'items' => 'one item|:count items', 'reloaded' => 'We reloaded :amount', 'migrated' => 'Moved on :when', 'step' => 'Step :ref is :state'],
        'es' => ['saved' => 'Guardado :name', 'items' => 'un elemento|:count elementos', 'reloaded' => 'Recargamos :amount', 'migrated' => 'Movido el :when', 'step' => 'El paso :ref está :state']] as $locale => $lines) {
        mkdir($this->lang.'/'.$locale, 0777, true);
        file_put_contents($this->lang.'/'.$locale.'/probe.php', '<?php return '.var_export($lines, true).';');
    }
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config(['beam.localization.supported' => ['en', 'es'], 'app.locale' => 'en', 'beam.commerce.money.currency' => 'EUR']);
    app()->setLocale('en');
    $this->presenters = app(MessagePresenters::class)
        ->adapter('probe.saved', ['name' => 'string'])
        ->adapter('probe.items', [])
        ->adapter('probe.reloaded', ['amount' => 'money:USD'])
        ->adapter('probe.migrated', ['when' => 'date'])
        ->adapter('probe.step', ['ref' => 'string', 'state' => 'string']);
});

it('is one shared registry', function (): void {
    expect(app(MessagePresenters::class))->toBe(app(MessagePresenters::class));
});

it('keeps EXACTLY the declared reference shape, and reads a stored one tolerantly', function (): void {
    $reference = MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => 'Acme'], 3);

    expect($reference->toProperties())->toBe(['version' => 1, 'key' => 'probe.saved', 'parameters' => ['name' => 'Acme'], 'default' => 'Saved :name', 'count' => 3])
        ->and(MessageReferenceData::fromProperties($reference->toProperties()))->toEqual($reference)
        ->and(MessageReferenceData::fromProperties(['version' => 2] + $reference->toProperties()))->toBeNull()
        ->and(MessageReferenceData::fromProperties(['parameters' => ['x' => ['nested']]] + $reference->toProperties()))->toBeNull()
        ->and(MessageReferenceData::fromProperties(['key' => ''] + $reference->toProperties()))->toBeNull()
        ->and(MessageReferenceData::fromProperties('Saved Acme'))->toBeNull();
});

it('renders one stored reference for two readers, the reference itself byte-unchanged', function (): void {
    $stored = MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => 'Acme'])->toProperties();
    $before = json_encode($stored);

    $en = $this->presenters->present('status', [], $stored, 'Saved Acme');
    $es = LocaleScope::run('es', fn () => $this->presenters->present('status', [], $stored, 'Saved Acme'));

    expect($en->text)->toBe('Saved Acme')->and($en->source)->toBe(PresentedLine::REFERENCE)->and($en->translated())->toBeTrue()
        ->and($es->text)->toBe('Guardado Acme')
        ->and(json_encode($stored))->toBe($before);
});

it('selects plural forms with an explicit count, by the answering locale', function (): void {
    $one = MessageReferenceData::of('probe.items', 'one item|:count items', [], 1);
    $five = MessageReferenceData::of('probe.items', 'one item|:count items', [], 5);

    expect($this->presenters->render($one)->text)->toBe('one item')
        ->and(LocaleScope::run('es', fn () => $this->presenters->render($five)->text))->toBe('5 elementos');
});

it('formats money in the unit the reference declares (USD), never the configured currency, in the reader locale', function (): void {
    $reference = MessageReferenceData::of('probe.reloaded', 'We reloaded :amount', ['amount' => 12.5]);

    $en = $this->presenters->render($reference)->text;
    $es = LocaleScope::run('es', fn () => $this->presenters->render($reference)->text);

    expect($en)->toContain('$12.50')->not->toContain('€')
        ->and($es)->toStartWith('Recargamos')->toContain('12,50')->not->toContain('€');
});

it('formats a date parameter in the reader locale', function (): void {
    $reference = MessageReferenceData::of('probe.migrated', 'Moved on :when', ['when' => '2026-03-05T10:00:00Z']);

    expect($this->presenters->render($reference)->text)->toContain('March')
        ->and(LocaleScope::run('es', fn () => $this->presenters->render($reference)->text))->toContain('marzo');
});

it('follows the reader rule: stored reference, then known identity, then the legacy literal', function (): void {
    $this->presenters->identity('status', fn (array $row): ?MessageReferenceData => ($row['ref'] ?? null) === 'deploy'
        ? MessageReferenceData::of('probe.step', 'Step :ref is :state', ['ref' => 'deploy', 'state' => (string) $row['state']])
        : null);
    $stored = MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => 'Acme'])->toProperties();

    $byReference = $this->presenters->present('status', ['ref' => 'deploy', 'state' => 'running'], $stored, 'old text');
    $byIdentity = LocaleScope::run('es', fn () => $this->presenters->present('status', ['ref' => 'deploy', 'state' => 'running'], null, 'Deploying now'));
    $legacy = LocaleScope::run('es', fn () => $this->presenters->present('status', ['ref' => 'unknown'], null, 'Something happened'));
    $empty = $this->presenters->present('status', [], null, null);

    expect($byReference->source)->toBe(PresentedLine::REFERENCE)->and($byReference->text)->toBe('Saved Acme')
        ->and($byIdentity->source)->toBe(PresentedLine::IDENTITY)->and($byIdentity->text)->toBe('El paso deploy está running')
        ->and($legacy->source)->toBe(PresentedLine::LEGACY)->and($legacy->text)->toBe('Something happened')->and($legacy->translated())->toBeFalse()
        ->and($empty->source)->toBe(PresentedLine::NONE)->and($empty->text)->toBeNull();
});

it('never translates a stored literal, even one that looks like a key', function (): void {
    $line = LocaleScope::run('es', fn () => $this->presenters->present('status', [], null, 'probe.saved'));

    expect($line->text)->toBe('probe.saved')->and($line->source)->toBe(PresentedLine::LEGACY);
});

it('fails a test loudly on an unregistered key, a key no catalog answers, or parameters off-schema', function (MessageReferenceData $reference): void {
    $this->presenters->adapter('probe.missing', []);

    expect(fn () => $this->presenters->render($reference))->toThrow(InvalidMessageReference::class);
})->with([
    'unregistered key' => [MessageReferenceData::of('probe.nobody', 'Nobody')],
    'no catalog line' => [MessageReferenceData::of('probe.missing', 'Missing')],
    'missing required parameter' => [MessageReferenceData::of('probe.saved', 'Saved :name')],
    'undeclared parameter' => [MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => 'A', 'extra' => 'x'])],
    'wrong unit' => [MessageReferenceData::of('probe.reloaded', 'We reloaded :amount', ['amount' => 'twelve'])],
]);

it('in production, falls back to the reference\'s own default plus a log diagnostic, never a raw key', function (): void {
    Log::spy();
    $env = app()['env'];
    app()['env'] = 'production';
    try {
        $line = $this->presenters->render(MessageReferenceData::of('probe.nobody', 'Nobody saw :name', ['name' => 'Acme']));
    } finally {
        app()['env'] = $env;
    }

    expect($line->text)->toBe('Nobody saw Acme')->not->toContain('probe.nobody');
    Log::shouldHaveReceived('warning')->once();
});

it('adds nothing to the PHP client-catalog population', function (): void {
    $client = fn (): int => count(array_filter(app(KeyCatalog::class)->entries(), fn ($entry): bool => $entry->source === 'client'));
    $before = $client();
    app(MessagePresenters::class)->adapter('probe.another', []);

    expect($client())->toBe($before);
});
