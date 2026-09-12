<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

/**
 * The OAuth consent screen. It renders during a redirect from a client we do
 * not control, so nothing here can be checked by clicking around the app —
 * these are the assertions that stand in for that.
 */
function renderConsent(?string $state = 'the-client-state'): string
{
    $client = new Client();
    $client->forceFill([
        'id' => (string) Str::uuid(),
        'name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
    ])->save();

    return view('mcp.authorize', [
        'client' => $client,
        'user' => User::factory()->create(['email' => 'reader@example.test']),
        'scopes' => [],
        'request' => new Request(['state' => $state]),
        'authToken' => 'auth-token-value',
    ])->render();
}

test('the consent screen echoes the client state back on both forms', function (): void {
    // A published Passport/MCP view hardcodes value="" here, dropping the
    // state the client sent. Claude sends one, and a client that verifies it
    // on callback would reject the round trip.
    $html = renderConsent();

    expect(substr_count($html, 'name="state" value="the-client-state"'))->toBe(2);
});

test('the consent screen names the client and the account being connected', function (): void {
    $html = renderConsent();

    expect($html)->toContain('Claude')
        ->and($html)->toContain('reader@example.test');
});

test('the consent screen offers both an approve and a deny path', function (): void {
    $html = renderConsent();

    expect($html)->toContain(route('passport.authorizations.approve'))
        ->and($html)->toContain(route('passport.authorizations.deny'))
        ->and($html)->toContain('DELETE');
});

test('the consent screen carries the auth token and a CSRF field', function (): void {
    $html = renderConsent();

    expect(substr_count($html, 'auth-token-value'))->toBe(2)
        ->and(substr_count($html, 'name="_token"'))->toBe(2);
});

test('the consent screen uses colours this theme actually defines', function (): void {
    // The published view is written in shadcn utility names — bg-background,
    // text-foreground — which this project's Tailwind theme does not define,
    // so it renders black on black: present in the DOM, invisible to a human.
    $html = renderConsent();

    foreach (['bg-background', 'text-foreground', 'text-muted-foreground', 'bg-card'] as $undefined) {
        expect($html)->not->toContain($undefined);
    }

    expect($html)->toContain('text-zinc-900');
});

test('the consent screen is not indexable', function (): void {
    expect(renderConsent())->toContain('<meta name="robots" content="noindex">');
});

test('the passport authorization routes the screen posts to exist', function (): void {
    expect(Route::has('passport.authorizations.approve'))->toBeTrue()
        ->and(Route::has('passport.authorizations.deny'))->toBeTrue();
});

test('uses a third-party-safe session cookie for MCP authorization', function (): void {
    expect(config('session.partitioned'))->toBeTrue()
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('none');
});
