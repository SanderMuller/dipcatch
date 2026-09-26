<?php declare(strict_types=1);

use App\Billing\BillingInterval;
use App\Billing\ProPrice;
use App\Filament\Admin\Widgets\RevenueOverviewWidget;
use App\Livewire\Billing\BillingPage;
use App\Models\User;
use App\Support\StructuredData;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

use function Pest\Livewire\livewire;

/**
 * A Stripe API that answers every call and records what was sent. An open
 * `cs_monthly` session exists; every new session is `cs_new`.
 */
final class RecordingStripeApi implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<array-key, mixed>}> */
    public array $calls = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
        $this->calls[] = ['method' => (string) $method, 'path' => $path, 'params' => is_array($params) ? $params : []];

        $body = match (true) {
            str_starts_with($path, '/v1/customers') => ['id' => 'cus_1', 'object' => 'customer'],
            $path === '/v1/checkout/sessions/cs_monthly' => ['id' => 'cs_monthly', 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.stripe.test/cs_monthly'],
            str_ends_with($path, '/expire') => ['id' => 'cs_monthly', 'object' => 'checkout.session', 'status' => 'expired'],
            default => ['id' => 'cs_new', 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.stripe.test/cs_new'],
        };

        return [(string) json_encode($body), 200, []];
    }
}

afterEach(function (): void {
    ApiRequestor::setHttpClient(new CurlClient());
});

/**
 * Pro sells monthly, and yearly once its own Stripe Price exists. The yearly
 * Price is optional: without it nothing about the monthly sale may change.
 */
beforeEach(function (): void {
    configureStripe();
    config()->set('plans.stripe.pro_amount', '4.99');
    config()->set('plans.stripe.pro_yearly_amount', '39.00');
});

it('sells the yearly Price when it exists, and the monthly one when it does not', function (): void {
    expect(ProPrice::priceIdFor(BillingInterval::Yearly))->toBe('price_1');

    config()->set('plans.stripe.pro_yearly_price_id', 'price_year');

    expect(ProPrice::priceIdFor(BillingInterval::Yearly))->toBe('price_year')
        ->and(ProPrice::priceIdFor(BillingInterval::Monthly))->toBe('price_1');
});

it('carries the yearly choice from the upgrade link to the checkout', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('upgrade', ['interval' => 'yearly']))
        ->assertRedirect(route('billing.checkout', ['interval' => 'yearly']));

    $this->get('/upgrade/weekly')->assertNotFound();
});

it('keeps a guest yearly choice through signup', function (): void {
    $this->get(route('upgrade', ['interval' => 'yearly']))->assertRedirect(route('register'));

    expect(session('url.intended'))->toBe(route('upgrade', ['interval' => 'yearly']));
});

it('offers the yearly price beside the monthly one on the billing page and the pricing page', function (): void {
    config()->set('plans.stripe.pro_yearly_price_id', 'price_year');
    $this->actingAs(User::factory()->create());

    livewire(BillingPage::class)
        ->assertSee('€4.99 a month')
        ->assertSee('€39.00 a year')
        ->assertSee(route('billing.checkout', ['interval' => 'yearly']));

    $this->get(route('pricing'))
        ->assertOk()
        ->assertSee('or €39.00 a year')
        ->assertSee('Pay yearly instead: €39.00');

    expect(collect(StructuredData::offers())->firstWhere('name', 'Pro, yearly')['priceSpecification']['unitCode'] ?? null)->toBe('ANN');
});

it('shows no yearly option while there is no yearly Price', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(BillingPage::class)->assertDontSee('a year');
    $this->get(route('pricing'))->assertOk()->assertDontSee('Pay yearly instead');

    expect(collect(StructuredData::offers())->pluck('name'))->not->toContain('Pro, yearly');
});

it('says per year for a yearly subscriber', function (): void {
    config()->set('plans.stripe.pro_yearly_price_id', 'price_pro');
    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user);

    livewire(BillingPage::class)->assertSee('€39.00 per year')->assertDontSee('per month');
});

it('counts a yearly subscription at a twelfth of its price in MRR', function (): void {
    config()->set('plans.stripe.pro_yearly_price_id', 'price_year');

    subscribeUser(User::factory()->create());
    subscribeUser(User::factory()->create())->forceFill(['stripe_price' => 'price_year'])->save();

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel('admin');

    // 4.99 + 39.00 / 12 = 8.24.
    livewire(RevenueOverviewWidget::class)->assertSee('8.24');
});

it('charges the yearly Price for a yearly checkout, also after ending an open monthly session', function (): void {
    config()->set('plans.stripe.pro_yearly_price_id', 'price_year');
    $api = new RecordingStripeApi();
    ApiRequestor::setHttpClient($api);

    $user = User::factory()->create(['stripe_id' => 'cus_1', 'stripe_checkout_session_id' => 'cs_monthly']);
    Cache::put('billing:checkout-interval:' . $user->id, 'monthly');

    $this->actingAs($user)
        ->get(route('billing.checkout', ['interval' => 'yearly']))
        ->assertRedirect('https://checkout.stripe.test/cs_new');

    $created = collect($api->calls)->firstWhere('path', '/v1/checkout/sessions');

    expect(collect($api->calls)->pluck('path'))->toContain('/v1/checkout/sessions/cs_monthly/expire')
        ->and($created['params']['line_items'][0]['price'] ?? null)->toBe('price_year')
        ->and($user->fresh()?->stripe_checkout_session_id)->toBe('cs_new');
});
