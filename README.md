# AgentePHP

A lightweight agentic framework for PHP, built to be understood.

Build LLM-driven agents that hold a conversation, call your code as tools, return typed
objects instead of prose, answer over your own documents, and run as multi-step
workflows with persisted state.

## Why AgentePHP

**Small enough to read.** One runtime dependency (`psr/event-dispatcher`). The HTTP
transport, the JSON Schema generation, and the vector search are part of the library
rather than a dependency tree, so the whole stack stays inspectable and installs
anywhere PHP 8.2 runs.

## Quick start

Not on Packagist yet, so install straight from this repository:

```bash
composer config repositories.agente-php vcs https://github.com/YuriiDem/AgentePHP
composer require ydem/agente-php:dev-main
export OPENAI_API_KEY=sk-...
```

An agent is a class. It declares which model it talks to, what it is told, and what it is
allowed to do:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use AgentePHP\Agent\Agent;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tools\Toolkits\Calculator;

final class MathTutor extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
    }

    protected function instructions(): string
    {
        return 'You are a patient maths tutor. Use your tools for arithmetic.';
    }

    protected function tools(): array
    {
        return [new Calculator()];
    }
}

$tutor = new MathTutor();

echo $tutor->chat('What is 73912 multiplied by 4471?')->content();
echo $tutor->chat('Now halve it.')->content(); // it remembers the conversation
```

The multiplication is done by PHP, not guessed by the model: it asks for the calculator,
the library runs it and hands the result back, and the model answers with it.

A tool of your own is a method with an attribute — no registration anywhere:

```php
use AgentePHP\Tools\Attributes\AsTool;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Toolkit;

final class WeatherToolkit extends Toolkit
{
    #[AsTool('Current temperature in a city, in degrees Celsius.')]
    public function temperature(
        #[Param('City name, e.g. "Kyiv".')]
        string $city,
    ): string {
        return "It is 4°C in {$city}."; // call your real weather API here
    }
}
```

Switching vendors is one line in `provider()` — `AnthropicProvider::fromEnvironment(...)`,
or `OllamaProvider::fromEnvironment(model: 'llama3.2')` for a local model with no API key
at all. More in [`examples/`](examples).

## Status

Early development. The component roadmap is in [docs/ROADMAP.md](docs/ROADMAP.md).

- [x] Project scaffold
- [x] Messages and conversation history
- [x] HTTP transport
- [x] LLM providers (OpenAI, Anthropic, Ollama)
- [x] Agent core
- [x] Tools and function calling
- [ ] Events and observability
- [ ] Structured output
- [ ] Retrieval (RAG)
- [ ] Workflows and multi-agent orchestration
- [ ] CLI
- [ ] Documentation and guides

The public API is unstable until 1.0.

## Requirements

- PHP 8.2+
- extensions: `curl`, `json`, `mbstring`
- Composer

## Development setup

```bash
composer install
cp .env.example .env   # then fill in your API keys
```

## Commands

```bash
composer test          # full test suite
composer test:unit     # unit tests only (no network)
composer analyse       # PHPStan level 8
composer style         # auto-format the code
composer check         # full verification: style + analysis + tests
```

## Contributing

New business logic is only considered done once a PHPUnit test for it has been written
and passes. See [AGENTS.md](AGENTS.md) for the full set of conventions.

## License

MIT — see [LICENSE](LICENSE).
