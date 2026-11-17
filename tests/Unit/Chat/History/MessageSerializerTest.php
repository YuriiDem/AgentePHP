<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Chat\History;

use AgentePHP\Chat\History\MessageSerializer;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\HistoryException;
use PHPUnit\Framework\TestCase;

/**
 * Turning a message into plain data and back is its own job, separate from any store that
 * needs it: it is testable without touching a disk, and the SQLite store the roadmap
 * promises will need exactly the same translation.
 */
final class MessageSerializerTest extends TestCase
{
    public function test_it_writes_a_user_message_as_plain_data(): void
    {
        $data = (new MessageSerializer())->toArray(new UserMessage('Hello.'));

        self::assertSame(['role' => 'user', 'content' => 'Hello.'], $data);
    }

    public function test_it_keeps_the_token_usage_of_an_assistant_message(): void
    {
        $message = new AssistantMessage('Hi.', new Usage(prompt: 412, completion: 57));

        $data = (new MessageSerializer())->toArray($message);

        self::assertSame([
            'role' => 'assistant',
            'content' => 'Hi.',
            'usage' => ['prompt' => 412, 'completion' => 57],
        ], $data);
    }

    public function test_a_round_trip_changes_nothing(): void
    {
        $serializer = new MessageSerializer();

        $originals = [
            new SystemMessage('Be brief.'),
            new UserMessage('Hello.'),
            new AssistantMessage('Hi.', new Usage(prompt: 10, completion: 2)),
        ];

        foreach ($originals as $original) {
            $restored = $serializer->fromArray($serializer->toArray($original));

            self::assertEquals($original, $restored);
        }
    }

    public function test_a_restored_assistant_message_still_knows_what_it_cost(): void
    {
        $serializer = new MessageSerializer();

        $restored = $serializer->fromArray([
            'role' => 'assistant',
            'content' => 'Hi.',
            'usage' => ['prompt' => 10, 'completion' => 2],
        ]);

        self::assertInstanceOf(AssistantMessage::class, $restored);
        self::assertSame(12, $restored->usage()->total());
    }

    public function test_an_assistant_message_stored_without_usage_is_still_readable(): void
    {
        $restored = (new MessageSerializer())->fromArray(['role' => 'assistant', 'content' => 'Hi.']);

        self::assertInstanceOf(AssistantMessage::class, $restored);
        self::assertSame(0, $restored->usage()->total());
    }

    public function test_it_names_the_role_it_does_not_recognise(): void
    {
        $this->expectException(HistoryException::class);
        $this->expectExceptionMessage('tool');

        (new MessageSerializer())->fromArray(['role' => 'tool', 'content' => 'x']);
    }

    public function test_it_rejects_stored_data_with_no_role(): void
    {
        $this->expectException(HistoryException::class);
        $this->expectExceptionMessage('role');

        (new MessageSerializer())->fromArray(['content' => 'x']);
    }

    public function test_it_rejects_stored_data_whose_content_is_not_text(): void
    {
        $this->expectException(HistoryException::class);
        $this->expectExceptionMessage('content');

        (new MessageSerializer())->fromArray(['role' => 'user', 'content' => ['not', 'text']]);
    }
}
