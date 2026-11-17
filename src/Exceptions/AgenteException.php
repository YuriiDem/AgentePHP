<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

use RuntimeException;

/**
 * The base of everything this library throws at runtime, so an application can catch all of
 * it with one clause and let everything else through.
 *
 * Programmer errors - a negative token count, a malformed argument - are not in this
 * hierarchy: those stay SPL exceptions, because they are bugs to fix rather than conditions
 * to handle.
 */
abstract class AgenteException extends RuntimeException
{
}
