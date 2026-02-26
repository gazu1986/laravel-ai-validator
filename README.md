# Laravel AI Validator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gazu1986/laravel-ai-validator.svg)](https://packagist.org/packages/gazu1986/laravel-ai-validator)
[![Total Downloads](https://img.shields.io/packagist/dt/gazu1986/laravel-ai-validator.svg)](https://packagist.org/packages/gazu1986/laravel-ai-validator)
[![License](https://img.shields.io/packagist/l/gazu1986/laravel-ai-validator.svg)](https://packagist.org/packages/gazu1986/laravel-ai-validator)

**Validate, retry, and type-cast structured AI output using Laravel's validation rules.**

Stop writing defensive JSON parsing code for AI responses. Define your expected output schema with familiar Laravel rules, and let this package handle validation, retries with error context, and type casting — across any AI provider.

---

## The Problem

Every time you call an AI API expecting structured JSON, you end up writing:

```php
// 😩 The reality of working with AI APIs
$response = $openai->chat($prompt);
$json = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // retry? log? throw? strip markdown fences?
}

if (!isset($json['sentiment']) || !in_array($json['sentiment'], ['positive', 'negative'])) {
    // retry with error context? give up?
}

// ... 30 more lines of defensive code
```

## The Solution

```php
// 😎 With Laravel AI Validator
$result = AiValidator::validate(
    'Analyze the sentiment of this review: "Great product!"',
    new SentimentSchema()
);

$result->dataOrFail(); // Typed, validated data — or throws with full attempt history
```

---

## Requirements

- PHP 8.2+
- Laravel 12.x

## Installation

```bash
composer require gazu1986/laravel-ai-validator
```

Publish the config:

```bash
php artisan vendor:publish --tag=ai-validator-config
```

Add your API key to `.env`:

```env
# Use any provider: openai, anthropic, ollama
AI_VALIDATOR_PROVIDER=openai
OPENAI_API_KEY=sk-...

# Or Anthropic
# AI_VALIDATOR_PROVIDER=anthropic
# ANTHROPIC_API_KEY=sk-ant-...
```

---

## Quick Start

### 1. Create a Schema

```bash
php artisan make:ai-schema ProductReviewSchema
```

This creates `app/AiSchemas/ProductReviewSchema.php`:

```php
use gazu1986\AiValidator\Support\StructuredOutput;

class ProductReviewSchema extends StructuredOutput
{
    public function rules(): array
    {
        return [
            'sentiment'  => ['required', 'string', 'in:positive,negative,mixed,neutral'],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'summary'    => ['required', 'string', 'min:10', 'max:200'],
            'pros'       => ['required', 'array', 'min:1'],
            'pros.*'     => ['string', 'max:100'],
            'cons'       => ['present', 'array'],
            'cons.*'     => ['string', 'max:100'],
        ];
    }
}
```

### 2. Validate AI Output

```php
use gazu1986\AiValidator\Facades\AiValidator;

$result = AiValidator::validate(
    'Analyze this review: "Amazing laptop, but the keyboard feels cheap"',
    new ProductReviewSchema()
);

if ($result->success) {
    $data = $result->data;
    // ['sentiment' => 'mixed', 'confidence' => 0.85, 'summary' => '...', ...]
}
```

### 3. Or Use Inline Rules (No Schema Class)

```php
$result = AiValidator::validateWithRules(
    'Extract the person name and age from: "John is 30 years old"',
    [
        'name' => ['required', 'string'],
        'age'  => ['required', 'integer', 'min:0'],
    ]
);

$result->data; // ['name' => 'John', 'age' => 30]
```

---

## How It Works

```
┌──────────┐     ┌──────────────┐     ┌───────────┐     ┌──────────┐
│  Prompt  │────▶│  AI Provider │────▶│  Parse    │────▶│ Validate │
│          │     │  (send)      │     │  JSON     │     │  Rules   │
└──────────┘     └──────────────┘     └───────────┘     └──────────┘
                        ▲                                     │
                        │              ┌───────────┐          │
                        └──────────────│  Retry    │◀─────────┘
                       (with errors)   │  Engine   │    (if failed)
                                       └───────────┘
                                                          │
                                                    ┌─────▼──────┐
                                                    │  Cast to   │
                                                    │  DTO/Type  │
                                                    └────────────┘
```

1. **Send** — Your prompt + auto-generated system prompt → AI provider
2. **Parse** — Strip markdown fences, extract JSON, handle edge cases
3. **Validate** — Run through Laravel's validator with your rules
4. **Retry** — If failed, append error context and retry (configurable)
5. **Cast** — Transform validated data into your DTO/typed object

---

## Type-Safe Casting

Cast validated data to a typed DTO:

```php
class ProductReviewSchema extends StructuredOutput
{
    public function rules(): array { /* ... */ }

    public function cast(array $validatedData): ProductReviewDTO
    {
        return new ProductReviewDTO(
            sentiment: Sentiment::from($validatedData['sentiment']),
            confidence: (float) $validatedData['confidence'],
            summary: $validatedData['summary'],
            pros: $validatedData['pros'],
            cons: $validatedData['cons'],
        );
    }
}

// Usage
$result = AiValidator::validate($prompt, new ProductReviewSchema());
$dto = $result->dataOrFail(); // ProductReviewDTO instance — or throws
```

---

## Multiple Providers

Switch providers per-request:

```php
// Default provider (from config)
AiValidator::validate($prompt, $schema);

// Use Anthropic for this request
AiValidator::using('anthropic')->validate($prompt, $schema);

// Use Ollama for local development
AiValidator::using('ollama')->validate($prompt, $schema);
```

---

## Retry & Error Handling

### Automatic Retries

The package retries with error context automatically:

```php
// Override max attempts per-request
$result = AiValidator::maxAttempts(5)->validate($prompt, $schema);

// Check what happened
$result->attemptCount;   // How many attempts were needed
$result->totalTokens();  // Total tokens consumed across all attempts
$result->attempts;       // Array of AttemptLog objects
```

### Inspecting Failures

```php
$result = AiValidator::validate($prompt, $schema);

if (!$result->success) {
    // What went wrong on the last attempt?
    $lastAttempt = end($result->attempts);
    $lastAttempt->jsonValid;         // Was it valid JSON?
    $lastAttempt->schemaValid;       // Did it pass validation?
    $lastAttempt->validationErrors;  // Laravel validation errors
    $lastAttempt->rawResponse;       // Raw AI response text
}

// Or throw with full context
try {
    $result->dataOrFail();
} catch (ValidationFailedException $e) {
    $e->lastErrors();  // Validation errors from final attempt
    $e->attempts;      // Full attempt history
}
```

---

## Custom System Prompts

The package auto-generates system prompts from your rules, but you can override:

```php
class ContactExtractionSchema extends StructuredOutput
{
    public function rules(): array { /* ... */ }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a contact information extractor.
        Extract all people mentioned and their details.
        Respond with ONLY valid JSON matching the required schema.
        Set confidence between 0-1 for each extracted field.
        PROMPT;
    }
}
```

---

## Configuration

```php
// config/ai-validator.php

return [
    'default_provider' => env('AI_VALIDATOR_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('AI_VALIDATOR_OPENAI_MODEL', 'gpt-4o'),
            'temperature' => 0.0,
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('AI_VALIDATOR_ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
        ],
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => 'llama3',
        ],
    ],

    'retry' => [
        'max_attempts' => 3,
        'backoff_ms' => 500,
        'backoff_multiplier' => 2.0,
    ],

    'cache' => [
        'enabled' => false,
        'ttl' => 3600,
    ],

    'logging' => [
        'enabled' => true,
        'log_prompts' => false,
        'log_responses' => false,
    ],
];
```

---

## Testing

The package works seamlessly with Laravel's HTTP faking:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.openai.com/*' => Http::response([
        'choices' => [[
            'message' => ['content' => '{"name":"John","age":30}'],
            'finish_reason' => 'stop',
        ]],
        'model' => 'gpt-4o',
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
    ]),
]);

$result = AiValidator::validateWithRules('Extract info', [
    'name' => ['required', 'string'],
    'age' => ['required', 'integer'],
]);

expect($result->success)->toBeTrue();
```

Run the package tests:

```bash
composer test
```

---

## Real-World Examples

Check the `examples/` directory for production-ready schemas:

- **ProductReviewSchema** — Sentiment analysis with typed DTO casting
- **ContactExtractionSchema** — Extract contacts with custom system prompt

---

## Roadmap

- [ ] JSON Schema support (alongside Laravel rules)
- [ ] Streaming validation for long outputs
- [ ] Provider-native structured output (OpenAI JSON mode, Anthropic tool use)
- [ ] Artisan command to test schemas interactively
- [ ] Token budget limits per validation
- [ ] Event dispatching for monitoring integrations

---

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
