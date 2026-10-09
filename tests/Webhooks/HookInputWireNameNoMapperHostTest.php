<?php

namespace Splicewire\Beam\Tests\Webhooks;

/** The same HookInputData wire-name cases on a host with NO global input mapper (the starters' shape). */
class HookInputWireNameNoMapperHostTest extends HookInputWireNameTest
{
    protected function hostInputMapper(): ?string
    {
        return null;
    }
}
