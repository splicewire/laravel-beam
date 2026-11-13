<?php

namespace Splicewire\Beam\Collections;

use Closure;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\PayloadRejected;
use Splicewire\Beam\Write\Stages\ValidateStage;
use Splicewire\Beam\Write\WriteContext;

/** Translate the shared acceptance refusal into the Collection input's validation error. */
final class CollectionValidateStage implements WriteStage
{
    public function __construct(private ValidateStage $validate) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        try {
            $context = $this->validate->handle($context, fn (WriteContext $validated): WriteContext => $validated);
        } catch (PayloadRejected $error) {
            throw CodedValidationException::for('fields', CollectionError::PayloadRejected, ['type' => $error->recordType()]);
        }

        return $next($context);
    }
}
