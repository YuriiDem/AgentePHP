<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\Anthropic\AnthropicProvider;
use AgentePHP\Providers\Ollama\OllamaProvider;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tests\Support\FakeHttpClient;
use AgentePHP\Tools\Toolkits\Calculator;
use PHPUnit\Framework\TestCase;

/**
 * Tool calling is where the three vendors diverge the most, so the divergence is pinned
 * down in one place: what each one is sent, how each one answers, and how a result goes
 * back. The last test is the one that matters - all of it has to come out the same on our
 * side of the contract.
 */
final class ToolCallingTest extends TestCase
{
    // --- OpenAI -------------------------------------------------------------------------

    public function test_openai_is_sent_tools_as_function_declarations(): void
    {
        $http = FakeHttpClient::returningJson(['choices' => [['message' => ['content' => 'ok']]]]);

        $provider = new OpenAIProvider(apiKey: 'k', model: 'gpt-4o-mini', http: $http);
        $provider->chat([new UserMessage('2+3?')], $this->calculatorTools());

        $sent = $http->lastSentJson();
        $first = $sent['tools'][0];

        self::assertSame('function', $first['type']);
        self::assertSame('add', $first['function']['name']);
        self::assertSame('Add two numbers and return the sum.', $first['function']['description']);
        self::assertSame('object', $first['function']['parameters']['type']);
    }

    public function test_openai_sends_no_tools_key_when_there_are_none(): void
    {
        $http = FakeHttpClient::returningJson(['choices' => [['message' => ['content' => 'ok']]]]);

        (new OpenAIProvider(apiKey: 'k', model: 'gpt-4o-mini', http: $http))->chat([new UserMessage('?')]);

        self::assertArrayNotHasKey('tools', $http->lastSentJson());
    }

    /** OpenAI sends the arguments as a JSON string, and nulls the content. */
    public function test_openai_tool_calls_are_read_out_of_the_response(): void
    {
        $http = FakeHttpClient::returningJson(['choices' => [['message' => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_abc',
                'type' => 'function',
                'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}'],
            ]],
        ]]]]);

        $answer = (new OpenAIProvider(apiKey: 'k', model: 'gpt-4o-mini', http: $http))->chat([new UserMessage('?')]);

        self::assertTrue($answer->hasToolCalls());
        self::assertSame('call_abc', $answer->toolCalls()[0]->id);
        self::assertSame('add', $answer->toolCalls()[0]->name);
        self::assertSame(['a' => 2, 'b' => 3], $answer->toolCalls()[0]->arguments);
        self::assertSame('', $answer->content());
    }

    public function test_openai_gets_the_request_and_the_result_back_as_two_messages(): void
    {
        $http = FakeHttpClient::returningJson(['choices' => [['message' => ['content' => '5']]]]);

        (new OpenAIProvider(apiKey: 'k', model: 'gpt-4o-mini', http: $http))->chat([
            new UserMessage('2+3?'),
            new AssistantMessage('', toolCalls: [new ToolCall('call_abc', 'add', ['a' => 2, 'b' => 3])]),
            new ToolMessage('5', toolCallId: 'call_abc', toolName: 'add'),
        ]);

        $messages = $http->lastSentJson()['messages'];

        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('call_abc', $messages[1]['tool_calls'][0]['id']);
        self::assertSame('{"a":2,"b":3}', $messages[1]['tool_calls'][0]['function']['arguments']);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_abc', 'content' => '5'], $messages[2]);
    }

    // --- Anthropic ----------------------------------------------------------------------

    public function test_anthropic_is_sent_tools_with_an_input_schema(): void
    {
        $http = FakeHttpClient::returningJson(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $provider = new AnthropicProvider(apiKey: 'k', model: 'claude-sonnet-4-5', http: $http);
        $provider->chat([new UserMessage('2+3?')], $this->calculatorTools());

        $first = $http->lastSentJson()['tools'][0];

        self::assertSame('add', $first['name']);
        self::assertSame('Add two numbers and return the sum.', $first['description']);
        self::assertSame('object', $first['input_schema']['type'], 'Anthropic calls it input_schema');
        self::assertArrayNotHasKey('function', $first, 'and does not wrap it in a function object');
    }

    /** Anthropic answers with content blocks, and the arguments arrive already decoded. */
    public function test_anthropic_tool_calls_are_read_out_of_the_content_blocks(): void
    {
        $http = FakeHttpClient::returningJson([
            'content' => [
                ['type' => 'text', 'text' => 'Let me add those.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'add', 'input' => ['a' => 2, 'b' => 3]],
            ],
            'stop_reason' => 'tool_use',
        ]);

        $answer = (new AnthropicProvider(apiKey: 'k', model: 'claude-sonnet-4-5', http: $http))
            ->chat([new UserMessage('?')]);

        self::assertTrue($answer->hasToolCalls());
        self::assertSame('toolu_1', $answer->toolCalls()[0]->id);
        self::assertSame(['a' => 2, 'b' => 3], $answer->toolCalls()[0]->arguments);
        self::assertSame('Let me add those.', $answer->content(), 'any text alongside is kept');
    }

    /** The one that catches people out: a result goes back as a *user* turn. */
    public function test_anthropic_takes_a_tool_result_as_a_user_turn(): void
    {
        $http = FakeHttpClient::returningJson(['content' => [['type' => 'text', 'text' => '5']]]);

        (new AnthropicProvider(apiKey: 'k', model: 'claude-sonnet-4-5', http: $http))->chat([
            new UserMessage('2+3?'),
            new AssistantMessage('', toolCalls: [new ToolCall('toolu_1', 'add', ['a' => 2, 'b' => 3])]),
            new ToolMessage('5', toolCallId: 'toolu_1', toolName: 'add'),
        ]);

        $messages = $http->lastSentJson()['messages'];

        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('tool_use', $messages[1]['content'][0]['type']);
        self::assertSame(['a' => 2, 'b' => 3], $messages[1]['content'][0]['input']);

        self::assertSame('user', $messages[2]['role']);
        self::assertSame([
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '5'],
        ], $messages[2]['content']);
    }

    public function test_anthropic_tool_use_without_a_name_is_reported(): void
    {
        $http = FakeHttpClient::returningJson([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'input' => []]],
        ]);

        $this->expectException(\AgentePHP\Exceptions\ProviderException::class);
        $this->expectExceptionMessage('content[].tool_use.name');

        (new AnthropicProvider(apiKey: 'k', model: 'claude-sonnet-4-5', http: $http))->chat([new UserMessage('?')]);
    }

    /**
     * Anthropic requires every tool_use of one assistant turn to be answered by
     * tool_result blocks in the single user turn that follows. Two separate user turns are
     * rejected, so consecutive results have to be merged - the one place where mapping is
     * not message-for-message.
     */
    public function test_anthropic_gets_two_results_merged_into_one_turn(): void
    {
        $http = FakeHttpClient::returningJson(['content' => [['type' => 'text', 'text' => 'done']]]);

        (new AnthropicProvider(apiKey: 'k', model: 'claude-sonnet-4-5', http: $http))->chat([
            new UserMessage('2+3 and 4*5?'),
            new AssistantMessage('', toolCalls: [
                new ToolCall('toolu_1', 'add', ['a' => 2, 'b' => 3]),
                new ToolCall('toolu_2', 'multiply', ['a' => 4, 'b' => 5]),
            ]),
            new ToolMessage('5', toolCallId: 'toolu_1', toolName: 'add'),
            new ToolMessage('20', toolCallId: 'toolu_2', toolName: 'multiply'),
        ]);

        $messages = $http->lastSentJson()['messages'];

        self::assertCount(3, $messages, 'user, assistant, and one user turn carrying both results');
        self::assertSame('user', $messages[2]['role']);
        self::assertSame(['toolu_1', 'toolu_2'], array_column($messages[2]['content'], 'tool_use_id'));
    }

    // --- Ollama -------------------------------------------------------------------------

    public function test_ollama_is_sent_tools_in_the_openai_shape(): void
    {
        $http = FakeHttpClient::returningJson(['message' => ['content' => 'ok']]);

        $provider = new OllamaProvider(model: 'llama3.1', http: $http);
        $provider->chat([new UserMessage('2+3?')], $this->calculatorTools());

        self::assertSame('function', $http->lastSentJson()['tools'][0]['type']);
        self::assertSame('add', $http->lastSentJson()['tools'][0]['function']['name']);
    }

    /**
     * Ollama hands back arguments already decoded and gives no id at all, so one is made
     * up here - the id only ever has to survive one round trip.
     */
    public function test_ollama_tool_calls_get_an_id_of_our_own(): void
    {
        $http = FakeHttpClient::returningJson(['message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['function' => ['name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]]]],
        ]]);

        $answer = (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')]);

        self::assertTrue($answer->hasToolCalls());
        self::assertSame('add', $answer->toolCalls()[0]->name);
        self::assertSame(['a' => 2, 'b' => 3], $answer->toolCalls()[0]->arguments);
        self::assertNotSame('', $answer->toolCalls()[0]->id);
    }

    public function test_ollama_takes_a_tool_result_as_a_tool_turn(): void
    {
        $http = FakeHttpClient::returningJson(['message' => ['content' => '5']]);

        (new OllamaProvider(model: 'llama3.1', http: $http))->chat([
            new UserMessage('2+3?'),
            new AssistantMessage('', toolCalls: [new ToolCall('call_0', 'add', ['a' => 2, 'b' => 3])]),
            new ToolMessage('5', toolCallId: 'call_0', toolName: 'add'),
        ]);

        $messages = $http->lastSentJson()['messages'];

        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('add', $messages[1]['tool_calls'][0]['function']['name']);
        self::assertSame(['a' => 2, 'b' => 3], $messages[1]['tool_calls'][0]['function']['arguments']);
        self::assertSame('tool', $messages[2]['role']);
        self::assertSame('5', $messages[2]['content']);
    }

    // --- and the point of all of it ------------------------------------------------------

    /**
     * Three request formats, three response formats, one ToolCall on our side.
     */
    public function test_a_tool_call_looks_the_same_whoever_answered(): void
    {
        $providers = [
            new OpenAIProvider(apiKey: 'k', model: 'gpt-4o-mini', http: FakeHttpClient::returningJson([
                'choices' => [['message' => ['content' => null, 'tool_calls' => [[
                    'id' => 'call_abc',
                    'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}'],
                ]]]]],
            ])),
            new AnthropicProvider(apiKey: 'k', model: 'claude-sonnet-4-5', http: FakeHttpClient::returningJson([
                'content' => [['type' => 'tool_use', 'id' => 'call_abc', 'name' => 'add', 'input' => ['a' => 2, 'b' => 3]]],
            ])),
            new OllamaProvider(model: 'llama3.1', http: FakeHttpClient::returningJson([
                'message' => ['tool_calls' => [['function' => ['name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]]]]],
            ])),
        ];

        foreach ($providers as $provider) {
            self::assertInstanceOf(AIProviderInterface::class, $provider);

            $call = $provider->chat([new UserMessage('2+3?')], $this->calculatorTools())->toolCalls()[0];

            self::assertSame('add', $call->name, $provider::class);
            self::assertSame(['a' => 2, 'b' => 3], $call->arguments, $provider::class);
        }
    }

    /**
     * @return list<\AgentePHP\Tools\Tool>
     */
    private function calculatorTools(): array
    {
        return array_values(array_filter(
            (new Calculator())->tools(),
            static fn (\AgentePHP\Tools\Tool $tool): bool => $tool->name === 'add',
        ));
    }
}
