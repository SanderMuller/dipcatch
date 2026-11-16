<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

/*
 * Links the app hands out must point at its own address, whatever host the
 * request names. On Laravel Cloud the framework trusts every proxy, so a
 * forged `X-Forwarded-Host` decides the request host; here the host in the
 * request URL stands in for it.
 */

test('a reset link points at the app, not at the host the request named', function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    Notification::fake();

    $user = User::factory()->create();

    $this->post('http://attacker.example.test/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        expect($url)->toStartWith(config()->string('app.url') . '/')
            ->not->toContain('attacker.example.test');

        return true;
    });
});

test('cached crawler files carry only the app address', function (string $path): void {
    $content = (string) $this->get('http://attacker.example.test' . $path)->assertOk()->getContent();

    expect($content)->toContain(config()->string('app.url'))
        ->not->toContain('attacker.example.test');
})->with(['/sitemap.xml', '/llms.txt']);
