<?php

namespace Splicewire\Beam\Doctor;

use Schemastud\Doctor\Finding;

/**
 * Advisory, presence-conditional: if schemastud/laravel-schema-forms is installed (its
 * service provider class present, or a `schema-forms.submit` route registered), report the
 * submit door as reachable; otherwise PASS with a skip note. Base beam has no forms door,
 * and that is valid (ADR-0082). Never FAILs.
 */
class SchemaFormsDoorAudit
{
    public function run(): Finding
    {
        $check = 'schema-forms door';
        $providerClass = 'Splicewire\\SchemaForms\\SchemaFormsServiceProvider';

        if (class_exists($providerClass)) {
            return Finding::pass($check, 'schema-forms door reachable (provider present).');
        }

        try {
            $router = app('router');
            if ($router->has('schema-forms.submit')) {
                return Finding::pass($check, 'schema-forms door reachable (schema-forms.submit route registered).');
            }
        } catch (\Throwable) {
            // fall through to the skip note
        }

        return Finding::pass($check, 'schema-forms not installed — no forms door (headless beam is valid).');
    }
}
