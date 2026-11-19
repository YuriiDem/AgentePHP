<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Tools;

use AgentePHP\Exceptions\ToolException;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Tool;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ToolTest extends TestCase
{
    public function test_a_tool_carries_what_the_model_needs_to_choose_it(): void
    {
        $tool = Tool::make(
            name: 'current_time',
            description: 'The current time in a timezone.',
            callback: static fn (string $timezone): string => 'noon in ' . $timezone,
        );

        self::assertSame('current_time', $tool->name);
        self::assertSame('The current time in a timezone.', $tool->description);
    }

    /** A leaf of the composite: one tool offered as a collection of one. */
    public function test_a_single_tool_presents_itself_as_a_collection_of_one(): void
    {
        $tool = Tool::make('noop', 'Does nothing.', static fn (): string => 'done');

        self::assertSame([$tool], $tool->tools());
    }

    public function test_the_schema_is_derived_from_the_signature(): void
    {
        $tool = Tool::make(
            name: 'convert',
            description: 'Convert an amount.',
            callback: static fn (
                #[Param('Amount to convert.')]
                float $amount,
                #[Param('Three-letter currency code.')]
                string $currency,
                #[Param('Round the result.')]
                bool $round = false,
            ): string => 'converted',
        );

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'amount' => ['type' => 'number', 'description' => 'Amount to convert.'],
                'currency' => ['type' => 'string', 'description' => 'Three-letter currency code.'],
                'round' => ['type' => 'boolean', 'description' => 'Round the result.'],
            ],
            'required' => ['amount', 'currency'],
        ], $tool->jsonSchema());
    }

    public function test_a_parameter_with_a_default_is_not_required(): void
    {
        $tool = Tool::make('t', 'd', static fn (int $page = 1): string => 'ok');

        self::assertArrayNotHasKey('required', $tool->jsonSchema());
    }

    public function test_a_nullable_parameter_is_not_required(): void
    {
        $tool = Tool::make('t', 'd', static fn (?string $note): string => 'ok');

        self::assertArrayNotHasKey('required', $tool->jsonSchema());
        self::assertSame('string', $tool->jsonSchema()['properties']['note']['type']);
    }

    public function test_a_parameter_with_no_description_still_appears(): void
    {
        $tool = Tool::make('t', 'd', static fn (string $city): string => 'ok');

        self::assertSame(['type' => 'string'], $tool->jsonSchema()['properties']['city']);
    }

    /**
     * An empty PHP array encodes as [], and a vendor expects {} for an object with no
     * properties - so a tool taking no arguments has to say so with an object.
     */
    public function test_a_tool_with_no_arguments_still_describes_an_object(): void
    {
        $schema = Tool::make('ping', 'Replies.', static fn (): string => 'pong')->jsonSchema();

        self::assertSame('object', $schema['type']);
        self::assertInstanceOf(stdClass::class, $schema['properties']);
        self::assertSame('{"type":"object","properties":{}}', json_encode($schema));
    }

    public function test_it_runs_the_callback_with_the_arguments_the_model_chose(): void
    {
        $tool = Tool::make(
            name: 'greet',
            description: 'Greets someone.',
            callback: static fn (string $name, string $greeting = 'Hello'): string => "{$greeting}, {$name}!",
        );

        self::assertSame('Hello, Yurii!', $tool->call(['name' => 'Yurii']));
        self::assertSame('Hi, Yurii!', $tool->call(['name' => 'Yurii', 'greeting' => 'Hi']));
    }

    public function test_arguments_are_passed_by_name_not_by_position(): void
    {
        $tool = Tool::make('t', 'd', static fn (string $first, string $second): string => $first . '|' . $second);

        self::assertSame('a|b', $tool->call(['second' => 'b', 'first' => 'a']));
    }

    /**
     * A model sends JSON, and JSON routinely carries a number as a string. Refusing "42"
     * for an int parameter would fail a call that lost no information, so numeric strings
     * are accepted - while anything genuinely unconvertible is refused loudly.
     */
    public function test_it_accepts_a_number_the_model_sent_as_a_string(): void
    {
        $tool = Tool::make('t', 'd', static fn (int $order, float $weight): string => "{$order}/{$weight}");

        self::assertSame('11482/2.5', $tool->call(['order' => '11482', 'weight' => '2.5']));
    }

    public function test_it_accepts_a_boolean_the_model_sent_as_a_word(): void
    {
        $tool = Tool::make('t', 'd', static fn (bool $urgent): string => $urgent ? 'yes' : 'no');

        self::assertSame('yes', $tool->call(['urgent' => 'true']));
        self::assertSame('no', $tool->call(['urgent' => 'false']));
    }

    public function test_it_renders_a_non_string_result_as_text_the_model_can_read(): void
    {
        self::assertSame('7', Tool::make('t', 'd', static fn (): int => 7)->call([]));
        self::assertSame('2.5', Tool::make('t', 'd', static fn (): float => 2.5)->call([]));
        self::assertSame('true', Tool::make('t', 'd', static fn (): bool => true)->call([]));
        self::assertSame('false', Tool::make('t', 'd', static fn (): bool => false)->call([]));
    }

    public function test_it_says_which_argument_the_model_failed_to_send(): void
    {
        $tool = Tool::make('cancel_order', 'd', static fn (int $orderNumber): string => 'ok');

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('orderNumber');

        $tool->call([]);
    }

    public function test_it_says_what_type_an_argument_should_have_been(): void
    {
        $tool = Tool::make('cancel_order', 'd', static fn (int $orderNumber): string => 'ok');

        try {
            $tool->call(['orderNumber' => 'eleven thousand']);
            self::fail('A value that cannot be converted must be refused.');
        } catch (ToolException $e) {
            self::assertStringContainsString('cancel_order', $e->getMessage());
            self::assertStringContainsString('orderNumber', $e->getMessage());
            self::assertStringContainsString('integer', $e->getMessage());
            self::assertStringContainsString('#[Param]', $e->getMessage(), 'the message should say how to fix it');
        }
    }

    /** The schema already told the model what exists; an extra key is noise, not a failure. */
    public function test_an_argument_that_is_not_in_the_signature_is_ignored(): void
    {
        $tool = Tool::make('t', 'd', static fn (string $name): string => $name);

        self::assertSame('Yurii', $tool->call(['name' => 'Yurii', 'hallucinated' => 'x']));
    }

    public function test_it_refuses_a_name_no_vendor_would_accept(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('letters');

        Tool::make('order status!', 'd', static fn (): string => 'ok');
    }

    public function test_it_refuses_a_tool_with_nothing_to_tell_the_model(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('description');

        Tool::make('order_status', '', static fn (): string => 'ok');
    }

    public function test_it_refuses_a_parameter_type_it_cannot_describe(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('$items');

        Tool::make('t', 'd', static fn (array $items): string => 'ok');
    }

    public function test_it_refuses_a_parameter_with_no_type_at_all(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('$anything');

        Tool::make('t', 'd', static fn ($anything): string => 'ok');
    }

    public function test_it_refuses_a_tool_that_returns_nothing_for_the_model_to_read(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('return');

        Tool::make('t', 'd', static function (): void {
        });
    }
}
