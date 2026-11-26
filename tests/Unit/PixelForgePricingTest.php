<?php

use App\Services\PixelForge\PixelForgePricing;

/**
 * A price list mirroring the documented GET /v1/pricing shape at the
 * rates 5.00 / 10.00 / 40.00 / 12.50 USD per million tokens.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgePriceList(array $overrides = []): array
{
    return array_replace_recursive([
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => '0.15',
            'per_reference_image' => 765,
            'output' => [
                'high' => [
                    '1536x1024' => 6450,
                ],
            ],
            'reasoning' => [
                'high' => [
                    'min' => 1000,
                    'max' => 8000,
                ],
            ],
        ],
        'rates' => pixelforgeLockedRates(),
    ], $overrides);
}

/**
 * The locked rates of the documented succeeded generation.
 *
 * @return array<string, string>
 */
function pixelforgeLockedRates(): array
{
    return [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];
}

/**
 * The usage of the documented succeeded generation (12 / 765 / 6,450 / 3,184).
 *
 * @return array<string, int>
 */
function pixelforgeUsage(): array
{
    return [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
        'total_tokens' => 10411,
    ];
}

test('estimate bounds for a 400 unicode character prompt are exact picos', function (string|float $variance) {
    $pricing = new PixelForgePricing;

    // 400 multibyte characters: byte-level counting would produce 800.
    $estimate = $pricing->estimate(
        str_repeat('é', 400),
        '1536x1024',
        'high',
        2,
        pixelforgePriceList(['tokens' => ['text_token_variance' => $variance]])
    );

    expect($estimate)->toBe(['min' => 357781250000, 'max' => 467343750000])
        ->and($pricing->formatUsd($estimate['min']))->toBe('0.35778125')
        ->and($pricing->formatUsd($estimate['max']))->toBe('0.46734375');
})->with([
    'decimal string variance' => '0.15',
    'JSON number variance' => 0.15,
]);

test('settlement applies the locked rates and markup exactly', function () {
    $pricing = new PixelForgePricing;

    $result = $pricing->settle(pixelforgeUsage(), pixelforgeLockedRates());

    expect($result)->toBe(['cost' => 305510000000, 'charge' => 381887500000])
        ->and($pricing->formatUsd($result['cost']))->toBe('0.30551')
        ->and($pricing->formatUsd($result['charge']))->toBe('0.3818875');
});

test('settlement keeps a low nonzero charge exact and ignores total_tokens', function () {
    $pricing = new PixelForgePricing;

    // Trailing zeros beyond the stored scale are allowed when the rate is
    // still exactly representable.
    $result = $pricing->settle(
        [
            'input_text_tokens' => 4,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 999999,
        ],
        [
            'input_text_tokens' => '0.000001000000',
            'input_image_tokens' => '0',
            'output_tokens' => '0',
            'reasoning_tokens' => '0',
        ]
    );

    expect($result)->toBe(['cost' => 4, 'charge' => 5])
        ->and($pricing->formatUsd($result['cost']))->toBe('0.000000000004')
        ->and($pricing->formatUsd($result['charge']))->toBe('0.000000000005');
});

test('provider data that cannot be priced exactly is rejected', function (array $usage, array $rates) {
    $pricing = new PixelForgePricing;

    $pricing->settle($usage, $rates);
})->with([
    'rate below the stored precision' => [
        pixelforgeUsage(),
        array_merge(pixelforgeLockedRates(), ['input_text_tokens' => '0.0000001']),
    ],
    'non-string rate' => [
        pixelforgeUsage(),
        array_merge(pixelforgeLockedRates(), ['output_tokens' => 40.0]),
    ],
    'fraction-of-pico markup' => [
        [
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
        [
            'input_text_tokens' => '0.000001',
            'input_image_tokens' => '0',
            'output_tokens' => '0',
            'reasoning_tokens' => '0',
        ],
    ],
])->throws(InvalidArgumentException::class);
