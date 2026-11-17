<?php

declare(strict_types=1);

namespace AgentePHP\Providers\Anthropic;

use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\Message;

/**
 * Translates our messages into the shape Anthropic's messages API expects.
 *
 * Anthropic is where a single parameterised mapper would have started collapsing into
 * conditionals: the system prompt is not a message here but a top-level field, so the same
 * conversation has to be split in two. Two small methods, each a pure function of the
 * messages, instead of one method with a flag.
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
     * Everything that is an actual turn: user and assistant, in order.
     *
     * @param list<Message> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public function conversation(array $messages): array
    {
        $turns = [];

        foreach ($messages as $message) {
            if ($message->role() === MessageRole::System) {
                continue;
            }

            $turns[] = [
                'role' => $message->role()->value,
                'content' => $message->content(),
            ];
        }

        return $turns;
    }
}
