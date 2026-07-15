<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Runtime;

use Throwable;

class SapiResponseDetacher implements ResponseDetacher
{
    public function detach(): bool
    {
        $previousIgnoreUserAbort = (bool) ignore_user_abort();
        // Enable before detaching: output after detachment would otherwise terminate
        // the script silently, and a client disconnect must not kill the eager flush.
        ignore_user_abort(true);

        try {
            if (\PHP_SESSION_ACTIVE === session_status()) {
                session_write_close();
            }

            if ($this->finishRequest()) {
                return true;
            }
        } catch (Throwable) {
        }

        ignore_user_abort($previousIgnoreUserAbort);

        return false;
    }

    protected function finishRequest(): bool
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
