<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\RecordsSupersession;
use Rushing\Popcorn\Registries\Registry;
use Schemastud\Frame\Contracts\ResourceSchemaResolver;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Schemastud\Frame\Registry\FormMode;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Registry\WidgetContext;
use Schemastud\Frame\Registry\WidgetContextRegistry;
use Schemastud\Frame\Vocabulary\DeclaredWidget;
use Schemastud\Frame\Vocabulary\DeclaredWidgets;
use Schemastud\Frame\Vocabulary\WidgetVocabulary;
use Splicewire\Beam\Collections\CollectionBacking;
use Splicewire\Beam\Collections\CollectionFormSchema;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Throwable;

/**
 * Advisory comparison of addressable declarations with the host kit's generated vocabulary.
 * Reads declarations through frame's walker, never by reflecting its attributes. Beam's served manifest is
 * deliberately not the population: unlabelled typed resources must be checked too (CONFORM 05-13).
 */
class FramedDeclarationAudit implements DoctorAudit
{
    public const CHECK_VOCABULARY = 'frame.vocabulary';

    public const CHECK_WIDGET_NAME = 'frame.widget-name';

    public const CHECK_WIDGET_NODE_TYPE = 'frame.widget-node-type';

    public const CHECK_PROJECTION = 'frame.projection';

    /** The `frame.contexts` registry root (frame-foundation 90). */
    private const CONTEXTS = 'frame.contexts';

    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected DeclaredWidgets $widgets,
        protected ?CompositeResourceRegistry $composite = null,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $vocabulary = WidgetVocabulary::fromConfig();
        $usable = $vocabulary->isReadable();
        $findings = $usable
            ? $this->vocabularyFindings($vocabulary)
            : [Finding::inconclusive(self::CHECK_VOCABULARY, "Widget vocabulary [{$vocabulary->path}] is {$vocabulary->state->value}; run pnpm frame:vocabulary --write to generate it. No widget names were checked. The file does not establish freshness against the running JS build.")];
        foreach ($findings as $finding) {
            if ($finding->status === \Rushing\Doctor\DoctorStatus::Fail) {
                $usable = false;
            }
        }
        $definitions = $this->definitions($findings);
        $resolver = app()->bound(ResourceSchemaResolver::class) ? app(ResourceSchemaResolver::class) : null;
        // A reserved name is the file's or any PHP context's: either way it is never looked up as a binding.
        $reserved = $usable ? array_values(array_unique([...$vocabulary->reserved(), ...array_merge(...array_map(fn (WidgetContext $context): array => $context->reserved, app(WidgetContextRegistry::class)->all()))])) : [];
        $names = $types = $walked = 0;
        $notWalked = [];
        $seen = [];
        foreach ($definitions as $definition) {
            $walk = $this->widgets->walk($definition);
            $walked++;
            if ($resolver instanceof CollectionFormSchema && $this->resources->find($definition->key)?->backing() instanceof CollectionBacking) {
                $notWalked[$definition->key] = $definition->key;
            }
            foreach ($walk->failures as $failure) {
                $findings[] = $this->projectionFailure($failure->resource, $failure->source, $failure->class, $failure->exception, $failure->message, $failure->action);
            }
            if (! $usable) {
                continue;
            }
            $write = [];
            foreach ($walk->widgets as $widget) {
                if ($widget->source === DeclaredWidget::SourceWriteSchema) {
                    $write[$widget->pointer.'|'.$widget->name] = true;
                }
            }
            foreach ($walk->widgets as $widget) {
                $pointer = $this->pointer($widget);
                if ($widget->source === DeclaredWidget::SourceContextBlock && $widget->context === 'edit' && ! $widget->heavyweight && isset($write[$pointer.'|'.$widget->name])) {
                    continue;
                }
                $identity = json_encode([$widget->resource, $pointer, $widget->context, $widget->name]);
                $newName = ! isset($seen[$identity]);
                $seen[$identity] = true;
                $names += $newName ? 1 : 0;
                $where = "Resource [{$widget->resource}] at [{$pointer}] in [{$widget->context}]";
                $origin = $widget->source.', '.$widget->class.($widget->action === null ? '' : ', action '.$widget->action);
                if (in_array($widget->name, $reserved, true)) {
                    if ($newName && ! $this->reservedHere($widget)) {
                        $findings[] = Finding::fail(self::CHECK_WIDGET_NAME, "{$where} declares reserved widget [{$widget->name}] outside the root of a context that reserves it ({$origin}).");
                    }

                    continue;
                }
                $binding = $vocabulary->binding($widget->name, $widget->context);
                if (! $binding->bound) {
                    if ($newName) {
                        $findings[] = Finding::fail(self::CHECK_WIDGET_NAME, "{$where} declares unbound widget [{$widget->name}] ({$origin}). Register the name in the host kit and run pnpm frame:vocabulary --write.");
                    }

                    continue;
                }
                $formControl = in_array($widget->source, [DeclaredWidget::SourceWriteSchema, DeclaredWidget::SourceActionInput], true);
                if ($formControl && $widget->nodeType !== null && $binding->nodeTypes !== null) {
                    $types++;
                    if (! in_array($widget->nodeType, $binding->nodeTypes, true)) {
                        $supported = json_encode($binding->nodeTypes);
                        $findings[] = Finding::fail(self::CHECK_WIDGET_NODE_TYPE, "{$where} uses widget [{$widget->name}] on [{$widget->nodeType}]; supported node types: {$supported} ({$origin}). Use a control bound for this node type.");
                    }
                }
            }
        }
        if ($usable) {
            $this->summarize($findings, self::CHECK_WIDGET_NAME, $names, 'declared widget names');
            $this->summarize($findings, self::CHECK_WIDGET_NODE_TYPE, $types, 'form controls with known node types and stock bindings');
        } else {
            foreach ([self::CHECK_WIDGET_NAME, self::CHECK_WIDGET_NODE_TYPE] as $check) {
                $findings[] = Finding::inconclusive($check, 'No widget names were checked: see frame.vocabulary.');
            }
        }
        $this->summarize($findings, self::CHECK_PROJECTION, $walked, 'resource declarations projected; realm overrides are not walked');
        // Coverage is separate from projection failures: a failed Data projection must not hide the request seam.
        // Context-block widget nodes have no request-dependent inputs, so no nodes need a separate skipped count.
        if ($resolver instanceof CollectionFormSchema) {
            if ($notWalked !== []) {
                sort($notWalked);
                $count = count($notWalked);
                $keys = implode(', ', $notWalked);
                $findings[] = Finding::warn(self::CHECK_PROJECTION, "Schemas not walked: {$count} of {$walked} resources are Collection-backed; CollectionFormSchema serves their schema per request, so the walk read their declared Data class instead: [{$keys}].");
            }
        } elseif ($resolver !== null) {
            $class = $resolver::class;
            $findings[] = Finding::inconclusive(self::CHECK_PROJECTION, "Schema coverage unknown: a host ResourceSchemaResolver [{$class}] is bound; which of the {$walked} walked resources it serves a per-request schema needs a request, so none is counted as walked or not walked.");
        }

        return $findings;
    }

    /**
     * The file's header against laravel-frame, and its contexts against the `frame.contexts` registry (frame-foundation
     * 92). `version` and `formModes` are frame's own and stay `fail`. The registry is an open set a package or host
     * extends on both sides, so each difference there is a `warn` that leaves widget-name checks running: a context on
     * one side only, a fallback edge or a `reserved` set that differs, and each of frame's contexts a registrant has
     * superseded. Grain is not in the file and is not compared (86 Q1).
     *
     * @return list<Finding>
     */
    private function vocabularyFindings(WidgetVocabulary $vocabulary): array
    {
        $findings = [];
        foreach ([
            'version' => [WidgetVocabulary::Version, $vocabulary->version()],
            'formModes' => [$this->set(array_column(FormMode::cases(), 'value')), $this->set($vocabulary->formModes())],
        ] as $axis => [$expected, $actual]) {
            if ($expected !== $actual) {
                $findings[] = Finding::fail(self::CHECK_VOCABULARY, "Widget vocabulary [{$vocabulary->path}] has incompatible {$axis}; expected ".json_encode($expected).', got '.json_encode($actual).'. Regenerate with pnpm frame:vocabulary --write.');
            }
        }
        array_push($findings, ...$this->contextFindings($vocabulary));
        foreach ($vocabulary->names() as $name) {
            foreach (array_unique($vocabulary->conflicts($name)) as $context) {
                $findings[] = Finding::warn(self::CHECK_VOCABULARY, "Widget [{$name}] has conflicting registrations in [{$context}]; node-type checking is skipped for that binding. Regenerate each kit with pnpm frame:vocabulary --write.");
            }
        }

        return $findings ?: [Finding::pass(self::CHECK_VOCABULARY, "Widget vocabulary [{$vocabulary->path}] matches laravel-frame and the frame.contexts registry. Freshness against the running JS build is not checked.")];
    }

    /** @return list<Finding> */
    private function contextFindings(WidgetVocabulary $vocabulary): array
    {
        $registry = app(WidgetContextRegistry::class);
        $store = $registry->unfiltered();
        $file = $vocabulary->path;
        $findings = [];

        $php = $registry->known();
        $kit = $vocabulary->contexts();
        foreach (array_diff($php, $kit) as $name) {
            $findings[] = Finding::warn(self::CHECK_VOCABULARY, "Render context [{$name}] is registered in PHP (by {$this->registrant($store, $name)}) but not in the kit's vocabulary [{$file}]; a widget declared in it has no binding in the browser. Register it in the host kit and run pnpm frame:vocabulary --write.");
        }
        foreach (array_diff($kit, $php) as $name) {
            $findings[] = Finding::warn(self::CHECK_VOCABULARY, "Render context [{$name}] is in the kit's vocabulary [{$file}] but not registered in PHP; no declaration can name it. Register it from a provider's boot() with app(WidgetContextRegistry::class)->register(new WidgetContext(...)), or remove it from the kit.");
        }

        $expected = $registry->inherits();
        $actual = $vocabulary->inherits();
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $name) {
            $want = $expected[$name] ?? [];
            $got = $actual[$name] ?? [];
            if ($want !== $got) {
                $findings[] = Finding::warn(self::CHECK_VOCABULARY, "Render context [{$name}] falls back to ".json_encode($want).' in PHP but to '.json_encode($got)." in the kit's vocabulary [{$file}]; an unbound widget there draws differently on each side. Align the fallback and run pnpm frame:vocabulary --write.");
            }
        }

        $reserved = $this->set(array_merge(...array_map(fn (WidgetContext $context): array => $context->reserved, $registry->all())));
        $listed = $this->set($vocabulary->reserved());
        if ($reserved !== $listed) {
            $findings[] = Finding::warn(self::CHECK_VOCABULARY, 'Reserved widget names differ: PHP reserves '.json_encode($reserved).", the kit's vocabulary [{$file}] lists ".json_encode($listed).'. Align them and run pnpm frame:vocabulary --write.');
        }

        if ($store instanceof RecordsSupersession) {
            foreach ($php as $name) {
                $displaced = $store->superseded(Key::parse(self::CONTEXTS.'.'.$name));
                if (($displaced[0] ?? null)?->by === WidgetContextRegistry::FRAME) {
                    $findings[] = Finding::warn(self::CHECK_VOCABULARY, "Render context [{$name}] registered by ".WidgetContextRegistry::FRAME." is superseded by {$this->registrant($store, $name)}.");
                }
            }
        }

        return $findings;
    }

    /** @param  Registry<WidgetContext>  $store */
    private function registrant(Registry $store, string $name): string
    {
        $by = $store instanceof RecordsRegistrants ? $store->registrantOf(Key::parse(self::CONTEXTS.'.'.$name)) : null;

        return $by ?? 'an unnamed registrant';
    }

    /** May `$name` stand here as a reserved name: at the root of a context block whose PHP entry reserves it. */
    private function reservedHere(DeclaredWidget $widget): bool
    {
        return $widget->source === DeclaredWidget::SourceContextBlock
            && $widget->pointer === ''
            && in_array($widget->name, app(WidgetContextRegistry::class)->context($widget->context)->reserved ?? [], true);
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<ResourceDefinition>
     */
    private function definitions(array &$findings): array
    {
        $definitions = [];
        foreach ($this->resources->unfiltered()->matches('beam.particle.resources') as $resource) {
            if (! $resource instanceof ParticleResource || ! $this->resources->hasAddressableResource($resource->key)) {
                continue;
            }
            try {
                $definitions[] = $this->resources->definition($resource->key);
            } catch (Throwable $e) {
                $findings[] = $this->projectionFailure($resource->key, 'definition', $resource->data ?? '', $e::class, $e->getMessage());
            }
        }
        foreach ($this->composite?->unfiltered()->matches('frame.resources') ?? [] as $member) {
            if ($member instanceof ParticleResourceRegistryAdapter) {
                continue;
            }
            try {
                array_push($definitions, ...$member->all());
            } catch (Throwable $e) {
                $findings[] = $this->projectionFailure('producer', 'listing', $member::class, $e::class, $e->getMessage());
            }
        }

        return $definitions;
    }

    private function projectionFailure(string $resource, string $source, string $class, string $exception, string $message, ?string $action = null): Finding
    {
        $origin = $class.($action === null ? '' : ', action '.$action);

        return Finding::fail(self::CHECK_PROJECTION, "Resource [{$resource}] could not project [{$source}] ({$origin}): {$exception}: {$message}. This projection was not walked.");
    }

    /** @param list<Finding> $findings */
    private function summarize(array &$findings, string $check, int $count, string $subject): void
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return;
            }
        }
        $findings[] = $count === 0
            ? Finding::inconclusive($check, "No {$subject} were walked; nothing was measured on this axis.")
            : Finding::pass($check, "Checked {$count} {$subject}; no findings among the declarations walked.");
    }

    private function pointer(DeclaredWidget $widget): string
    {
        return $widget->source === DeclaredWidget::SourceContextBlock && $widget->pointer !== ''
            ? '/properties/'.str_replace(['~', '/'], ['~0', '~1'], $widget->pointer)
            : $widget->pointer;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function set(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
