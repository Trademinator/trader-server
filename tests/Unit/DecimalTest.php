<?php

use App\Helpers\Decimal;

it('formats decimals without adding binary digits or losing string precision', function (int|float|string $number, int $scale, string $expected) {
    expect(Decimal::format($number, $scale, '.', ''))->toBe($expected);
})->with([
    'JSON tenth' => [0.1, 18, '0.100000000000000000'],
    'small JSON float' => [3e-8, 18, '0.000000030000000000'],
    'eighteen-place float' => [1e-18, 18, '0.000000000000000001'],
    'full float precision' => [1.2345678901234567, 18, '1.234567890123456700'],
    'decimal string' => ['0.123456789012345678', 18, '0.123456789012345678'],
    'negative exponent string' => ['1.234567890123456789e-8', 26, '0.00000001234567890123456789'],
    'positive exponent string' => ['1.234567890123456789E+18', 0, '1234567890123456789'],
    'large integer string' => ['9007199254740993', 0, '9007199254740993'],
    'large integer' => [PHP_INT_MAX, 0, '9223372036854775807'],
    'large fractional string' => ['12345678901234567890.123456789012345678', 18, '12345678901234567890.123456789012345678'],
    'positive tie' => ['1.005', 2, '1.01'],
    'negative tie' => ['-1.005', 2, '-1.01'],
    'just below a tie' => ['0.499999999999999999', 0, '0'],
    'carry into integer' => ['999.995', 2, '1000.00'],
    'negative decimal places' => ['1250', -2, '1300'],
    'negative zero' => [-0.0, 3, '0.000'],
    'round to zero' => ['-0.0004', 3, '0.000'],
    'leading and trailing whitespace' => [' +.5e-2 ', 3, '0.005'],
    'integer padding' => [10, 3, '10.000'],
    'small value below display scale' => ['1e-20', 18, '0.000000000000000000'],
]);

it('keeps grouping and decimal separators suitable for existing displays', function () {
    expect(Decimal::format('9007199254740993'))->toBe('9,007,199,254,740,993');
    expect(Decimal::format('-1234567.895', 2))->toBe('-1,234,567.90');
    expect(Decimal::format('1234567.5', 2, ',', ' '))->toBe('1 234 567,50');
    expect(Decimal::format('1234567.5', 2, ',', '$1'))->toBe('1$1234$1567,50');
    expect(Decimal::format(null))->toBe('0');
});

it('normalizes calculation inputs without applying a display scale', function () {
    expect(Decimal::normalize('1.234567890123456789e-8'))->toBe('0.00000001234567890123456789');
    expect(Decimal::normalize(0.1))->toBe('0.1');
    expect(Decimal::normalize(' +.5E-2 '))->toBe('0.005');
});

it('does not let PHP precision settings add digits or truncate floats', function () {
    $precision = ini_get('precision');
    $serializePrecision = ini_get('serialize_precision');
    ini_set('precision', '3');
    ini_set('serialize_precision', '17');

    try {
        expect(Decimal::format(0.1, 18, '.', ''))->toBe('0.100000000000000000');
        expect(Decimal::format(1.2345678901234567, 18, '.', ''))->toBe('1.234567890123456700');
        expect(ini_get('precision'))->toBe('3');
        expect(ini_get('serialize_precision'))->toBe('17');
    } finally {
        ini_set('precision', $precision);
        ini_set('serialize_precision', $serializePrecision);
    }
});

it('rejects malformed nonfinite or excessive decimal inputs', function (float|string $number) {
    expect(fn () => Decimal::format($number, 18))->toThrow(InvalidArgumentException::class);
})->with([
    'NaN' => [NAN],
    'infinity' => [INF],
    'negative infinity' => [-INF],
    'invalid number' => ['not-a-number'],
    'missing mantissa' => ['e-8'],
    'excessive positive exponent' => ['1e99999999999999'],
    'excessive negative exponent' => ['1e-99999999999999'],
]);
