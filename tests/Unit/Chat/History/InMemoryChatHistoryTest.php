<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Chat\History;

use AgentePHP\Chat\History\InMemoryChatHistory;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\UserMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InMemoryChatHistoryTest extends TestCase
{
    public function test_a_new_conversation_is_empty(): void
    {
        self::assertSame([], (new InMemoryChatHistory())->messages());
    }

    public function test_it_keeps_messages_in_the_order_they_were_said(): void
    {
        $history = new InMemoryChatHistory();

        $history->add(new UserMessage('Hello.'));
        $history->add(new AssistantMessage('Hi.'));
        $history->add(new UserMessage('Still there?'));

        $said = array_map(static fn ($message): string => $message->content(), $history->messages());

        self::assertSame(['Hello.', 'Hi.', 'Still there?'], $said);
    }

    public function test_it_accepts_a_whole_exchange_in_one_call(): void
    {
        $history = new InMemoryChatHistory();

        $history->add(new UserMessage('Hello.'), new AssistantMessage('Hi.'));

        self::assertCount(2, $history->messages());
    }

    public function test_clearing_leaves_nothing_behind(): void
    {
        $history = new InMemoryChatHistory();
        $history->add(new UserMessage('Hello.'));

        $history->clear();

        self::assertSame([], $history->messages());
    }

    public function test_it_remembers_everything_when_no_limit_is_set(): void
    {
        $history = new InMemoryChatHistory();

        for ($i = 0; $i < 50; $i++) {
            $history->add(new UserMessage("message {$i}"));
        }

        self::assertCount(50, $history->messages());
    }

    /**
     * A conversation sent in full on every call grows the bill with every turn, so a
     * long-running agent needs a ceiling. The newest messages are the ones worth keeping.
     */
    public function test_a_limited_history_keeps_the_most_recent_messages(): void
    {
        $history = new InMemoryChatHistory(maxMessages: 3);

        foreach (['first', 'second', 'third', 'fourth', 'fifth'] as $content) {
            $history->add(new UserMessage($content));
        }

        $kept = array_map(static fn ($message): string => $message->content(), $history->messages());

        self::assertSame(['third', 'fourth', 'fifth'], $kept);
    }

    public function test_the_limit_applies_to_a_batch_added_at_once(): void
    {
        $history = new InMemoryChatHistory(maxMessages: 2);

        $history->add(new UserMessage('a'), new AssistantMessage('b'), new UserMessage('c'));

        $kept = array_map(static fn ($message): string => $message->content(), $history->messages());

        self::assertSame(['b', 'c'], $kept);
    }

    public function test_the_kept_messages_stay_a_list_so_they_can_be_sent_as_json(): void
    {
        $history = new InMemoryChatHistory(maxMessages: 2);

        $history->add(new UserMessage('a'), new UserMessage('b'), new UserMessage('c'));

        self::assertSame([0, 1], array_keys($history->messages()));
    }

    public function test_a_limit_of_zero_is_a_mistake_rather_than_an_empty_history(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1');

        new InMemoryChatHistory(maxMessages: 0);
    }

    public function test_a_negative_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryChatHistory(maxMessages: -5);
    }
}
