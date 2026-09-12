<?php declare(strict_types=1);

use App\Health\SessionDriverCheck;
use Spatie\Health\Enums\Status;

it('fails the health check when sessions live in a browser cookie', function (): void {
    // The consent page's session payload measures about 7 KB; a browser
    // drops any cookie above 4 KB, so the approve request never sees the
    // auth token Passport stored.
    config()->set('session.driver', 'cookie');

    expect(new SessionDriverCheck()->run()->status)->toBe(Status::failed());
});

it('passes the health check with a server-side session driver', function (): void {
    config()->set('session.driver', 'database');

    expect(new SessionDriverCheck()->run()->status)->toBe(Status::ok());
});
