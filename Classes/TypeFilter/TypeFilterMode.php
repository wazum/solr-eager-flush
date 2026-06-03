<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\TypeFilter;

enum TypeFilterMode: string
{
    case Records = 'records';
    case Pages = 'pages';
    case Both = 'both';
}
