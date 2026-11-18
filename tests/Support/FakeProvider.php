<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Support;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Providers\AIProviderInterface;
use RuntimeException;
use Throwable;

/**
 * A provider that answers from a script and records every conversation it was given.
 *
 * It is what makes an agent testable: the agent's job is assembling a conversation and
 * remembering the result, and this double lets a test read the assembled conversation
 * directly instead of inferring it from an HTTP body.
 */
final class FakeProvider implements AIProviderInterface
{
    /** @var list<string> */
    private array $answers;

    /** @var list<list<Message>> */
    private array $conversations = [];

    private ?Throwable $failure = null;

    public function __construct(string ...$answers)
    {
        $this->answers = array_values($answers);
    }

    /** A provider whose next call fails, for testing what an agent does with a refusal. */
    public static function failingWith(Throwable $failure): self
    {
        $provider = new self();
        $provider->failure = $failure;

        return $provider;
    }

    public function chat(array $messages): AssistantMessage
    {
        $this->conversations[] = $messages;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $answer = array_shift($this->answers);

        if ($answer === null) {
            throw new RuntimeException(sprintf(
                'FakeProvider ran out of scripted answers on call %d. '
                . 'Pass one answer per expected call.',
                count($this->conversations),
            ));
        }

        return new AssistantMessage($answer, new Usage(prompt: 10, completion: 2));
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

    public function callCount(): int
    {
        return count($this->conversations);
    }
}
