<?php

/**
 * An agent that acts, rather than only answering.
 *
 * Run it:
 *   export OPENAI_API_KEY=sk-...
 *   php examples/04-tools.php
 *
 * Two things are worth watching here. The arithmetic is done by PHP, not guessed by the
 * model - which is the whole reason to hand a model a calculator. And the second question
 * uses a tool of this file's own: a tool is a method, and nothing had to be registered
 * anywhere for the model to be able to call it.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Exceptions\AgenteException;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tools\Attributes\AsTool;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Toolkit;
use AgentePHP\Tools\Toolkits\Calculator;

/** A toolkit of one, to show that a tool is nothing more than a method with an attribute. */
final class ClockToolkit extends Toolkit
{
    #[AsTool('The current date and time in a given IANA timezone.')]
    public function currentTime(
        #[Param('An IANA timezone name, such as "Europe/Kyiv".')]
        string $timezone,
    ): string {
        // A tool answering a sentence rather than a bare value gives the model context it
        // would otherwise have to assume.
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return sprintf('It is %s in %s.', $now->format('H:i on j F Y'), $timezone);
    }
}

try {
    $agent = Agent::make()
        ->withProvider(OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini'))
        ->withInstructions('Use your tools rather than working things out yourself. Be brief.')
        ->withTools([new Calculator(), new ClockToolkit()]);

    $questions = [
        'What is 73912 multiplied by 4471?',
        'What time is it in Europe/Kyiv right now?',
    ];

    foreach ($questions as $question) {
        printf("you  > %s\n", $question);
        printf("model> %s\n\n", $agent->chat($question)->content());
    }

    // The history holds the tool turns too, so the whole exchange can be replayed - which
    // is also what a stored conversation needs for the next call to be valid.
    echo "--- what the agent remembers ---\n";

    foreach ($agent->history()->messages() as $message) {
        printf(
            "%-9s %s\n",
            $message->role()->value,
            $message instanceof ToolMessage
                ? sprintf('[%s] %s', $message->toolName, $message->content())
                : summarise($message),
        );
    }
} catch (AgenteException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

/**
 * An assistant turn that only asks for tools has no words of its own, and printing an
 * empty line for it would be more confusing than saying so.
 */
function summarise(Message $message): string
{
    if ($message->content() !== '') {
        return $message->content();
    }

    return '(asked for a tool)';
}
