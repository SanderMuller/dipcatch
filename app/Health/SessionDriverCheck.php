<?php declare(strict_types=1);

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * The cookie session driver stores the whole session in one browser cookie,
 * and browsers drop any cookie above 4 KB without an error. Passport's
 * consent step keeps the serialized authorization request in the session,
 * which puts the payload at about 7 KB. The consent page then renders, but
 * the browser never stores the `authToken`, and every "Allow" fails with a
 * 403 from Passport.
 */
class SessionDriverCheck extends Check
{
    public function run(): Result
    {
        if (config('session.driver') === 'cookie') {
            return Result::make()
                ->failed('SESSION_DRIVER is "cookie", so the OAuth consent step cannot complete.')
                ->shortSummary('cookie driver');
        }

        return Result::make()
            ->ok('The session driver can hold an OAuth authorization request.')
            ->shortSummary('server-side');
    }
}
