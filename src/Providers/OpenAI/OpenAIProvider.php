<?php

declare(strict_types=1);

namespace AgentePHP\Providers\OpenAI;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\ToolCall;
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

    public function __construct(
        private readonly string $apiKey,
        string $model,
        ?HttpClientInterface $http = null,
    ) {
        parent::__construct($model, $http);

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

    protected function buildRequest(array $messages, array $tools): Request
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->mapper->map($messages),
        ];

        // Sent only when there are any: the API rejects an empty tools array.
        if ($tools !== []) {
            $payload['tools'] = $this->mapper->tools($tools);
        }

        return Request::postJson(
            self::ENDPOINT,
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $payload,
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

        $toolCalls = $this->readToolCalls($message, $payload);
        $content = $message['content'] ?? null;

        // A model that only wants a tool run sends no text at all, so null content is the
        // normal shape of that answer rather than a broken response.
        if (!is_string($content)) {
            if ($toolCalls === []) {
                throw ProviderException::unexpectedPayload($this->name(), 'choices[0].message.content', $payload);
            }

            $content = '';
        }

        return new AssistantMessage($content, $this->readUsage($payload), $toolCalls);
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<string, mixed>    $payload
     *
     * @return list<ToolCall>
     */
    private function readToolCalls(array $message, array $payload): array
    {
        $requested = $message['tool_calls'] ?? null;

        if (!is_array($requested)) {
            return [];
        }

        $calls = [];

        foreach ($requested as $call) {
            $function = is_array($call) ? $call['function'] ?? null : null;

            if (!is_array($function) || !is_string($function['name'] ?? null)) {
                throw ProviderException::unexpectedPayload($this->name(), 'tool_calls[].function.name', $payload);
            }

            $id = is_array($call) ? $call['id'] ?? null : null;

            $calls[] = new ToolCall(
                id: is_string($id) ? $id : uniqid('call_', true),
                name: $function['name'],
                arguments: $this->decodeArguments($function['arguments'] ?? null, $payload),
            );
        }

        return $calls;
    }

    /**
     * OpenAI sends the arguments as a JSON string, so they have to be decoded here. A model
     * can produce a string that is not valid JSON, which is a bad answer from the API rather
     * than a bug in this library - hence the same unexpected-payload failure as any other
     * malformed response.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $arguments, array $payload): array
    {
        if ($arguments === null || $arguments === '') {
            return [];
        }

        if (!is_string($arguments)) {
            throw ProviderException::unexpectedPayload($this->name(), 'tool_calls[].function.arguments', $payload);
        }

        $decoded = json_decode($arguments, true);

        if (!is_array($decoded)) {
            throw ProviderException::unexpectedPayload($this->name(), 'tool_calls[].function.arguments', $payload);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
