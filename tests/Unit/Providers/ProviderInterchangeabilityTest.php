<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers;

use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\Anthropic\AnthropicProvider;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The promise this layer exists for: application code depends on the interface, so changing
 * the model is one line at one place. These tests fail the moment a provider starts needing
 * special handling from its caller.
 */
final class ProviderInterchangeabilityTest extends TestCase
{
    /**
     * Application code, written once. It knows nothing about which vendor answers.
     *
     * @param list<SystemMessage|UserMessage> $messages
     */
    private function ask(AIProviderInterface $provider, array $messages): string
    {
        return $provider->chat($messages)->content();
    }

    public function test_the_same_caller_works_with_either_provider(): void
    {
        $conversation = [new SystemMessage('Be brief.'), new UserMessage('Capital of France?')];

        $openAi = new OpenAIProvider(
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
            http: FakeHttpClient::returningJson(['choices' => [['message' => ['content' => 'Paris']]]]),
        );

        $anthropic = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-5',
            http: FakeHttpClient::returningJson(['content' => [['type' => 'text', 'text' => 'Paris']]]),
        );

        self::assertSame('Paris', $this->ask($openAi, $conversation));
        self::assertSame('Paris', $this->ask($anthropic, $conversation));
    }

    public function test_both_providers_report_usage_through_the_same_value_object(): void
    {
        $openAi = new OpenAIProvider(
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
            http: FakeHttpClient::returningJson([
                'choices' => [['message' => ['content' => 'Paris']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ]),
        );

        $anthropic = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-5',
            http: FakeHttpClient::returningJson([
                'content' => [['type' => 'text', 'text' => 'Paris']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
            ]),
        );

        $message = [new UserMessage('Capital of France?')];

        self::assertEquals(
            $openAi->chat($message)->usage(),
            $anthropic->chat($message)->usage(),
            'Two vendors count the same conversation with different field names; '
            . 'the library must present one Usage.',
        );
    }
}
