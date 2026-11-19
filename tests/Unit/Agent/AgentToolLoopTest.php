<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Agent;

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Exceptions\MaxToolIterationsReached;
use AgentePHP\Exceptions\ToolException;
use AgentePHP\Tests\Support\FakeProvider;
use AgentePHP\Tools\Attributes\AsTool;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Tool;
use AgentePHP\Tools\Toolkit;
use AgentePHP\Tools\Toolkits\Calculator;
use PHPUnit\Framework\TestCase;

/**
 * The agentic loop: the model asks for a tool, the library runs it, feeds the result back,
 * and asks again - until the model answers in words or the cap is reached.
 */
final class AgentToolLoopTest extends TestCase
{
    public function test_it_runs_the_tool_the_model_asked_for_and_reports_the_final_answer(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('add', ['a' => 2, 'b' => 3]),
            FakeProvider::text('Two plus three is five.'),
        );

        $answer = Agent::make()
            ->withProvider($provider)
            ->withTools([new Calculator()])
            ->chat('What is 2 + 3?');

        self::assertSame('Two plus three is five.', $answer->content());
        self::assertSame(2, $provider->callCount(), 'one call to ask, one to report');
    }

    public function test_the_result_is_fed_back_as_a_tool_turn(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('add', ['a' => 2, 'b' => 3]),
            FakeProvider::text('five'),
        );

        Agent::make()->withProvider($provider)->withTools([new Calculator()])->chat('2+3?');

        self::assertSame(['user', 'assistant', 'tool'], $provider->lastRoles());
        self::assertSame('5', $provider->lastConversation()[2]->content());
    }

    public function test_the_tools_are_described_to_the_model_on_every_call(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('add', ['a' => 2, 'b' => 3]),
            FakeProvider::text('five'),
        );

        Agent::make()->withProvider($provider)->withTools([new Calculator()])->chat('2+3?');

        $names = array_map(static fn (Tool $tool): string => $tool->name, $provider->lastTools());

        sort($names);

        self::assertSame(['add', 'divide', 'multiply', 'subtract'], $names);
    }

    public function test_the_whole_exchange_is_remembered_including_the_tool_turns(): void
    {
        $agent = Agent::make()
            ->withProvider(FakeProvider::sequence(
                FakeProvider::toolCall('add', ['a' => 2, 'b' => 3]),
                FakeProvider::text('five'),
            ))
            ->withTools([new Calculator()]);

        $agent->chat('2+3?');

        $roles = array_map(
            static fn (Message $message): string => $message->role()->value,
            $agent->history()->messages(),
        );

        self::assertSame(['user', 'assistant', 'tool', 'assistant'], $roles);
    }

    public function test_it_handles_two_tools_asked_for_in_one_turn(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCalls([
                ['name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]],
                ['name' => 'multiply', 'arguments' => ['a' => 4, 'b' => 5]],
            ]),
            FakeProvider::text('5 and 20.'),
        );

        Agent::make()->withProvider($provider)->withTools([new Calculator()])->chat('2+3 and 4*5?');

        self::assertSame(['user', 'assistant', 'tool', 'tool'], $provider->lastRoles());
        self::assertSame('5', $provider->lastConversation()[2]->content());
        self::assertSame('20', $provider->lastConversation()[3]->content());
    }

    public function test_a_model_that_keeps_calling_fails_the_request_rather_than_the_process(): void
    {
        $provider = FakeProvider::repeating(FakeProvider::toolCall('add', ['a' => 1, 'b' => 1]));

        $agent = Agent::make()->withProvider($provider)->withTools([new Calculator()]);

        try {
            $agent->chat('loop forever');
            self::fail('A model asking for tools without end must be stopped.');
        } catch (MaxToolIterationsReached $e) {
            self::assertStringContainsString('add', $e->getMessage());
            self::assertStringContainsString('maxToolIterations', $e->getMessage());
        }
    }

    public function test_the_cap_leaves_the_history_untouched(): void
    {
        $agent = Agent::make()
            ->withProvider(FakeProvider::repeating(FakeProvider::toolCall('add', ['a' => 1, 'b' => 1])))
            ->withTools([new Calculator()]);

        try {
            $agent->chat('loop forever');
        } catch (MaxToolIterationsReached) {
            self::assertSame([], $agent->history()->messages());
        }
    }

    /**
     * A model that sends a bad argument should be told, not have the run abandoned: the
     * next turn is its chance to correct itself, which is the whole value of the loop.
     */
    public function test_a_bad_argument_is_reported_to_the_model_instead_of_aborting(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('add', ['a' => 'two', 'b' => 3]),
            FakeProvider::text('I need numbers, not words.'),
        );

        $answer = Agent::make()
            ->withProvider($provider)
            ->withTools([new Calculator()])
            ->chat('add two and three');

        self::assertSame('I need numbers, not words.', $answer->content());
        self::assertSame('tool', $provider->lastConversation()[2]->role()->value);
        self::assertStringContainsString('not a number', $provider->lastConversation()[2]->content());
    }

    public function test_a_tool_that_refuses_the_work_reports_why_to_the_model(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('divide', ['a' => 1, 'b' => 0]),
            FakeProvider::text('That has no answer.'),
        );

        Agent::make()->withProvider($provider)->withTools([new Calculator()])->chat('1/0?');

        self::assertStringContainsString('zero', $provider->lastConversation()[2]->content());
    }

    public function test_a_tool_the_model_invented_is_reported_rather_than_fatal(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('send_email', ['to' => 'x']),
            FakeProvider::text('I cannot do that.'),
        );

        Agent::make()->withProvider($provider)->withTools([new Calculator()])->chat('email someone');

        $reported = $provider->lastConversation()[2]->content();

        self::assertStringContainsString('send_email', $reported);
        self::assertStringContainsString('add', $reported, 'the message should list what does exist');
    }

    /** A failure inside the application is a bug to fix, not something to tell the model. */
    public function test_an_unexpected_failure_inside_a_tool_reaches_the_developer(): void
    {
        $provider = FakeProvider::sequence(FakeProvider::toolCall('explode', []));

        $agent = Agent::make()->withProvider($provider)->withTools([new BrokenToolkit()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the database is on fire');

        $agent->chat('go');
    }

    public function test_tools_declared_by_a_subclass_are_used(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('add', ['a' => 1, 'b' => 1]),
            FakeProvider::text('two'),
        );

        $agent = (new CalculatingAgent())->withProvider($provider);

        self::assertSame('two', $agent->chat('1+1?')->content());
    }

    public function test_two_tools_with_one_name_is_a_mistake_worth_stopping_for(): void
    {
        $agent = Agent::make()
            ->withProvider(FakeProvider::sequence(FakeProvider::text('hi')))
            ->withTools([new Calculator(), new Calculator()]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('add');

        $agent->chat('2+3?');
    }

    public function test_an_agent_with_no_tools_behaves_exactly_as_before(): void
    {
        $provider = FakeProvider::sequence(FakeProvider::text('Paris'));

        $answer = Agent::make()->withProvider($provider)->chat('Capital of France?');

        self::assertSame('Paris', $answer->content());
        self::assertSame([], $provider->lastTools());
        self::assertSame(MessageRole::User, $provider->lastConversation()[0]->role());
    }
}

final class CalculatingAgent extends Agent
{
    protected function tools(): array
    {
        return [new Calculator()];
    }
}

final class BrokenToolkit extends Toolkit
{
    #[AsTool('Pretends to work.')]
    public function explode(
        #[Param('Unused.')]
        string $note = '',
    ): string {
        throw new \RuntimeException('the database is on fire');
    }
}
