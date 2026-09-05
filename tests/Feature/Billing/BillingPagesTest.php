<?php declare(strict_types=1);

use App\Filament\Admin\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Admin\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\App\Pages\Billing;
use App\Models\Product;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('shows a free account its usage and the upgrade route', function (): void {
    config()->set('plans.stripe.pro_price_id', 'price_test');

    $user = User::factory()->create();
    Product::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(Billing::class)
        ->assertSee('Free')
        ->assertSee('3')
        ->assertSee('Start 14-day trial');
});

it('offers a pro account the stripe portal instead of checkout', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(Billing::class)
        ->assertSee('Manage subscription')
        ->assertDontSee('Upgrade to Pro');
});

it('warns a past due account without threatening its data', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'past_due');

    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(Billing::class)
        ->assertSee('Your last payment did not go through')
        ->assertSee('Your tracked products keep working either way.');
});

it('says why pro is off after a lost chargeback', function (): void {
    $user = User::factory()->create(['billing_blocked_at' => now()]);
    subscribeUser($user);

    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(Billing::class)->assertSee('Pro is paused after a chargeback');
});

it('serves the public pricing page to a guest', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('Unlimited products')
        ->assertSee('20 products');
});

it('links to the pricing page from the marketing site', function (): void {
    $this->get('/')->assertOk()->assertSee(route('pricing'));
});

it('shows the pricing page in Dutch', function (): void {
    $this->get('/pricing?lang=nl')
        ->assertOk()
        ->assertSee('Onbeperkt producten')
        ->assertSee('20 producten');
});

it('keeps the admin subscriber list away from a normal user', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/subscribers')
        ->assertForbidden();
});

it('keeps the chargeback queue away from a normal user', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/disputes')
        ->assertForbidden();
});

it('shows the owner each subscriber, their revenue and their disputes', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $customer = User::factory()->create(['stripe_id' => 'cus_1']);
    subscribeUser($customer);
    StripePayment::factory()->create(['user_id' => $customer->id, 'amount' => 499]);
    StripePayment::factory()->refund()->create(['user_id' => $customer->id, 'amount' => -100]);
    StripeDispute::factory()->create(['user_id' => $customer->id]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    livewire(ListSubscribers::class)
        ->assertCanSeeTableRecords([$customer])
        ->assertSee('Pro')
        // 499 paid minus a 100 refund, in euros.
        ->assertSee('3.99');
});

it('lists open chargebacks and hides closed ones by default', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $open = StripeDispute::factory()->create();
    $closed = StripeDispute::factory()->lost()->create();

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    livewire(ListDisputes::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed]);
});
