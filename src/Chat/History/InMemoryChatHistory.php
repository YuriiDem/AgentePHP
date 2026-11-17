<?php

declare(strict_types=1);

namespace AgentePHP\Chat\History;

use AgentePHP\Chat\Messages\Message;
use InvalidArgumentException;

/**
 * Remembers a conversation for as long as the process lives.
 *
 * The right default: a web request or a queue job ends, and so does the conversation. It
 * is also the implementation an agent test should use, because it needs nothing.
 */
final class InMemoryChatHistory implements ChatHistoryInterface
{
    /** @var list<Message> */
    private array $messages = [];

    /**
     * @param int|null $maxMessages how many of the most recent messages to keep,
     *                              or null to keep everything
     */
    public function __construct(private readonly ?int $maxMessages = null)
    {
        if ($maxMessages !== null && $maxMessages < 1) {
            throw new InvalidArgumentException(sprintf(
                'A history has to keep at least 1 message, got %d. '
                . 'Pass null (the default) to keep every message instead.',
                $maxMessages,
            ));
        }
    }

    public function add(Message ...$messages): void
    {
        foreach ($messages as $message) {
            $this->messages[] = $message;
        }

        $this->forgetTheOldest();
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    /**
     * A conversation is sent in full on every call, so an unbounded history makes each
     * turn more expensive than the last. array_slice with a negative offset keeps the tail
     * and renumbers the keys, which matters because the result has to stay a JSON list.
     */
    private function forgetTheOldest(): void
    {
        if ($this->maxMessages === null || count($this->messages) <= $this->maxMessages) {
            return;
        }

        $this->messages = array_slice($this->messages, -$this->maxMessages);
    }
}
