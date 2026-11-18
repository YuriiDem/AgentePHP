<?php

declare(strict_types=1);

namespace AgentePHP\Agent;

use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\InMemoryChatHistory;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\AgentException;
use AgentePHP\Providers\AIProviderInterface;

/**
 * An agent: a provider, a standing instruction, and a memory, behind one call.
 *
 * There are two ways to configure one and they are the same object. An application
 * declares its defaults by subclassing:
 *
 *     final class SupportAgent extends Agent
 *     {
 *         protected function provider(): ?AIProviderInterface
 *         {
 *             return OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
 *         }
 *     }
 *
 * while a script, or a test of that subclass, overrides one of them for a single instance:
 *
 *     Agent::make()->withProvider($provider)->withInstructions('Be brief.');
 *
 * The protected methods are the declarations; withX() wins over them where it is used.
 * That is what lets a production agent class be tested without touching its source.
 */
class Agent implements AgentInterface
{
    private ?AIProviderInterface $providerOverride = null;

    private ?string $instructionsOverride = null;

    /** Set only by withHistory(): a history the caller owns and may share. */
    private ?ChatHistoryInterface $providedHistory = null;

    /** Resolved once per agent, because a new one on every call would remember nothing. */
    private ?ChatHistoryInterface $resolvedHistory = null;

    /**
     * A bare agent, to be configured with the withX() methods.
     *
     * Returns an Agent and not static() on purpose: an agent class of your own is built
     * with `new`, because it may well take collaborators - a repository, a mailer - that
     * this factory knows nothing about and could not pass.
     */
    final public static function make(): self
    {
        return new self();
    }

    /**
     * The model this agent talks to.
     *
     * Final because the flow it fixes is the agent's whole contract: the conversation is
     * assembled, sent, and only then remembered. Later milestones extend an agent through
     * the hooks below, not by rewriting this.
     */
    final public function chat(string|Message $message): AssistantMessage
    {
        $question = $message instanceof Message ? $message : new UserMessage($message);

        $answer = $this->resolveProvider()->chat($this->conversationWith($question));

        // Written only now: a refused call must leave the conversation exactly as it was,
        // or retrying would send the same question twice.
        $this->history()->add($question, $answer);

        return $answer;
    }

    final public function history(): ChatHistoryInterface
    {
        return $this->resolvedHistory ??= $this->providedHistory ?? $this->chatHistory() ?? new InMemoryChatHistory();
    }

    public function withProvider(AIProviderInterface $provider): static
    {
        $copy = clone $this;
        $copy->providerOverride = $provider;

        return $copy;
    }

    public function withInstructions(string $instructions): static
    {
        $copy = clone $this;
        $copy->instructionsOverride = $instructions;

        return $copy;
    }

    public function withHistory(ChatHistoryInterface $history): static
    {
        $copy = clone $this;
        $copy->providedHistory = $history;
        $copy->resolvedHistory = $history;

        return $copy;
    }

    /**
     * A reconfigured agent is a different agent, so it starts its own conversation: moving
     * a half-finished one into an agent with different instructions would be surprising.
     * A history the caller handed over explicitly is theirs, and stays shared.
     */
    public function __clone(): void
    {
        if ($this->providedHistory === null) {
            $this->resolvedHistory = null;
        }
    }

    /**
     * The model to use. Returning null means "not declared here", which is an error only
     * if nothing else supplies one.
     */
    protected function provider(): ?AIProviderInterface
    {
        return null;
    }

    /**
     * What the agent is told before every conversation.
     *
     * Deliberately takes no arguments and returns a plain string: it describes the agent,
     * not the request. Anything that varies per call - a customer name, today's date -
     * belongs in the message being sent, so that the instruction stays the same thing on
     * every call and can be read without running the program.
     */
    protected function instructions(): string
    {
        return '';
    }

    /** Where this agent remembers. Null means the default: in memory, for this process. */
    protected function chatHistory(): ?ChatHistoryInterface
    {
        return null;
    }

    /**
     * Instructions first, then everything remembered, then the new question.
     *
     * The instruction is prepended on every call and never stored, so that rewording it
     * does not invalidate a conversation already on disk.
     *
     * @return list<Message>
     */
    private function conversationWith(Message $question): array
    {
        $instructions = $this->instructionsOverride ?? $this->instructions();

        $conversation = $instructions === '' ? [] : [new SystemMessage($instructions)];

        foreach ($this->history()->messages() as $remembered) {
            $conversation[] = $remembered;
        }

        $conversation[] = $question;

        return $conversation;
    }

    private function resolveProvider(): AIProviderInterface
    {
        $provider = $this->providerOverride ?? $this->provider();

        if ($provider === null) {
            throw AgentException::noProvider(static::class);
        }

        return $provider;
    }
}
