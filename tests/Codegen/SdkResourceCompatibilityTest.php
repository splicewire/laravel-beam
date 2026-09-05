<?php

use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Field;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\RecordType;
use Rushing\Codegen\Model\Type;
use Splicewire\Beam\Codegen\SplicewireClientGenerator;

it('generates the established raw and typed names from the same declared operation', function () {
    $model = (new CodegenModel)->operation(
        name: 'createEntity', method: 'POST', path: '/api/v1/intake/entities',
        returns: Type::ref('EntityRecord'), methods: ['POST'],
    );
    $files = (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(),
        'options' => [
            'domains' => ['Intake' => ['POST /api/v1/intake/entities']],
            'data' => ['EntityRecord' => 'Example\\IntakeEntity'],
            'requests' => ['POST /api/v1/intake/entities' => [
                'resource' => ['raw' => 'provisionEntityResponse', 'typed' => 'provisionEntity'],
            ]],
        ],
    ])['files'];
    expect($files['Resource/Intake.php'])
        ->toContain('function provisionEntityResponse(): Response')
        ->toContain('function provisionEntity(): IntakeEntity')
        ->toContain('IntakeEntity::fromResponse($this->provisionEntityResponse())');
});

it('generates a checked JSON projection alongside its raw response method', function () {
    $model = (new CodegenModel)->operation(name: 'link', method: 'POST', path: '/entities/{entity}/guest-tokens', methods: ['POST'], params: [new Field('entity', Type::primitive(Primitive::String))]);
    $files = (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(),
        'options' => [
            'domains' => ['Intake' => ['POST /entities/{entity}/guest-tokens']],
            'requests' => ['POST /entities/{entity}/guest-tokens' => [
                'resource' => ['raw' => 'issueSectionLinkResponse', 'typed' => 'issueSectionLink',
                    'result' => ['type' => 'array', 'path' => 'data', 'required' => ['token']]],
            ]],
        ],
    ])['files'];
    expect($files['Resource/Intake.php'])
        ->toContain('function issueSectionLink(string $entity): array')
        ->toContain("\$this->issueSectionLinkResponse(\$entity)->throw()->json('data')")
        ->toContain("is_string(\$data['token'] ?? null)")
        ->toContain('throw new \\UnexpectedValueException');
});

it('keeps client parameter names and bound task options out of the request contract', function () {
    $model = (new CodegenModel)->operation(name: 'analyze', method: 'POST', path: '/media/{id}/analyze', methods: ['POST'], params: [
        new Field('id', Type::primitive(Primitive::String)),
        new Field('async', Type::optional(Type::primitive(Primitive::Bool))),
    ]);
    $files = (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(),
        'options' => [
            'domains' => ['Media' => ['POST /media/{id}/analyze']],
            'requests' => ['POST /media/{id}/analyze' => ['resource' => [
                'parameters' => ['id' => 'mediaId'], 'bind' => ['async' => false],
            ]]],
        ],
    ])['files'];
    expect($files['Resource/Media.php'])
        ->toContain('function analyzeMedium(string $mediaId): Response')
        ->toContain('new AnalyzeMedium($mediaId, false)');
    expect($files['Requests/Media/AnalyzeMedium.php'])->toContain('protected string $id');
});

it('groups facade payload fields using the declared request body', function () {
    $body = (new RecordType('TenantInput'))
        ->field('slug', Type::primitive(Primitive::String))
        ->field('owner_email', Type::optional(Type::primitive(Primitive::String)));
    $model = (new CodegenModel)->operation(name: 'create', method: 'POST', path: '/tenants', methods: ['POST'], body: $body);
    $files = (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(), 'options' => [
            'domains' => ['TenantBroker' => ['POST /tenants']],
            'requests' => ['POST /tenants' => ['resource' => ['raw' => 'createResponse', 'body' => 'payload']]],
        ],
    ])['files'];
    expect($files['Resource/TenantBroker.php'])
        ->toContain('function createResponse(array $payload): Response')
        ->toContain("new CreateTenant(\$payload['slug'], \$payload['owner_email'] ?? NULL)");
    expect($files['Requests/TenantBroker/CreateTenant.php'])->toContain('protected string $slug');
});

it('hydrates a declared list as a list and preserves a connector factory alias', function () {
    $model = (new CodegenModel)->operation(name: 'children', method: 'GET', path: '/brokers/children', methods: ['GET'], returns: Type::ref('TenantData'), returnsMany: true);
    $files = (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(), 'options' => [
            'domains' => ['TenantBroker' => ['GET /brokers/children']],
            'data' => ['TenantData' => 'Example\\Tenant'],
            'factories' => ['TenantBroker' => 'tenants'],
            'requests' => ['GET /brokers/children' => ['resource' => ['raw' => 'listChildrenResponse', 'typed' => 'listChildren']]],
        ],
    ])['files'];
    expect($files['Resource/TenantBroker.php'])
        ->toContain('function listChildren(): array')
        ->toContain('Tenant::fromArray($row)')
        ->toContain("->throw()->json('data')");
    expect($files['GeneratedConnector.php'])->toContain('function tenants(): TenantBroker');
});

it('generates legacy request subclasses while the canonical class owns the endpoint', function () {
    $model = (new CodegenModel)->operation(name: 'code', method: 'POST', path: '/api/device/code', methods: ['POST']);
    $files = (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(), 'options' => [
            'domains' => ['Auth' => ['POST /api/device/code']],
            'aliases' => ['Requests/Auth/RequestDeviceCode.php' => 'Requests/Auth/CreateCode.php', 'Requests/Legacy/CreateCode.php' => 'Requests/Auth/CreateCode.php'],
        ],
    ])['files'];
    expect($files)->toHaveKeys(['Requests/Auth/CreateCode.php', 'Requests/Auth/RequestDeviceCode.php']);
    expect($files['Requests/Auth/RequestDeviceCode.php'])->toContain('extends CreateCode')->not->toContain('resolveEndpoint');
    expect($files['Resource/Auth.php'])->toContain('new RequestDeviceCode(');
    expect($files['Requests/Legacy/CreateCode.php'])->toContain('extends CanonicalCreateCode');
});

it('refuses to silently omit a promised typed facade when its response declaration disappears', function () {
    $model = (new CodegenModel)->operation(name: 'get', method: 'GET', path: '/compositions/{id}', methods: ['GET']);
    expect(fn () => (new SplicewireClientGenerator)->invoke([
        'model' => $model->toArray(), 'options' => [
            'domains' => ['Compositions' => ['GET /compositions/{id}']],
            'requests' => ['GET /compositions/{id}' => ['resource' => ['typed' => 'get']]],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'typed facade');
});
