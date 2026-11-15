<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Validation\ValidationException;

/**
 * A config override write the store refuses: an unknown section, a key above its tier ceiling, a value its section
 * schema rejects, an `extend` collision, or a pointer DataOverlay cannot address. It is a {@see ValidationException},
 * so an op answers 422 with the reason under the offending field, the way any particle write refuses.
 */
final class ConfigOverrideRefusal
{
    public static function because(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
