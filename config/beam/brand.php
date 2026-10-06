<?php

/*
 * The family's ONE brand declaration (tower-is-splicewire D8′; ux-walkthrough R2 D-2), read as `config('beam.brand.*')`
 * and resolved by `Splicewire\Beam\Brand\BrandData::current()`. Hosts and the SPA CARRY it (Inertia shares it; the SPA
 * receives it in runtime config); none of them authors a brand string. This file ships no literal brand: an unset key
 * falls back to `config('app.name')` or a neutral sentence.
 *
 * An install serving more than one product (www: Splicewire at `/`, Beam at `/beam/docs`) also declares `beam.brands`
 * (its own `config/beam/brands.php`): a map from a docs root's declared `product` to a brand of this same shape, read by
 * `Brand::forProduct()`. An undeclared product reads this file (docs-walkthrough DOCS-12, C-1).
 */
return [
    // The product name users see. Unset → `config('app.name')`.
    'name' => env('BEAM_BRAND_NAME'),

    // A URL or public path to the logo. Unset → none (renderers draw the name).
    'logo' => env('BEAM_BRAND_LOGO'),

    // Document title template, `:title` and `:name` placeholders. Unset → the renderer's own default.
    'title_template' => env('BEAM_BRAND_TITLE_TEMPLATE'),

    // The legal entity that processes end-user data on this install (the processor statement names it).
    // Unset → a neutral statement naming no company.
    'legal_entity' => env('BEAM_BRAND_LEGAL_ENTITY'),

    // The passkey card's sentence, verbatim. Unset → a neutral sentence built from `name`.
    'passkey_copy' => env('BEAM_BRAND_PASSKEY_COPY'),

    // How a buyer reaches the install (BUY-08, BQ-9): the upsell's "talk to us" address for a sales-led plan. Unset:
    // none, and the upsell offers no contact. A Splicewire host sets it to its one address (SPLICEWIRE_CONTACT_EMAIL).
    'contact' => [
        'sales' => env('BEAM_BRAND_CONTACT_SALES'),
    ],

    // The product's own site page, where a docs header's "back" link goes (DOCS-12). Unset → `/`.
    'home' => env('BEAM_BRAND_HOME'),

    // One line saying what the product is: the docs product switcher's blurb (DOCS-12, C-6). Unset → none.
    'tagline' => env('BEAM_BRAND_TAGLINE'),
];
