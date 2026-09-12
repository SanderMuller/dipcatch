<?php declare(strict_types=1);

namespace App\Livewire;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

final class AppCommandPalette extends Component
{
    public function render(): View
    {
        return view('livewire.app-command-palette', [
            'products' => $this->products(),
        ]);
    }

    /**
     * @return EloquentCollection<int, Product>
     */
    private function products(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection();
        }

        return Product::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(8)
            ->get();
    }
}
