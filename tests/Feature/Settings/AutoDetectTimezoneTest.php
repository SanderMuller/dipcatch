<?php declare(strict_types=1);

use App\Filament\App\Pages\NotificationSettings;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('app');
});

test('happy path: persists timezone and stamps timezone_detected_at', function (): void {
    $user = User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'timezone_detected_at' => null,
    ]);

    $this->actingAs($user)
        ->postJson('/profile/timezone/auto-detect', ['timezone' => 'America/New_York'])
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    $fresh = $user->fresh();
    expect($fresh->timezone)->toBe('America/New_York')
        ->and($fresh->timezone_detected_at)->not->toBeNull();
});

test('idempotent: a second POST does not overwrite a user-chosen timezone', function (): void {
    $detectedAt = now()->subHour();
    $user = User::factory()->create([
        'timezone' => 'Asia/Tokyo',
        'timezone_detected_at' => $detectedAt,
    ]);

    $this->actingAs($user)
        ->postJson('/profile/timezone/auto-detect', ['timezone' => 'America/Los_Angeles'])
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    $fresh = $user->fresh();
    // Timezone unchanged, timestamp preserved — atomic conditional UPDATE
    // matched 0 rows because timezone_detected_at was already non-null.
    expect($fresh->timezone)->toBe('Asia/Tokyo');
    expect($fresh->timezone_detected_at)->toBeSameTimestampAs($detectedAt);
});

test('rejects an unrecognised IANA timezone with 422', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/profile/timezone/auto-detect', ['timezone' => 'Europe/Atlantis'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['timezone']);

    expect($user->fresh()->timezone_detected_at)->toBeNull();
});

test('rejects a missing timezone with 422', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/profile/timezone/auto-detect', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['timezone']);
});

test('unauthenticated requests are redirected away (302)', function (): void {
    $this->post('/profile/timezone/auto-detect', ['timezone' => 'Europe/Berlin'])
        ->assertStatus(302);
});

test('auto-detect view emits the script when the authenticated user has no timezone_detected_at', function (): void {
    $user = User::factory()->create(['timezone_detected_at' => null]);
    $this->actingAs($user);

    $rendered = view('filament.app.timezone-autodetect')->render();

    expect($rendered)->toContain('Intl.DateTimeFormat')
        ->and($rendered)->toContain('profile/timezone/auto-detect');
});

test('auto-detect view emits nothing once timezone_detected_at is set', function (): void {
    $user = User::factory()->create(['timezone_detected_at' => now()]);
    $this->actingAs($user);

    $rendered = view('filament.app.timezone-autodetect')->render();

    expect(trim($rendered))->toBe('');
});

test('auto-detect view emits nothing for an unauthenticated request', function (): void {
    $rendered = view('filament.app.timezone-autodetect')->render();

    expect(trim($rendered))->toBe('');
});

test('NotificationSettings::save() stamps timezone_detected_at so future auto-detects are no-ops', function (): void {
    $user = User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'timezone_detected_at' => null,
    ]);
    $this->actingAs($user);

    // User explicitly saves their preferences (keeping the default tz, even).
    livewire(NotificationSettings::class)
        ->set('data.timezone', 'Europe/Amsterdam')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->timezone_detected_at)->not->toBeNull();

    // Now the browser fires an auto-detect with a different timezone — must
    // be ignored because the explicit save already stamped the flag.
    $this->postJson('/profile/timezone/auto-detect', ['timezone' => 'Asia/Tokyo'])
        ->assertOk();

    expect($user->fresh()->timezone)->toBe('Europe/Amsterdam');
});
