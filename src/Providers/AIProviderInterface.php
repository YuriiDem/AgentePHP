<?php

declare(strict_types=1);

namespace AgentePHP\Providers;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Tools\Tool;

/**
 * One contract for every model vendor.
 *
 * This is the interface application code depends on, and the reason changing the model is
 * one line in one place: a provider is an interchangeable strategy, and nothing about the
 * caller changes when it is swapped.
 *
 * Deliberately small. Everything added here has to be implemented by every vendor adapter
 * that will ever exist, so each addition has to earn it; tool calling did, because every
 * vendor supports it and the tools have to be described on the call itself.
 */
interface AIProviderInterface
{
    /**
     * Send a conversation and get the model's answer.
     *
     * The conversation is passed in full on every call: the provider holds no state between
     * calls, which is what makes one instance safe to share and trivial to reason about.
     * Remembering the conversation is the agent's job, not the provider's.
     *
     * Tools are passed per call rather than configured once, because the provider holds no
     * state and because which tools are on offer is the agent's decision, not the model's.
     * An answer may come back asking for some of them to be run.
     *
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @throws ProviderException when the API refuses the request or answers with something unusable
     */
    public function chat(array $messages, array $tools = []): AssistantMessage;
}
