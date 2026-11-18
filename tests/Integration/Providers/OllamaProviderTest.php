<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Integration\Providers;

use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Providers\Ollama\OllamaProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one provider that can be tested against a real model for free.
 *
 * Needs a running Ollama with a model pulled, so it is skipped unless you name the model:
 *
 *   ollama pull llama3.2
 *   AGENTE_OLLAMA_MODEL=llama3.2 composer test
 *
 * The assertions are deliberately about shape rather than wording: a real model is free to
 * answer however it likes, and a test that pins its prose would fail for no good reason.
 */
final class OllamaProviderTest extends TestCase
{
    private string $model;

    protected function setUp(): void
    {
        $model = getenv('AGENTE_OLLAMA_MODEL');

        if (!is_string($model) || trim($model) === '') {
            self::markTestSkipped('Set AGENTE_OLLAMA_MODEL=<a pulled model> to test against a real Ollama.');
        }

        $this->model = trim($model);
    }

    public function test_a_local_model_answers_and_reports_what_it_used(): void
    {
        $provider = OllamaProvider::fromEnvironment(model: $this->model);

        $answer = $provider->chat([
            new SystemMessage('Answer with a single word.'),
            new UserMessage('What is the capital of France?'),
        ]);

        self::assertNotSame('', trim($answer->content()));
        self::assertSame('assistant', $answer->role()->value);
        self::assertGreaterThan(0, $answer->usage()->prompt, 'Ollama reports prompt_eval_count');
        self::assertGreaterThan(0, $answer->usage()->completion, 'Ollama reports eval_count');
    }
}
