<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Support;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Tools\Tool;
use RuntimeException;
use Throwable;

/**
 * A provider that answers from a script and records every conversation it was given.
 *
 * It is what makes an agent testable: the agent's job is assembling a conversation,
 * running whatever the model asks for and remembering the result, and this double lets a
 * test read the assembled conversation directly instead of inferring it from an HTTP body.
 */
final class FakeProvider implements AIProviderInterface
{
    /** @var list<AssistantMessage> */
    private array $answers;

    /** @var list<list<Message>> */
    private array $conversations = [];

    /** @var list<list<Tool>> */
    private array $offeredTools = [];

    private ?Throwable $failure = null;

    /** Set by repeating(): answered on every call, so a loop can be driven off the end. */
    private ?AssistantMessage $always = null;

    public function __construct(string ...$answers)
    {
        $this->answers = array_values(
            array_map(static fn (string $text): AssistantMessage => self::text($text), $answers),
        );
    }

    /** One scripted answer per expected call. */
    public static function sequence(AssistantMessage ...$answers): self
    {
        $provider = new self();
        $provider->answers = array_values($answers);

        return $provider;
    }

    /** The same answer however many times it is asked - for testing the iteration cap. */
    public static function repeating(AssistantMessage $answer): self
    {
        $provider = new self();
        $provider->always = $answer;

        return $provider;
    }

    public static function text(string $content): AssistantMessage
    {
        return new AssistantMessage($content, new Usage(prompt: 10, completion: 2));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function toolCall(string $name, array $arguments = [], string $id = 'call_1'): AssistantMessage
    {
        return new AssistantMessage('', new Usage(prompt: 10, completion: 2), [
            new ToolCall(id: $id, name: $name, arguments: $arguments),
        ]);
    }

    /**
     * Several tools asked for in one turn, which is where the ids start to matter.
     *
     * @param list<array{name: string, arguments?: array<string, mixed>}> $calls
     */
    public static function toolCalls(array $calls): AssistantMessage
    {
        $toolCalls = [];

        foreach ($calls as $index => $call) {
            $toolCalls[] = new ToolCall(
                id: sprintf('call_%d', $index + 1),
                name: $call['name'],
                arguments: $call['arguments'] ?? [],
            );
        }

        return new AssistantMessage('', new Usage(prompt: 10, completion: 2), $toolCalls);
    }

    /** A provider whose next call fails, for testing what an agent does with a refusal. */
    public static function failingWith(Throwable $failure): self
    {
        $provider = new self();
        $provider->failure = $failure;

        return $provider;
    }

    public function chat(array $messages, array $tools = []): AssistantMessage
    {
        $this->conversations[] = $messages;
        $this->offeredTools[] = $tools;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->always !== null) {
            return $this->always;
        }

        $answer = array_shift($this->answers);

        if ($answer === null) {
            throw new RuntimeException(sprintf(
                'FakeProvider ran out of scripted answers on call %d. '
                . 'Pass one answer per expected call.',
                count($this->conversations),
            ));
        }

        return $answer;
    }

    /**
     * The conversation the agent assembled for its most recent call - the thing an agent
     * test is really about.
     *
     * @return list<Message>
     */
    public function lastConversation(): array
    {
        $last = end($this->conversations);

        if ($last === false) {
            throw new RuntimeException('FakeProvider was never asked anything.');
        }

        return $last;
    }

    /** @return list<string> the roles of the most recent conversation, in order */
    public function lastRoles(): array
    {
        return array_map(
            static fn (Message $message): string => $message->role()->value,
            $this->lastConversation(),
        );
    }

    /** @return list<Tool> the tools offered on the most recent call */
    public function lastTools(): array
    {
        $last = end($this->offeredTools);

        return $last === false ? [] : $last;
    }

    public function callCount(): int
    {
        return count($this->conversations);
    }
}
