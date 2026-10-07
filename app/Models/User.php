<?php declare(strict_types=1);

namespace App\Models;

use App\Concerns\Subscribes;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property bool $is_admin
 * @property CarbonImmutable|null $billing_blocked_at
 * @property CarbonImmutable|null $comped_until
 * @property string|null $comped_reason
 * @property CarbonImmutable|null $trial_ends_at
 * @property string|null $stripe_checkout_session_id
 * @property string $default_currency
 * @property bool $notify_via_email
 * @property bool $notify_via_filament
 * @property bool $notify_via_push
 * @property string $timezone
 * @property bool $auto_categories
 * @property bool $shop_checks
 * @property bool $affiliate_links_excluded
 * @property CarbonImmutable|null $ai_prompts_dismissed_at
 * @property CarbonImmutable|null $digest_processed_until
 * @property CarbonImmutable|null $tracking_ideas_hidden_at
 * @property CarbonImmutable|null $timezone_detected_at
 * @property string|null $country The country web discovery finds shops for; null lets the timezone decide.
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read EloquentCollection<int, SocialAccount> $socialAccounts
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
final class User extends Authenticatable implements FilamentUser, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, Subscribes, TwoFactorAuthenticatable;

    /**
     * Stored lower-case. Fortify lower-cases what a person types to sign in or
     * to ask for a reset link, and `email` compares byte for byte on Postgres.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower($value));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'billing_blocked_at' => 'datetime',
            'comped_until' => 'datetime',
            // Cashier reads this one directly (`onGenericTrial()` calls `isFuture()`
            // on it), and it is not in the model's own casts by default.
            'trial_ends_at' => 'datetime',
            'notify_via_email' => 'boolean',
            'notify_via_filament' => 'boolean',
            'notify_via_push' => 'boolean',
            'auto_categories' => 'boolean',
            'shop_checks' => 'boolean',
            'affiliate_links_excluded' => 'boolean',
            'ai_prompts_dismissed_at' => 'datetime',
            'digest_processed_until' => 'datetime',
            'tracking_ideas_hidden_at' => 'datetime',
            'timezone_detected_at' => 'datetime',
        ];
    }

    /**
     * The products this account tracks.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The shops of every product this account tracks.
     *
     * @return HasManyThrough<Shop, Product, $this>
     */
    public function shops(): HasManyThrough
    {
        return $this->hasManyThrough(Shop::class, Product::class);
    }

    /**
     * The social identity providers this account signs in with.
     *
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Getting-started ideas this person ticked by hand.
     *
     * @return HasMany<TrackingIdeaMark, $this>
     */
    public function trackingIdeaMarks(): HasMany
    {
        return $this->hasMany(TrackingIdeaMark::class);
    }

    /**
     * @return HasMany<HiddenShop, $this>
     */
    public function hiddenShops(): HasMany
    {
        return $this->hasMany(HiddenShop::class);
    }

    /** The one guard for automatic categories: opted in, and the plan allows it. */
    public function wantsAutoCategories(): bool
    {
        return $this->auto_categories && $this->entitlements()->allowsAutoCategories();
    }

    /** The one guard for the AI check on a newly added shop: opted in, and the plan allows it. */
    public function wantsShopChecks(): bool
    {
        return $this->shop_checks && $this->entitlements()->allowsShopChecks();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_admin === true,
            'app' => true,
            default => false,
        };
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word): string => Str::substr($word, 0, 1))
            ->implode('');
    }
}
