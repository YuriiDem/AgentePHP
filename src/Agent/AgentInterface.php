<?php

declare(strict_types=1);

namespace AgentePHP\Agent;

use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Exceptions\AgentException;
use AgentePHP\Exceptions\ProviderException;

/**
 * Something an application can hold a conversation with.
 *
 * The difference from a provider: a provider is stateless and is handed the whole
 * conversation every time, while an agent remembers one. Everything else an agent will
 * grow - tools, retrieval, structured output - is built on top of these two methods rather
 * than alongside them.
 */
interface AgentInterface
{
    /**
     * Say something and get the answer, with the conversation so far taken care of.
     *
     * A plain string is accepted because that is what a caller almost always has; a
     * Message is accepted for when it is not.
     *
     * @throws AgentException    when the agent has no provider to talk to
     * @throws ProviderException when the model's API refuses or answers with something unusable
     */
    public function chat(string|Message $message): AssistantMessage;

    /**
     * What the agent remembers, for reading, clearing, or persisting elsewhere.
     *
     * Part of the contract rather than an implementation detail: an application routinely
     * has to show a conversation, reset one, or hand one to another agent.
     */
    public function history(): ChatHistoryInterface;
}
