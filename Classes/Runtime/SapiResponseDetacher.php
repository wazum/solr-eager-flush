<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Runtime;

final readonly class SapiResponseDetacher implements ResponseDetacher
{
    public function detach(): bool
    {
        if (\PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        $detached = $this->finishRequest();
        if ($detached) {
            // Output after detaching would terminate the script silently otherwise
            ignore_user_abort(true);
        }

        return $detached;
    }

    private function finishRequest(): bool
    {
        if (\function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }
        if (\function_exists('litespeed_finish_request')) {
            return litespeed_finish_request();
        }

        return false;
    }
}
