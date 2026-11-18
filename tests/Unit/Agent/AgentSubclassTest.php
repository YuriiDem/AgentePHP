<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Agent;

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\InMemoryChatHistory;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Tests\Support\FakeProvider;
use PHPUnit\Framework\TestCase;

/**
 * The form an application actually uses: an agent is a class, and its dependencies are
 * declarations rather than arguments every call site has to remember.
 *
 * These tests exist to prove the two styles are the same object - a subclass declares the
 * defaults, and withX() overrides one of them for a single instance, which is what makes a
 * production agent testable without touching its source.
 */
final class AgentSubclassTest extends TestCase
{
    public function test_a_subclass_needs_no_configuration_at_the_call_site(): void
    {
        $agent = new BookshopAgent();

        self::assertSame('It shipped yesterday.', $agent->chat('Where is my order?')->content());
    }

    public function test_the_subclass_instructions_are_used(): void
    {
        $agent = new BookshopAgent();

        $agent->chat('Where is my order?');

        self::assertSame(['system', 'user'], BookshopAgent::$provider->lastRoles());
        self::assertSame(
            'You are a support agent for a bookshop.',
            BookshopAgent::$provider->lastConversation()[0]->content(),
        );
    }

    public function test_the_subclass_chooses_its_own_history(): void
    {
        $agent = new BookshopAgent();

        self::assertInstanceOf(InMemoryChatHistory::class, $agent->history());
        self::assertCount(0, $agent->history()->messages());
    }

    /** The seam that makes a production agent testable: swap the provider, keep the class. */
    public function test_a_declared_default_can_be_overridden_for_one_instance(): void
    {
        $underTest = new FakeProvider('A different answer.');

        $agent = (new BookshopAgent())->withProvider($underTest);

        self::assertSame('A different answer.', $agent->chat('Where is my order?')->content());
        self::assertSame(1, $underTest->callCount());
    }

    public function test_overriding_one_default_keeps_the_others(): void
    {
        $underTest = new FakeProvider('A different answer.');

        (new BookshopAgent())->withProvider($underTest)->chat('Where is my order?');

        self::assertSame(
            'You are a support agent for a bookshop.',
            $underTest->lastConversation()[0]->content(),
            'replacing the provider must not drop the instructions the subclass declared',
        );
    }

    public function test_a_copy_of_a_subclass_is_still_that_subclass(): void
    {
        $agent = (new BookshopAgent())->withInstructions('Something else.');

        self::assertInstanceOf(BookshopAgent::class, $agent);
    }

    protected function setUp(): void
    {
        BookshopAgent::$provider = new FakeProvider('It shipped yesterday.', 'And again.');
    }
}

/**
 * A stand-in for the kind of agent an application writes. The provider is a static here
 * only so the test can read what it was sent; a real one would build its own.
 */
final class BookshopAgent extends Agent
{
    public static FakeProvider $provider;

    // The base declares these as nullable ("not declared here"); a subclass that always
    // supplies one narrows the return type, which PHP allows and the reader benefits from.
    protected function provider(): AIProviderInterface
    {
        return self::$provider;
    }

    protected function instructions(): string
    {
        return 'You are a support agent for a bookshop.';
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory(maxMessages: 20);
    }
}
