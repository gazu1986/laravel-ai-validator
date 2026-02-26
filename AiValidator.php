<?php

declare(strict_types=1);

namespace gazu1986\AiValidator\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use gazu1986\AiValidator\Contracts\AiProviderContract;
use gazu1986\AiValidator\Contracts\StructuredOutputContract;
use gazu1986\AiValidator\Support\AttemptLog;
use gazu1986\AiValidator\Support\ValidationResult;

class AiValidator
{
    private ?AiProviderContract $provider = null;

    private ?int $maxAttempts = null;

    public function __construct(
        private readonly ProviderManager $providerManager,
    ) {}

    /**
     * Use a specific provider for this request.
     */
    public function using(string $provider): self
    {
        $clone = clone $this;
        $clone->provider = $this->providerManager->driver($provider);

        return $clone;
    }

    /**
     * Override max retry attempts for this request.
     */
    public function maxAttempts(int $attempts): self
    {
        $clone = clone $this;
        $clone->maxAttempts = $attempts;

        return $clone;
    }

    /**
     * Validate AI output against a StructuredOutput schema.
     *
     * @template T
     *
     * @param  StructuredOutputContract  $schema
     * @return ValidationResult<T>
     */
    public function validate(string $prompt, StructuredOutputContract $schema, array $options = []): ValidationResult
    {
        // Check cache first
        $cacheKey = $this->buildCacheKey($prompt, $schema);
        if ($cached = $this->checkCache($cacheKey)) {
            return $cached;
        }

        $provider = $this->provider ?? $this->providerManager->driver();
        $maxAttempts = $this->maxAttempts ?? config('ai-validator.retry.max_attempts', 3);
        $backoffMs = config('ai-validator.retry.backoff_ms', 500);
        $multiplier = config('ai-validator.retry.backoff_multiplier', 2.0);

        $attempts = [];
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $currentPrompt = $prompt;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startTime = microtime(true);

            // Send to AI provider
            $response = $provider->send(
                prompt: $currentPrompt,
                systemPrompt: $schema->systemPrompt(),
                options: $options,
            );

            $durationMs = (microtime(true) - $startTime) * 1000;
            $rawText = $response->text();
            $usage = $response->usage();

            // Accumulate token usage
            $totalUsage['prompt_tokens'] += $usage['prompt_tokens'];
            $totalUsage['completion_tokens'] += $usage['completion_tokens'];
            $totalUsage['total_tokens'] += $usage['total_tokens'];

            // Try to parse JSON
            $parsed = $this->parseJson($rawText);
            $jsonValid = $parsed !== null;

            // Validate against schema rules
            $validationErrors = [];
            $schemaValid = false;

            if ($jsonValid) {
                $validator = Validator::make($parsed, $schema->rules(), $schema->messages());
                $schemaValid = $validator->passes();
                $validationErrors = $validator->errors()->toArray();
            }

            // Log the attempt
            $attemptLog = new AttemptLog(
                attempt: $attempt,
                rawResponse: $rawText,
                parsedJson: $parsed,
                jsonValid: $jsonValid,
                schemaValid: $schemaValid,
                validationErrors: $validationErrors,
                usage: $usage,
                durationMs: $durationMs,
            );
            $attempts[] = $attemptLog;

            $this->logAttempt($attemptLog, $provider->name());

            // Success!
            if ($jsonValid && $schemaValid) {
                $castData = $schema->cast($validator->validated());

                $result = new ValidationResult(
                    data: $castData,
                    success: true,
                    attemptCount: $attempt,
                    attempts: $attempts,
                    totalUsage: $totalUsage,
                );

                $this->storeCache($cacheKey, $result);

                return $result;
            }

            // Build retry prompt with error context
            if ($attempt < $maxAttempts) {
                $currentPrompt = $this->buildRetryPrompt($prompt, $rawText, $jsonValid, $validationErrors);

                // Backoff before retry
                $sleepMs = (int) ($backoffMs * ($multiplier ** ($attempt - 1)));
                usleep($sleepMs * 1000);
            }
        }

        // All attempts failed
        $lastAttempt = end($attempts);
        $errorSummary = $lastAttempt->jsonValid
            ? 'Schema validation failed: ' . json_encode($lastAttempt->validationErrors)
            : 'Invalid JSON response from AI';

        return new ValidationResult(
            data: null,
            success: false,
            attemptCount: count($attempts),
            attempts: $attempts,
            totalUsage: $totalUsage,
            error: $errorSummary,
        );
    }

    /**
     * Quick validation with inline rules (no schema class needed).
     *
     * @param  array<string, mixed>  $rules
     * @return ValidationResult<array<string, mixed>>
     */
    public function validateWithRules(string $prompt, array $rules, array $options = []): ValidationResult
    {
        $schema = new class($rules) extends \gazu1986\AiValidator\Support\StructuredOutput
        {
            public function __construct(private readonly array $validationRules) {}

            public function rules(): array
            {
                return $this->validationRules;
            }
        };

        return $this->validate($prompt, $schema, $options);
    }

    /**
     * Parse JSON from AI response, handling common issues.
     */
    private function parseJson(string $text): ?array
    {
        // Strip markdown code fences if present
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*\n?/i', '', $text);
        $text = preg_replace('/\n?```\s*$/', '', $text);
        $text = trim($text);

        // Try direct parse
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to extract JSON object from surrounding text
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build retry prompt with error context.
     */
    private function buildRetryPrompt(string $originalPrompt, string $previousResponse, bool $jsonValid, array $errors): string
    {
        $parts = [$originalPrompt];

        $parts[] = "\n\n---\nYour previous response was invalid and needs correction.";

        if (! $jsonValid) {
            $parts[] = "ERROR: Your response was not valid JSON. Respond with ONLY a valid JSON object.";
            $parts[] = "Previous response (invalid): " . mb_substr($previousResponse, 0, 500);
        } else {
            $parts[] = 'ERROR: The JSON did not pass validation. Fix these errors:';
            foreach ($errors as $field => $fieldErrors) {
                foreach ($fieldErrors as $error) {
                    $parts[] = "  - {$field}: {$error}";
                }
            }
        }

        $parts[] = "\nPlease try again. Return ONLY the corrected JSON object.";

        return implode("\n", $parts);
    }

    /**
     * Build cache key from prompt + schema combination.
     */
    private function buildCacheKey(string $prompt, StructuredOutputContract $schema): string
    {
        $prefix = config('ai-validator.cache.prefix', 'ai_validator:');

        return $prefix . md5($prompt . serialize($schema->rules()));
    }

    /**
     * Check cache for existing result.
     */
    private function checkCache(string $key): ?ValidationResult
    {
        if (! config('ai-validator.cache.enabled', false)) {
            return null;
        }

        $store = config('ai-validator.cache.store');

        return Cache::store($store)->get($key);
    }

    /**
     * Store result in cache.
     */
    private function storeCache(string $key, ValidationResult $result): void
    {
        if (! config('ai-validator.cache.enabled', false)) {
            return;
        }

        $store = config('ai-validator.cache.store');
        $ttl = config('ai-validator.cache.ttl', 3600);

        Cache::store($store)->put($key, $result, $ttl);
    }

    /**
     * Log an attempt for debugging.
     */
    private function logAttempt(AttemptLog $attempt, string $providerName): void
    {
        if (! config('ai-validator.logging.enabled', true)) {
            return;
        }

        $channel = config('ai-validator.logging.channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $context = [
            'provider' => $providerName,
            'attempt' => $attempt->attempt,
            'json_valid' => $attempt->jsonValid,
            'schema_valid' => $attempt->schemaValid,
            'duration_ms' => round($attempt->durationMs, 2),
            'tokens' => $attempt->usage['total_tokens'] ?? 0,
        ];

        if (! empty($attempt->validationErrors)) {
            $context['errors'] = $attempt->validationErrors;
        }

        if (config('ai-validator.logging.log_responses', false)) {
            $context['response'] = mb_substr($attempt->rawResponse, 0, 1000);
        }

        if ($attempt->schemaValid) {
            $logger->info('AiValidator: attempt succeeded', $context);
        } else {
            $logger->warning('AiValidator: attempt failed', $context);
        }
    }
}
