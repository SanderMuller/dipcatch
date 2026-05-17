<?php declare(strict_types=1);

namespace App\Enums;

enum ScrapeStatus: string
{
    case Ok = 'ok';
    case EmptyMatch = 'empty_match';
    case HttpError = 'http_error';
    case ParseError = 'parse_error';
    case Throttled = 'throttled';
    case RobotsBlocked = 'robots_blocked';
    case NeedsJs = 'needs_js';

    case Pending = 'pending';
    case Blocked = 'blocked';
    case RateLimited = 'rate_limited';
    case TransientServerError = '5xx';
    case RobotsDisallowed = 'robots_disallowed';
    case Dead = 'dead';
    case Failed = 'failed';
}
