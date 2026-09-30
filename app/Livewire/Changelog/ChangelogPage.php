<?php declare(strict_types=1);

namespace App\Livewire\Changelog;

use App\Support\Changelog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "What's new": the features, shops, Pro features and fixes that shipped,
 * newest first. The entries live in `config/changelog.php`.
 */
#[Title("What's new")]
final class ChangelogPage extends Component
{
    public function render(): View
    {
        return view('livewire.changelog.changelog-page', [
            'days' => Changelog::byDate(),
        ]);
    }
}
