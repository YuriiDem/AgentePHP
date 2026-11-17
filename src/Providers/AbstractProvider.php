<?php

declare(strict_types=1);

namespace AgentePHP\Providers;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Exceptions\MissingApiKey;
use AgentePHP\Exceptions\ProviderException;
use AgentePHP\Exceptions\RateLimited;
use AgentePHP\Http\CurlHttpClient;
use AgentePHP\Http\HttpClientInterface;
use AgentePHP\Http\Request;
use AgentePHP\Http\Response;

/**
 * The part of "talk to a model over HTTP" that is identical for every vendor.
 *
 * This is the Template Method pattern: chat() fixes the three steps of the algorithm -
 * build a request, check the response, read the answer - and a subclass fills in the two
 * steps that are vendor-specific. Adding a provider therefore requires no edit to an
 * existing one.
 *
 * The abstraction is justified by two concrete cases arriving together (OpenAI and
 * Anthropic). With one provider it would have been premature.
 */
abstract class AbstractProvider implements AIProviderInterface
{
    protected readonly HttpClientInterface $http;

    /**
     * The transport defaults to the real one, so the common path needs no wiring, and is
     * injectable, so a test needs no network. Both matter; neither is an afterthought.
     * Resolving the default here means no subclass has to repeat the decision.
     */
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $model,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
    }

    final public function chat(array $messages): AssistantMessage
    {
        $response = $this->http->send($this->buildRequest($messages));

        $this->failIfRefused($response);

        return $this->readAnswer($response->json());
    }

    /**
     * The vendor's name as a human reads it, used in every error message so that an
     * application switching models on one line knows which API refused.
     */
    abstract protected function name(): string;

    /**
     * @param list<Message> $messages
     */
    abstract protected function buildRequest(array $messages): Request;

    /**
     * @param array<string, mixed> $payload
     */
    abstract protected function readAnswer(array $payload): AssistantMessage;

    /**
     * A 429 gets its own type because a caller can act on it; everything else is one
     * failure as far as this library is concerned.
     */
    private function failIfRefused(Response $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        throw $response->status === 429
            ? RateLimited::forProvider($this->name(), $response->body)
            : ProviderException::requestFailed($this->name(), $response->status, $response->body);
    }

    /**
     * Read a key out of a vendor payload, or explain what was missing.
     *
     * @param array<string, mixed> $payload
     *
     * @return non-empty-array<array-key, mixed>
     */
    final protected function requireArray(array $payload, string $key, string $path): array
    {
        $value = $payload[$key] ?? null;

        if (!is_array($value) || $value === []) {
            throw ProviderException::unexpectedPayload($this->name(), $path, $payload);
        }

        return $value;
    }

    /**
     * Token counts arrive as integers in practice, but a missing or odd value must not turn
     * a usable answer into an exception - usage is bookkeeping, not the answer itself.
     */
    final protected function tokenCount(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * @throws MissingApiKey
     */
    final protected static function keyFromEnvironment(string $variable, string $provider, string $model): string
    {
        $key = getenv($variable);

        if (!is_string($key) || trim($key) === '') {
            throw MissingApiKey::inEnvironment($variable, $provider, $model);
        }

        return $key;
    }
}
