<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\User;
use App\Support\IanaTimezones;
use App\Support\Iso4217;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * The timezone the daily email follows and the currency new products start
 * in, on the profile page.
 */
final class RegionalPreferences extends Component
{
    use HasFluentValidation;

    public string $default_currency = 'EUR';

    public string $timezone = 'Europe/Amsterdam';

    public function mount(): void
    {
        $user = $this->user();

        $this->default_currency = is_string($user->default_currency) && $user->default_currency !== ''
            ? $user->default_currency
            : 'EUR';
        $this->timezone = is_string($user->timezone) && $user->timezone !== ''
            ? $user->timezone
            : 'Europe/Amsterdam';
    }

    /**
     * @return array<string, FluentRuleContract>
     */
    public function rules(): array
    {
        return [
            'default_currency' => FluentRule::string('Default currency')->required()->in(Iso4217::CODES),
        ];
    }

    public function save(): void
    {
        $this->default_currency = strtoupper(trim($this->default_currency));
        $this->validate();

        $this->user()->forceFill([
            'default_currency' => $this->default_currency,
            'timezone' => IanaTimezones::isValid($this->timezone) ? $this->timezone : 'Europe/Amsterdam',
            // An explicit save is the strongest signal of intent, so stamp it:
            // the browser-detected timezone POST must never overwrite a choice
            // the user made deliberately.
            'timezone_detected_at' => now(),
        ])->save();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function render(): View
    {
        return view('livewire.settings.regional-preferences', [
            'timezones' => IanaTimezones::options(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
