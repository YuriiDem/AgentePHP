<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Chat\History;

use AgentePHP\Chat\History\ChatHistoryInterface;
use AgentePHP\Chat\History\FileChatHistory;
use AgentePHP\Chat\History\InMemoryChatHistory;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\HistoryException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The filesystem is not the network: these stay in tests/Unit, in a temporary directory
 * that is removed afterwards.
 */
final class FileChatHistoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/agente-history-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function test_a_thread_nobody_has_written_to_is_empty_rather_than_an_error(): void
    {
        $history = new FileChatHistory($this->directory, threadId: 'user-42');

        self::assertSame([], $history->messages());
    }

    public function test_it_creates_its_directory_when_first_written_to(): void
    {
        $history = new FileChatHistory($this->directory, threadId: 'user-42');

        $history->add(new UserMessage('Hello.'));

        self::assertDirectoryExists($this->directory);
    }

    /** The whole point of this implementation: the conversation outlives the process. */
    public function test_another_instance_reads_what_the_first_one_wrote(): void
    {
        $writing = new FileChatHistory($this->directory, threadId: 'user-42');
        $writing->add(new UserMessage('Hello.'), new AssistantMessage('Hi.', new Usage(prompt: 7, completion: 1)));

        $reading = new FileChatHistory($this->directory, threadId: 'user-42');
        $messages = $reading->messages();

        self::assertCount(2, $messages);
        self::assertSame('Hello.', $messages[0]->content());
        self::assertSame('user', $messages[0]->role()->value);
        self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        self::assertSame(8, $messages[1]->usage()->total());
    }

    public function test_it_appends_to_what_is_already_stored(): void
    {
        $history = new FileChatHistory($this->directory, threadId: 'user-42');

        $history->add(new UserMessage('first'));
        $history->add(new UserMessage('second'));

        self::assertCount(2, (new FileChatHistory($this->directory, threadId: 'user-42'))->messages());
    }

    public function test_two_threads_in_one_directory_do_not_see_each_other(): void
    {
        (new FileChatHistory($this->directory, threadId: 'alice'))->add(new UserMessage('from Alice'));
        (new FileChatHistory($this->directory, threadId: 'bob'))->add(new UserMessage('from Bob'));

        $alice = new FileChatHistory($this->directory, threadId: 'alice');

        self::assertCount(1, $alice->messages());
        self::assertSame('from Alice', $alice->messages()[0]->content());
    }

    public function test_clearing_a_thread_empties_it_for_every_reader(): void
    {
        $history = new FileChatHistory($this->directory, threadId: 'user-42');
        $history->add(new UserMessage('Hello.'));

        $history->clear();

        self::assertSame([], (new FileChatHistory($this->directory, threadId: 'user-42'))->messages());
    }

    public function test_clearing_a_thread_that_was_never_written_is_not_an_error(): void
    {
        $history = new FileChatHistory($this->directory, threadId: 'nobody');

        $history->clear();

        self::assertSame([], $history->messages());
        self::assertFileDoesNotExist(
            $this->directory . '/nobody.json',
            'clear() must not leave an empty file behind for a thread that never existed',
        );
    }

    /**
     * A thread id reaches the filesystem as a file name, so an id out of a URL or a form
     * must not be able to address a path of its own choosing.
     */
    public function test_it_refuses_a_thread_id_that_could_escape_the_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thread id');

        new FileChatHistory($this->directory, threadId: '../../etc/passwd');
    }

    public function test_it_refuses_a_thread_id_with_a_separator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileChatHistory($this->directory, threadId: 'users/42');
    }

    public function test_it_refuses_an_empty_thread_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileChatHistory($this->directory, threadId: '');
    }

    public function test_it_names_the_file_it_could_not_make_sense_of(): void
    {
        mkdir($this->directory, 0o777, true);
        file_put_contents($this->directory . '/user-42.json', 'not json at all');

        $history = new FileChatHistory($this->directory, threadId: 'user-42');

        $this->expectException(HistoryException::class);
        $this->expectExceptionMessage('user-42.json');

        $history->messages();
    }

    /**
     * Liskov, concretely: the storage backend is a deployment choice, so whatever an agent
     * does with a history must hold for either implementation.
     */
    public function test_it_behaves_like_the_in_memory_history_it_replaces(): void
    {
        $implementations = [
            new InMemoryChatHistory(),
            new FileChatHistory($this->directory, threadId: 'user-42'),
        ];

        foreach ($implementations as $history) {
            self::assertInstanceOf(ChatHistoryInterface::class, $history);

            $history->add(new UserMessage('Hello.'), new AssistantMessage('Hi.'));

            self::assertCount(2, $history->messages(), $history::class);
            self::assertSame('Hello.', $history->messages()[0]->content(), $history::class);

            $history->clear();

            self::assertSame([], $history->messages(), $history::class);
        }
    }
}
