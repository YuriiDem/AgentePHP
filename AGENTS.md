# AGENTS.md — contributor and agent conventions

This file is read by AI agents (Claude Code and others) and by human contributors
before working on the project.

## The project rule

> **New business logic is only considered done once a PHPUnit test for it has been
> written and passes.**

Concretely:

1. Write the test first, in `tests/Unit/...` (or `tests/Integration/...`).
2. Then write the implementation in `src/...`.
3. Run `composer test` and show the **actual PHPUnit output**.
4. Only after a green run is the task considered complete.

Not allowed:

- claiming "done" without running the tests;
- committing new logic without a test;
- bending the test to match a bug instead of fixing the code;
- deleting a failing test, or silencing it with `@skip` / `markTestSkipped()`, just to
  turn the run green.

Exempt from the rule: documentation edits, renames that do not change behaviour, and
config files. Anything containing an `if`, a loop, or a computation needs a test.

## Language

**The entire project is in English — without exception.** Code, identifiers, comments,
commit messages, documentation, test names, CLI output, and exception messages.

## Design posture

- Every component is written from scratch and kept as small as its scope requires. No
  third-party library is pulled in to solve a problem this library is meant to solve
  itself.
- Prefer the version that is easier to read over the one that is shorter or cleverer.
- No abstraction is introduced before a second concrete case for it exists. One
  implementation behind an interface is a sign the interface is premature — unless the
  second implementation is the test double that makes the first one testable.
- Public API changes are deliberate: the surface is small, and every exported type
  earns its place.

## Technical conventions

- PHP **8.2+**. Use: `enum`, `readonly`, promoted constructor properties, named
  arguments, first-class callable syntax, `never` / `void` return types.
- **Strict typing is mandatory**: `declare(strict_types=1);` in every file, and types on
  every parameter, property, and return value.
- PSR-4 autoloading, namespace `AgentePHP\` maps to `src/`.
- PSR-12 code style, enforced by php-cs-fixer.
- PHPStan **level 8** must stay green.
- Runtime dependencies are kept to a minimum — currently only `psr/event-dispatcher`.
  Adding one is a design decision, not a convenience.
- No real network calls in `tests/Unit` — that is what `FakeHttpClient` is for. Tests
  that need the network or an API key live in `tests/Integration` and call
  `markTestSkipped()` when the key is absent.
- Commit messages explain why the change was made, not what the diff shows.
- Stage files by name. Never `git add -A`, which has already pulled an unrelated crash
  dump into a commit once.

## Commands

```bash
composer test          # full test suite
composer test:unit     # unit tests only (no network)
composer analyse       # PHPStan level 8
composer style         # auto-format
composer check         # style + analysis + tests (full verification)
```

Run `composer check` before declaring a task complete.

## Layout

```
src/
  Chat/             messages, roles, conversation history
  Http/             HTTP transport and its test double
  Providers/        LLM adapters (OpenAI, Anthropic, Ollama)
  Agent/            the core agentic loop
  Tools/            agent tools (function calling)
  Observability/    events and logging
  StructuredOutput/ JSON Schema from PHP classes, deserialization
  RAG/              embeddings, vector store, retrieval
  Workflow/         graph orchestration, state, multi-agent
  Exceptions/       domain exceptions
tests/
  Unit/             fast, no network
  Integration/      real APIs, skipped without keys
  Support/          test doubles and helpers
docs/               roadmap and design notes
examples/           runnable usage examples
```
