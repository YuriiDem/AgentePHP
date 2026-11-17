<?php

/**
 * A conversation that remembers itself.
 *
 * Run it:
 *   export OPENAI_API_KEY=sk-...
 *   php examples/01-conversation.php
 *
 * There is no Agent class yet, so this example does by hand what the agent will do for
 * you: hold the conversation, prepend the standing instruction, and send the whole thing
 * on every call. It is worth reading once in this form - everything the agent adds later
 * is a convenience over exactly these lines.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AgentePHP\Chat\History\FileChatHistory;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\AgenteException;
use AgentePHP\Providers\OpenAI\OpenAIProvider;

$turns = [
    'My name is Yurii and I am learning object-oriented PHP.',
    'What is my name, and what am I learning?',
];

// Everything is inside the try, including construction: a missing API key is the most
// likely thing to go wrong here, and this library's failures are meant to be read as a
// sentence rather than as a stack trace.
try {
    $provider = OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');

    // Swap the line above for this one and nothing below changes:
    // $provider = AnthropicProvider::fromEnvironment(model: 'claude-sonnet-4-5');

    // Stored under examples/var/, so you can open the file and read the conversation.
    $history = new FileChatHistory(__DIR__ . '/var/conversations', threadId: 'example');
    $history->clear();

    $instruction = new SystemMessage('You are concise. Answer in one short sentence.');

    foreach ($turns as $question) {
        $history->add(new UserMessage($question));

        // The instruction is not stored in history - it is supplied on every call, so that
        // changing it later does not rewrite conversations already on disk.
        $answer = $provider->chat([$instruction, ...$history->messages()]);

        $history->add($answer);

        printf("you  > %s\n", $question);
        printf("model> %s\n", $answer->content());
        printf("       [%d tokens]\n\n", $answer->usage()->total());
    }

    printf("The second answer knew the first because %d messages were sent with it.\n", count($history->messages()));
    printf("Stored at: %s\n", __DIR__ . '/var/conversations/example.json');
} catch (AgenteException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}
