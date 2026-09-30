<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\HiddenShop;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The shops a person chose never to have suggested, each with "Show again".
 */
final class HiddenShops extends Component
{
    public function showAgain(string $host): void
    {
        HiddenShop::showAgain($this->user(), $host);
    }

    public function render(): View
    {
        return view('livewire.settings.hidden-shops', [
            'shops' => $this->user()->hiddenShops()->orderBy('label')->get(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
