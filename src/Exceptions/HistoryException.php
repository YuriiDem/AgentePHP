<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

use Throwable;

/** Stored conversation could not be read, written, or made sense of. */
final class HistoryException extends AgenteException
{
    public static function directoryNotUsable(string $directory): self
    {
        return new self(sprintf(
            'Could not create the conversation directory %s. '
            . 'Check that the parent directory exists and that this process may write to it.',
            $directory,
        ));
    }

    public static function fileNotWritable(string $file): self
    {
        return new self(sprintf(
            'Could not write the conversation to %s. '
            . 'Check the file permissions, and that no other process is holding it.',
            $file,
        ));
    }

    public static function fileIsNotReadable(string $file): self
    {
        return new self(sprintf(
            'The conversation file %s exists but could not be read. Check its permissions.',
            $file,
        ));
    }

    public static function fileIsCorrupt(string $file, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'The conversation file %s does not contain a list of stored messages, so it '
                . 'cannot be replayed. Either an unrelated file is sitting in the thread '
                . 'directory, or a write was interrupted. Delete the file to start the '
                . 'conversation again.',
                $file,
            ),
            previous: $previous,
        );
    }

    /**
     * @param list<string> $knownRoles
     */
    public static function unknownStoredRole(string $role, array $knownRoles): self
    {
        return new self(sprintf(
            'A stored message has role "%s", which this version cannot rebuild. Known roles '
            . 'are: %s. A conversation stored by a newer version of AgentePHP cannot be read '
            . 'by this one.',
            $role,
            implode(', ', $knownRoles),
        ));
    }

    public static function missingStoredField(string $field, string $expected): self
    {
        return new self(sprintf(
            'A stored message has no usable "%s" field: expected %s. '
            . 'The file was not written by this library, or was edited by hand.',
            $field,
            $expected,
        ));
    }
}
