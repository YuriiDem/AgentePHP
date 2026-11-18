<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers\Ollama;

use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Providers\Ollama\OllamaProvider;
use AgentePHP\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OllamaProviderTest extends TestCase
{
    public function test_it_posts_the_conversation_to_a_local_ollama(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new OllamaProvider(model: 'llama3.1', http: $http);
        $provider->chat([new SystemMessage('Be brief.'), new UserMessage('Capital of France?')]);

        $request = $http->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('http://localhost:11434/api/chat', $request->url);
        self::assertSame([
            'model' => 'llama3.1',
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Capital of France?'],
            ],
            'stream' => false,
        ], $http->lastSentJson());
    }

    /**
     * Ollama streams by default and answers with newline-delimited JSON when it does, which
     * is not one object and cannot be decoded as one. Asking for a single response is not
     * an optimisation here, it is what makes the reply parseable at all.
     */
    public function test_it_always_asks_for_a_single_response_rather_than_a_stream(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')]);

        self::assertFalse($http->lastSentJson()['stream']);
    }

    public function test_it_sends_no_credentials_because_there_are_none(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')]);

        $headers = $http->lastRequest()->headers;

        self::assertArrayNotHasKey('Authorization', $headers);
        self::assertArrayNotHasKey('x-api-key', $headers);
    }

    public function test_it_talks_to_another_host_when_told_to(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new OllamaProvider(model: 'llama3.1', http: $http, baseUrl: 'http://gpu-box:11434');
        $provider->chat([new UserMessage('?')]);

        self::assertSame('http://gpu-box:11434/api/chat', $http->lastRequest()->url);
    }

    public function test_a_trailing_slash_on_the_base_url_does_not_produce_a_double_slash(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $provider = new OllamaProvider(model: 'llama3.1', http: $http, baseUrl: 'http://localhost:11434/');
        $provider->chat([new UserMessage('?')]);

        self::assertSame('http://localhost:11434/api/chat', $http->lastRequest()->url);
    }

    public function test_it_returns_the_message_as_an_assistant_message(): void
    {
        $http = FakeHttpClient::returningJson(self::reply('Paris'));

        $message = (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')]);

        self::assertSame('Paris', $message->content());
        self::assertSame('assistant', $message->role()->value);
    }

    /** Ollama reports its counts at the top level, under names of its own. */
    public function test_it_reads_ollamas_own_token_counts(): void
    {
        $http = FakeHttpClient::returningJson([
            'message' => ['role' => 'assistant', 'content' => 'Paris'],
            'prompt_eval_count' => 26,
            'eval_count' => 8,
            'done' => true,
        ]);

        $usage = (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')])->usage();

        self::assertSame(26, $usage->prompt);
        self::assertSame(8, $usage->completion);
    }

    public function test_it_reports_zero_usage_when_the_counts_are_absent(): void
    {
        $http = FakeHttpClient::returningJson(['message' => ['content' => 'Paris']]);

        $usage = (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')])->usage();

        self::assertSame(0, $usage->total());
    }

    /**
     * Ollama puts a plain string in "error" where the hosted vendors put an object, and the
     * useful part of a failure is that string - most often "model not found", which the
     * user fixes by pulling the model.
     */
    public function test_it_surfaces_the_error_text_ollama_returned(): void
    {
        $http = FakeHttpClient::returning('{"error":"model \'llama3.1\' not found, try pulling it first"}', 404);

        try {
            (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')]);
            self::fail('An unsuccessful status must not be returned as an answer.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('Ollama', $e->getMessage());
            self::assertStringContainsString('404', $e->getMessage());
            self::assertStringContainsString("model 'llama3.1' not found, try pulling it first", $e->getMessage());
            self::assertStringNotContainsString(
                '{"error"',
                $e->getMessage(),
                'the vendor sentence is the useful part; the JSON envelope is noise',
            );
        }
    }

    public function test_it_explains_which_part_of_an_unexpected_payload_was_missing(): void
    {
        $http = FakeHttpClient::returningJson(['done' => true]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('message');

        (new OllamaProvider(model: 'llama3.1', http: $http))->chat([new UserMessage('?')]);
    }

    /**
     * A missing key cannot be guessed, but a missing host can: a local Ollama is at a known
     * address, so this reads the environment and falls back instead of failing.
     */
    public function test_it_falls_back_to_the_local_address_when_the_environment_is_silent(): void
    {
        putenv('OLLAMA_URL');

        $http = FakeHttpClient::returningJson(self::reply('Paris'));
        OllamaProvider::fromEnvironment(model: 'llama3.1', http: $http)->chat([new UserMessage('?')]);

        self::assertSame('http://localhost:11434/api/chat', $http->lastRequest()->url);
    }

    public function test_it_takes_the_host_from_the_environment_when_there_is_one(): void
    {
        putenv('OLLAMA_URL=http://gpu-box:11434');

        try {
            $http = FakeHttpClient::returningJson(self::reply('Paris'));
            OllamaProvider::fromEnvironment(model: 'llama3.1', http: $http)->chat([new UserMessage('?')]);

            self::assertSame('http://gpu-box:11434/api/chat', $http->lastRequest()->url);
        } finally {
            putenv('OLLAMA_URL');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function reply(string $content): array
    {
        return [
            'model' => 'llama3.1',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done' => true,
        ];
    }
}
