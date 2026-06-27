<?php

namespace Tests\Unit;

use App\Services\PriceLevelNormalizer;
use PHPUnit\Framework\TestCase;

class PriceLevelNormalizerTest extends TestCase
{
    private PriceLevelNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PriceLevelNormalizer;
    }

    public function test_null_and_empty_strings_return_null(): void
    {
        $this->assertNull($this->normalizer->normalize(null));
        $this->assertNull($this->normalizer->normalize(''));
    }

    public function test_counts_dollar_signs(): void
    {
        $this->assertSame(1, $this->normalizer->normalize('$'));
        $this->assertSame(2, $this->normalizer->normalize('$$'));
        $this->assertSame(3, $this->normalizer->normalize('$$$'));
        $this->assertSame(4, $this->normalizer->normalize('$$$$'));
    }

    public function test_counts_euro_signs(): void
    {
        $this->assertSame(1, $this->normalizer->normalize('€'));
        $this->assertSame(2, $this->normalizer->normalize('€€'));
        $this->assertSame(3, $this->normalizer->normalize('€€€'));
    }

    public function test_counts_pound_signs(): void
    {
        $this->assertSame(1, $this->normalizer->normalize('£'));
        $this->assertSame(2, $this->normalizer->normalize('££'));
    }

    public function test_counts_yen_and_won_signs(): void
    {
        $this->assertSame(1, $this->normalizer->normalize('¥'));
        $this->assertSame(2, $this->normalizer->normalize('¥¥'));
        $this->assertSame(1, $this->normalizer->normalize('₩'));
        $this->assertSame(3, $this->normalizer->normalize('₩₩₩'));
    }

    public function test_counts_mixed_currency_symbols_capped_at_4(): void
    {
        // Multiple symbols should be counted but capped at 4
        $this->assertSame(4, $this->normalizer->normalize('$$$$$'));
    }

    public function test_price_range_with_dollar_and_number(): void
    {
        // "$10-$15" should extract the average ~12.5 and return 1
        $this->assertSame(1, $this->normalizer->normalize('$10-$15'));
    }

    public function test_price_range_with_euro_and_number(): void
    {
        // "€10-€30" should extract average 20 and return 2
        $this->assertSame(2, $this->normalizer->normalize('€10-€30'));
    }

    public function test_price_range_higher_average(): void
    {
        // "$50-$100" average is 75, should return 3
        $this->assertSame(3, $this->normalizer->normalize('$50-$100'));

        // "$100-$150" average is 125, should return 4
        $this->assertSame(4, $this->normalizer->normalize('$100-$150'));
    }

    public function test_text_based_price_descriptions(): void
    {
        $this->assertSame(1, $this->normalizer->normalize('cheap'));
        $this->assertSame(1, $this->normalizer->normalize('inexpensive'));
        $this->assertSame(1, $this->normalizer->normalize('budget'));
        $this->assertSame(1, $this->normalizer->normalize('affordable'));

        $this->assertSame(2, $this->normalizer->normalize('moderate'));
        $this->assertSame(2, $this->normalizer->normalize('moderate pricing'));
        $this->assertSame(2, $this->normalizer->normalize('mid-range'));
        $this->assertSame(2, $this->normalizer->normalize('mid range'));

        $this->assertSame(3, $this->normalizer->normalize('expensive'));
        $this->assertSame(3, $this->normalizer->normalize('pricey'));
        $this->assertSame(3, $this->normalizer->normalize('upscale'));

        $this->assertSame(4, $this->normalizer->normalize('fine dining'));
        $this->assertSame(4, $this->normalizer->normalize('luxury'));
        $this->assertSame(4, $this->normalizer->normalize('high-end'));
        $this->assertSame(4, $this->normalizer->normalize('high end'));
    }

    public function test_case_insensitive_text_matching(): void
    {
        $this->assertSame(1, $this->normalizer->normalize('CHEAP'));
        $this->assertSame(1, $this->normalizer->normalize('Cheap'));
        $this->assertSame(2, $this->normalizer->normalize('MODERATE'));
        $this->assertSame(3, $this->normalizer->normalize('EXPENSIVE'));
    }

    public function test_text_with_extra_whitespace(): void
    {
        $this->assertSame(2, $this->normalizer->normalize('  moderate  '));
        $this->assertSame(3, $this->normalizer->normalize('  expensive  pricing  '));
    }

    public function test_mixed_symbol_and_text(): void
    {
        // "$$ (cheap)" - symbols take precedence
        $this->assertSame(2, $this->normalizer->normalize('$$ (cheap)'));
    }

    public function test_unrecognized_format_returns_null(): void
    {
        $this->assertNull($this->normalizer->normalize('unknown format'));
        $this->assertNull($this->normalizer->normalize('abc'));
    }
}
