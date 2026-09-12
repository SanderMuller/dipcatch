<?php declare(strict_types=1);

use App\Livewire\AppCommandPalette;
use App\Livewire\Connections\ConnectionsPage;
use App\Livewire\Products\ProductShow;
use App\Livewire\Settings\Security;
use App\Models\Invitation;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;

use function Pest\Laravel\mock;

/**
 * @param  array<string, string>  $parameters
 * @param  array<string, string>  $expected
 */
test('guest credential forms expose password-manager autocomplete tokens', function (string $routeName, array $parameters, array $expected): void {
    if ($routeName === 'register') {
        $this->skipUnlessFortifyHas(Features::registration());
    }

    if (in_array($routeName, ['password.request', 'password.reset'], strict: true)) {
        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    $html = (string) $this->get(route($routeName, $parameters))->assertOk()->getContent();

    foreach ($expected as $name => $autocomplete) {
        if (! is_string($name) || $name === '') {
            Assert::fail('Expected a named form control.');
        }

        $control = formControl($html, $name);

        expect($control->getAttribute('autocomplete'))->toBe($autocomplete)
            ->and($control->getAttribute('id'))->toBe($name);
    }
})->with([
    'login' => ['login', [], ['email' => 'username', 'password' => 'current-password']],
    'register' => ['register', [], [
        'email' => 'username',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]],
    'forgot password' => ['password.request', [], ['email' => 'username']],
    'reset password' => ['password.reset', ['token' => 'test-token'], [
        'email' => 'username',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]],
]);

test('the invitation form associates the invite email with the new password', function (): void {
    $invitation = Invitation::factory()->create([
        'email' => 'invitee@dipcatch.test',
    ]);

    $html = (string) $this->get(route('invitation.show', ['token' => $invitation->token]))
        ->assertOk()
        ->getContent();

    $email = formControl($html, 'email');

    expect($email->getAttribute('autocomplete'))->toBe('username')
        ->and($email->getAttribute('value'))->toBe('invitee@dipcatch.test')
        ->and($email->getAttribute('id'))->toBe('email')
        ->and($email->hasAttribute('readonly'))->toBeTrue()
        ->and($email->hasAttribute('disabled'))->toBeFalse()
        ->and(formControl($html, 'password')->getAttribute('autocomplete'))->toBe('new-password')
        ->and(formControl($html, 'password')->getAttribute('id'))->toBe('password')
        ->and(formControl($html, 'password_confirmation')->getAttribute('autocomplete'))->toBe('new-password')
        ->and(formControl($html, 'password_confirmation')->getAttribute('id'))->toBe('password_confirmation');
});

test('the confirm-password form associates the signed-in email with the current password', function (): void {
    $user = User::factory()->create([
        'email' => 'member@dipcatch.test',
    ]);

    $html = (string) $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->getContent();

    expectPasswordManagerUsername($html, 'member@dipcatch.test');
    expect(formControl($html, 'password')->getAttribute('autocomplete'))->toBe('current-password')
        ->and(formControl($html, 'password')->getAttribute('id'))->toBe('password');
});

test('the security form associates the signed-in email with the password change', function (): void {
    $user = User::factory()->create([
        'email' => 'member@dipcatch.test',
    ]);

    $html = (string) $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()])
        ->get(route('security.edit'))
        ->assertOk()
        ->getContent();

    expectPasswordManagerUsername($html, 'member@dipcatch.test');
    expect(formControl($html, 'current_password')->getAttribute('autocomplete'))->toBe('current-password')
        ->and(formControl($html, 'current_password')->getAttribute('id'))->toBe('current_password')
        ->and(formControl($html, 'password')->getAttribute('autocomplete'))->toBe('new-password')
        ->and(formControl($html, 'password')->getAttribute('id'))->toBe('password')
        ->and(formControl($html, 'password_confirmation')->getAttribute('autocomplete'))->toBe('new-password')
        ->and(formControl($html, 'password_confirmation')->getAttribute('id'))->toBe('password_confirmation');
});

test('the profile email field is not marked as a username next to the delete-account password', function (): void {
    $user = User::factory()->create([
        'email' => 'member@dipcatch.test',
    ]);

    $html = (string) $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    expect(formControl($html, 'email')->getAttribute('autocomplete'))->toBe('email')
        ->and(formControl($html, 'email')->getAttribute('id'))->toBe('email');
});

test('the delete-account form associates the signed-in email with the current password', function (): void {
    $user = User::factory()->create([
        'email' => 'member@dipcatch.test',
    ]);

    $this->actingAs($user);

    $html = Livewire::test('settings.delete-user-form')->html();

    expectPasswordManagerUsername($html, 'member@dipcatch.test');
    expect(formControl($html, 'password')->getAttribute('autocomplete'))->toBe('current-password')
        ->and(formControl($html, 'password')->getAttribute('id'))->toBe('password');
});

test('the two-factor challenge marks the authenticator pin as a one-time code', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create([
        'email' => 'member@dipcatch.test',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $html = (string) $this->get(route('two-factor.login'))->assertOk()->getContent();

    expectPasswordManagerUsername($html, 'member@dipcatch.test');
    expectOneTimeCodeOnAnInput($html);

    $recovery = formControl($html, 'recovery_code');

    expect($recovery->getAttribute('autocomplete'))->toBe('off')
        ->and($recovery->getAttribute('id'))->toBe('recovery_code')
        ->and($recovery->hasAttribute('data-1p-ignore'))->toBeTrue();

    $labelTexts = new Crawler($html)->filter('ui-label, label')->each(
        static fn (Crawler $node): string => trim($node->text()),
    );

    Assert::assertTrue(
        array_any($labelTexts, fn (string $text): bool => str_contains($text, 'Recovery code')),
        '1Password needs a visible labelled recovery-code field.',
    );
});

test('two-factor setup does not offer the secret as a password', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('generateSecretKey')
        ->once()
        ->andReturn('CMN5TSOG355MJ55R')
        ->shouldReceive('qrCodeUrl')
        ->once()
        ->with(config('app.name'), $user->email, 'CMN5TSOG355MJ55R')
        ->andReturn('otpauth://totp/Dipcatch:test@example.com?secret=CMN5TSOG355MJ55R');

    $this->actingAs($user);

    $enabled = Livewire::test(Security::class)->call('enable');
    $setupHtml = $enabled->html();

    expect(new Crawler($setupHtml)->filter('input#totp-setup-key, input[name="totp-setup-key"]')->count())->toBe(0)
        ->and(new Crawler($setupHtml)->filter('#totp-setup-key')->text())->toContain('CMN5TSOG355MJ55R');

    $verifying = $enabled->call('showVerificationIfNecessary');

    expectOneTimeCodeOnAnInput($verifying->html());
});

test('copyable secret fields are ignored by password managers', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'share_slug' => str_repeat('a', 32),
    ]);

    $this->actingAs($user);

    $mcp = formControl(Livewire::test(ConnectionsPage::class)->html(), 'mcp-endpoint');

    expect($mcp->hasAttribute('data-1p-ignore'))->toBeTrue()
        ->and($mcp->getAttribute('autocomplete'))->toBe('off')
        ->and($mcp->getAttribute('id'))->toBe('mcp-endpoint');

    $share = formControl(Livewire::test(ProductShow::class, ['product' => $product])->html(), 'share-url');

    expect($share->hasAttribute('data-1p-ignore'))->toBeTrue()
        ->and($share->getAttribute('autocomplete'))->toBe('off')
        ->and($share->getAttribute('id'))->toBe('share-url');
});

test('the command palette search is ignored by password managers', function (): void {
    $this->actingAs(User::factory()->create());

    $input = new Crawler(Livewire::test(AppCommandPalette::class)->html())
        ->filter('input[data-1p-ignore]')
        ->getNode(0);

    Assert::assertInstanceOf(DOMElement::class, $input);
    Assert::assertSame('off', $input->getAttribute('autocomplete'));
});

test('the passkey name field is ignored by password managers', function (): void {
    $this->skipUnlessFortifyHas(Features::passkeys());

    Features::passkeys([
        'confirmPassword' => true,
    ]);

    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()]);

    $input = formControl(Livewire::test(Security::class)->html(), 'passkey-name');

    expect($input->hasAttribute('data-1p-ignore'))->toBeTrue()
        ->and($input->getAttribute('autocomplete'))->toBe('off')
        ->and($input->getAttribute('id'))->toBe('passkey-name');
});

function formControl(string $html, string $name): DOMElement
{
    if ($html === '' || $name === '') {
        Assert::fail('HTML and form control name must not be empty.');
    }

    $node = new Crawler($html)->filter(sprintf('[name="%s"]', $name))->getNode(0);
    Assert::assertInstanceOf(DOMElement::class, $node);

    return $node;
}

function expectOneTimeCodeOnAnInput(string $html): void
{
    expect(formControl($html, 'code')->getAttribute('autocomplete'))->toBe('one-time-code');

    $input = new Crawler($html)->filter('input[autocomplete="one-time-code"]')->getNode(0);
    Assert::assertInstanceOf(DOMElement::class, $input);
}

function expectPasswordManagerUsername(string $html, string $email): void
{
    $username = formControl($html, 'username');

    Assert::assertSame('email', $username->getAttribute('type'));
    Assert::assertSame('username', $username->getAttribute('autocomplete'));
    Assert::assertSame($email, $username->getAttribute('value'));
    Assert::assertSame('username', $username->getAttribute('id'));
    Assert::assertTrue($username->hasAttribute('readonly'));
    Assert::assertFalse($username->hasAttribute('disabled'));
    Assert::assertFalse($username->hasAttribute('aria-hidden'));
    Assert::assertStringNotContainsString('sr-only', $username->getAttribute('class'));

    $labelTexts = new Crawler($html)->filter('ui-label, label')->each(
        static fn (Crawler $node): string => trim($node->text()),
    );

    Assert::assertTrue(
        array_any($labelTexts, fn (string $text): bool => str_contains($text, 'Email')),
        '1Password needs a visible labelled username field in the same form as the password.',
    );
}
