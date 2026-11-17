<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Chat\Messages;

use AgentePHP\Chat\Messages\Usage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase
{
    public function test_it_counts_nothing_by_default(): void
    {
        $usage = new Usage();

        self::assertSame(0, $usage->prompt);
        self::assertSame(0, $usage->completion);
        self::assertSame(0, $usage->total());
    }

    public function test_it_totals_the_two_counters(): void
    {
        $usage = new Usage(prompt: 412, completion: 57);

        self::assertSame(469, $usage->total());
    }

    public function test_it_adds_two_counts_into_a_new_value(): void
    {
        $first = new Usage(prompt: 100, completion: 10);
        $second = new Usage(prompt: 5, completion: 1);

        $sum = $first->plus($second);

        self::assertSame(105, $sum->prompt);
        self::assertSame(11, $sum->completion);
        self::assertSame(100, $first->prompt, 'plus() must not mutate the left operand');
    }

    public function test_two_counts_with_the_same_numbers_are_equal_by_value(): void
    {
        self::assertEquals(new Usage(1, 2), new Usage(1, 2));
        self::assertNotSame(new Usage(1, 2), new Usage(1, 2));
    }

    public function test_it_rejects_a_negative_prompt_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt');

        new Usage(prompt: -1);
    }

    public function test_it_rejects_a_negative_completion_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('completion');

        new Usage(completion: -3);
    }
}
