<?php

namespace App\Services\PixelForge;

use InvalidArgumentException;

/**
 * Exact pricing math for PixelForge generations.
 *
 * All amounts are integer picos (trillionths of a US dollar) held in signed
 * native integers, which is far beyond anything the provider can charge. No
 * floats are used: provider decimals are parsed digit by digit, and JSON
 * numbers are converted to a decimal representation first. Malformed,
 * negative, or nonrepresentable provider data is rejected rather than
 * rounded.
 */
final class PixelForgePricing
{
    private const int PICOS_PER_DOLLAR = 1_000_000_000_000;

    private const int MICROS_PER_DOLLAR = 1_000_000;

    private const int RATE_DECIMAL_SCALE = 6;

    private const int MARKUP_NUMERATOR = 5;

    private const int MARKUP_DENOMINATOR = 4;

    /**
     * The four priced dimensions, in usage-key => rate-key order. The total
     * token count reported by the provider is never used.
     *
     * @var array<string, string>
     */
    private const array DIMENSIONS = [
        'input_text_tokens' => 'input_text',
        'input_image_tokens' => 'input_image',
        'output_tokens' => 'output',
        'reasoning_tokens' => 'reasoning',
    ];

    /**
     * Estimate bounds for a generation, in picos, with the 25% markup applied.
     *
     * @param  array<string, mixed>  $pricing  Decoded body of GET /v1/pricing.
     * @return array{min: int, max: int}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceCount, array $pricing): array
    {
        if ($referenceCount < 0) {
            throw new InvalidArgumentException('Reference count must not be negative.');
        }

        $tokens = $pricing['tokens'] ?? null;
        if (! is_array($tokens)) {
            throw new InvalidArgumentException('Pricing data is missing the tokens object.');
        }

        $charactersPerToken = $this->positiveInt(
            $tokens['characters_per_text_token'] ?? null,
            'tokens.characters_per_text_token'
        );
        [$varianceNumerator, $varianceDenominator] = $this->fraction(
            $tokens['text_token_variance'] ?? null,
            'tokens.text_token_variance'
        );
        if ($varianceNumerator > $varianceDenominator) {
            throw new InvalidArgumentException('tokens.text_token_variance must not exceed 1.');
        }
        $perReferenceImage = $this->nonNegativeInt(
            $tokens['per_reference_image'] ?? null,
            'tokens.per_reference_image'
        );
        $outputTokens = $this->nonNegativeInt(
            $this->nested($tokens, ['output', $quality, $size]),
            "tokens.output.$quality.$size"
        );
        $reasoningMin = $this->nonNegativeInt(
            $this->nested($tokens, ['reasoning', $quality, 'min']),
            "tokens.reasoning.$quality.min"
        );
        $reasoningMax = $this->nonNegativeInt(
            $this->nested($tokens, ['reasoning', $quality, 'max']),
            "tokens.reasoning.$quality.max"
        );
        if ($reasoningMin > $reasoningMax) {
            throw new InvalidArgumentException("tokens.reasoning.$quality min must not exceed max.");
        }

        $rates = $pricing['rates'] ?? null;
        if (! is_array($rates)) {
            throw new InvalidArgumentException('Pricing data is missing the rates object.');
        }

        $base = $this->ceilDiv(mb_strlen($prompt, 'UTF-8'), $charactersPerToken);
        $textMin = intdiv(
            $this->multiply($base, $varianceDenominator - $varianceNumerator),
            $varianceDenominator
        );
        $textMax = $this->ceilDiv(
            $this->multiply($base, $this->add($varianceDenominator, $varianceNumerator)),
            $varianceDenominator
        );
        $imageTokens = $this->multiply($perReferenceImage, $referenceCount);

        $usage = [
            'input_text_tokens' => $textMin,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningMin,
        ];

        return [
            'min' => $this->settle($usage, $rates)['charge'],
            'max' => $this->settle(array_merge($usage, [
                'input_text_tokens' => $textMax,
                'reasoning_tokens' => $reasoningMax,
            ]), $rates)['charge'],
        ];
    }

    /**
     * Exact provider cost and user charge for a succeeded generation, in picos.
     *
     * @param  array<string, mixed>  $usage  Final token counts; total_tokens is ignored.
     * @param  array<string, mixed>  $rates  Locked decimal rates in USD per million tokens.
     * @return array{cost: int, charge: int}
     */
    public function settle(array $usage, array $rates): array
    {
        $cost = 0;

        foreach (self::DIMENSIONS as $usageKey => $rateKey) {
            $tokenCount = $this->nonNegativeInt($usage[$usageKey] ?? null, "usage.$usageKey");
            $cost = $this->add($cost, $this->multiply($tokenCount, $this->rateMicros($rates, $usageKey, $rateKey)));
        }

        return [
            'cost' => $cost,
            'charge' => $this->withMarkup($cost),
        ];
    }

    /**
     * Format picos as a decimal dollar string: up to 12 decimal places with
     * trailing zeros trimmed, always keeping at least two.
     */
    public function formatUsd(int $picos): string
    {
        if ($picos < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        $dollars = intdiv($picos, self::PICOS_PER_DOLLAR);
        $fraction = rtrim(str_pad((string) ($picos % self::PICOS_PER_DOLLAR), 12, '0', STR_PAD_LEFT), '0');

        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }

        return $dollars.'.'.$fraction;
    }

    /**
     * Parse a decimal rate in USD per million tokens into integer millionths
     * of a dollar per million tokens; multiplying by a token count then
     * yields picos directly.
     *
     * @param  array<string, mixed>  $rates
     */
    private function rateMicros(array $rates, string $usageKey, string $rateKey): int
    {
        // The documented keys mirror the usage keys; short keys are accepted
        // for compatibility but never win on conflict.
        $value = $rates[$usageKey] ?? $rates[$rateKey] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("rates.$usageKey must be a decimal string.");
        }

        [$intPart, $fracPart] = $this->decimalParts($value, "rates.$usageKey");

        if (strlen($fracPart) > self::RATE_DECIMAL_SCALE) {
            if (substr($fracPart, self::RATE_DECIMAL_SCALE) !== str_repeat('0', strlen($fracPart) - self::RATE_DECIMAL_SCALE)) {
                throw new InvalidArgumentException("rates.$usageKey is not representable in millionths of a dollar.");
            }
            $fracPart = substr($fracPart, 0, self::RATE_DECIMAL_SCALE);
        }

        if (strlen(ltrim($intPart, '0')) > 12) {
            throw new InvalidArgumentException("rates.$usageKey exceeds the representable range.");
        }

        return $this->add(
            $this->multiply((int) $intPart, self::MICROS_PER_DOLLAR),
            (int) str_pad($fracPart, self::RATE_DECIMAL_SCALE, '0')
        );
    }

    /**
     * Split a decimal string or JSON number into integer and fraction digit
     * strings without using float arithmetic. JSON numbers are converted to
     * their shortest exact decimal representation first.
     *
     * @return array{0: string, 1: string}
     */
    private function decimalParts(mixed $value, string $name): array
    {
        $decimal = match (true) {
            is_int($value) => (string) $value,
            is_string($value) => $value,
            is_float($value) => $this->floatToDecimal($value),
            default => throw new InvalidArgumentException("$name must be a decimal string or number."),
        };

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException("$name is not a valid decimal value.");
        }

        return [$matches[1], $matches[2] ?? ''];
    }

    /**
     * Convert a JSON number to its shortest exact decimal representation,
     * expanding exponent notation to plain decimal digits.
     */
    private function floatToDecimal(float $value): string
    {
        $representation = json_encode($value);

        if (! is_string($representation)) {
            throw new InvalidArgumentException('Non-finite numeric value.');
        }

        if (strpbrk($representation, 'eE') === false) {
            return $representation;
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $representation, $matches) !== 1) {
            throw new InvalidArgumentException('Unusable numeric value.');
        }

        $digits = $matches[2].$matches[3];
        $point = strlen($matches[2]) + (int) $matches[4];

        if ($point <= 0) {
            $expanded = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $expanded = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $expanded = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return $matches[1].$expanded;
    }

    /**
     * Parse a decimal value into a numerator/denominator pair.
     *
     * @return array{0: int, 1: int}
     */
    private function fraction(mixed $value, string $name): array
    {
        [$intPart, $fracPart] = $this->decimalParts($value, $name);

        if (strlen($fracPart) > 15) {
            throw new InvalidArgumentException("$name has too many decimal places.");
        }

        $digits = ltrim($intPart.$fracPart, '0');
        if (strlen($digits) > 15) {
            throw new InvalidArgumentException("$name exceeds the representable range.");
        }

        $numerator = $digits === '' ? 0 : (int) $digits;
        $denominator = (int) (10 ** strlen($fracPart));

        return [$numerator, $denominator];
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private function nested(array $array, array $keys): mixed
    {
        $value = $array;

        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $name): int
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("$name must be a positive integer.");
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $name): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("$name must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * Apply the 25% markup exactly. A result that would need a fraction of a
     * pico is rejected rather than rounded.
     */
    private function withMarkup(int $picos): int
    {
        if ($picos % self::MARKUP_DENOMINATOR !== 0) {
            throw new InvalidArgumentException('Charge cannot be represented exactly in picos.');
        }

        return $this->multiply(intdiv($picos, self::MARKUP_DENOMINATOR), self::MARKUP_NUMERATOR);
    }

    /**
     * Exact ceil division for non-negative dividends.
     */
    private function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend, $divisor) + ($dividend % $divisor !== 0 ? 1 : 0);
    }

    private function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a))) {
            throw new InvalidArgumentException('Amount exceeds the representable range.');
        }

        return $a * $b;
    }

    private function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $b > PHP_INT_MAX - $a) {
            throw new InvalidArgumentException('Amount exceeds the representable range.');
        }

        return $a + $b;
    }
}
