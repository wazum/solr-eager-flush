<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Runtime;

interface ResponseDetacher
{
    /**
     * Sends the buffered response and releases the client connection while the script keeps running.
     *
     * @return bool whether the client connection was actually released
     */
    public function detach(): bool;
}
