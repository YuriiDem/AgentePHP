<?php

declare(strict_types=1);

namespace AgentePHP\Providers\Anthropic;

use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Tools\Tool;

/**
 * Translates our messages and tools into the shape Anthropic's messages API expects.
 *
 * Anthropic is where a single parameterised mapper would have collapsed into conditionals.
 * The system prompt is not a message here but a top-level field; a turn's content is a list
 * of blocks rather than a string; and a tool result is not a role of its own but a user
 * turn carrying tool_result blocks. Three structural differences, one per method below.
 */
final class AnthropicMessageMapper
{
    /**
     * The system prompt, or null when the conversation has none.
     *
     * Several system messages are joined rather than the last one winning: an agent's
     * standing instructions and a per-request instruction are both legitimate, and silently
     * dropping one would be the kind of failure nobody notices until the model misbehaves.
     *
     * @param list<Message> $messages
     */
    public function system(array $messages): ?string
    {
        $prompts = [];

        foreach ($messages as $message) {
            if ($message->role() === MessageRole::System) {
                $prompts[] = $message->content();
            }
        }

        return $prompts === [] ? null : implode("\n\n", $prompts);
    }

    /**
     * Everything that is an actual turn, in order.
     *
     * Consecutive tool results are merged into one user turn, because Anthropic requires
     * every tool_use in an assistant turn to be answered by tool_result blocks in the single
     * user turn that follows it. Sending them as separate turns is rejected.
     *
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function conversation(array $messages): array
    {
        $turns = [];
        /** @var list<array<string, mixed>> $pendingResults */
        $pendingResults = [];

        foreach ($messages as $message) {
            if ($message->role() === MessageRole::System) {
                continue;
            }

            if ($message instanceof ToolMessage) {
                $pendingResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content' => $message->content(),
                ];

                continue;
            }

            if ($pendingResults !== []) {
                $turns[] = ['role' => 'user', 'content' => $pendingResults];
                $pendingResults = [];
            }

            $turns[] = $this->turn($message);
        }

        if ($pendingResults !== []) {
            $turns[] = ['role' => 'user', 'content' => $pendingResults];
        }

        return $turns;
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<array<string, mixed>>
     */
    public function tools(array $tools): array
    {
        return array_map(
            static fn (Tool $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,

                // Anthropic calls it input_schema, and does not wrap it in a function object.
                'input_schema' => $tool->jsonSchema(),
            ],
            $tools,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function turn(Message $message): array
    {
        if (!$message instanceof AssistantMessage || !$message->hasToolCalls()) {
            return ['role' => $message->role()->value, 'content' => $message->content()];
        }

        $blocks = [];

        // Any words the model said alongside its request are kept, but an empty text block
        // is rejected by the API, so it is only added when there is something in it.
        if ($message->content() !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content()];
        }

        foreach ($message->toolCalls() as $call) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $call->id,
                'name' => $call->name,

                // Already an object here, unlike OpenAI's JSON string.
                'input' => $call->arguments,
            ];
        }

        return ['role' => 'assistant', 'content' => $blocks];
    }
}
