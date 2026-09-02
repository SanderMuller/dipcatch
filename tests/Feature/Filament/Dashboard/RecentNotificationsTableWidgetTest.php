<?php declare(strict_types=1);

use App\Filament\App\Widgets\RecentNotificationsTableWidget;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

function seedDropNotification(User $user, Product $product, array $overrides = []): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => array_merge([
            'product_id' => $product->id,
            'title' => $product->title,
            'currency' => 'EUR',
            'drop_percent' => '12.5',
            'drop_absolute' => '15.00',
        ], $overrides),
        'read_at' => null,
    ]);
}

test('lists current user s drop notifications, newest first', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create(['title' => 'Mech Keyboard']);

    $older = seedDropNotification($me, $product, ['title' => 'Mech Keyboard old']);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = seedDropNotification($me, $product, ['title' => 'Mech Keyboard new']);

    $this->actingAs($me);

    livewire(RecentNotificationsTableWidget::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSeeText('Mech Keyboard new')
        ->assertSeeText('Mech Keyboard old');
});

test('does not leak another user s notifications', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $theirProduct = Product::factory()->for($other)->create();

    $theirs = seedDropNotification($other, $theirProduct, ['title' => 'Their secret product']);

    $this->actingAs($me);

    livewire(RecentNotificationsTableWidget::class)
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSeeText('Their secret product');
});

test('ignores notifications of other types', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create();

    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SomeOtherNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $me->id,
        'data' => ['title' => 'Should not appear'],
        'read_at' => null,
    ]);

    $included = seedDropNotification($me, $product, ['title' => 'Real drop alert']);

    $this->actingAs($me);

    livewire(RecentNotificationsTableWidget::class)
        ->assertCanSeeTableRecords([$included])
        ->assertSeeText('Real drop alert')
        ->assertDontSeeText('Should not appear');
});

test('renders the empty state when there are no alerts', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(RecentNotificationsTableWidget::class)
        ->assertSeeText('No alerts yet');
});

test('renders the absolute drop symbol-first, as a positive amount', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create();

    seedDropNotification($me, $product, ['currency' => 'EUR', 'drop_absolute' => '-15.00']);

    $this->actingAs($me);

    livewire(RecentNotificationsTableWidget::class)
        ->assertSeeText('€15.00')
        ->assertDontSeeText('EUR 15.00')
        ->assertDontSeeText('-€15.00');
});
