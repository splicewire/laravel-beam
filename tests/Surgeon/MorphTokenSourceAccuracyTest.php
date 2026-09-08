<?php

use Splicewire\Beam\Surgeon\MorphTokenBypassAudit;

it('ignores non-persistence source that resembles morph writes', function (string $source) {
    expect((new MorphTokenBypassAudit)->hitsIn($source))->toBe([]);
})->with([
    'docblock and line comment' => [<<<'SOURCE'
        <?php
        /** Model::where('syncable_type', Foo::class); */
        // Model::create(['syncable_type' => $class]);
        SOURCE],
    'schema metadata' => [<<<'SOURCE'
        <?php
        $this->models[] = ['class' => $class, 'key_type' => $keyType];
        $this->tables[$table] = ['key_type' => $keyType, 'source' => $file];
        SOURCE],
    'enum casts' => [<<<'SOURCE'
        <?php
        class Edge {
            protected $casts = ['edge_type' => EdgeType::class];
            protected function casts(): array { return ['edge_type' => EdgeType::class]; }
        }
        SOURCE],
    'backed enum writes and queries' => [<<<'SOURCE'
        <?php
        enum EdgeType: string { case Owns = 'owns'; }
        function insert(EdgeType $type) {
            DB::table('edges')->updateOrInsert(['edge_type' => $type->value], []);
            DB::table('edges')->where('edge_type', $type->value)->delete();
        }
        SOURCE],
]);

it('retains real morph writes and ambiguous values', function (string $source, string $check) {
    expect(array_column((new MorphTokenBypassAudit)->hitsIn($source), 'check'))->toBe([$check]);
})->with([
    'class write beside cast' => [<<<'SOURCE'
        <?php
        class Edge {
            protected $casts = ['edge_type' => EdgeType::class];
            function saveEdge() { Edge::create(['edge_type' => Model::class]); }
        }
        SOURCE, 'morph-token.class-literal'],
    'metadata-named column really persisted' => ["Model::create(['key_type' => \$class]);", 'morph-token.raw-value'],
    'indirect payload' => ["\$payload = ['syncable_type' => \$class]; Model::insert(\$payload);", 'morph-token.raw-value'],
    'unknown value property' => ["Model::where('syncable_type', \$object->value);", 'morph-token.raw-value'],
    'static query' => ["Model::where('syncable_type', Foo::class);", 'morph-token.class-literal'],
    'qualified join' => ["\$join->where('sib.siloable_type', '=', Foo::class);", 'morph-token.class-literal'],
]);

it('recognizes an imported backed enum without executing scanned source', function () {
    expect((new MorphTokenBypassAudit)->hitsIn(<<<'SOURCE'
        <?php
        use Splicewire\Beam\Ownership\OwnershipEdgeType as EdgeType;
        function record(EdgeType $type) {
            throw new RuntimeException('This source must never execute');
            DB::table('edges')->insert(['edge_type' => $type->value]);
        }
        SOURCE))->toBe([]);
});

it('keeps reassigned enum parameters ambiguous', function () {
    expect(array_column((new MorphTokenBypassAudit)->hitsIn(<<<'SOURCE'
        <?php
        enum EdgeType: string { case Owns = 'owns'; }
        function record(EdgeType $type) {
            $type = unknownObject();
            Model::create(['syncable_type' => $type->value]);
        }
        SOURCE), 'check'))->toBe(['morph-token.raw-value']);
});

it('still detects both condition and update payloads and nested insert rows', function () {
    expect(array_column((new MorphTokenBypassAudit)->hitsIn(<<<'SOURCE'
        Model::updateOrCreate(['owner_type' => $owner], ['target_type' => Target::class]);
        Model::insert([['syncable_type' => $class]]);
        SOURCE), 'check'))->toBe(['morph-token.raw-value', 'morph-token.class-literal', 'morph-token.raw-value']);
});

it('does not report a malformed source file as clean', function () {
    $audit = new class extends MorphTokenBypassAudit
    {
        protected function scanRoots(): array
        {
            return ['fixture' => [__DIR__]];
        }

        protected function phpFilesIn(string $dir): array
        {
            return ['broken.php' => '<?php function {'];
        }

        protected function relative(string $path): string
        {
            return $path;
        }
    };

    expect($audit->run()[0]->conclusive)->toBeFalse()
        ->and($audit->run()[0]->detail)->toContain('source could not be parsed');
});

it('resolves payloads in their own scope at the point of use', function (string $source, array $checks) {
    expect(array_column((new MorphTokenBypassAudit)->hitsIn($source), 'check'))->toBe($checks);
})->with([
    'unrelated functions' => [<<<'SOURCE'
        function metadata($class) { $payload = ['key_type' => $class]; }
        function persist() { $payload = ['label' => 'safe']; Model::insert($payload); }
        SOURCE, []],
    'overwritten payload' => [<<<'SOURCE'
        $payload = ['syncable_type' => Foo::class];
        $payload = ['label' => 'safe']; Model::insert($payload);
        SOURCE, []],
    'later assignment' => [<<<'SOURCE'
        $payload = ['label' => 'safe']; Model::insert($payload);
        $payload = ['syncable_type' => Foo::class];
        SOURCE, []],
    'alias captures preceding value' => [<<<'SOURCE'
        $payload = ['syncable_type' => Foo::class]; $alias = $payload;
        $payload = ['label' => 'safe']; Model::insert($alias);
        SOURCE, ['morph-token.class-literal']],
    'conditional overwrite is not definite' => [<<<'SOURCE'
        $payload = ['syncable_type' => Foo::class];
        if ($condition) { $payload = ['label' => 'safe']; }
        Model::insert($payload);
        SOURCE, ['morph-token.class-literal']],
]);

it('uses the value position for each query signature', function (string $source, string $check) {
    expect(array_column((new MorphTokenBypassAudit)->hitsIn($source), 'check'))->toBe([$check]);
})->with([
    'whereIn boolean' => ["Model::whereIn('syncable_type', \$classes, 'and');", 'morph-token.raw-value'],
    'whereIn class list' => ["Model::whereIn('syncable_type', [Foo::class]);", 'morph-token.class-literal'],
    'whereNot map' => ["Model::whereNot(['syncable_type' => Foo::class]);", 'morph-token.class-literal'],
    'orWhereNot map' => ["Model::orWhereNot(['syncable_type' => Foo::class]);", 'morph-token.class-literal'],
    'whereNotIn' => ["Model::whereNotIn('syncable_type', [Foo::class], 'or');", 'morph-token.class-literal'],
    'where operator' => ["Model::where('syncable_type', '=', Foo::class, 'and');", 'morph-token.class-literal'],
]);

it('uses scope-aware mutation evidence before excluding enum values', function (string $body, array $checks) {
    $source = "enum Kind: string { case Owns = 'owns'; } function persist(Kind \$kind) { ".$body.' }';
    expect(array_column((new MorphTokenBypassAudit)->hitsIn($source), 'check'))->toBe($checks);
})->with([
    'foreach shadow' => ["foreach (\$objects as \$kind) { Model::where('syncable_type', \$kind->value); }", ['morph-token.raw-value']],
    'destructure shadow' => ["[\$kind] = \$objects; Model::where('syncable_type', \$kind->value);", ['morph-token.raw-value']],
    'reference assignment' => ["\$alias =& \$kind; Model::where('syncable_type', \$kind->value);", ['morph-token.raw-value']],
    'by-reference closure' => ["\$cb = function () use (&\$kind) {}; Model::where('syncable_type', \$kind->value);", ['morph-token.raw-value']],
    'nested own variable' => ["\$cb = function () { \$kind = unknown(); }; Model::where('edge_type', \$kind->value);", []],
    'later mutation' => ["Model::where('edge_type', \$kind->value); \$kind = unknown();", []],
]);

it('retains nullsafe query positives', function () {
    expect(array_column((new MorphTokenBypassAudit)->hitsIn("\$query?->where('owner_type', Foo::class);"), 'check'))
        ->toBe(['morph-token.class-literal']);
});
