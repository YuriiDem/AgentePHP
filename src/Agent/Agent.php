<?php

declare(strict_types=1);

namespace AgentePHP\Agent;

use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\InMemoryChatHistory;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\AgentException;
use AgentePHP\Exceptions\MaxToolIterationsReached;
use AgentePHP\Exceptions\ToolException;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Tools\Tool;
use AgentePHP\Tools\ToolInterface;

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

    /** @var list<ToolInterface>|null */
    private ?array $toolsOverride = null;

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

        $provider = $this->resolveProvider();
        $tools = $this->resolveTools();

        $conversation = $this->conversationWith($question);

        // Kept apart from the history until the exchange finishes: a turn half-written into
        // a stored conversation - a tool call with no result - is one no vendor will accept
        // on the next call.
        $newTurns = [$question];
        $calledTools = [];

        for ($iteration = 1; ; $iteration++) {
            $answer = $provider->chat($conversation, $tools);

            $conversation[] = $answer;
            $newTurns[] = $answer;

            if (!$answer->hasToolCalls()) {
                break;
            }

            foreach ($answer->toolCalls() as $call) {
                $calledTools[] = $call->name;

                $result = new ToolMessage(
                    content: $this->runToolCall($tools, $call),
                    toolCallId: $call->id,
                    toolName: $call->name,
                );

                $conversation[] = $result;
                $newTurns[] = $result;
            }

            if ($iteration >= $this->maxToolIterations()) {
                throw MaxToolIterationsReached::after($iteration, $calledTools);
            }
        }

        $this->history()->add(...$newTurns);

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

    /**
     * @param list<ToolInterface> $tools
     */
    public function withTools(array $tools): static
    {
        $copy = clone $this;
        $copy->toolsOverride = $tools;

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
     * What this agent is allowed to do, as tools or toolkits.
     *
     * @return list<ToolInterface>
     */
    protected function tools(): array
    {
        return [];
    }

    /**
     * How many times in one exchange the model may ask for tools before the request fails.
     *
     * Low on purpose. A model that has not answered after five rounds of tool calls is
     * usually stuck rather than making progress, and every round is a paid API call.
     */
    protected function maxToolIterations(): int
    {
        return 5;
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

    /**
     * Flattened once per exchange: the model is told about the same tools on every call,
     * and a toolkit should not be reflected over again for each one.
     *
     * @return list<Tool>
     */
    private function resolveTools(): array
    {
        $offers = $this->toolsOverride ?? $this->tools();
        $tools = [];

        foreach ($offers as $offer) {
            foreach ($offer->tools() as $tool) {
                if (isset($tools[$tool->name])) {
                    throw ToolException::nameIsNotUnique($tool->name, array_keys($tools));
                }

                $tools[$tool->name] = $tool;
            }
        }

        return array_values($tools);
    }

    /**
     * Run what the model asked for, and turn anything it got wrong into an answer it can
     * read.
     *
     * A model sending a bad argument, or naming a tool that does not exist, is a thing
     * models do - and the next turn is its chance to correct itself, which is the whole
     * value of the loop. Anything else a tool throws is a bug in the application and is
     * left to reach the developer.
     *
     * @param list<Tool> $tools
     */
    private function runToolCall(array $tools, ToolCall $call): string
    {
        foreach ($tools as $tool) {
            if ($tool->name !== $call->name) {
                continue;
            }

            try {
                return $tool->call($call->arguments);
            } catch (ToolException $e) {
                return $e->getMessage();
            }
        }

        return ToolException::describeUnknownTool(
            $call->name,
            array_map(static fn (Tool $tool): string => $tool->name, $tools),
        );
    }
}
