<?php declare(strict_types=1);

namespace App\Analytics\Contracts;

use App\Analytics\SignupSource;

/**
 * The app's user model implements this to take part in linked usage
 * analytics. A user that does not implement it is only ever counted
 * anonymously.
 */
interface TrackableUser
{
    /** False once the person objected to linking their page views to their account. */
    public function allowsUsageTracking(): bool;

    /** Store where the person came from before they signed up. Called once, at registration. */
    public function recordSignupSource(SignupSource $source): void;
}
