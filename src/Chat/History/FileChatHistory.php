<?php

declare(strict_types=1);

namespace AgentePHP\Chat\History;

use AgentePHP\Chat\Messages\Message;
use AgentePHP\Exceptions\HistoryException;
use InvalidArgumentException;
use JsonException;

/**
 * Remembers a conversation in one JSON file per thread.
 *
 * For a CLI session, a chat that must survive the request that started it, or a queue
 * worker picking up where another process left off. The file is written pretty-printed on
 * purpose: being able to read a stored conversation in an editor is worth more here than
 * the bytes it costs.
 *
 * Nothing is cached in the object. Two instances pointing at the same thread therefore see
 * each other's writes, which is the behaviour a worker pool needs; the cost is a read per
 * call, which is the right trade for conversations of this size.
 */
final class FileChatHistory implements ChatHistoryInterface
{
    /**
     * A thread id becomes a file name, so it is restricted to characters that cannot
     * address a path: ids routinely come from a URL or a form.
     */
    private const SAFE_THREAD_ID = '/^[A-Za-z0-9_-]+$/';

    public function __construct(
        private readonly string $directory,
        private readonly string $threadId = 'default',
        private readonly MessageSerializer $serializer = new MessageSerializer(),
    ) {
        if (preg_match(self::SAFE_THREAD_ID, $threadId) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A thread id may only contain letters, digits, "-" and "_", and cannot be '
                . 'empty; got %s. It is used as a file name, so a value like "../other" '
                . 'would address a file outside %s.',
                var_export($threadId, true),
                $this->directory,
            ));
        }
    }

    public function add(Message ...$messages): void
    {
        if ($messages === []) {
            return;
        }

        $stored = $this->messages();

        foreach ($messages as $message) {
            $stored[] = $message;
        }

        $this->write($stored);
    }

    public function messages(): array
    {
        $file = $this->file();

        // A thread nobody has spoken in yet is empty, not missing.
        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw HistoryException::fileIsNotReadable($file);
        }

        return array_map(
            fn (array $data): Message => $this->serializer->fromArray($data),
            $this->decode($contents, $file),
        );
    }

    public function clear(): void
    {
        $file = $this->file();

        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * @param list<Message> $messages
     */
    private function write(array $messages): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            throw HistoryException::directoryNotUsable($this->directory);
        }

        $data = array_map(
            fn (Message $message): array => $this->serializer->toArray($message),
            $messages,
        );

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($this->file(), $json, LOCK_EX) === false) {
            throw HistoryException::fileNotWritable($this->file());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $contents, string $file): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw HistoryException::fileIsCorrupt($file, $e);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw HistoryException::fileIsCorrupt($file);
        }

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                throw HistoryException::fileIsCorrupt($file);
            }
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function file(): string
    {
        return $this->directory . '/' . $this->threadId . '.json';
    }
}
