<?php declare(strict_types=1);

use App\Livewire\AppCommandPalette;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

it('lists app pages and this accounts recent products', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    Product::factory()->create(['title' => 'Someone elses coffee']);

    $this->actingAs($user);

    livewire(AppCommandPalette::class)
        ->assertSee('Dashboard')
        ->assertSee('Arabica beans')
        ->assertDontSee('Someone elses coffee');
});
