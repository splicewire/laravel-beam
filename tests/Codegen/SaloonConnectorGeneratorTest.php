<?php

namespace Splicewire\Beam\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Type;
use Splicewire\Beam\Codegen\SaloonConnectorGenerator;

/**
 * The #06 driver — proves the SAME canonical model that feeds the TS client also emits a valid PHP
 * Saloon connector + request classes. Pure unit test (no Laravel boot): the generator is a plain
 * model-in → files-out invocable.
 */
class SaloonConnectorGeneratorTest extends TestCase
{
    private function model(): CodegenModel
    {
        return (new CodegenModel)
            ->operation('library-lyrics.index', 'GET', 'resources/library-lyrics',
                Type::external('App.Data.LyricData'), returnsMany: true, methods: ['GET'])
            ->operation('library-lyrics.update', 'PATCH', 'resources/library-lyrics/{id}',
                Type::external('App.Data.LyricData'), methods: ['PUT', 'PATCH']);
    }

    private function generate(): array
    {
        return (new SaloonConnectorGenerator)->invoke([
            'model' => $this->model()->toArray(),
            'options' => ['namespace' => 'App\\Generated\\Saloon', 'base_url' => 'https://api.test'],
        ])['files'];
    }

    public function test_it_emits_a_connector_and_one_request_per_operation(): void
    {
        $files = $this->generate();

        $this->assertArrayHasKey('ApiConnector.php', $files);
        $this->assertArrayHasKey('Requests/LibraryLyricsIndexRequest.php', $files);
        $this->assertArrayHasKey('Requests/LibraryLyricsUpdateRequest.php', $files);

        $connector = $files['ApiConnector.php'];
        $this->assertStringContainsString('class ApiConnector extends Connector', $connector);
        $this->assertStringContainsString("return 'https://api.test';", $connector);
        $this->assertStringContainsString('public function libraryLyricsIndex(array $params = [])', $connector);
        $this->assertStringContainsString('public function libraryLyricsUpdate(array $params = [], array $body = [])', $connector);
    }

    public function test_read_and_write_requests_differ_in_verb_body_and_endpoint(): void
    {
        $files = $this->generate();

        $index = $files['Requests/LibraryLyricsIndexRequest.php'];
        $this->assertStringContainsString('Method::GET', $index);
        $this->assertStringContainsString("return 'resources/library-lyrics';", $index);
        $this->assertStringNotContainsString('HasJsonBody', $index);          // read → no body trait

        $update = $files['Requests/LibraryLyricsUpdateRequest.php'];
        $this->assertStringContainsString('Method::PATCH', $update);
        $this->assertStringContainsString('use HasJsonBody;', $update);        // write → body trait
        $this->assertStringContainsString("return 'resources/library-lyrics/'.\$this->params['id'].'';", $update);
        $this->assertStringContainsString('function defaultBody()', $update);
    }

    public function test_emitted_php_is_syntactically_valid(): void
    {
        $dir = sys_get_temp_dir().'/saloon-gen-'.uniqid();
        mkdir($dir.'/Requests', 0o755, true);

        foreach ($this->generate() as $rel => $contents) {
            file_put_contents($dir.'/'.$rel, $contents);
            $lint = shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($dir.'/'.$rel).' 2>&1');
            $this->assertStringContainsString('No syntax errors detected', (string) $lint, "Syntax error in {$rel}: {$lint}");
        }

        array_map('unlink', glob($dir.'/Requests/*') ?: []);
        array_map('unlink', glob($dir.'/*.php') ?: []);
        @rmdir($dir.'/Requests');
        @rmdir($dir);
    }
}
