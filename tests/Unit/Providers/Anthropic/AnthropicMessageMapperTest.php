<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers\Anthropic;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Providers\Anthropic\AnthropicMessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * Anthropic does not accept a system message inside the conversation: the system prompt is
 * a separate top-level field. This is the difference that makes a per-vendor mapper worth
 * having, so it is what these tests pin down.
 */
final class AnthropicMessageMapperTest extends TestCase
{
    public function test_it_lifts_the_system_message_out_of_the_conversation(): void
    {
        $mapper = new AnthropicMessageMapper();

        $messages = [new SystemMessage('Be brief.'), new UserMessage('Hello.')];

        self::assertSame('Be brief.', $mapper->system($messages));
        self::assertSame([['role' => 'user', 'content' => 'Hello.']], $mapper->conversation($messages));
    }

    public function test_it_reports_no_system_prompt_when_there_is_none(): void
    {
        $mapper = new AnthropicMessageMapper();

        self::assertNull($mapper->system([new UserMessage('Hello.')]));
    }

    public function test_it_joins_several_system_messages_in_order(): void
    {
        $mapper = new AnthropicMessageMapper();

        $system = $mapper->system([
            new SystemMessage('Be brief.'),
            new UserMessage('Hello.'),
            new SystemMessage('Answer in English.'),
        ]);

        self::assertSame("Be brief.\n\nAnswer in English.", $system);
    }

    public function test_it_keeps_user_and_assistant_turns_in_order(): void
    {
        $mapper = new AnthropicMessageMapper();

        $conversation = $mapper->conversation([
            new UserMessage('Hello.'),
            new AssistantMessage('Hi.'),
            new UserMessage('Still there?'),
        ]);

        self::assertSame([
            ['role' => 'user', 'content' => 'Hello.'],
            ['role' => 'assistant', 'content' => 'Hi.'],
            ['role' => 'user', 'content' => 'Still there?'],
        ], $conversation);
    }
}
