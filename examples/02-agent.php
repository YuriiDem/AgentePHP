<?php

/**
 * The same conversation as 01, now with the agent doing the work.
 *
 * Run it:
 *   export OPENAI_API_KEY=sk-...
 *   php examples/02-agent.php
 *
 * Compare it with 01-conversation.php: the history, the system message and the assembling
 * of the conversation have all moved behind chat(). Nothing was added that you could not
 * have written yourself - that is the point.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\FileChatHistory;
use AgentePHP\Exceptions\AgenteException;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\OpenAI\OpenAIProvider;

/**
 * The form an application uses: an agent is a class, and what it needs are declarations
 * rather than arguments every call site has to remember.
 */
final class TutorAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // Swap this line for AnthropicProvider and nothing else in the class changes.
        return OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
    }

    protected function instructions(): string
    {
        return <<<'TEXT'
            You are teaching object-oriented PHP to a developer who knows the syntax
            but has not studied design patterns. Answer in one short sentence.
            TEXT;
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new FileChatHistory(__DIR__ . '/var/conversations', threadId: 'tutor');
    }
}

try {
    $agent = new TutorAgent();
    $agent->history()->clear();

    foreach (['My name is Yurii.', 'What is my name, and what are you helping me with?'] as $question) {
        $answer = $agent->chat($question);

        printf("you  > %s\n", $question);
        printf("model> %s\n", $answer->content());
        printf("       [%d tokens]\n\n", $answer->usage()->total());
    }

    printf("Remembered: %d messages (the instruction is not among them).\n", count($agent->history()->messages()));

    // The scripting form of the same object, for a one-off question with no class to write.
    $throwaway = Agent::make()
        ->withProvider(OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini'))
        ->withInstructions('Answer with a single word.');

    printf("one-off> %s\n", $throwaway->chat('Capital of France?')->content());
} catch (AgenteException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}
