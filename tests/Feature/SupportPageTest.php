<?php declare(strict_types=1);

use App\Enums\SupportRequestType;
use App\Livewire\Support\SupportPage;
use App\Mail\SupportRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

test('guests cannot open the support form', function (): void {
    $this->get('/app/support')
        ->assertRedirect(route('login'));
});

test('verified users can open the support form', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/app/support')
        ->assertOk()
        ->assertSee('How can we help?')
        ->assertSee('Report a shop problem');
});

test('shop support links prefill the request type and product URL', function (): void {
    $user = User::factory()->create();
    $shopUrl = 'https://shop.example.test/products/coffee';

    Livewire::withQueryParams([
        'type' => SupportRequestType::ShopIssue->value,
        'shop_url' => $shopUrl,
    ])
        ->actingAs($user)
        ->test(SupportPage::class)
        ->assertSet('requestType', SupportRequestType::ShopIssue->value)
        ->assertSet('shopUrl', $shopUrl);
});

test('shop request links use the support form for signed-in users', function (): void {
    $user = User::factory()->create();
    $shopUrl = 'https://shop.example.test/products/coffee';

    $this->actingAs($user);

    $html = Blade::render('<x-shop-request-link :url="$url" />', ['url' => $shopUrl]);

    expect($html)->toContain(e(route('app.support', [
        'type' => SupportRequestType::ShopRequest->value,
        'shop_url' => $shopUrl,
    ])));
});

test('shop requests and shop problems require a valid shop URL', function (SupportRequestType $requestType): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', $requestType->value)
        ->set('message', 'Please take a look at this shop.')
        ->call('submit')
        ->assertHasErrors(['shopUrl' => 'required_if'])
        ->set('shopUrl', 'not a URL')
        ->call('submit')
        ->assertHasErrors(['shopUrl' => 'url'])
        ->set('shopUrl', 'ftp://shop.example.test/product')
        ->call('submit')
        ->assertHasErrors(['shopUrl' => 'url']);
})->with([
    'shop request' => SupportRequestType::ShopRequest,
    'shop problem' => SupportRequestType::ShopIssue,
]);

test('feedback does not require a shop URL', function (): void {
    Mail::fake();
    config()->set('site.contact_email', 'support@dipcatch.test');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', SupportRequestType::Feedback->value)
        ->set('message', 'The new product page is much easier to scan.')
        ->call('submit')
        ->assertHasNoErrors();

    Mail::assertSent(SupportRequestMail::class, 1);
});

test('switching away from a shop request clears the hidden shop URL', function (): void {
    Mail::fake();
    config()->set('site.contact_email', 'support@dipcatch.test');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', SupportRequestType::ShopIssue->value)
        ->set('shopUrl', 'not a URL')
        ->set('requestType', SupportRequestType::Feedback->value)
        ->assertSet('shopUrl', '')
        ->set('message', 'The dashboard is easy to understand now.')
        ->call('submit')
        ->assertHasNoErrors();

    Mail::assertSent(SupportRequestMail::class, 1);
});

test('submitting a support request emails DipCatch with user and request details', function (): void {
    Mail::fake();
    config()->set('site.contact_email', 'support@dipcatch.test');
    $user = User::factory()->create([
        'name' => 'Robin Example',
        'email' => 'robin@example.test',
    ]);

    Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', SupportRequestType::ShopIssue->value)
        ->set('shopUrl', 'https://www.example-shop.test/products/coffee')
        ->set('message', 'DipCatch shows the old price for this product.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true)
        ->assertSet('shopUrl', '')
        ->assertSet('message', '');

    Mail::assertSent(SupportRequestMail::class, function (SupportRequestMail $mail) use ($user): bool {
        return $mail->hasTo('support@dipcatch.test')
            && $mail->hasReplyTo($user->email, $user->name)
            && $mail->requestType === SupportRequestType::ShopIssue
            && $mail->shopUrl === 'https://www.example-shop.test/products/coffee'
            && $mail->message === 'DipCatch shows the old price for this product.'
            && $mail->user->is($user);
    });
});

test('support request email renders user and shop details', function (): void {
    $user = User::factory()->create([
        'name' => 'Robin Example',
        'email' => 'robin@example.test',
    ]);

    $mail = new SupportRequestMail(
        user: $user,
        requestType: SupportRequestType::ShopRequest,
        message: 'Please add this shop.',
        shopUrl: 'https://shop.example.test/product/123',
    );

    $mail->assertSeeInHtml('Request a shop')
        ->assertSeeInHtml('Robin Example')
        ->assertSeeInHtml('robin@example.test')
        ->assertSeeInHtml('https://shop.example.test/product/123')
        ->assertSeeInText('Please add this shop.');
});

test('a user can send no more than five support messages per hour', function (): void {
    Mail::fake();
    config()->set('site.contact_email', 'support@dipcatch.test');
    $user = User::factory()->create();
    $component = Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', SupportRequestType::Feedback->value);

    for ($messageNumber = 1; $messageNumber <= 5; $messageNumber++) {
        $component
            ->set('message', "This is feedback message number {$messageNumber}.")
            ->call('submit')
            ->assertHasNoErrors();
    }

    $component
        ->set('message', 'This sixth message should be rate limited.')
        ->call('submit')
        ->assertHasErrors(['form']);

    Mail::assertSent(SupportRequestMail::class, 5);
});

test('submission uses the admin email when the contact email is missing', function (): void {
    Mail::fake();
    config()->set('site.contact_email');
    config()->set('dipcatch.admin.email', 'owner@dipcatch.test');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', SupportRequestType::Support->value)
        ->set('message', 'I need help changing a notification setting.')
        ->call('submit')
        ->assertHasNoErrors();

    Mail::assertSent(SupportRequestMail::class, fn (SupportRequestMail $mail): bool => $mail->hasTo('owner@dipcatch.test'));
});

test('submission shows an error and keeps the message when no recipient is configured', function (): void {
    Mail::fake();
    config()->set('site.contact_email');
    config()->set('dipcatch.admin.email');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SupportPage::class)
        ->set('requestType', SupportRequestType::Support->value)
        ->set('message', 'Please help me update my account email.')
        ->call('submit')
        ->assertHasErrors(['form'])
        ->assertSet('submitted', false)
        ->assertSet('message', 'Please help me update my account email.');

    Mail::assertNothingSent();
    expect(RateLimiter::attempts('support-request:user:' . $user->id))->toBe(0);
});
