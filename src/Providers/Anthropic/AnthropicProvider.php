<?php

declare(strict_types=1);

namespace AgentePHP\Providers\Anthropic;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Http\HttpClientInterface;
use AgentePHP\Http\Request;
use AgentePHP\Providers\AbstractProvider;

/** Talks to Anthropic's messages API. */
final class AnthropicProvider extends AbstractProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_KEY_VARIABLE = 'ANTHROPIC_API_KEY';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 4096;

    private readonly AnthropicMessageMapper $mapper;

    /**
     * Anthropic requires max_tokens on every request, so it is a constructor parameter with
     * a default rather than something a caller must remember. OpenAI has no equivalent
     * requirement, which is why this parameter lives here and not on AbstractProvider.
     */
    public function __construct(
        private readonly string $apiKey,
        string $model,
        ?HttpClientInterface $http = null,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ) {
        parent::__construct($model, $http);

        $this->mapper = new AnthropicMessageMapper();
    }

    public static function fromEnvironment(
        string $model,
        ?HttpClientInterface $http = null,
        int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ): self {
        return new self(
            apiKey: self::keyFromEnvironment(self::API_KEY_VARIABLE, self::class, $model),
            model: $model,
            http: $http,
            maxTokens: $maxTokens,
        );
    }

    protected function name(): string
    {
        return 'Anthropic';
    }

    protected function buildRequest(array $messages, array $tools): Request
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->mapper->conversation($messages),
        ];

        $system = $this->mapper->system($messages);

        // Sent only when there is one: Anthropic rejects a null system field.
        if ($system !== null) {
            $payload['system'] = $system;
        }

        if ($tools !== []) {
            $payload['tools'] = $this->mapper->tools($tools);
        }

        return Request::postJson(
            self::ENDPOINT,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ],
            $payload,
        );
    }

    protected function readAnswer(array $payload): AssistantMessage
    {
        $blocks = $this->requireArray($payload, 'content', 'content');
        $text = '';
        $toolCalls = [];

        // The answer arrives as a list of blocks of mixed kinds: text, and a tool_use block
        // per tool the model wants run. A plain text answer is usually one block, but the
        // API is free to split it, so the text is joined rather than indexed into.
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];

                continue;
            }

            if ($type === 'tool_use') {
                $toolCalls[] = $this->readToolCall($block, $payload);
            }
        }

        // No text and no request to run anything means there is nothing to return at all.
        if ($text === '' && $toolCalls === []) {
            throw ProviderException::unexpectedPayload($this->name(), 'content[].text', $payload);
        }

        return new AssistantMessage($text, $this->readUsage($payload), $toolCalls);
    }

    /**
     * @param array<array-key, mixed> $block
     * @param array<string, mixed>    $payload
     */
    private function readToolCall(array $block, array $payload): ToolCall
    {
        $name = $block['name'] ?? null;

        if (!is_string($name)) {
            throw ProviderException::unexpectedPayload($this->name(), 'content[].tool_use.name', $payload);
        }

        $input = $block['input'] ?? [];

        /** @var array<string, mixed> $input Anthropic sends an object here, already decoded. */
        $input = is_array($input) ? $input : [];

        $id = $block['id'] ?? null;

        return new ToolCall(
            id: is_string($id) ? $id : uniqid('toolu_', true),
            name: $name,
            arguments: $input,
        );
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
            prompt: $this->tokenCount($usage['input_tokens'] ?? null),
            completion: $this->tokenCount($usage['output_tokens'] ?? null),
        );
    }
}
