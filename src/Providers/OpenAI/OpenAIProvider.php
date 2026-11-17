<?php

declare(strict_types=1);

namespace AgentePHP\Providers\OpenAI;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Http\HttpClientInterface;
use AgentePHP\Http\Request;
use AgentePHP\Providers\AbstractProvider;

/** Talks to OpenAI's chat-completions API. */
final class OpenAIProvider extends AbstractProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const API_KEY_VARIABLE = 'OPENAI_API_KEY';

    private readonly OpenAIMessageMapper $mapper;

    public function __construct(string $apiKey, string $model, ?HttpClientInterface $http = null)
    {
        parent::__construct($apiKey, $model, $http);

        $this->mapper = new OpenAIMessageMapper();
    }

    /**
     * The model is named here rather than read from the environment: a key is a secret and
     * belongs to the deployment, while the model is a behavioural choice tied to the prompt
     * and the tools around it, and must not differ between production and its tests.
     */
    public static function fromEnvironment(string $model, ?HttpClientInterface $http = null): self
    {
        return new self(
            apiKey: self::keyFromEnvironment(self::API_KEY_VARIABLE, self::class, $model),
            model: $model,
            http: $http,
        );
    }

    protected function name(): string
    {
        return 'OpenAI';
    }

    protected function buildRequest(array $messages): Request
    {
        return Request::postJson(
            self::ENDPOINT,
            ['Authorization' => 'Bearer ' . $this->apiKey],
            [
                'model' => $this->model,
                'messages' => $this->mapper->map($messages),
            ],
        );
    }

    protected function readAnswer(array $payload): AssistantMessage
    {
        $choices = $this->requireArray($payload, 'choices', 'choices');
        $first = $choices[0] ?? null;

        if (!is_array($first)) {
            throw ProviderException::unexpectedPayload($this->name(), 'choices[0]', $payload);
        }

        $message = $first['message'] ?? null;

        if (!is_array($message)) {
            throw ProviderException::unexpectedPayload($this->name(), 'choices[0].message', $payload);
        }

        $content = $message['content'] ?? null;

        if (!is_string($content)) {
            throw ProviderException::unexpectedPayload($this->name(), 'choices[0].message.content', $payload);
        }

        return new AssistantMessage($content, $this->readUsage($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readUsage(array $payload): Usage
    {
        $usage = $payload['usage'] ?? null;

        if (!is_array($usage)) {
            return new Usage();
        }

        return new Usage(
            prompt: $this->tokenCount($usage['prompt_tokens'] ?? null),
            completion: $this->tokenCount($usage['completion_tokens'] ?? null),
        );
    }
}
