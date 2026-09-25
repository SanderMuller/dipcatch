<?php declare(strict_types=1);

use App\Actions\Shops\KeepShopAsLink;
use App\Jobs\SendDailyDigest;
use App\Mail\PriceDropDigestMail;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

test('a zero lookback still sends a digest rather than going silent forever', function (): void {
    // A 0 would make the window `fired_at > $now AND fired_at <= $now`, which
    // no event can satisfy: no mail, no cursor movement, no error, for good.
    config()->set('dipcatch.digest.lookback_days', 0);
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

    Mail::assertSent(PriceDropDigestMail::class, fn (PriceDropDigestMail $mail): bool => $mail->totalDrops === 1);
});

test('the cursor is the instant the window ended, not a later clock read', function (): void {
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

    // A clock that advances one second per read. Under the frozen clock the
    // rest of this file uses, a job reading `now()` once and a job reading
    // it three times stamp the same value, so neither test can tell them
    // apart. Every instant is built up front: calling a Carbon constructor
    // inside the closure re-enters it and hangs the run.
    $base = CarbonImmutable::parse('2026-01-15 09:30:00', 'UTC');
    $reads = [$base, $base->addSeconds(), $base->addSeconds(2), $base->addSeconds(3)];
    Date::setTestNow(function () use (&$reads): CarbonImmutable {
        return count($reads) > 1 ? array_shift($reads) : $reads[0];
    });

    new SendDailyDigest($user, '2026-01-15')->handle();

    // The first read, because one read now serves both the window's end and
    // the cursor. Three separate reads would stamp 09:30:02 here.
    expect($user->fresh()->last_digest_sent_at?->toIso8601String())
        ->toBe('2026-01-15T09:30:00+00:00');
});

test('an event fired in the same second as the window end is still mailed', function (): void {
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
    // Both columns hold whole seconds, so this is the live boundary rather
    // than a corner: every drop that fires in the run's own second lands
    // here. Narrowing the bound to `<` would drop it from the mail and then
    // bury it under the cursor, which is the loss the change set out to fix.
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, fn (PriceDropDigestMail $mail): bool => $mail->totalDrops === 2);
});

test('an event fired after the window stays above the cursor and arrives next run', function (): void {
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
    // A drop stamped in a later second than the run that is selecting now.
    // The bound must leave it above the cursor so the next run picks it up.
    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['fired_at' => now()->addSeconds(30)])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, fn (PriceDropDigestMail $mail): bool => $mail->totalDrops === 1);

    Date::setTestNow(CarbonImmutable::create(2026, 1, 15, 10, 30, 0, 'UTC'));
    $user->refresh();
    new SendDailyDigest($user, '2026-01-15')->handle();

    // Exactly the held-back event, not a re-send of the first one.
    Mail::assertSent(PriceDropDigestMail::class, 2);
    Mail::assertSent(
        PriceDropDigestMail::class,
        fn (PriceDropDigestMail $mail): bool => $mail->totalDrops === 1
            && $mail->grouped->flatMap(fn (array $group): mixed => $group['events'])
                ->every(fn (PriceDropEvent $event): bool => $event->fired_at?->second === 30),
    );
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
            $product->id => ['product' => $product, 'events' => collect([$event]), 'reached' => collect()],
        ]),
        'heading' => '1 price drop today',
        'totalDrops' => 1,
        'totalReached' => 0,
        'user' => $user,
    ]);

    expect($html)->toContain('€1.69')
        ->and($html)->toContain('€0.30')
        ->and($html)->not->toContain('EUR 1.69')
        ->and($html)->toContain('15.0%');
});

test('digest reads bundle terms from protected triggering check', function (): void {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create();
    $check = PriceCheck::factory()->for($shop)->create([
        'price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);
    $event = PriceDropEvent::factory()->for($user)->for($product)->create([
        'price_check_id' => $check->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '2.00',
    ]);

    $html = (string) app(Markdown::class)->render('emails.price-drop-digest', [
        'grouped' => collect([
            $product->id => ['product' => $product, 'events' => collect([$event]), 'reached' => collect()],
        ]),
        'heading' => '1 price drop today',
        'totalDrops' => 1,
        'totalReached' => 0,
        'user' => $user,
    ]);

    expect($html)->toContain('2 for €4.00')
        ->and($html)->toContain('Deal')
        ->and($html)->toContain('Normal price:')
        ->and($html)->toContain('€2.85')
        ->and($html)->toContain('<del title="Regular price"')
        ->and($html)->not->toContain('or €2.85 each');
});

test('digest eager loads every triggering price check', function (): void {
    $user = User::factory()->create(['last_digest_sent_at' => null]);
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create();

    PriceCheck::factory()->count(3)->for($shop)->create()->each(function (PriceCheck $check) use ($user, $product, $shop): void {
        PriceDropEvent::factory()->for($user)->for($product)->create([
            'price_check_id' => $check->id,
            'triggered_by_shop_id' => $shop->id,
            'fired_at' => now()->subHour(),
        ]);
    });

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, function (PriceDropDigestMail $mail): bool {
        return $mail->grouped
            ->flatMap(fn (array $group): mixed => $group['events'])
            ->every(fn (PriceDropEvent $event): bool => $event->relationLoaded('priceCheck'));
    });
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

test('the digest leaves out an image whose stored url is not http(s)', function (): void {
    Mail::swap(app('mail.manager'));

    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create([
        'title' => 'Digest product',
        'image_url' => 'ftp://example.com/img.png',
    ]);
    $shop = Shop::factory()->for($product)->create();
    $events = PriceDropEvent::factory()->count(1)->for($user)->create([
        'product_id' => $product->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '1.69',
        'drop_abs' => '0.50',
        'drop_pct' => '22.8',
    ]);

    $html = new PriceDropDigestMail($user, PriceDropEvent::query()->whereKey($events->modelKeys())->get())->render();

    expect($html)->toContain('Digest product')
        ->and($html)->not->toContain('ftp://example.com/img.png');
});

test('the digest states the change per unit and omits money it cannot honestly report', function (): void {
    Mail::swap(app('mail.manager'));

    // A drop measured across two pack sizes. There is no money figure — the
    // pack difference would be a saving nobody made — so `drop_abs` is null and
    // the line says what fell and by how much per kilo instead.
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Digest product']);
    $shop = Shop::factory()->for($product)->create();
    $events = PriceDropEvent::factory()->count(1)->for($user)->create([
        'product_id' => $product->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '8.00',
        'reference_price' => null,
        'drop_abs' => null,
        'drop_pct' => '20.0',
        'reference_unit_price' => '10.00',
        'new_unit_price' => '8.00',
        'comparison_unit' => 'g',
    ]);

    $html = new PriceDropDigestMail($user, PriceDropEvent::query()->whereKey($events->modelKeys())->get())->render();

    expect($html)->toContain('Digest product')
        ->and($html)->toContain('20.0% per kilo')
        ->and($html)->toContain('€8.00 /kg')
        ->and($html)->toContain('was €10.00 /kg')
        // The till price of the winning pack follows on the second line; this
        // event was written before the pack size was stored, so it has none.
        ->and($html)->not->toContain('€8.00 for');
});

test('the digest leads with the unit price and names the pack it was measured on', function (): void {
    Mail::swap(app('mail.manager'));

    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Tablets']);
    $shop = Shop::factory()->for($product)->create();
    $events = PriceDropEvent::factory()->count(1)->for($user)->create([
        'product_id' => $product->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '21.99',
        'reference_price' => '25.99',
        'drop_abs' => '4.00',
        'drop_pct' => '15.4',
        'reference_unit_price' => '0.0325',
        'new_unit_price' => '0.0275',
        'comparison_unit' => 'piece',
        'pack_quantity' => '800.00',
        'pack_unit' => 'piece',
    ]);

    $html = (string) preg_replace('/\s+/', ' ', strip_tags(new PriceDropDigestMail($user, PriceDropEvent::query()->whereKey($events->modelKeys())->get())->render()));

    expect($html)->toMatch('/€0\.0275 \/piece ↓ 15\.4% per piece · €4\.00 €21\.99 for 800 pieces · was €0\.0325 \/piece/');
});

test('a pack-basis drop keeps the pack price as the lead', function (): void {
    Mail::swap(app('mail.manager'));

    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Camera']);
    $shop = Shop::factory()->for($product)->create();
    $events = PriceDropEvent::factory()->count(1)->for($user)->create([
        'product_id' => $product->id,
        'triggered_by_shop_id' => $shop->id,
        'currency' => 'EUR',
        'new_price' => '299.00',
        'reference_price' => '349.00',
        'drop_abs' => '50.00',
        'drop_pct' => '14.3',
        'comparison_unit' => null,
    ]);

    $html = (string) preg_replace('/\s+/', ' ', strip_tags(new PriceDropDigestMail($user, PriceDropEvent::query()->whereKey($events->modelKeys())->get())->render()));

    expect($html)->toContain('€299.00 ↓ 14.3% · €50.00')->not->toContain('/kg');
});

it('names the shops it cannot read beside the drops it can', function (): void {
    // The digest is the other moment somebody is about to buy.
    $user = User::factory()->create(['notify_via_email' => true, 'timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create();

    app(KeepShopAsLink::class)($product, 'https://www.koffiehenk.nl/dolce-gusto-lungo-xl');

    PriceDropEvent::factory()
        ->for($user)
        ->for($product)
        ->state(['triggered_by_shop_id' => $shop->id, 'fired_at' => now()->subHours(3)])
        ->create();

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, function (PriceDropDigestMail $mail): bool {
        return str_contains($mail->render(), 'Also worth checking by hand: koffiehenk.nl');
    });
});

test('a reached target alone sends the digest, with the price, the target and the deal', function (): void {
    $user = User::factory()->create(['notify_via_email' => true, 'timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Sanimed Skin Sensitive']);
    $shop = Shop::factory()->for($product)->create(['url' => 'https://www.dierenapotheek.nl/p/1']);
    TargetPriceEvent::factory()->for($user)->for($product)->create([
        'shop_id' => $shop->id,
        'price' => '17.05',
        'target' => '18.00',
        'deal' => '2 for €34.10',
        'fired_at' => now()->subHours(2),
    ]);

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, function (PriceDropDigestMail $mail): bool {
        $text = (string) preg_replace('/\s+/', ' ', strip_tags($mail->render()));

        return $mail->envelope()->subject === '1 price alert today'
            && $mail->totalDrops === 0
            && str_contains($text, 'Sanimed Skin Sensitive')
            && str_contains($text, 'dierenapotheek.nl')
            && str_contains($text, '€17.05 Reached your price')
            && str_contains($text, 'your price €18.00')
            && str_contains($text, '2 for €34.10');
    });
    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
});

test('a reached unit-price target leads with the price per unit and names the pack', function (): void {
    Mail::swap(app('mail.manager'));

    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $product = Product::factory()->for($user)->create(['title' => 'Lay’s Naturel']);
    $reached = TargetPriceEvent::factory()->unitPrice()->count(1)->for($user)->for($product)->create();

    $text = (string) preg_replace('/\s+/', ' ', strip_tags(new PriceDropDigestMail($user, new EloquentCollection(), TargetPriceEvent::query()->whereKey($reached->modelKeys())->get())->render()));

    expect($text)->toContain('€5.38 /kg Reached your price')
        ->and($text)->toContain('€1.29 for 240 g')
        ->and($text)->toContain('your price €6.00 /kg');
});

test('drops and reached targets share one mail, grouped per product, counted together', function (): void {
    $user = User::factory()->create(['notify_via_email' => true, 'last_digest_sent_at' => null]);
    $both = Product::factory()->for($user)->create();
    $dropOnly = Product::factory()->for($user)->create();

    PriceDropEvent::factory()->for($user)->for($both)->state(['fired_at' => now()->subHours(3)])->create();
    PriceDropEvent::factory()->for($user)->for($dropOnly)->state(['fired_at' => now()->subHours(2)])->create();
    TargetPriceEvent::factory()->for($user)->for($both)->create(['fired_at' => now()->subHour()]);
    // Before the window: sent in an earlier digest.
    TargetPriceEvent::factory()->for($user)->for($dropOnly)->create(['fired_at' => now()->subDays(2)]);

    new SendDailyDigest($user, '2026-01-15')->handle();

    Mail::assertSent(PriceDropDigestMail::class, function (PriceDropDigestMail $mail) use ($both, $dropOnly): bool {
        $bothGroup = $mail->grouped->get($both->id);
        $dropOnlyGroup = $mail->grouped->get($dropOnly->id);

        return $mail->envelope()->subject === '3 price alerts today'
            && $mail->grouped->count() === 2
            && $bothGroup !== null && $dropOnlyGroup !== null
            && $bothGroup['events']->count() === 1
            && $bothGroup['reached']->count() === 1
            && $dropOnlyGroup['reached']->isEmpty();
    });
});
