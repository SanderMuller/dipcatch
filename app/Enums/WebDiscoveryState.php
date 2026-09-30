<?php declare(strict_types=1);

namespace App\Enums;

enum WebDiscoveryState: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Done = 'done';
}
