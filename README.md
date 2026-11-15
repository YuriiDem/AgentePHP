# AgentePHP

A lightweight agentic framework for PHP.

Build LLM-driven agents that hold a conversation, call your code as tools, return typed
objects instead of prose, answer over your own documents, and run as multi-step
workflows with persisted state.

AgentePHP has one runtime dependency (`psr/event-dispatcher`). The HTTP transport, the
JSON Schema generation, and the vector search are part of the library rather than a
dependency tree, so the whole stack stays inspectable and installs anywhere PHP 8.2
runs.

## Status

Early development. The component roadmap is in [docs/ROADMAP.md](docs/ROADMAP.md).

- [x] Project scaffold
- [ ] Messages and conversation history
- [ ] HTTP transport
- [ ] LLM providers (OpenAI, Anthropic, Ollama)
- [ ] Agent core
- [ ] Tools and function calling
- [ ] Events and observability
- [ ] Structured output
- [ ] Retrieval (RAG)
- [ ] Workflows and multi-agent orchestration
- [ ] CLI

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

MIT
