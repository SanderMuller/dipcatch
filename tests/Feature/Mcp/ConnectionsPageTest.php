<?php declare(strict_types=1);

use App\Livewire\Connections\ConnectionsPage;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

use function Pest\Livewire\livewire;

beforeEach(function (): void {});

function connectToken(User $user, string $client = 'Claude'): Token
{
    $record = new Client();
    $record->forceFill([
        'id' => (string) Str::uuid(),
        'name' => $client,
        'redirect_uris' => ['https://example.test/callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
    ])->save();

    $token = Token::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'client_id' => $record->getKey(),
        'scopes' => ['mcp:use'],
        'revoked' => false,
        'expires_at' => now()->addDay(),
    ]);

    RefreshToken::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'access_token_id' => $token->getKey(),
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);

    return $token;
}

function tokenId(Token $token): string
{
    $key = $token->getKey();

    return is_scalar($key) ? (string) $key : '';
}

test('a user sees only their own connections', function (): void {
    $me = User::factory()->create();
    connectToken($me, 'Mine');
    connectToken(User::factory()->create(), 'Theirs');

    $this->actingAs($me);

    livewire(ConnectionsPage::class)
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('the endpoint address is shown so a connector can be set up', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)->assertOk()->assertSee(url('/mcp'));
});

test('the page shows the locked connect copy', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)
        ->assertOk()
        ->assertSee('Connect Claude or ChatGPT to this account, or disconnect an app you already allowed.')
        ->assertSee('Opens Claude with DipCatch filled in. Review the URL, add the connector, then allow access.')
        ->assertSee('ChatGPT needs DipCatch in its plugin directory. That listing is not live yet. Use Claude or this website until it is.')
        ->assertSee('Other MCP clients can use this URL and then sign in with this account.')
        ->assertDontSee('Developer Mode');
});

test('the page offers a Claude install link with the encoded mcp url', function (): void {
    $this->actingAs(User::factory()->create());

    $endpoint = url('/mcp');
    $href = 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=DipCatch&connectorUrl=' . rawurlencode($endpoint);

    livewire(ConnectionsPage::class)
        ->assertOk()
        ->assertSee('Connect Claude')
        ->assertSee(htmlspecialchars($href, ENT_QUOTES | ENT_HTML5), escape: false)
        ->assertDontSee('Developer Mode');
});

test('the ChatGPT install button stays hidden when no listing url is set', function (): void {
    config()->set('dipcatch.chatgpt_plugin_url', null);

    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)
        ->assertOk()
        ->assertSee('ChatGPT needs DipCatch in its plugin directory.')
        ->assertDontSee('Install in ChatGPT');
});

test('the ChatGPT install button stays hidden for a non-https or non-chatgpt url', function (string $url): void {
    config()->set('dipcatch.chatgpt_plugin_url', $url);

    $this->actingAs(User::factory()->create());

    $page = livewire(ConnectionsPage::class)
        ->assertOk()
        ->assertSee('ChatGPT needs DipCatch in its plugin directory.')
        ->assertDontSee('Install in ChatGPT');

    if ($url !== '') {
        $page->assertDontSee($url, escape: false);
    }
})->with([
    'empty' => [''],
    'http chatgpt' => ['http://chatgpt.com/g/g-dipcatch'],
    'javascript scheme' => ['javascript:alert(1)'],
    'other host' => ['https://example.test/plugin'],
    'space in path' => ['https://chatgpt.com/foo bar'],
]);

test('the ChatGPT install button uses a valid chatgpt.com listing url', function (string $listing): void {
    config()->set('dipcatch.chatgpt_plugin_url', $listing);

    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)
        ->assertOk()
        ->assertSee('Install in ChatGPT')
        ->assertSee('Opens the DipCatch plugin in ChatGPT. Connect it there, then allow access.')
        ->assertSee($listing, escape: false)
        ->assertDontSee('ChatGPT needs DipCatch in its plugin directory.');
})->with([
    'apex' => ['https://chatgpt.com/g/g-dipcatch'],
    'subdomain' => ['https://chat.chatgpt.com/g/g-dipcatch'],
]);

test('disconnecting revokes the access token and its refresh token', function (): void {
    // Revoking only the access token leaves the refresh token able to mint a
    // new one, so the connection would silently return.
    $me = User::factory()->create();
    $token = connectToken($me);

    $this->actingAs($me);

    livewire(ConnectionsPage::class)->call('revoke', tokenId($token));

    expect($token->fresh()?->revoked)->toBeTrue()
        ->and(RefreshToken::query()->where('access_token_id', $token->getKey())->first()?->revoked)->toBeTrue();
});

test('a user cannot revoke someone elses connection', function (): void {
    $theirs = connectToken(User::factory()->create());

    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)->call('revoke', tokenId($theirs));

    expect($theirs->fresh()?->revoked)->toBeFalse();
});
