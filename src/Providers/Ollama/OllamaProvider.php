<?php

declare(strict_types=1);

namespace AgentePHP\Providers\Ollama;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Http\HttpClientInterface;
use AgentePHP\Http\Request;
use AgentePHP\Providers\AbstractProvider;

/**
 * Talks to a local Ollama.
 *
 * The provider with no account, no key and no bill: useful for learning what the agentic
 * loop actually sends, for tests that may touch a real model, and for anything that must
 * not leave the machine.
 */
final class OllamaProvider extends AbstractProvider
{
    private const DEFAULT_BASE_URL = 'http://localhost:11434';
    private const BASE_URL_VARIABLE = 'OLLAMA_URL';

    private readonly string $baseUrl;

    private readonly OllamaMessageMapper $mapper;

    public function __construct(
        string $model,
        ?HttpClientInterface $http = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        parent::__construct($model, $http);

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->mapper = new OllamaMessageMapper();
    }

    /**
     * Unlike the hosted providers, this one does not fail when the environment is silent:
     * a missing API key cannot be guessed, but a local Ollama lives at a known address.
     */
    public static function fromEnvironment(string $model, ?HttpClientInterface $http = null): self
    {
        $configured = getenv(self::BASE_URL_VARIABLE);

        return new self(
            model: $model,
            http: $http,
            baseUrl: is_string($configured) && trim($configured) !== '' ? trim($configured) : self::DEFAULT_BASE_URL,
        );
    }

    protected function name(): string
    {
        return 'Ollama';
    }

    protected function buildRequest(array $messages): Request
    {
        return Request::postJson(
            $this->baseUrl . '/api/chat',
            [],
            [
                'model' => $this->model,
                'messages' => $this->mapper->map($messages),

                // Ollama streams by default, and a stream is newline-delimited JSON rather
                // than one object. Until this library can stream, asking for a single
                // response is what makes the answer parseable at all.
                'stream' => false,
            ],
        );
    }

    protected function readAnswer(array $payload): AssistantMessage
    {
        $message = $this->requireArray($payload, 'message', 'message');
        $content = $message['content'] ?? null;

        if (!is_string($content)) {
            throw ProviderException::unexpectedPayload($this->name(), 'message.content', $payload);
        }

        return new AssistantMessage($content, $this->readUsage($payload));
    }

    /**
     * Ollama reports its counts at the top level of the response rather than in a nested
     * object, under names of its own.
     *
     * @param array<string, mixed> $payload
     */
    private function readUsage(array $payload): Usage
    {
        return new Usage(
            prompt: $this->tokenCount($payload['prompt_eval_count'] ?? null),
            completion: $this->tokenCount($payload['eval_count'] ?? null),
        );
    }
}
