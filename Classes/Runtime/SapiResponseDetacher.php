<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Runtime;

use Throwable;

final readonly class SapiResponseDetacher implements ResponseDetacher
{
    public function detach(): bool
    {
        try {
            if (\PHP_SESSION_ACTIVE === session_status()) {
                session_write_close();
            }

            if (!$this->finishRequest()) {
                return false;
            }

            // Output after detaching would terminate the script silently otherwise
            ignore_user_abort(true);

            return true;
        } catch (Throwable) {
            return false;
        }
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
