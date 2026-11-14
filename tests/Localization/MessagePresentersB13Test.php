<?php

use Illuminate\Support\Facades\Log;
use Splicewire\Beam\Data\MessageReferenceData;
use Splicewire\Beam\Localization\Presentation\InvalidMessageReference;
use Splicewire\Beam\Localization\Presentation\MessagePresenters;
use Splicewire\Beam\Localization\Presentation\PresentedLine;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 37 amend (RANGE REVIEW B13, from review-r1's red probes): dates are calendar days in the HOST timezone
 * and only ISO values are admitted; production's fallback never throws, selects the captured plural and keeps a valid
 * reference's declared units; a rejected stored reference keeps its legacy text WITH a bounded diagnostic.
 */

beforeEach(function (): void {
    $this->lang = sys_get_temp_dir().'/beam-presenters-b13-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/en', 0777, true);
    file_put_contents($this->lang.'/en/probe.php', '<?php return '.var_export(['saved' => 'Saved :name', 'dated' => 'Moved on :when'], true).';');
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config(['beam.localization.supported' => ['en'], 'app.locale' => 'en']);
    app()->setLocale('en');
    $this->presenters = app(MessagePresenters::class)
        ->adapter('probe.saved', ['name' => 'string'])
        ->adapter('probe.dated', ['when' => 'date'])
        ->adapter('probe.missingitems', [])
        ->adapter('probe.missingmoney', ['amount' => 'money:USD'])
        ->adapter('probe.missingdated', ['when' => 'date']);
    $this->timezone = [date_default_timezone_get(), config('app.timezone')];
    $this->env = app()['env'];
});

afterEach(function (): void {
    date_default_timezone_set($this->timezone[0]);
    config(['app.timezone' => $this->timezone[1]]);
    app()['env'] = $this->env;
});

function b13InHostTimezone(string $timezone): void
{
    date_default_timezone_set($timezone);
    config(['app.timezone' => $timezone]);
}

function b13Dated(string $when): MessageReferenceData
{
    return MessageReferenceData::of('probe.dated', 'Moved on :when', ['when' => $when]);
}

it('B13-1: renders a zoned instant as its calendar day in the host timezone, across the midnight boundary', function (): void {
    b13InHostTimezone('America/Los_Angeles');

    expect($this->presenters->render(b13Dated('2026-03-05T00:30:00Z'))->text)->toBe('Moved on March 4, 2026')
        ->and($this->presenters->render(b13Dated('2026-03-05T07:59:59Z'))->text)->toBe('Moved on March 4, 2026')
        ->and($this->presenters->render(b13Dated('2026-03-05T08:00:00Z'))->text)->toBe('Moved on March 5, 2026')
        ->and($this->presenters->render(b13Dated('2026-03-05T09:30:00+09:00'))->text)->toBe('Moved on March 4, 2026', 'an offset-bearing instant converts too')
        ->and($this->presenters->render(b13Dated('2026-03-05'))->text)->toBe('Moved on March 5, 2026', 'a calendar date stays its own day');

    b13InHostTimezone('Asia/Tokyo');
    expect($this->presenters->render(b13Dated('2026-03-04T15:30:00Z'))->text)->toBe('Moved on March 5, 2026');

    b13InHostTimezone('UTC');
    expect($this->presenters->render(b13Dated('2026-03-05T00:30:00Z'))->text)->toBe('Moved on March 5, 2026');
});

it('B13-2: admits only ISO dates and date-times, refusing relative prose and impossible days', function (string $when): void {
    expect(fn () => $this->presenters->render(b13Dated($when)))->toThrow(InvalidMessageReference::class);
})->with(['tomorrow', 'next monday', 'now', '+1 day', 'March 5, 2026', '2026-02-30', '2026-13-01', '2026-03-05T24:00:00Z', '']);

it('B13-2: admits the ISO shapes it declares', function (string $when): void {
    expect($this->presenters->render(b13Dated($when))->source)->toBe(PresentedLine::REFERENCE);
})->with(['2026-03-05', '2026-03-05T10:00', '2026-03-05T10:00:00Z', '2026-03-05T10:00:00.123456Z', '2026-03-05T10:00:00+05:30', '2026-03-05 10:00:00-0800']);

it('B13-3: in production, an invalid non-scalar parameter falls back to the default with a diagnostic, never throwing', function (mixed $nested): void {
    Log::spy();
    app()['env'] = 'production';

    $line = $this->presenters->render(MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => $nested]));

    expect($line->text)->toBe('Saved ')->and($line->source)->toBe(PresentedLine::REFERENCE);
    Log::shouldHaveReceived('warning')->once();
})->with([
    'nested array' => [['nested']],
    'object' => [new stdClass],
    'stringable object' => [new class
    {
        public function __toString(): string
        {
            return 'coerced';
        }
    }],
]);

it('B13-3: the same invalid parameter still throws under tests', function (): void {
    expect(fn () => $this->presenters->render(MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => ['nested']])))
        ->toThrow(InvalidMessageReference::class);
});

it('B13-4: a missing-key production fallback selects the captured plural by its explicit count', function (int $count, string $expected): void {
    Log::spy();
    app()['env'] = 'production';

    expect($this->presenters->render(MessageReferenceData::of('probe.missingitems', 'one item|:count items', [], $count))->text)->toBe($expected);
})->with([[5, '5 items'], [1, 'one item'], [0, '0 items']]);

it('B13-4: a missing-key production fallback keeps the declared USD unit and host-timezone date', function (): void {
    Log::spy();
    app()['env'] = 'production';
    config(['beam.commerce.money.currency' => 'EUR', 'commerce.currency' => 'EUR']);
    b13InHostTimezone('America/Los_Angeles');

    $money = $this->presenters->render(MessageReferenceData::of('probe.missingmoney', 'We reloaded :amount', ['amount' => 12.5]))->text;
    $date = $this->presenters->render(MessageReferenceData::of('probe.missingdated', 'Moved on :when', ['when' => '2026-03-05T00:30:00Z']))->text;

    expect($money)->toBe('We reloaded $12.50')->not->toContain('€')
        ->and($date)->toBe('Moved on March 4, 2026');
});

it('B13-5: a rejected stored reference keeps its legacy text and logs one bounded diagnostic', function (): void {
    Log::spy();
    $stored = MessageReferenceData::of('probe.saved', 'Saved :name', ['name' => 'Acme secret'])->toProperties();
    $stored['version'] = 2;

    $line = $this->presenters->present('status', [], $stored, 'Old history');

    expect($line->text)->toBe('Old history')->and($line->source)->toBe(PresentedLine::LEGACY);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['family' => 'status', 'version' => 2, 'key' => 'probe.saved']);
});

it('B13-5: an absent stored reference is not a diagnostic', function (): void {
    Log::spy();

    expect($this->presenters->present('status', [], null, 'Old history')->text)->toBe('Old history');
    Log::shouldNotHaveReceived('warning');
});

it('B13-6: hasAdapter() is gone from the public surface', function (): void {
    expect(method_exists(MessagePresenters::class, 'hasAdapter'))->toBeFalse();
});
