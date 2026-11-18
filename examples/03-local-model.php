<?php

/**
 * The same agent, against a model running on your own machine.
 *
 * This is the one example that needs no account, no API key and no money:
 *
 *   ollama pull llama3.2
 *   php examples/03-local-model.php
 *
 * Compare it with 02-agent.php. One line differs - the provider - and that is the whole
 * claim of the provider layer. Ollama needs no credentials, answers at a URL of its own
 * and names its token counts differently from the hosted vendors; none of that reaches
 * the agent, let alone this file.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AgentePHP\Agent\Agent;
use AgentePHP\Exceptions\AgenteException;
use AgentePHP\Providers\Ollama\OllamaProvider;

$model = getenv('AGENTE_OLLAMA_MODEL') ?: 'llama3.2';

try {
    $agent = Agent::make()
        ->withProvider(OllamaProvider::fromEnvironment(model: $model))
        ->withInstructions('You are concise. Answer in one short sentence.');

    foreach (['My name is Yurii.', 'What is my name?'] as $question) {
        $answer = $agent->chat($question);

        printf("you  > %s\n", $question);
        printf("%-5s> %s\n", $model, $answer->content());
        printf("       [%d tokens]\n\n", $answer->usage()->total());
    }
} catch (AgenteException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    fwrite(STDERR, sprintf(
        "\nIs Ollama running, and is \"%s\" pulled? Check with:\n  ollama list\n  ollama pull %s\n",
        $model,
        $model,
    ));

    exit(1);
}
