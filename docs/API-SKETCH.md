# API sketch

This is the call site we are building toward, written before the implementation so the
shape can be argued with while it is still free to change.

**What runs today:** messages, conversation history, the HTTP transport, and the OpenAI
and Anthropic providers - so the provider construction in sections 1 and 2, both history
constructors in section 6, and the provider test in section 11 work as written. See
`examples/01-conversation.php` for a working conversation built out of those parts.
Everything that goes through `Agent` or `$agent->chat()` does not exist yet.

Two things the implementation settled, recorded here rather than left in the open
questions below: `fromEnvironment()` takes the model as a required argument (no default to
go stale), and `role()` is a method like `content()`, not a property.

The rule this file lives by: when a milestone in [ROADMAP.md](ROADMAP.md) lands, the
snippets for it must either run exactly as written here, or be updated with a note
saying what the implementation taught us. A sketch that silently drifts from the library
is worse than no sketch.

Read it top to bottom: each section adds one capability, and nothing below changes the
code above it.

Snippets that are whole files show their full header; the rest assume it. One line in
that header is worth explaining before it gets copied seven times: `declare(strict_types=1)`
is mandatory in **this library's** sources and is *not* something AgentePHP asks of your
code. The declaration is per-file and governs only the calls written in that file, so it
never reaches across into the library or out of it. Section 1 keeps it because that
snippet is a complete file; every snippet after it omits it, to keep what the library
requires separate from how the library is written.

---

## 1. The first agent

Installation, then a complete working script.

```bash
composer require ydem/agente-php
```

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use AgentePHP\Agent\Agent;
use AgentePHP\Providers\OpenAI\OpenAIProvider;

$agent = Agent::make()
    ->withProvider(OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini'))
    ->withInstructions('You are a concise assistant. Answer in at most three sentences.');

echo $agent->chat('What problem does dependency injection solve?')->content();
```

Two of those lines are Composer's, not ours. `require …/vendor/autoload.php` is how every
PHP program loads its dependencies, and no library can remove it: something has to load
the autoloader before any class name resolves. What AgentePHP *can* promise is that it
asks for nothing beyond it — no container to configure, no config file, no bootstrap of
its own, no service registration. Four statements after the autoloader, you have a
working agent.

`fromEnvironment()` reads `OPENAI_API_KEY` — the variable `.env.example` already names.
It is a named constructor rather than a default inside `new` on purpose: the call site
says out loud that the environment is being read, so a key that is missing in production
and present locally is a visible difference rather than a mystery. When the key has to
come from somewhere else — a secrets manager, a per-tenant row in the database — pass it
directly instead: `new OpenAIProvider(apiKey: $key, model: 'gpt-4o-mini')`.

In an application you will not write that line at all. A framework's front controller has
already loaded the autoloader, and so has `phpunit.xml`; the agent is just a class you
construct, as in section 2. The `require` belongs to standalone scripts — and for the
smallest of those, the CLI from milestone 11 skips the file entirely:

```bash
export OPENAI_API_KEY=sk-…
vendor/bin/agente ask 'What problem does dependency injection solve?'
```

The agent keeps the conversation, so the second call still knows what the first one was
about.

```php
$agent->chat('My name is Yurii.');
echo $agent->chat('What is my name?')->content(); // knows it is Yurii
```

Switching models is one line, because every provider satisfies the same contract:

```php
use AgentePHP\Providers\Anthropic\AnthropicProvider;
use AgentePHP\Providers\Ollama\OllamaProvider;

$agent = $agent->withProvider(AnthropicProvider::fromEnvironment(model: 'claude-sonnet-4-5'));

// Local model, no API key, no account:
$agent = $agent->withProvider(new OllamaProvider(model: 'llama3.1'));
```

> `withX()` returns a **new** agent and leaves the original untouched. Configuration is
> never mutated under another caller's feet, which matters as soon as one agent is shared
> across a request or a queue worker.

---

## 2. An agent in an application

In a real codebase an agent is not assembled at the call site; it *is* a class, and its
dependencies are declarations rather than arguments someone has to remember to pass.

```php
<?php

namespace App\Agents;

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\FileChatHistory;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\OpenAI\OpenAIProvider;

final class SupportAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // Swap this line for AnthropicProvider, OllamaProvider, or your own:
        // nothing else in the class changes.
        return OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
    }

    protected function instructions(): string
    {
        return <<<'TEXT'
            You are a support agent for an online bookshop.
            Answer only from the order data available through your tools.
            If a tool cannot answer the question, say so and offer to escalate.
            TEXT;
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new FileChatHistory(__DIR__ . '/../../var/conversations');
    }
}
```

```php
$agent = new SupportAgent();
echo $agent->chat('Where is order 11482?')->content();
```

No constructor, and nothing to pass. An agent writes a constructor only when it has a
real collaborator — a repository, a mailer, a clock — which is the case in section 4. A
secret is not a collaborator: threading one through every agent class, from the call site
down, is work that buys nothing, because the only thing the agent does with it is hand it
to the provider.

Both styles are the same object. The protected methods are the defaults a subclass
declares; `withProvider()` and friends override them for one instance, which is what
makes a production agent class testable without touching its source.

---

## 3. Streaming

The answer arrives as text deltas, and the finished message is still returned at the end
so usage and tool calls are not lost.

```php
$stream = $agent->stream('Explain the agentic loop to a PHP developer.');

foreach ($stream as $chunk) {
    echo $chunk;
}

$message = $stream->getReturn();
printf("\n[%d prompt + %d completion tokens]\n", $message->usage()->prompt, $message->usage()->completion);
```

`stream()` returns a `Generator<int, string, void, AssistantMessage>`: plain PHP, so
`getReturn()` is the language's own way of asking for the result, not an invented one.

---

## 4. Tools

A tool is a PHP method. The attribute is what marks it as callable by the model — not a
naming convention, not every public method, so a reader can see the boundary of what the
model can reach by grepping for `#[AsTool]`.

```php
<?php

namespace App\Tools;

use AgentePHP\Tools\Attributes\AsTool;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Toolkit;
use App\Repository\OrderRepository;

final class OrderToolkit extends Toolkit
{
    public function __construct(private readonly OrderRepository $orders) {}

    #[AsTool('Look up the delivery status of a single order.')]
    public function orderStatus(
        #[Param('Order number as shown on the customer receipt, e.g. 11482.')]
        int $orderNumber,
    ): string {
        $order = $this->orders->find($orderNumber);

        return $order === null
            ? "No order {$orderNumber} exists."
            : "Order {$orderNumber}: {$order->status}, expected {$order->expectedAt->format('j M')}.";
    }

    #[AsTool('Cancel an order that has not shipped yet.')]
    public function cancelOrder(
        #[Param('Order number to cancel.')]
        int $orderNumber,
        #[Param('Reason, shown to the warehouse team.')]
        string $reason = 'Customer request',
    ): string {
        return $this->orders->cancel($orderNumber, $reason)
            ? "Order {$orderNumber} cancelled."
            : "Order {$orderNumber} has already shipped and cannot be cancelled.";
    }
}
```

The JSON Schema sent to the model is derived from the signature: parameter names, PHP
types, whether a default makes them optional. A signature and its schema cannot drift
apart, because there is only one of them.

Registering the toolkit is one more method on the agent — which makes this the first
point where a whole agent can be read at once. Everything it needs is declared here, and
there is nothing else to wire up anywhere:

```php
<?php

namespace App\Agents;

use AgentePHP\Agent\Agent;
use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\FileChatHistory;
use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tools\ToolInterface;
use AgentePHP\Tools\Toolkits\Calculator;
use App\Repository\OrderRepository;
use App\Tools\OrderToolkit;

final class SupportAgent extends Agent
{
    public function __construct(private readonly OrderRepository $orders) {}

    protected function provider(): AIProviderInterface
    {
        return OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
    }

    protected function instructions(): string
    {
        return <<<'TEXT'
            You are a support agent for an online bookshop.
            Answer only from the order data available through your tools.
            Never promise a delivery date that a tool has not given you.
            If a tool cannot answer the question, say so and offer to escalate.
            TEXT;
    }

    /** @return list<ToolInterface> */
    protected function tools(): array
    {
        return [
            new OrderToolkit($this->orders),
            new Calculator(),
        ];
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new FileChatHistory(__DIR__ . '/../../var/conversations');
    }
}
```

```php
$agent = new SupportAgent($orders);

echo $agent->chat('Where is order 11482?')->content();
```

Four methods, and each one answers a question the reader of the class is entitled to ask:
which model, what it is told to do, what it is allowed to do, and what it remembers. None
of them takes an argument, so there is no order to get wrong and no call site to keep in
sync; and because they are methods rather than constructor parameters, a subclass can
change one of them without restating the other three.

Here the constructor is earned: the order repository is a collaborator the agent cannot
invent, and in an application it comes from the container that already builds everything
else. That is the difference from section 2 — the key went away because the provider
could fetch it itself; the repository stays, because nothing but the caller knows which
database it talks to.

The tool loop has a hard cap, and an agent that genuinely needs more raises it in the same
style as everything else:

```php
    protected function maxToolIterations(): int
    {
        return 8; // default is 5; a model that keeps calling must fail the request, not hang the process
    }
```

```php
echo $agent->chat('Cancel order 11482, it was a duplicate.')->content();
// The model asks for cancelOrder(11482, 'duplicate order'), the library runs it,
// feeds the result back, and the model writes the reply.
```

For a one-off, a closure needs no class:

```php
use AgentePHP\Tools\Tool;

$agent = $agent->withTools([
    Tool::make(
        name: 'current_time',
        description: 'The current time in a given IANA timezone.',
        callback: fn (string $timezone): string =>
            (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('H:i'),
    ),
]);
```

A `Toolkit` satisfies the same contract as a single tool, so a toolkit built out of
toolkits needs no special handling anywhere in the agent.

---

## 5. Structured output

When the caller needs data rather than prose, the PHP class is the contract. One
declaration produces both the schema sent to the model and the type that comes back.

```php
<?php

namespace App\Extraction;

use AgentePHP\StructuredOutput\Attributes\ArrayOf;
use AgentePHP\StructuredOutput\Attributes\Description;

final class Invoice
{
    /** @param list<LineItem> $lines */
    public function __construct(
        #[Description('Invoice number exactly as printed on the document.')]
        public readonly string $number,

        #[Description('Total including tax, in the invoice currency.')]
        public readonly float $total,

        #[Description('ISO 4217 currency code, e.g. EUR.')]
        public readonly string $currency,

        #[ArrayOf(LineItem::class)]
        public readonly array $lines,

        #[Description('Due date, or null when the document does not state one.')]
        public readonly ?DateTimeImmutable $dueAt = null,
    ) {}
}
```

```php
$invoice = $agent->structured($pdfText, Invoice::class);

echo $invoice->number;              // string, not a guess
echo count($invoice->lines);        // list<LineItem>, each one typed
```

`structured()` is generic over the class it is given, so static analysis knows the return
type and a typo in a property name is a PHPStan error rather than a production surprise.
A response that does not satisfy the schema is rejected at the boundary and retried; it
never half-populates an object.

---

## 6. Conversation history

History is an interface because storage is a deployment choice, and the agent is written
not to care which one it got.

```php
use AgentePHP\Chat\History\FileChatHistory;
use AgentePHP\Chat\History\InMemoryChatHistory;

$agent = $agent->withHistory(new InMemoryChatHistory(maxMessages: 20));

// A CLI session or a chat thread that must survive the process:
$agent = $agent->withHistory(new FileChatHistory(__DIR__ . '/var/threads', threadId: 'user-42'));
```

Messages are immutable value objects, so the instance an observability listener reads is
the same one the provider was given, with no chance of a surprise edit in between.

```php
foreach ($agent->history()->messages() as $message) {
    printf("%-9s %s\n", $message->role()->value, $message->content());
}

$agent->history()->clear();
```

---

## 7. Seeing inside the agent

Nothing is logged from inside the agent. It announces what it is doing, and whoever cares
listens.

```php
use AgentePHP\Observability\ConsoleListener;

$agent = $agent->withListener(new ConsoleListener());

echo $agent->chat('Cancel order 11482.')->content();
```

```text
→ chat          2 messages, model gpt-4o-mini
→ tool calling  cancelOrder {"orderNumber":11482,"reason":"duplicate order"}
← tool called   cancelOrder 18ms "Order 11482 cancelled."
← chat stop     412 prompt + 57 completion tokens, 1.3s
```

An application that already has a PSR-14 dispatcher hands it over instead, and its own
listeners see the same events:

```php
$agent = $agent->withDispatcher($container->get(Psr\EventDispatcher\EventDispatcherInterface::class));
```

```php
use AgentePHP\Observability\Events\ToolCalled;

final class ToolMetrics
{
    public function __construct(private readonly StatsD $statsd) {}

    public function __invoke(ToolCalled $event): void
    {
        $this->statsd->timing("agent.tool.{$event->tool->name()}", $event->durationMs);
    }
}
```

---

## 8. Answering over your own documents

```php
<?php

namespace App\Agents;

use AgentePHP\Providers\AIProviderInterface;
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\RAG\Embeddings\EmbeddingsProviderInterface;
use AgentePHP\RAG\Embeddings\OpenAIEmbeddings;
use AgentePHP\RAG\RagAgent;
use AgentePHP\RAG\VectorStore\FileVectorStore;
use AgentePHP\RAG\VectorStore\VectorStoreInterface;

final class HandbookAgent extends RagAgent
{
    protected function provider(): AIProviderInterface
    {
        return OpenAIProvider::fromEnvironment(model: 'gpt-4o-mini');
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return OpenAIEmbeddings::fromEnvironment(model: 'text-embedding-3-small');
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new FileVectorStore(__DIR__ . '/../../var/handbook-index');
    }

    protected function instructions(): string
    {
        return 'Answer only from the handbook excerpts provided. Quote the section heading you used.';
    }
}
```

Indexing is a separate, explicit step — it costs money and time, and hiding it inside the
first question would make a user's first query mysteriously slow.

```php
use AgentePHP\RAG\DataLoader\MarkdownLoader;
use AgentePHP\RAG\Splitter\TextSplitter;

$agent = new HandbookAgent();

$agent->index(
    new MarkdownLoader(__DIR__ . '/handbook'),
    new TextSplitter(maxChars: 1_200, overlapChars: 200),
);
```

Asking is the same `chat()` as everywhere else — retrieval happens underneath it:

```php
$answer = $agent->chat('How many days of unpaid leave can I take?');

echo $answer->content();

foreach ($answer->sources() as $document) {
    printf("  %s (score %.2f)\n", $document->metadata['path'], $document->score);
}
```

Loaders and splitters are generators, so indexing a corpus never requires holding it in
memory.

---

## 9. Multi-step work with a pause for a human

Some work does not fit in one round trip: it has steps, branches, and a point where a
person must approve what the model produced. That is a graph, and the state has to
survive the process that was running it.

```php
<?php

namespace App\Workflows;

use AgentePHP\Workflow\Node;
use AgentePHP\Workflow\WorkflowState;

final class TriageState extends WorkflowState
{
    public function __construct(
        public readonly string $ticket,
        public readonly ?string $category = null,
        public readonly ?string $draft = null,
        public readonly bool $approved = false,
    ) {}
}

final class Classify extends Node
{
    public function __construct(private readonly SupportAgent $agent) {}

    public function run(TriageState $state): TriageState
    {
        $category = $this->agent->structured($state->ticket, Category::class);

        return $state->with(category: $category->name);
    }
}
```

The graph is declared in one place. Nodes hold no references to each other, which is what
lets the same node appear in a second workflow and be tested on its own.

```php
use AgentePHP\Workflow\Persistence\FileStateStore;
use AgentePHP\Workflow\Workflow;

$workflow = Workflow::make()
    ->from(new Classify($agent))
    ->then(new DraftReply($agent))
    ->interrupt('human-approval')
    ->then(new SendReply($mailer), when: fn (TriageState $s): bool => $s->approved)
    ->otherwise(new EscalateToHuman($tracker))
    ->persistTo(new FileStateStore(__DIR__ . '/var/workflows'));

$run = $workflow->start(new TriageState(ticket: $ticket));
```

At the interrupt the run stops and is persisted. A different process — a web request
handling the reviewer's click — picks it up by id:

```php
if ($run->isWaiting()) {
    $draft = $run->state()->draft;   // show it to the reviewer
    $runId = $run->id();             // store it with the review task
}
```

```php
$run = $workflow->resume($runId, new TriageState(approved: true));

echo $run->state()->draft;
echo $run->isFinished() ? 'sent' : 'escalated';
```

---

## 10. When things go wrong

Failures are typed, so a caller can handle the one case it knows how to handle and let
the rest go up.

```php
use AgentePHP\Exceptions\MaxIterationsReached;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Exceptions\RateLimited;

try {
    echo $agent->chat($question)->content();
} catch (RateLimited $e) {
    $queue->later($e->retryAfter(), new AskAgain($question));
} catch (MaxIterationsReached $e) {
    $logger->warning('Agent looped', ['calls' => $e->toolCalls()]);
} catch (ProviderException $e) {
    $logger->error($e->getMessage());
}
```

Every exception message says what happened, what was expected, and what to do — a stack
trace alone is not an explanation:

```text
AgentePHP\Exceptions\ToolException:
  Tool "cancelOrder" expects parameter "orderNumber" of type int, and the model sent
  the string "eleven thousand". Make the description in #[Param] state the expected
  format, or accept a string and parse it inside the tool.
```

Reading configuration from the environment is only acceptable if failing to find it says
so plainly. This is the price `fromEnvironment()` has to pay to be allowed:

```text
AgentePHP\Exceptions\MissingApiKey:
  OpenAIProvider::fromEnvironment() found no OPENAI_API_KEY in the environment.
  Export it (export OPENAI_API_KEY=sk-...), or pass the key explicitly:
  new OpenAIProvider(apiKey: $key, model: 'gpt-4o-mini').
  Note that PHP does not read .env files on its own - see docs/configuration.md.
```

---

## 11. Testing an agent

An agent that cannot be tested without a network and a credit card is not
production-ready. The test doubles are part of how the library is designed, not an
afterthought.

```php
<?php

namespace App\Tests\Unit\Agents;

use AgentePHP\Agent\Agent;
use AgentePHP\Tests\Support\FakeProvider;
use PHPUnit\Framework\TestCase;

final class SupportAgentTest extends TestCase
{
    public function test_it_answers_from_the_provider(): void
    {
        $agent = Agent::make()->withProvider(new FakeProvider(['Paris']));

        self::assertSame('Paris', $agent->chat('Capital of France?')->content());
    }

    public function test_it_runs_the_tool_the_model_asks_for(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::toolCall('orderStatus', ['orderNumber' => 11482]),
            FakeProvider::text('Your order is in transit.'),
        );

        $agent = Agent::make()
            ->withProvider($provider)
            ->withTools([new OrderToolkit(new InMemoryOrderRepository([11482 => 'in transit']))]);

        $answer = $agent->chat('Where is order 11482?');

        self::assertSame('Your order is in transit.', $answer->content());
        self::assertSame(['orderStatus'], $provider->toolsCalled());
    }
}
```

Testing a provider instead of an agent swaps the layer below, with no network and no key:

```php
use AgentePHP\Providers\OpenAI\OpenAIProvider;
use AgentePHP\Tests\Support\FakeHttpClient;

$http = FakeHttpClient::returning(json_encode(['choices' => [['message' => ['content' => 'pong']]]]));
$provider = new OpenAIProvider(apiKey: 'test-key', model: 'gpt-4o-mini', http: $http);

self::assertSame('pong', $provider->chat([new UserMessage('ping')])->content());
self::assertSame('https://api.openai.com/v1/chat/completions', $http->lastRequest()->url);
```

---

## Open questions this sketch exposes

Writing the call sites first surfaced four decisions that the roadmap currently leaves
open or answers differently. Each one is cheaper to settle now than after the code exists.

1. **`withListener()` implies the library ships a dispatcher.** `psr/event-dispatcher` is
   interfaces only, so the no-setup path in §7 needs a small dispatcher of our own.
   Either we accept writing one (perhaps twenty lines), or `withListener()` goes away and
   observability always requires a host dispatcher — which costs us the "no setup"
   promise exactly where a newcomer first wants to see inside the agent.
2. **Nodes return state here; the roadmap says they return an event.** Doing both means
   two mechanisms for one job, and an event-returning node cannot be type-checked against
   the state it produces. §9 shows the state-returning version because it reads better;
   if events are kept, milestone 10 needs to say what an event is *for* beyond
   observability, which §7 already covers.
3. **`RAG\Rag` is named `RagAgent` in §8.** `Rag` alone does not say it is an agent, and
   the class is used as one. A rename worth making before it has callers.
4. **`#[AsTool]`, not `#[Tool]`.** The attribute and the `Tools\Tool` value object cannot
   share a short name without forcing an alias in every file that uses both.
5. **Milestone 11 needs an `ask` command, not only the `make:` generators.** Section 1
   leans on `vendor/bin/agente ask …` as the shortest possible first contact — the only
   version of "try it" that involves no PHP file, and therefore no autoloader line, no
   `$agent` variable, and nothing to get wrong. A code generator helps someone who has
   already committed to the library; a one-line question helps someone deciding whether
   to.
6. **Nothing in the project reads `.env`.** `README.md` tells the reader to copy
   `.env.example` to `.env`, but PHP does not load that file, and `getenv()` will not see
   it — so `fromEnvironment()` would fail for someone who followed the instructions
   exactly. Either the README switches to `export`, or we ship a twenty-line `.env`
   reader. If we do, it belongs to the CLI and the examples, not to `src/Providers`: a
   provider that reads a file off disk to find its key is doing something a reader of
   section 1 has no way to predict.
7. ~~**Whether `fromEnvironment()` should default the model.**~~ *Settled when the
   providers were written: it does not.* The key is a secret and belongs to the
   environment; the model is a behavioural choice tied to the prompt and the tools around
   it, and a default would be friendly on day one and a lie a year later.
8. **A PHP install with no CA bundle fails every HTTPS call,** which is the default on
   Windows and was hit on the first real request this library ever made. `HttpException`
   now says what to fix in `php.ini`, but the getting-started guide needs to say it too -
   a newcomer should not have to trigger the error to learn about it.

And one thing the sketch deliberately does not answer: whether `chat()` accepting a bare
string is enough, or whether multimodal input (an image, a PDF) needs to arrive as a
`UserMessage` with content parts from the start. Milestone 2 decides that, and changing it
later would break every call site on this page.
