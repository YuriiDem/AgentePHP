<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers\OpenAI;

use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\MissingApiKey;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Exceptions\RateLimited;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OpenAIProviderTest extends TestCase
{
    public function test_it_posts_the_conversation_to_the_chat_completions_endpoint(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);
        $provider->chat([new SystemMessage('Be brief.'), new UserMessage('Capital of France?')]);

        $request = $http->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.openai.com/v1/chat/completions', $request->url);
        self::assertSame('Bearer test-key', $request->headers['Authorization']);
        self::assertSame([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Capital of France?'],
            ],
        ], $http->lastSentJson());
    }

    public function test_it_returns_the_assistant_message_from_the_response(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));
        $provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);

        $message = $provider->chat([new UserMessage('Capital of France?')]);

        self::assertSame('Paris', $message->content());
        self::assertSame('assistant', $message->role()->value);
    }

    public function test_it_reads_the_token_usage_the_api_reported(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris', ['prompt_tokens' => 412, 'completion_tokens' => 57]));
        $provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);

        $usage = $provider->chat([new UserMessage('Capital of France?')])->usage();

        self::assertSame(412, $usage->prompt);
        self::assertSame(57, $usage->completion);
    }

    public function test_it_reports_zero_usage_when_the_api_omits_it(): void
    {
        $http = FakeHttpClient::returningJson(['choices' => [['message' => ['content' => 'Paris']]]]);
        $provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);

        self::assertSame(0, $provider->chat([new UserMessage('?')])->usage()->total());
    }

    public function test_it_names_the_provider_and_the_status_when_the_api_fails(): void
    {
        $http = FakeHttpClient::returning('{"error":{"message":"Incorrect API key provided"}}', 401);
        $provider = new OpenAIProvider(apiKey: 'wrong-key', model: 'gpt-4o-mini', http: $http);

        try {
            $provider->chat([new UserMessage('?')]);
            self::fail('An unsuccessful status must not be returned as an answer.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('OpenAI', $e->getMessage());
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringContainsString('Incorrect API key provided', $e->getMessage());
        }
    }

    public function test_a_429_is_a_rate_limit_a_caller_can_catch_on_its_own(): void
    {
        $http = FakeHttpClient::returning('{"error":{"message":"Rate limit reached"}}', 429);
        $provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);

        $this->expectException(RateLimited::class);

        $provider->chat([new UserMessage('?')]);
    }

    public function test_it_explains_which_part_of_an_unexpected_payload_was_missing(): void
    {
        $http = FakeHttpClient::returningJson(['choices' => [['message' => ['refusal' => 'no']]]]);
        $provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('choices[0].message.content');

        $provider->chat([new UserMessage('?')]);
    }

    public function test_it_builds_itself_from_the_environment(): void
    {
        putenv('OPENAI_API_KEY=key-from-env');

        try {
            $http = FakeHttpClient::returningJson(self::reply('Paris'));
            $provider = OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini', http: $http);

            $provider->chat([new UserMessage('?')]);

            self::assertSame('Bearer key-from-env', $http->lastRequest()->headers['Authorization']);
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    public function test_it_says_what_to_do_when_the_environment_has_no_key(): void
    {
        putenv('OPENAI_API_KEY');

        $this->expectException(MissingApiKey::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');

        OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
    }

    /**
     * @param array<string, int> $usage
     *
     * @return array<string, mixed>
     */
    private static function reply(string $content, array $usage = []): array
    {
        $payload = ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];

        if ($usage !== []) {
            $payload['usage'] = $usage;
        }

        return $payload;
    }
}
