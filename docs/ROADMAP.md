# Roadmap

The library is built in order, bottom-up: each component depends only on the ones below
it. A milestone is closed when its code is written, its tests are green, and a runnable
example exists in `examples/`.

Keeping each layer small and self-contained is deliberate. A reader should be able to
start at the bottom and understand one component at a time, rather than needing the
whole framework in their head before the first agent runs.

Design notes record why a given structure was chosen, so the decision can be revisited
later instead of being re-litigated from scratch.

---

## 1. Project scaffold (done)

`composer.json`, PSR-4 autoloading, PHPUnit, PHPStan level 8, php-cs-fixer, the
directory layout, `AGENTS.md`.

---

## 2. Messages and conversation history

How a conversation with a model is represented in memory.

**Scope**
- `Chat\Enums\MessageRole` — `User`, `Assistant`, `System`, `Tool`
- `Chat\Messages\Message` — the base message type
- `UserMessage`, `AssistantMessage`, `SystemMessage`
- `Chat\Messages\Usage` — token accounting
- `Chat\History\ChatHistoryInterface`
- `Chat\History\InMemoryChatHistory`
- `Chat\History\FileChatHistory`

**Design notes**
- Roles are an enum rather than string constants: an invalid role becomes impossible to
  construct instead of failing later inside a provider request.
- Messages are immutable. A message already appended to history must never change,
  because providers and observability listeners both read the same instances.
- `Usage` is compared by value, not identity — it carries no state of its own beyond the
  counts.
- History is an interface because the storage backend is a deployment choice: in-process
  for a single request, file-backed for a CLI session, and later a database. Every
  implementation must be substitutable without the agent noticing.

---

## 3. HTTP transport

**Scope**
- `Http\HttpClientInterface`
- `Http\Request`, `Http\Response` — immutable
- `Http\CurlHttpClient` — the ext-curl implementation
- `tests\Support\FakeHttpClient` — canned responses for tests
- `Exceptions\HttpException`

**Design notes**
- The transport is injected, never constructed inside a provider. Without that, no
  provider could be tested without a live API key and a network round trip, and the
  suite would be slow and flaky.
- Depending on `HttpClientInterface` rather than on curl also leaves room for a PSR-18
  adapter later without touching provider code.
- `FakeHttpClient` lives in `tests/`, not `src/`: it is test infrastructure, not part of
  the public API.

---

## 4. LLM providers

Hides the differences between vendor APIs behind one contract.

**Scope**
- `Providers\AIProviderInterface` — `chat(array $messages): AssistantMessage`
- `Providers\AbstractProvider` — request assembly, error handling, retries
- `Providers\OpenAI\OpenAIProvider` plus its message mapper
- `Providers\Anthropic\AnthropicProvider` plus its own mapper
- `Providers\Ollama\OllamaProvider` — local models, no API key
- `tests\Support\FakeProvider`

**Design notes**
- A provider is interchangeable at the call site: switching models must not ripple
  through application code.
- Each vendor gets its own mapper. The formats genuinely differ — system prompts,
  content blocks, and tool-call shapes are not the same across vendors — so a single
  parameterised mapper would collapse into a pile of conditionals.
- `AbstractProvider` owns the parts that are identical everywhere and leaves the
  vendor-specific steps to subclasses. Adding a provider must require no edit to an
  existing one.

---

## 5. Agent core

**Scope**
- `Agent\AgentInterface`
- `Agent\Agent` — `instructions()`, `provider()`, `chatHistory()`, `chat()`
- `examples/01-simple-chat.php`

**Design notes**
- Configuration happens by overriding methods rather than through a wide constructor: an
  agent is a class in the host application, so its dependencies are declarations, not
  call-site arguments.
- The agent is the only type an application needs to touch for a simple use case; the
  provider, the history, and the mapping stay behind it.

---

## 6. Tools and function calling

**Scope**
- `Tools\ToolInterface`, `Tools\Tool`
- `Tools\ToolProperty`
- `Tools\ToolCall`, `Tools\ToolCallResult`
- `Tools\Toolkit` — a set of tools addressed as one
- JSON Schema generation for tool signatures
- the call loop: the model requests a call, the library executes it, feeds the result
  back, and repeats
- `Tools\Toolkits\Calculator`, `Tools\Toolkits\FileSystem`

**Design notes**
- A tool is an object, not a closure in a registry array: it carries its own name,
  description, and schema, which is what the provider needs to serialise it.
- A toolkit satisfies the same contract as a single tool, so a toolkit of toolkits costs
  no extra handling in the agent.
- Schemas are derived from method signatures via Reflection rather than hand-written
  JSON, so a signature and its schema cannot drift apart.
- The call loop needs a hard iteration cap. A model that keeps requesting calls must
  terminate the request, not the process.

---

## 7. Events and observability

**Scope**
- PSR-14 `EventDispatcherInterface` integration
- `Observability\Events\*`: `ChatStart`, `ChatStop`, `ToolCalling`, `ToolCalled`,
  `ProviderError`
- `Observability\LogListener`, `Observability\ConsoleListener`

**Design notes**
- Logging inline in the agent would make the agent responsible for both running the
  conversation and reporting on it, and would hard-code one output format.
- PSR-14 rather than a bespoke dispatcher: host applications already have one, and
  reusing the standard interface means the library plugs into it instead of competing
  with it.

---

## 8. Structured output

**Scope**
- `StructuredOutput\SchemaGenerator` — PHP class to JSON Schema via Reflection
- `#[Description]`, `#[ArrayOf]` attributes
- `StructuredOutput\Deserializer` — JSON to typed object
- `StructuredOutput\Validator`
- retry when the model returns invalid JSON

**Design notes**
- The PHP class is the single source of truth: the schema sent to the model and the type
  received back are generated from the same declaration.
- Validation runs before deserialization, so a malformed response fails at the boundary
  with a clear error rather than half-populating an object.
- Nested object support makes schema generation recursive; depth needs a bound.

---

## 9. Retrieval (RAG)

**Scope**
- `RAG\Embeddings\EmbeddingsProviderInterface` plus an OpenAI implementation
- `RAG\Document` — chunk, vector, metadata
- `RAG\Splitter\TextSplitter` — chunking with overlap
- `RAG\DataLoader\*` — txt, md
- `RAG\VectorStore\VectorStoreInterface`, `InMemoryVectorStore`, `FileVectorStore`
- cosine similarity
- `RAG\Rag` — an agent with context retrieval

**Design notes**
- Loaders and splitters are generators: a corpus must be indexable without holding it in
  memory.
- The vector store is a repository interface so that the in-memory implementation can
  back tests and small deployments while a real vector database is added later behind the
  same contract.
- Similarity is computed in PHP for the built-in stores. Exact, testable, and fast enough
  at the scale those stores are for; large corpora belong in an external store.

---

## 10. Workflows and multi-agent orchestration

**Scope**
- `Workflow\WorkflowState` — shared state
- `Workflow\Node` — takes state, returns an event
- `Workflow\Edge` — transitions, including conditional ones
- `Workflow\Workflow` — the executor
- `Workflow\Persistence\*` — file and SQLite
- interruption and human-in-the-loop: halt, await input, resume

**Design notes**
- Nodes hold no references to each other; the workflow owns the graph. That is what makes
  a node reusable in more than one flow and testable on its own.
- State is serialisable by construction, because resuming after an interruption means
  rebuilding it in a different process.
- Modelling the flow as a state machine, rather than as nested calls, is what makes
  pausing for human input possible at all.

---

## 11. CLI

**Scope**
- `bin/agente`
- generators: `make:agent`, `make:tool`
- Packagist release

---

## 12. Documentation

**Scope**
- getting started: a working agent in under twenty lines
- one guide per component, each answering what it does, when to reach for it, and what
  it costs
- a concepts page explaining the agentic loop, tool calling, and retrieval in plain
  terms, independent of this library's API
- a worked example built up across milestones rather than a set of disconnected
  snippets

**Design notes**
- Documentation is a deliverable, not an afterthought: a framework whose selling point is
  being understandable is not finished while its docs are a class reference.
- Concepts are documented separately from the API so the explanation stays useful to
  someone evaluating whether they need an agent at all.
