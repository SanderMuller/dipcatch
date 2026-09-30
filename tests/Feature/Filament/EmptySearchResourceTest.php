<?php declare(strict_types=1);

use App\Filament\Admin\Resources\EmptySearches\Pages\ListEmptySearches;
use App\Models\EmptySearch;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('lists the searches without results for an admin, most recent first', function (): void {
    $searcher = User::factory()->create(['email' => 'searcher@example.test']);
    EmptySearch::record($searcher, 'stroopwafel');
    EmptySearch::record($searcher, 'stroopwafel');
    EmptySearch::query()->update(['last_searched_at' => now()->subDay()]);
    EmptySearch::record($searcher, 'hagelslag');

    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ListEmptySearches::class)
        ->assertCanSeeTableRecords(EmptySearch::query()->latest('last_searched_at')->get(), inOrder: true)
        ->assertSee('searcher@example.test');
});

it('keeps the admin list from anyone who is not an admin', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/empty-searches')->assertForbidden();
});
