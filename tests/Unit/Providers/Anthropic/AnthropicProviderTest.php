<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers\Anthropic;

use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\MissingApiKey;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Exceptions\RateLimited;
use AgentePHP\Providers\Anthropic\AnthropicProvider;
use AgentePHP\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AnthropicProviderTest extends TestCase
{
    public function test_it_posts_to_the_messages_endpoint_with_the_version_header(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);
        $provider->chat([new UserMessage('Capital of France?')]);

        $request = $http->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.anthropic.com/v1/messages', $request->url);
        self::assertSame('test-key', $request->headers['x-api-key']);
        self::assertSame('2023-06-01', $request->headers['anthropic-version']);
    }

    public function test_it_sends_the_system_prompt_as_a_top_level_field(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);
        $provider->chat([new SystemMessage('Be brief.'), new UserMessage('Capital of France?')]);

        self::assertSame([
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 4096,
            'messages' => [['role' => 'user', 'content' => 'Capital of France?']],
            'system' => 'Be brief.',
        ], $http->lastSentJson());
    }

    public function test_it_omits_the_system_field_when_there_is_no_system_message(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);
        $provider->chat([new UserMessage('Capital of France?')]);

        self::assertArrayNotHasKey('system', $http->lastSentJson());
    }

    public function test_the_token_ceiling_is_configurable_because_the_api_requires_one(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-5',
            http: $http,
            maxTokens: 256,
        );
        $provider->chat([new UserMessage('?')]);

        self::assertSame(256, $http->lastSentJson()['max_tokens']);
    }

    public function test_it_returns_the_text_block_as_an_assistant_message(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);

        $message = $provider->chat([new UserMessage('Capital of France?')]);

        self::assertSame('Paris', $message->content());
        self::assertSame('assistant', $message->role()->value);
    }

    public function test_it_joins_several_text_blocks_into_one_answer(): void
    {
        $http = FakeHttpClient::returningJson([
            'content' => [
                ['type' => 'text', 'text' => 'Paris'],
                ['type' => 'text', 'text' => ' is the capital.'],
            ],
        ]);
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);

        self::assertSame('Paris is the capital.', $provider->chat([new UserMessage('?')])->content());
    }

    public function test_it_reads_anthropics_own_usage_field_names(): void
    {
        $http = FakeHttpClient::returningJson(
            self::reply('Paris', ['input_tokens' => 412, 'output_tokens' => 57]),
        );
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);

        $usage = $provider->chat([new UserMessage('?')])->usage();

        self::assertSame(412, $usage->prompt);
        self::assertSame(57, $usage->completion);
    }

    public function test_it_names_the_provider_and_the_status_when_the_api_fails(): void
    {
        $http = FakeHttpClient::returning('{"error":{"message":"invalid x-api-key"}}', 401);
        $provider = new AnthropicProvider(apiKey: 'wrong-key', model: 'claude-sonnet-4-5', http: $http);

        try {
            $provider->chat([new UserMessage('?')]);
            self::fail('An unsuccessful status must not be returned as an answer.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('Anthropic', $e->getMessage());
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringContainsString('invalid x-api-key', $e->getMessage());
        }
    }

    public function test_a_429_is_a_rate_limit_a_caller_can_catch_on_its_own(): void
    {
        $http = FakeHttpClient::returning('{"error":{"message":"rate limited"}}', 429);
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);

        $this->expectException(RateLimited::class);

        $provider->chat([new UserMessage('?')]);
    }

    /**
     * A block of a kind this version does not handle - a thinking block, say - leaves
     * nothing to return, and the failure has to name what was looked for.
     */
    public function test_it_explains_which_part_of_an_unexpected_payload_was_missing(): void
    {
        $http = FakeHttpClient::returningJson(['content' => [['type' => 'redacted_thinking', 'data' => 'x']]]);
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5', http: $http);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('content[].text');

        $provider->chat([new UserMessage('?')]);
    }

    public function test_it_says_what_to_do_when_the_environment_has_no_key(): void
    {
        putenv('ANTHROPIC_API_KEY');

        $this->expectException(MissingApiKey::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY');

        AnthropicProvider::fromEnvironment(model: 'claude-sonnet-4-5');
    }

    /**
     * @param array<string, int> $usage
     *
     * @return array<string, mixed>
     */
    private static function reply(string $content, array $usage = []): array
    {
        $payload = ['content' => [['type' => 'text', 'text' => $content]]];

        if ($usage !== []) {
            $payload['usage'] = $usage;
        }

        return $payload;
    }
}
