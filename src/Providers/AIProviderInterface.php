<?php

declare(strict_types=1);

namespace AgentePHP\Providers;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Exceptions\ProviderException;

/**
 * One contract for every model vendor.
 *
 * This is the interface application code depends on, and the reason changing the model is
 * one line in one place: a provider is an interchangeable strategy, and nothing about the
 * caller changes when it is swapped.
 *
 * Deliberately one method. Streaming, tool calls and structured output are later milestones,
 * and each will have to justify widening this surface - everything added here has to be
 * implemented by every vendor adapter that will ever exist.
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
     * @param list<Message> $messages
     *
     * @throws ProviderException when the API refuses the request or answers with something unusable
     */
    public function chat(array $messages): AssistantMessage;
}
