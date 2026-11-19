<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Tools\Toolkits;

use AgentePHP\Exceptions\ToolException;
use AgentePHP\Tools\Tool;
use AgentePHP\Tools\Toolkits\Calculator;
use PHPUnit\Framework\TestCase;

/**
 * The first real toolkit, and the one that exists because models are unreliable at
 * arithmetic: giving them a calculator is cheaper than hoping.
 */
final class CalculatorTest extends TestCase
{
    public function test_it_offers_the_four_operations(): void
    {
        $names = array_map(static fn (Tool $tool): string => $tool->name, (new Calculator())->tools());

        sort($names);

        self::assertSame(['add', 'divide', 'multiply', 'subtract'], $names);
    }

    public function test_every_tool_it_offers_tells_the_model_what_it_does(): void
    {
        foreach ((new Calculator())->tools() as $tool) {
            self::assertNotSame('', $tool->description, $tool->name);
            self::assertArrayHasKey('a', $tool->jsonSchema()['properties'], $tool->name);
        }
    }

    public function test_it_adds(): void
    {
        self::assertSame('5', $this->call('add', ['a' => 2, 'b' => 3]));
    }

    public function test_it_subtracts(): void
    {
        self::assertSame('-1', $this->call('subtract', ['a' => 2, 'b' => 3]));
    }

    public function test_it_multiplies(): void
    {
        self::assertSame('7.5', $this->call('multiply', ['a' => 2.5, 'b' => 3]));
    }

    public function test_it_divides(): void
    {
        self::assertSame('2.5', $this->call('divide', ['a' => 5, 'b' => 2]));
    }

    public function test_it_works_on_numbers_the_model_sent_as_strings(): void
    {
        self::assertSame('5', $this->call('add', ['a' => '2', 'b' => '3']));
    }

    /** A float that happens to be whole should read as a number, not as 5.0 or 5.00000. */
    public function test_a_whole_result_reads_as_a_whole_number(): void
    {
        self::assertSame('4', $this->call('divide', ['a' => 8, 'b' => 2]));
    }

    public function test_dividing_by_zero_explains_itself_instead_of_crashing(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('zero');

        $this->call('divide', ['a' => 1, 'b' => 0]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(string $name, array $arguments): string
    {
        foreach ((new Calculator())->tools() as $tool) {
            if ($tool->name === $name) {
                return $tool->call($arguments);
            }
        }

        self::fail("The calculator offers no tool named {$name}.");
    }
}
