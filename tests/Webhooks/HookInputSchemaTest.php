<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Tests\TestCase;

/**
 * owner-review-20261102 O9, slice 09-T1: the hook form reads its fields from HookInputData's schema, so the
 * schema has to say what `events` holds (strings, catalog event names) and describe each field in words a
 * subscriber uses. Without item types a form can only offer a free-form list.
 */
class HookInputSchemaTest extends TestCase
{
    public function test_events_declares_string_items(): void
    {
        $events = HookInputData::jsonSchema()['properties']['events'] ?? null;

        $this->assertNotNull($events, 'events is in the input schema');
        $this->assertContains('array', (array) ($events['type'] ?? []));
        $this->assertSame('string', $events['items']['type'] ?? null);
    }

    public function test_every_input_field_carries_a_plain_description(): void
    {
        $properties = HookInputData::jsonSchema()['properties'];

        foreach (['endpoint', 'events', 'token', 'subjectType', 'subjectId', 'paused'] as $field) {
            $description = $properties[$field]['description'] ?? '';
            $this->assertNotSame('', $description, "{$field} has a description");
            $this->assertDoesNotMatchRegularExpression('/\b(HMAC|webhook catalog|delivery reach|dispatching)\b/i', $description, "{$field} uses plain words");
        }
    }
}
