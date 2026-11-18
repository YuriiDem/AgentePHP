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

**Approachable by design.** You should be able to get an agent running without first
learning a new vocabulary. There is no container to configure, no YAML, no code
generation step, and no magic: an agent is a PHP class, a tool is a PHP method, and the
call that reaches the model is one you can follow in a debugger.

**Explains the machinery.** Agentic systems are not complicated once you can see them,
but most frameworks hide the moving parts. Here the agentic loop, the tool-call
round trip, and the retrieval pipeline are small, named, and documented — so reading the
source is a reasonable way to learn how AI agents actually work. Each milestone in
[docs/ROADMAP.md](docs/ROADMAP.md) carries a design note explaining why its structure
was chosen.

## Status

Early development. The component roadmap is in [docs/ROADMAP.md](docs/ROADMAP.md).

- [x] Project scaffold
- [x] Messages and conversation history
- [x] HTTP transport
- [x] LLM providers (OpenAI, Anthropic, Ollama)
- [x] Agent core
- [ ] Tools and function calling
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

MIT
