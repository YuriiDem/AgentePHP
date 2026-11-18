<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Agent;

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\History\InMemoryChatHistory;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\AgentException;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Tests\Support\FakeProvider;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function test_it_answers_with_what_the_provider_said(): void
    {
        $agent = Agent::make()->withProvider(new FakeProvider('Paris'));

        self::assertSame('Paris', $agent->chat('Capital of France?')->content());
    }

    public function test_the_question_reaches_the_provider_as_a_user_message(): void
    {
        $provider = new FakeProvider('Paris');

        Agent::make()->withProvider($provider)->chat('Capital of France?');

        self::assertSame(['user'], $provider->lastRoles());
        self::assertSame('Capital of France?', $provider->lastConversation()[0]->content());
    }

    public function test_it_also_accepts_a_message_object(): void
    {
        $agent = Agent::make()->withProvider(new FakeProvider('Paris'));

        self::assertSame('Paris', $agent->chat(new UserMessage('Capital of France?'))->content());
    }

    /** The point of the whole class: the second call knows about the first. */
    public function test_it_remembers_the_conversation_between_calls(): void
    {
        $provider = new FakeProvider('Hello, Yurii.', 'You are Yurii.');
        $agent = Agent::make()->withProvider($provider);

        $agent->chat('My name is Yurii.');
        $agent->chat('What is my name?');

        self::assertSame(['user', 'assistant', 'user'], $provider->lastRoles());
    }

    public function test_the_conversation_is_readable_and_clearable(): void
    {
        $agent = Agent::make()->withProvider(new FakeProvider('Hi.'));

        $agent->chat('Hello.');

        self::assertCount(2, $agent->history()->messages());

        $agent->history()->clear();

        self::assertSame([], $agent->history()->messages());
    }

    public function test_instructions_are_sent_ahead_of_the_conversation(): void
    {
        $provider = new FakeProvider('Paris');

        Agent::make()
            ->withProvider($provider)
            ->withInstructions('Answer in one word.')
            ->chat('Capital of France?');

        self::assertSame(['system', 'user'], $provider->lastRoles());
        self::assertSame('Answer in one word.', $provider->lastConversation()[0]->content());
    }

    public function test_an_agent_with_nothing_to_say_sends_no_system_message(): void
    {
        $provider = new FakeProvider('Paris');

        Agent::make()->withProvider($provider)->chat('Capital of France?');

        self::assertSame(['user'], $provider->lastRoles());
    }

    /**
     * Instructions belong to the agent, not to the conversation: rewording them must not
     * require rewriting a stored history, so they are prepended on every call and never
     * stored.
     */
    public function test_instructions_are_not_kept_in_the_history(): void
    {
        $agent = Agent::make()
            ->withProvider(new FakeProvider('Paris'))
            ->withInstructions('Answer in one word.');

        $agent->chat('Capital of France?');

        $roles = array_map(
            static fn ($message): string => $message->role()->value,
            $agent->history()->messages(),
        );

        self::assertSame(['user', 'assistant'], $roles);
    }

    public function test_instructions_are_resent_on_every_call(): void
    {
        $provider = new FakeProvider('one', 'two');
        $agent = Agent::make()->withProvider($provider)->withInstructions('Be brief.');

        $agent->chat('first');
        $agent->chat('second');

        self::assertSame(['system', 'user', 'assistant', 'user'], $provider->lastRoles());
    }

    /**
     * A refused call must leave the conversation exactly as it was, or a retry would send
     * the same question twice.
     */
    public function test_a_failed_call_leaves_the_history_untouched(): void
    {
        $agent = Agent::make()->withProvider(FakeProvider::failingWith(
            ProviderException::requestFailed('OpenAI', 500, 'boom'),
        ));

        try {
            $agent->chat('Capital of France?');
            self::fail('The provider failure must reach the caller.');
        } catch (ProviderException) {
            self::assertSame([], $agent->history()->messages());
        }
    }

    public function test_it_says_what_to_do_when_no_provider_was_configured(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('withProvider');

        Agent::make()->chat('Hello?');
    }

    public function test_configuring_an_agent_leaves_the_original_alone(): void
    {
        $bare = Agent::make();

        $configured = $bare->withProvider(new FakeProvider('Paris'));

        self::assertNotSame($bare, $configured);
        self::assertSame('Paris', $configured->chat('Capital of France?')->content());

        $this->expectException(AgentException::class);
        $bare->chat('Capital of France?');
    }

    /**
     * A reconfigured agent is a different agent: carrying a half-finished conversation into
     * one with different instructions would be surprising.
     */
    public function test_a_reconfigured_agent_starts_its_own_conversation(): void
    {
        $agent = Agent::make()->withProvider(new FakeProvider('Hi.'));
        $agent->chat('Hello.');

        $reconfigured = $agent->withInstructions('Be brief.');

        self::assertCount(2, $agent->history()->messages());
        self::assertSame([], $reconfigured->history()->messages());
    }

    /** Unless the caller hands over a history explicitly - then it is theirs to share. */
    public function test_an_explicitly_given_history_is_shared_by_every_copy(): void
    {
        $shared = new InMemoryChatHistory();

        $agent = Agent::make()->withProvider(new FakeProvider('Hi.'))->withHistory($shared);
        $agent->chat('Hello.');

        $reconfigured = $agent->withInstructions('Be brief.');

        self::assertSame($shared, $reconfigured->history());
        self::assertCount(2, $reconfigured->history()->messages());
    }

    public function test_a_bounded_history_is_honoured(): void
    {
        $provider = new FakeProvider('one', 'two', 'three');
        $agent = Agent::make()
            ->withProvider($provider)
            ->withHistory(new InMemoryChatHistory(maxMessages: 2));

        $agent->chat('first');
        $agent->chat('second');
        $agent->chat('third');

        self::assertCount(2, $agent->history()->messages());
    }
}
