<?php

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Splicewire\Beam\Codegen\SdkDataAdapterGenerator;
use Splicewire\Beam\Tests\Fixtures\SdkAdapters\ComplexRecord;
use Splicewire\Beam\Tests\Fixtures\SdkAdapters\Document;
use Splicewire\Beam\Tests\Fixtures\SdkAdapters\EvolvedMeasurement;
use Splicewire\Beam\Tests\Fixtures\SdkAdapters\Measurement;
use Splicewire\Beam\Tests\Fixtures\SdkAdapters\Record;
use Splicewire\Beam\Tests\Fixtures\SdkAdapters\RuntimeResponse;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/SdkDataAdapters.php';

/** Execute the actual emitted file without Laravel or a sibling checkout's dependencies. */
function runSdkAdapter(string $spine, array $options, string $expression): array
{
    $file = tempnam(sys_get_temp_dir(), 'sdk-adapter-');
    try {
        $model = (new SdkDataAdapterGenerator)->generate('GeneratedSdk', 'Adapter', $spine, $options);
        file_put_contents($file, (new PsrPrinter)->printFile($model));
        $script = 'require '.var_export(__DIR__.'/../Fixtures/SdkDataAdapters.php', true).';'
            .'class_alias('.var_export(RuntimeResponse::class, true).', "Saloon\\Http\\Response");'
            .'require '.var_export($file, true).';'
            .'try { $result = '.$expression.'; echo json_encode(["value" => $result], JSON_THROW_ON_ERROR); }'
            .'catch (Throwable $e) { echo json_encode(["exception" => get_class($e), "message" => $e->getMessage()], JSON_THROW_ON_ERROR); }';
        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        unlink($file);
    }
}

it('emits the default adapter without copying spine fields or changing its unwrap', function () {
    $model = (new SdkDataAdapterGenerator)->generate('Example', 'Record', Record::class);
    $php = (new PsrPrinter)->printFile($model);
    expect($model)->toBeInstanceOf(PhpFile::class)
        ->and($php)->toContain('as SpineRecord;', 'class Record extends SpineRecord', 'public static function fromResponse(Response $response): self', '$response->throw();', "return static::fromArray(\$response->json('data') ?? []);")
        ->not->toContain('function __construct');
    expect(runSdkAdapter(Record::class, [], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => ["id" => "one"]]))'))
        ->toBe(['value' => ['values' => ['id' => 'one']]]);
    expect(runSdkAdapter(Record::class, [], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response([]))'))
        ->toBe(['value' => ['values' => []]]);
});

it('emits reusable aliases without response methods', function () {
    $php = (new PsrPrinter)->printFile((new SdkDataAdapterGenerator)->generate('Example', 'Record', Record::class, ['mode' => 'alias']));
    expect($php)->toContain('class Record extends SpineRecord')->not->toContain('fromResponse', 'Saloon');
    expect(runSdkAdapter(Record::class, ['mode' => 'alias'], 'GeneratedSdk\Data\Adapter::fromArray(["id" => "alias"])'))
        ->toBe(['value' => ['values' => ['id' => 'alias']]]);
});

it('selects nested collection elements and whole JSON bodies for spine factories', function () {
    expect(runSdkAdapter(Record::class, ['path' => 'data.media.0'], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => ["media" => [["uuid" => "first"], ["uuid" => "second"]]]]))'))
        ->toBe(['value' => ['values' => ['uuid' => 'first']]]);
    expect(runSdkAdapter(Record::class, ['path' => null, 'factory' => 'hydrate'], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["root" => true]))'))
        ->toBe(['value' => ['values' => ['custom' => ['root' => true]]]]);
});

it('pairs raw bytes with content type and its fallback', function () {
    foreach (['text/html', null] as $type) {
        expect(runSdkAdapter(Document::class, ['mode' => 'body'], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(null, "<p>raw</p>", '.var_export($type, true).'))'))
            ->toBe(['value' => ['contentType' => $type ?? 'text/plain', 'body' => '<p>raw</p>']]);
    }
});

it('reflects constructor names casts and defaults without a field map', function () {
    $options = ['factory' => 'constructor', 'required' => ['artifactRef']];
    $result = runSdkAdapter(Measurement::class, $options, 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => ["artifactRef" => "media-uuid", "seconds" => "1.25", "summary" => "invalid", "wall" => "2.5", "count" => "3", "complete" => 1]]))');
    expect($result)->toBe(['value' => ['artifactRef' => 'media-uuid', 'seconds' => 1.25, 'summary' => [], 'wall' => 2.5, 'count' => 3, 'complete' => true, 'label' => 'default']]);
    expect(runSdkAdapter(Measurement::class, $options, 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => ["artifactRef" => "ref", "seconds" => 1.5]]))')['value'])
        ->toMatchArray(['summary' => [], 'wall' => null, 'count' => 7, 'complete' => false]);
    expect(runSdkAdapter(EvolvedMeasurement::class, ['factory' => 'constructor'], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => ["newRequired" => "new"]]))'))
        ->toBe(['value' => ['newRequired' => 'new', 'revision' => 9]]);
});

it('rejects missing empty and non-string required keys', function (mixed $value) {
    $data = $value === null ? [] : ['artifactRef' => $value];
    $result = runSdkAdapter(Measurement::class, ['factory' => 'constructor', 'required' => ['artifactRef']], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => '.var_export($data, true).']))');
    expect($result['exception'])->toBe(UnexpectedValueException::class)
        ->and($result['message'])->toContain('artifactRef');
})->with([null, '', 123]);

it('refuses absent constructor fields rather than casting an absent value to zero', function () {
    expect(runSdkAdapter(Measurement::class, ['factory' => 'constructor'], 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(["data" => ["artifactRef" => "ref"]]))'))
        ->toBe(['exception' => UnexpectedValueException::class, 'message' => 'SDK constructor adapter: missing seconds.']);
});

it('throws before any hydration in every response mode', function (string $mode) {
    $options = match ($mode) {
        'body' => ['mode' => 'body'],
        'constructor' => ['factory' => 'constructor', 'required' => ['artifactRef']],
        default => [],
    };
    $spine = match ($mode) {
        'body' => Document::class,
        'constructor' => Measurement::class,
        default => Record::class,
    };
    expect(runSdkAdapter($spine, $options, 'GeneratedSdk\Data\Adapter::fromResponse(new Saloon\Http\Response(null, "", null, true))'))
        ->toBe(['exception' => RuntimeException::class, 'message' => 'HTTP refused']);
})->with(['json', 'body', 'constructor']);

it('rejects unsupported constructor shapes and invalid generation options', function () {
    expect(fn () => (new SdkDataAdapterGenerator)->generate('Example', 'Bad', ComplexRecord::class, ['factory' => 'constructor']))
        ->toThrow(InvalidArgumentException::class, 'use a spine factory');
    foreach ([['mode' => 'unknown'], ['factory' => 'bad();'], ['required' => ['']], ['path' => 123]] as $options) {
        expect(fn () => (new SdkDataAdapterGenerator)->generate('Example', 'Bad', Record::class, $options))
            ->toThrow(InvalidArgumentException::class);
    }
});
