<?php

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Splicewire\Beam\Localization\Contracts\BrandLocaleSource;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;
use Splicewire\Beam\Localization\DeliveryRecipient;
use Splicewire\Beam\Localization\LocaleResolver;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Localization\LocalizationContext;
use Splicewire\Beam\Localization\Queue\WithLocaleScope;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 36 (ruling 03 §3): the named delivery seam. LocaleResolver::deliver() resolves each recipient with no
 * request, then sends inside a LocaleScope in that language. The workspace a delivery concerns reaches the tenant
 * slot; the sender's ambient language never takes part; a throw restores the caller.
 */

/** A recipient with a saved preference (or none). */
final class DeliveryPerson
{
    public function __construct(public string $name, public ?string $preference = null) {}
}

/** A workspace with a default language (or none). */
final class DeliveryWorkspace
{
    public function __construct(public ?string $default = null) {}
}

beforeEach(function (): void {
    config(['beam.localization.supported' => ['en', 'es', 'de', 'fr'], 'app.locale' => 'en']);
    app()->setLocale('en');
    Number::useLocale('en');
    Number::useCurrency('USD');
    Carbon::setLocale('en');
    app()->bind(PersonLocaleSource::class, fn () => new class implements PersonLocaleSource
    {
        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            $person = DeliveryRecipient::notifiableOf($recipient);

            return $person instanceof DeliveryPerson ? $person->preference : null;
        }
    });
    app()->bind(TenantLocaleSource::class, fn () => new class implements TenantLocaleSource
    {
        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return $recipient instanceof DeliveryRecipient && $recipient->workspace instanceof DeliveryWorkspace
                ? $recipient->workspace->default : null;
        }
    });
    app()->bind(BrandLocaleSource::class, fn () => new class implements BrandLocaleSource
    {
        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return null;
        }
    });
});

/** What a send sees: its language, how it formats, and the state it must not move. */
function deliveredState(string $passed): array
{
    return [
        'passed' => $passed,
        'app' => app()->getLocale(),
        'number' => Number::format(1234.5),
        'date' => Carbon::parse('2026-03-05')->translatedFormat('j F'),
        'realm' => app(LocalizationContext::class)->realm(),
        'currency' => Number::defaultCurrency(),
        'timezone' => date_default_timezone_get(),
    ];
}

it('resolves each recipient on its own: the saved preference, else the delivery workspace, else the app default', function (): void {
    $resolver = app(LocaleResolver::class);

    expect($resolver->deliver(new DeliveryPerson('ana', 'es'), fn (string $l): string => $l.'/'.app()->getLocale()))->toBe('es/es')
        ->and($resolver->deliver(new DeliveryRecipient(new DeliveryPerson('max'), new DeliveryWorkspace('de')), fn (string $l): string => $l))->toBe('de')
        ->and($resolver->deliver(new DeliveryRecipient(new DeliveryPerson('kim', 'fr'), new DeliveryWorkspace('de')), fn (string $l): string => $l))->toBe('fr')
        ->and($resolver->deliver(new DeliveryRecipient(null), fn (string $l): string => $l))->toBe('en');
});

it('never lets the sender\'s language reach an unknown invitee of an English workspace', function (): void {
    $seen = LocaleScope::run('es', fn (): string => app(LocaleResolver::class)->deliver(
        new DeliveryRecipient(null, new DeliveryWorkspace('en')),
        fn (string $locale): string => $locale.'/'.app()->getLocale(),
    ));

    expect($seen)->toBe('en/en')->and(app()->getLocale())->toBe('en');
});

/** A family-style queued job: two recipients, and a send that throws between them. */
final class DeliverToTwo implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function middleware(): array
    {
        return [new WithLocaleScope];
    }

    public function handle(LocaleResolver $resolver): void
    {
        foreach ([new DeliveryPerson('ana', 'es'), new DeliveryPerson('boom', 'fr'), new DeliveryPerson('max', 'de')] as $person) {
            try {
                self::$seen[] = $resolver->deliver($person, function (string $locale) use ($person): array {
                    $state = deliveredState($locale);
                    if ($person->name === 'boom') {
                        throw new RuntimeException('the transport refused '.$locale);
                    }

                    return $state;
                });
            } catch (RuntimeException $failed) {
                self::$seen[] = ['failed' => $failed->getMessage(), 'after' => deliveredState('-')];
            }
        }
    }
}

it('delivers to two recipients and survives an exception in one worker, restoring dates, Number and realm, never currency or timezone', function (): void {
    config(['queue.default' => 'sync']);
    Number::useCurrency('EUR');
    $tz = date_default_timezone_get();
    DeliverToTwo::$seen = [];

    // The worker carries residue from an earlier request in another language and realm.
    LocaleScope::runIn('fr', 'acme', function (): void {
        DeliverToTwo::dispatch();
    });

    [$ana, $boom, $max] = DeliverToTwo::$seen;
    expect($ana['passed'])->toBe('es')->and($ana['app'])->toBe('es')
        ->and($ana['number'])->toBe(Number::format(1234.5, locale: 'es'))
        ->and($ana['date'])->toBe('5 marzo')
        ->and($ana['realm'])->toBe([true, null], 'the job enters the base realm, not the dispatching request\'s')
        ->and($boom['failed'])->toBe('the transport refused fr')
        ->and($boom['after']['app'])->toBe('en', 'the throw restored the job scope (the application default)')
        ->and($boom['after']['number'])->toBe('1,234.5')
        ->and($max['passed'])->toBe('de')->and($max['date'])->toBe('5 März')
        ->and($max['number'])->toBe(Number::format(1234.5, locale: 'de'));
    foreach ([$ana, $boom['after'], $max] as $state) {
        expect($state['currency'])->toBe('EUR')->and($state['timezone'])->toBe($tz);
    }

    // And the worker is back where it was: no locale, Number, date or realm leaks into the next request.
    expect(app()->getLocale())->toBe('en')
        ->and(Number::defaultLocale())->toBe('en')
        ->and(Carbon::getLocale())->toBe('en')
        ->and(app(LocalizationContext::class)->realm())->toBe([false, null])
        ->and(Number::defaultCurrency())->toBe('EUR');
});
