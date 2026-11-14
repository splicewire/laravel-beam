<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Authorization\AccessError;
use Splicewire\Beam\Particle\ParticleRequestError;

/**
 * localization 23's beam side (coord-lead 01:04Z, the 23 tots legs): a coded error's message must work where no
 * translator is bound, such as a bare container in a pure unit test or a diagnostic path outside a booted app. It falls
 * back to the declared English with its parameters, as 21's last-resort encoder does. It never resolves the container
 * for a translator or a namespace allocator it doesn't have.
 */
final class CodedErrorWithoutTranslatorTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        Container::setInstance(new Container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public function test_a_coded_message_renders_its_declared_english_with_parameters_on_a_bare_container(): void
    {
        $this->assertSame('Unknown input field [wire_name].', ParticleRequestError::UnknownInputField->message(['field' => 'wire_name']));
        $this->assertSame('Workspace-shared particles require an initialized workspace.', AccessError::WorkspaceUninitialized->message());
    }

    public function test_it_resolves_nothing_from_the_container(): void
    {
        $container = Container::getInstance();
        ParticleRequestError::IntakeSchemaUnknown->message(['schema' => 'contact']);

        $this->assertFalse($container->resolved('translator'));
        $this->assertFalse($container->bound('translator'));
    }
}
