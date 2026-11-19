<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\ToolMessage;
use PHPUnit\Framework\TestCase;

final class ToolMessageTest extends TestCase
{
    public function test_an_answer_without_tool_calls_says_so(): void
    {
        $message = new AssistantMessage('Paris');

        self::assertFalse($message->hasToolCalls());
        self::assertSame([], $message->toolCalls());
    }

    public function test_an_answer_can_ask_for_work_to_be_done(): void
    {
        $message = new AssistantMessage('', toolCalls: [
            new ToolCall(id: 'call_1', name: 'add', arguments: ['a' => 2, 'b' => 3]),
        ]);

        self::assertTrue($message->hasToolCalls());
        self::assertSame('add', $message->toolCalls()[0]->name);
        self::assertSame(['a' => 2, 'b' => 3], $message->toolCalls()[0]->arguments);
    }

    /**
     * A model asking for a tool usually says nothing else, and that empty content is not a
     * failure - the request is the content.
     */
    public function test_an_answer_that_only_asks_for_a_tool_has_no_text(): void
    {
        $message = new AssistantMessage('', toolCalls: [new ToolCall('call_1', 'add')]);

        self::assertSame('', $message->content());
    }

    public function test_a_tool_result_is_a_turn_in_the_conversation(): void
    {
        $result = new ToolMessage(content: '5', toolCallId: 'call_1', toolName: 'add');

        self::assertSame(MessageRole::Tool, $result->role());
        self::assertSame('tool', $result->role()->value);
        self::assertSame('5', $result->content());
        self::assertSame('call_1', $result->toolCallId);
        self::assertSame('add', $result->toolName);
    }

    /**
     * The id is what lets a vendor pair a result with the request that caused it, which
     * matters as soon as a model asks for two tools in one turn.
     */
    public function test_two_calls_in_one_turn_keep_their_own_identities(): void
    {
        $message = new AssistantMessage('', toolCalls: [
            new ToolCall('call_1', 'add', ['a' => 1, 'b' => 2]),
            new ToolCall('call_2', 'multiply', ['a' => 3, 'b' => 4]),
        ]);

        self::assertCount(2, $message->toolCalls());
        self::assertSame(['call_1', 'call_2'], array_map(
            static fn (ToolCall $call): string => $call->id,
            $message->toolCalls(),
        ));
    }
}
