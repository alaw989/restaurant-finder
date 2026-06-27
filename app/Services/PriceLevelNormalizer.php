<?php

namespace App\Services;

class PriceLevelNormalizer
{
    /**
     * Normalize a price_range string to a numeric level 1-4 for sorting.
     * Returns null if the price range cannot be determined.
     *
     * @param  string|null  $priceRange  The price range text (e.g., "$", "$$$", "€10-€30", "moderate")
     * @return int|null Numeric level 1-4, or null if undeterminable
     */
    public function normalize(?string $priceRange): ?int
    {
        if ($priceRange === null || $priceRange === '') {
            return null;
        }

        $trimmed = trim($priceRange);

        // Step 1: Check for repeated currency symbols (2+) NOT followed immediately by a number
        // Patterns like "$$", "€€€", "$$ (cheap)" -> count the symbols
        // But NOT "$10", "€15-€30" -> those are price values, not levels
        if (preg_match('/^([\$\€\£\¥\₩\₹\₽\₴\₦\₪\₫\₡\₸\₱\₲\₾\₮])\1/u', $trimmed)) {
            // We have at least 2 repeated symbols
            $remaining = preg_replace('/^([\$\€\£\¥\₩\₹\₽\₴\₦\₪\₫\₡\₸\₱\₲\₾\₮])+/u', '', $trimmed, 1);
            // If what follows starts with a digit, this is a price value like "$10-30", not a level indicator
            if ($remaining === '' || $remaining === ' ' || ! preg_match('/^\d/', ltrim($remaining, ' '))) {
                // Count consecutive symbols at the start
                preg_match('/^([\$\€\£\¥\₩\₹\₽\₴\₦\₪\₫\₡\₸\₱\₲\₾\₮])+/u', $trimmed, $matches);
                if (isset($matches[0])) {
                    return min(mb_strlen($matches[0]), 4);
                }
            }
        }

        // Step 2: Extract numbers from patterns like "10-30" or "$10-$15" -> use average
        if (preg_match_all('/\d+/', $priceRange, $matches)) {
            $numbers = array_map('intval', $matches[0]);
            if (! empty($numbers)) {
                $avg = array_sum($numbers) / count($numbers);
                // Map average price to level: <$20 = 1, $20-$40 = 2, $40-$80 = 3, $80+ = 4
                if ($avg < 20) {
                    return 1;
                }
                if ($avg < 40) {
                    return 2;
                }
                if ($avg < 80) {
                    return 3;
                }

                return 4;
            }
        }

        // Step 3: Handle a single currency symbol (often means cheap/budget) - treat as level 1
        if ($trimmed === '$' || $trimmed === '€' || $trimmed === '£' || $trimmed === '¥' || $trimmed === '₩') {
            return 1;
        }

        // Step 4: Handle text-based price descriptions
        $lower = strtolower($trimmed);
        $textToLevel = [
            'cheap' => 1,
            'inexpensive' => 1,
            'budget' => 1,
            'affordable' => 1,
            'moderate' => 2,
            'moderate pricing' => 2,
            'mid-range' => 2,
            'mid range' => 2,
            'expensive' => 3,
            'pricey' => 3,
            'upscale' => 3,
            'fine dining' => 4,
            'luxury' => 4,
            'high-end' => 4,
            'high end' => 4,
        ];

        foreach ($textToLevel as $text => $level) {
            if (str_contains($lower, $text)) {
                return $level;
            }
        }

        return null;
    }
}
