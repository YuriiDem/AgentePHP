<?php

declare(strict_types=1);

namespace AgentePHP\Chat\History;

use AgentePHP\Chat\Messages\Message;

/**
 * What an agent remembers of a conversation.
 *
 * An interface because the storage backend is a deployment choice, not a design one:
 * in-process for a single web request, a file for a CLI session that must survive the
 * process, a database later. Every implementation has to be substitutable without the
 * agent noticing, which is what makes this an interface rather than one class with a
 * "where to store it" flag.
 *
 * A history holds the dialogue, not the request payload. The standing instruction
 * (`SystemMessage`) belongs to the agent and is supplied on every call, so that changing
 * an agent's instructions does not require rewriting every conversation ever stored.
 */
interface ChatHistoryInterface
{
    /**
     * Append one message, or a whole exchange at once.
     *
     * Variadic because a turn is usually a pair - what was asked and what was answered -
     * and storing them in one call is both cheaper for a file-backed store and harder to
     * get half-done.
     */
    public function add(Message ...$messages): void;

    /**
     * Everything remembered, oldest first.
     *
     * @return list<Message>
     */
    public function messages(): array;

    /** Forget the conversation. The history stays usable afterwards. */
    public function clear(): void;
}
