<?php

namespace Tests\Unit;

use App\Services\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['01712345678', '01712345678'],
            ['+8801712345678', '01712345678'],
            ['8801712345678', '01712345678'],
            ['1712345678', '01712345678'],
            ['017 1234-5678', '01712345678'],
            ['০১৭১২৩৪৫৬৭৮', '01712345678'],
            ['01212345678', null],
            ['0171234567', null],
            ['abc', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalise(string $in, ?string $out): void
    {
        $this->assertSame($out, Phone::normalize($in));
    }
}
