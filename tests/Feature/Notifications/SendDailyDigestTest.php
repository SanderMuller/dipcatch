<?php declare(strict_types=1);

use App\Jobs\SendDailyDigest;
use App\Mail\PriceDropDigestMail;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    Date::setTestNow(CarbonImmutable::create(2026, 1, 15, 9, 30, 0, 'UTC'));
});

afterEach(function (): void {
    Date::setTestNow();
});

test('empty window does not send mail and does not update last_digest_sent_at', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'last_digest_sent_at' => null,
    ]);

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertNothingSent();
    expect($user->fresh()->last_digest_sent_at)->toBeNull();
});

test('sends one mail grouping drops by product and updates last_digest_sent_at', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'last_digest_sent_at' => null,
    ]);
    $product1 = Product::factory()->for($user)->create();
    $product2 = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product1)->create();

    PriceDropEvent::factory()
        ->count(2)
        ->for($user)
        ->for($product1)
        ->state(['triggered_by_shop_id' => $shop->id, 'fired_at' => now()->subHours(3)])
        ->create();
    PriceDropEvent::factory()
        ->for($user)
        ->for($product2)
        ->state(['fired_at' => now()->subHour()])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, function (PriceDropDigestMail $mail) use ($user): bool {
        return $mail->hasTo($user->email)
            && $mail->grouped->count() === 2
            && $mail->totalDrops === 3;
    });
    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
});

test('only includes events since last_digest_sent_at', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'last_digest_sent_at' => now()->subHours(2),
    ]);
    $product = Product::factory()->for($user)->create();

    // Old event — already included in a prior digest.
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->subHours(5)])
        ->create();
    // Recent event — should be in this digest.
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->subHour()])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, fn (PriceDropDigestMail $mail): bool => $mail->totalDrops === 1);
});

test('caps the lookback at configured days even with stale last_digest_sent_at', function (): void {
    config()->set('dipcatch.digest.lookback_days', 3);
    $user = User::factory()->create([
        'notify_via_email' => true,
        // Bounced for two weeks — would otherwise pull a huge backlog.
        'last_digest_sent_at' => now()->subDays(14),
    ]);
    $product = Product::factory()->for($user)->create();

    // Outside the 3-day lookback.
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->subDays(5)])
        ->create();
    // Inside the 3-day lookback.
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->subDays()])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, fn (PriceDropDigestMail $mail): bool => $mail->totalDrops === 1);
});

test('claims the cursor before sending so a mail failure does not double-send on retry', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'last_digest_sent_at' => null,
    ]);
    $product = Product::factory()->for($user)->create();
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->subHour()])
        ->create();

    // Make Mail::send throw to simulate a transient transport failure.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP timeout'));

    try {
        new SendDailyDigest($user, '2026-01-15')->handle();
    } catch (RuntimeException) {
        // Expected.
    }

    // Cursor advanced even though send failed — second attempt (retry) sees
    // an empty window and won't double-send.
    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
});

test('second run within the same digest window sends no new mail', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'last_digest_sent_at' => null,
    ]);
    $product = Product::factory()->for($user)->create();
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->subHour()])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();
    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, 1);
});

test('the digest table renders money as symbol-first, not the ISO code', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'last_digest_sent_at' => null,
        'timezone' => 'UTC',
    ]);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create();

    $event = PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->create([
            'triggered_by_shop_id' => $shop->id,
            'fired_at' => now()->subHours(3),
            'currency' => 'EUR',
            'new_price' => '1.69',
            'drop_abs' => '0.30',
            'drop_pct' => '15.0000',
        ]);

    // The digest template is rendered through the markdown renderer, which is
    // what registers the `x-mail::` component namespace.
    $html = (string) app(Markdown::class)->render('emails.price-drop-digest', [
        'grouped' => collect([
            $product->id => ['product' => $product, 'events' => collect([$event])],
        ]),
        'totalDrops' => 1,
        'user' => $user,
    ]);

    expect($html)->toContain('€1.69')
        ->and($html)->toContain('€0.30')
        ->and($html)->not->toContain('EUR 1.69')
        ->and($html)->toContain('15.0%');
});

test('the digest mailable renders end to end without the mail fake', function (): void {
    Mail::swap(app('mail.manager'));

    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Digest product']);
    $shop = Shop::factory()->for($product)->create();
    $events = PriceDropEvent::factory()->count(1)->for($user)->create([
        'product_id' => $product->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '1.69',
        'drop_abs' => '0.50',
        'drop_pct' => '22.8',
    ]);

    // Regression: `Content(view:)` rendered the <x-mail::message> template
    // outside the Markdown renderer, which throws "No hint path defined
    // for [mail]" — invisible under Mail::fake().
    $html = new PriceDropDigestMail($user, PriceDropEvent::query()->whereKey($events->modelKeys())->get())->render();

    expect($html)->toContain('Digest product')->toContain('€1.69');
});
