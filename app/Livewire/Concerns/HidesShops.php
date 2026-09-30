<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Suggestions\SuggestShops;
use App\Models\HiddenShop;
use App\Models\User;
use App\Support\UrlNormalizer;
use Flux\Flux;
use Livewire\Attributes\On;

/**
 * "Don't suggest {shop}" on a list of suggested shops, with Undo in the
 * toast. It hides suggestions only; shops the person tracks keep working.
 */
trait HidesShops
{
    public function hideShop(string $host, SuggestShops $suggest): void
    {
        $host = trim($host);
        abort_if(filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || ! str_contains($host, '.'), 422);
        $host = UrlNormalizer::normalizeHost($host);

        $label = HiddenShop::hide($this->shopHider(), $host);
        $suggest->forgetSuggestions();

        // The product page shows the suggestions twice; both must drop the shop.
        $this->dispatch('shop-suggestions-changed');

        Flux::toast(
            text: __(':shop won’t be suggested again.', ['shop' => $label]),
            action: ['label' => __('Undo'), 'event' => 'show-shop-again', 'params' => ['host' => $host]],
        );
    }

    /** The toast's Undo dispatches this. */
    #[On('show-shop-again')]
    public function showShopAgain(string $host, SuggestShops $suggest): void
    {
        HiddenShop::showAgain($this->shopHider(), $host);
        $suggest->forgetSuggestions();
    }

    private function shopHider(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
