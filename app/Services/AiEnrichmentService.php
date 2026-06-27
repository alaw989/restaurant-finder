<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI enrichment service using OpenAI-compatible API (default: Groq).
 *
 * Normalizes and extracts structured fields from restaurant data:
 * - cuisines (normalized list)
 * - normalized address
 * - gap fields (phone, website_url, etc.)
 *
 * NEVER produces ratings - only structural/attribute fields.
 * Gracefully degrades to no-op when no key is configured.
 */
class AiEnrichmentService
{
    private ?string $apiKey;

    private ?string $baseUrl;

    private ?string $model;

    public function __construct()
    {
        $this->apiKey = config('services.ai.api_key');
        $this->baseUrl = config('services.ai.base_url', 'https://api.groq.com/openai/v1');
        $this->model = config('services.ai.model', 'llama-3.3-70b-versatile');
    }

    /**
     * Enrich a single restaurant with AI-extracted structured data.
     * Returns the enrichment data or null on error/no-key.
     */
    public function enrichRestaurant(array $restaurantData): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $prompt = $this->buildPrompt($restaurantData);
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a data normalization assistant for restaurant data. Extract structured information and normalize it. NEVER invent ratings or scores - only extract structural/attribute fields that are present or can be reasonably inferred. Return valid JSON only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if ($response->failed()) {
                Log::warning('AI enrichment request failed', [
                    'status' => $response->status(),
                    'restaurant_id' => $restaurantData['id'] ?? null,
                ]);

                return null;
            }

            $data = $response->json();

            $content = $data['choices'][0]['message']['content'] ?? null;

            if (empty($content)) {
                return null;
            }

            $parsed = json_decode($content, true);

            if (! is_array($parsed)) {
                Log::warning('AI enrichment returned invalid JSON', [
                    'content' => $content,
                    'restaurant_id' => $restaurantData['id'] ?? null,
                ]);

                return null;
            }

            // Filter out any ratings (AI must not produce ratings)
            unset($parsed['rating'], $parsed['review_count'], $parsed['score']);

            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('AI enrichment threw exception', [
                'message' => $e->getMessage(),
                'restaurant_id' => $restaurantData['id'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * Build the enrichment prompt for a restaurant.
     */
    private function buildPrompt(array $restaurantData): string
    {
        $name = $restaurantData['name'] ?? 'Unknown';
        $address = $restaurantData['address'] ?? null;
        $city = $restaurantData['city'] ?? null;
        $state = $restaurantData['state'] ?? null;
        $postalCode = $restaurantData['postal_code'] ?? null;
        $phone = $restaurantData['phone'] ?? null;
        $website = $restaurantData['website_url'] ?? null;
        $description = $restaurantData['description'] ?? null;

        $prompt = "Extract and normalize structured data for this restaurant:\n\n";
        $prompt .= "Name: {$name}\n";

        if ($address) {
            $prompt .= "Address: {$address}\n";
        }
        if ($city) {
            $prompt .= "City: {$city}\n";
        }
        if ($state) {
            $prompt .= "State: {$state}\n";
        }
        if ($postalCode) {
            $prompt .= "Postal Code: {$postalCode}\n";
        }
        if ($phone) {
            $prompt .= "Phone: {$phone}\n";
        }
        if ($website) {
            $prompt .= "Website: {$website}\n";
        }
        if ($description) {
            $prompt .= "Description: {$description}\n";
        }

        $prompt .= "\nReturn a JSON object with these fields (only include fields with values):\n";
        $prompt .= "- normalized_address: full normalized street address\n";
        $prompt .= "- phone: normalized phone number (if present and can be normalized)\n";
        $prompt .= "- website_url: normalized/cleaned website URL (if present)\n";
        $prompt .= "- cuisines: array of cuisine types (e.g., [\"Italian\", \"Pizza\"])\n";
        $prompt .= "- description: brief description (if missing and can be inferred)\n";
        $prompt .= "\nDO NOT include: rating, review_count, score, or any ratings fields.";

        return $prompt;
    }
}
