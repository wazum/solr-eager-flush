<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Gate;

interface EagerFlushGate
{
    public function shouldProceed(): bool;
}
