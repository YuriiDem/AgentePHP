<?php

declare(strict_types=1);

namespace AgentePHP\Providers\Ollama;

use AgentePHP\Chat\Messages\Message;

/**
 * Translates our messages into the shape Ollama's /api/chat expects.
 *
 * This happens to produce the same arrays as the OpenAI mapper today, because Ollama
 * modelled its chat endpoint on that format. They are still two independent APIs with
 * separate release schedules - Ollama already carries a per-message `images` field that
 * OpenAI's chat-completions does not - so sharing one class would mean a change for one
 * vendor forcing a change to the other. Ten duplicated lines are cheaper than that
 * coupling.
 */
final class OllamaMessageMapper
{
    /**
     * @param list<Message> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public function map(array $messages): array
    {
        return array_map(
            static fn (Message $message): array => [
                'role' => $message->role()->value,
                'content' => $message->content(),
            ],
            $messages,
        );
    }
}
