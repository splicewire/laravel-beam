<?php

use Splicewire\Tower\InstructionsProviders\AgentInstructionsProvider;
use Splicewire\Tower\InstructionsProviders\ThreadInstructionsProvider;
use Splicewire\Tower\Services\PrismChatService;

return [
    'default_model' => 'gpt-4o-mini',
    // The Prism provider a chat call is emitted through. null = defer to the model's
    // own provider (a model entry may name `prism_provider`; absent means 'openai').
    // A tenant's llm_config.provider is mapped here in tenant context (TenancyServiceProvider),
    // making it the tenant-level say over the per-model provider. OpenAI is the only active
    // provider today — a new provider needs Prism creds AND a {provider}.tokens meter + pricing.
    'default_provider' => null,
    'max_tool_steps' => 8,
    'models' => [
        // https://platform.openai.com/docs/models
        'gpt-3.5-turbo' => [
            'provider' => PrismChatService::class,
            'token_limit' => 4097,
            'accepts' => [],
        ],
        'gpt-3.5-turbo-16k' => [
            'provider' => PrismChatService::class,
            'token_limit' => 16384,
            'accepts' => [],
        ],
        'gpt-4' => [
            'provider' => PrismChatService::class,
            'token_limit' => 8000,
            'accepts' => [],
        ],
        'gpt-4-turbo' => [
            'provider' => PrismChatService::class,
            'token_limit' => 128000,
            'accepts' => ['image', 'document'],
        ],
        'gpt-4o' => [
            'provider' => PrismChatService::class,
            'token_limit' => 128000,
            'instructions_limit' => 256000, // chars
            'accepts' => ['image', 'document'],
        ],
        'gpt-4o-mini' => [
            'provider' => PrismChatService::class,
            'token_limit' => 128000,
            'instructions_limit' => 256000, // chars
            'accepts' => ['image', 'document'],
        ],
        // Anthropic Claude — served on the platform ANTHROPIC_API_KEY (SOURCE: Platform) until a
        // tenant connects its own conduit-model-provider for `anthropic` (then SOURCE: Your key).
        // `prism_provider => 'anthropic'` routes the Prism call (ThreadService::prismProvider);
        // token_limit is the input context window. Metered via the `anthropic.tokens` meter.
        'claude-opus-4-8' => [
            'provider' => PrismChatService::class,
            'prism_provider' => 'anthropic',
            'token_limit' => 1000000,
            'instructions_limit' => 256000, // chars
            'accepts' => ['image', 'document'],
        ],
        'claude-sonnet-5' => [
            'provider' => PrismChatService::class,
            'prism_provider' => 'anthropic',
            'token_limit' => 1000000,
            'instructions_limit' => 256000, // chars
            'accepts' => ['image', 'document'],
        ],
        'claude-haiku-4-5' => [
            'provider' => PrismChatService::class,
            'prism_provider' => 'anthropic',
            'token_limit' => 200000,
            'instructions_limit' => 256000, // chars
            'accepts' => ['image', 'document'],
        ],
        'claude-fable-5' => [
            'provider' => PrismChatService::class,
            'prism_provider' => 'anthropic',
            'token_limit' => 1000000,
            'instructions_limit' => 256000, // chars
            'accepts' => ['image', 'document'],
        ],
        // These are not yet available with the Assistants API
        // 'o1-preview' => [
        //     'provider' => Splicewire\Tower\Services\OpenAiService::class,
        //     'token_limit' => 128000,
        //     'instructions_limit' => 256000, // chars
        // ],
        // 'o1-mini' => [
        //     'provider' => Splicewire\Tower\Services\OpenAiService::class,
        //     'token_limit' => 128000,
        //     'instructions_limit' => 256000, // chars
        // ],
    ],
    'instructions_providers' => [
        'agent' => [
            'provider' => AgentInstructionsProvider::class,
        ],
        'chat' => [
            'provider' => ThreadInstructionsProvider::class,
        ],
    ],
];
