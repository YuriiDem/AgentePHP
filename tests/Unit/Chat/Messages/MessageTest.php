<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function test_each_concrete_message_knows_its_own_role(): void
    {
        self::assertSame(MessageRole::User, (new UserMessage('hi'))->role());
        self::assertSame(MessageRole::Assistant, (new AssistantMessage('hi'))->role());
        self::assertSame(MessageRole::System, (new SystemMessage('hi'))->role());
    }

    public function test_every_message_carries_its_content(): void
    {
        self::assertSame('Capital of France?', (new UserMessage('Capital of France?'))->content());
    }

    public function test_every_concrete_type_is_substitutable_for_the_base_type(): void
    {
        /** @var list<Message> $messages */
        $messages = [new SystemMessage('Be brief.'), new UserMessage('Hello.'), new AssistantMessage('Hi.')];

        $roles = array_map(static fn (Message $message): string => $message->role()->value, $messages);

        self::assertSame(['system', 'user', 'assistant'], $roles);
    }

    public function test_an_assistant_message_reports_no_token_usage_unless_it_is_given_some(): void
    {
        self::assertSame(0, (new AssistantMessage('hi'))->usage()->total());
    }

    public function test_an_assistant_message_carries_the_usage_the_provider_reported(): void
    {
        $message = new AssistantMessage('hi', new Usage(prompt: 412, completion: 57));

        self::assertSame(412, $message->usage()->prompt);
        self::assertSame(57, $message->usage()->completion);
    }
}
