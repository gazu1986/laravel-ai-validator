<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The default AI provider to use when none is specified. Supported
    | providers: "openai", "anthropic", "ollama", "custom".
    |
    */

    'default_provider' => env('AI_VALIDATOR_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure each AI provider with their API keys and default models.
    | The package uses these settings when making retry requests with
    | error context to get corrected structured output.
    |
    */

    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('AI_VALIDATOR_OPENAI_MODEL', 'gpt-4o'),
            'temperature' => 0.0,
            'max_tokens' => 4096,
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('AI_VALIDATOR_ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
            'temperature' => 0.0,
            'max_tokens' => 4096,
        ],

        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('AI_VALIDATOR_OLLAMA_MODEL', 'llama3'),
            'temperature' => 0.0,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | When AI output fails validation, the package can automatically retry
    | with error context appended to the prompt. Configure max attempts
    | and backoff strategy here.
    |
    */

    'retry' => [
        'max_attempts' => env('AI_VALIDATOR_MAX_RETRIES', 3),
        'backoff_ms' => env('AI_VALIDATOR_BACKOFF_MS', 500),
        'backoff_multiplier' => 2.0,
        'include_errors_in_retry' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Cache validated responses to avoid redundant API calls for identical
    | prompts + schema combinations.
    |
    */

    'cache' => [
        'enabled' => env('AI_VALIDATOR_CACHE_ENABLED', false),
        'store' => env('AI_VALIDATOR_CACHE_STORE', null), // null = default
        'ttl' => env('AI_VALIDATOR_CACHE_TTL', 3600),
        'prefix' => 'ai_validator:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Log all validation attempts, retries, and failures for debugging
    | and monitoring token usage.
    |
    */

    'logging' => [
        'enabled' => env('AI_VALIDATOR_LOGGING', true),
        'channel' => env('AI_VALIDATOR_LOG_CHANNEL', null), // null = default
        'log_prompts' => env('AI_VALIDATOR_LOG_PROMPTS', false),
        'log_responses' => env('AI_VALIDATOR_LOG_RESPONSES', false),
    ],

];
