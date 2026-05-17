<?php declare(strict_types=1);

use App\Filament\Admin\Resources\Shops\Pages\ListShops;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

test('admin shops list exposes a notes column showing the value', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $shop = Shop::factory()->create(['notes' => 'Ships only to NL']);

    livewire(ListShops::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$shop])
        ->assertTableColumnExists('notes');
});

test('the notes column renders the saved text inline', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Shop::factory()->create(['notes' => 'Ships only to NL']);

    livewire(ListShops::class)
        ->assertOk()
        ->assertSee('Ships only to NL');
});
