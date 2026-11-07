<?php

namespace Tests\Unit;

use App\Services\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parse_taka(): void
    {
        $this->assertSame(12000, Money::parseTaka('120'));
        $this->assertSame(12050, Money::parseTaka('120.5'));
        $this->assertSame(125075, Money::parseTaka('1,250.75'));
        $this->assertSame(12000, Money::parseTaka('৳১২০'));
        $this->expectException(InvalidArgumentException::class);
        Money::parseTaka('12.345');
    }

    public function test_format_taka(): void
    {
        $this->assertSame('৳১,২৫০.৫০', Money::formatTaka(125050));
        $this->assertSame('৳1,250.50', Money::formatTaka(125050, 'en'));
        $this->assertSame('৳1,23,456', Money::formatTaka(12345600, 'en'));
        $this->assertSame('৳0', Money::formatTaka(0, 'en'));
        $this->assertSame('-৳5', Money::formatTaka(-500, 'en'));
    }

    public function test_arithmetic_is_integer_only(): void
    {
        $this->assertSame(600, Money::sum([100, 200, 300]));
        $this->assertSame(750, Money::multiply(250, 3));
        $this->assertSame(3, Money::percentOfBp(250, 100)); // 2.5 rounds half up
        $this->expectException(InvalidArgumentException::class);
        Money::multiply(250, -1);
    }

    public function test_float_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::sum([1, 2.5]);
    }

    public function test_allocate_proportionally_sums_exactly(): void
    {
        $parts = Money::allocateProportionally(100, [1, 1, 1]);
        $this->assertSame(100, array_sum($parts));
        $this->assertSame([34, 33, 33], $parts);
        $this->assertSame([0, 0], Money::allocateProportionally(0, [0, 0]));
        $this->assertSame([], Money::allocateProportionally(0, []));
    }
}
