<?php

declare(strict_types=1);

namespace AgentePHP\Http;

use AgentePHP\Exceptions\HttpException;

/**
 * Send one request, get one response.
 *
 * This interface is the seam that makes every provider testable: a provider depends on this
 * contract and never constructs a transport of its own, so a test can hand it canned
 * responses instead of a network. It is also what leaves room for a PSR-18 adapter later
 * without touching provider code.
 *
 * An unsuccessful status is a Response, not an exception - the provider decides what a 401
 * or a 429 means. Only a call that never produced a response at all throws.
 */
interface HttpClientInterface
{
    /**
     * @throws HttpException when no response could be obtained
     */
    public function send(Request $request): Response;
}
