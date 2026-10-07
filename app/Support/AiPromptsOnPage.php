<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\AiFeature;
use Illuminate\Container\Attributes\Scoped;

/**
 * The AI features a prompt already claimed in this request, so a page with
 * several places for one feature offers it once. Scoped: a later Livewire
 * request (a lazy component, a form opened later) starts empty.
 */
#[Scoped]
final class AiPromptsOnPage
{
    /** @var array<string, true> */
    private array $claimed = [];

    public function claim(AiFeature $feature): bool
    {
        if (isset($this->claimed[$feature->value])) {
            return false;
        }

        $this->claimed[$feature->value] = true;

        return true;
    }
}
