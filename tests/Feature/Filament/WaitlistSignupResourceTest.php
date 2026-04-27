<?php declare(strict_types=1);

use App\Filament\Admin\Resources\WaitlistSignups\Pages\ListWaitlistSignups;
use App\Filament\Admin\Resources\WaitlistSignups\WaitlistSignupResource;
use App\Models\User;
use App\Models\WaitlistSignup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

test('admins can list waitlist signups in the admin panel', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $signups = WaitlistSignup::factory()->count(3)->create();

    livewire(ListWaitlistSignups::class)
        ->assertOk()
        ->assertCanSeeTableRecords($signups);
});

test('non-admins cannot reach the waitlist resource list page', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(WaitlistSignupResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

test('admins can bulk-delete waitlist signups', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $signups = WaitlistSignup::factory()->count(3)->create();

    livewire(ListWaitlistSignups::class)
        ->selectTableRecords($signups->pluck('id')->all())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(WaitlistSignup::query()->count())->toBe(0);
});

test('the list page exposes an export action in the header', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    livewire(ListWaitlistSignups::class)
        ->assertActionExists('export');
});
