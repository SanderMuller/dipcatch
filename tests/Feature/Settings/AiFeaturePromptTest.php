<?php declare(strict_types=1);

use App\Enums\AiPromptPlace;
use App\Livewire\AiFeaturePrompt;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ViewException;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
});

/**
 * @param  array<model-property<User>, mixed>  $attributes
 */
function proAccountWithAi(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    subscribeUser($user);

    return $user->refresh();
}

function promptOnNewPage(string $place): mixed
{
    app()->forgetScopedInstances();

    return livewire(AiFeaturePrompt::class, ['place' => $place]);
}

test('a Pro account with the feature off is offered it, and one click switches it on', function (): void {
    $user = proAccountWithAi();
    $this->actingAs($user);

    promptOnNewPage('add_shop')
        ->assertSeeHtml('data-test="ai-feature-prompt"')
        ->assertSee('Shop checks come with your Pro plan.')
        ->assertSee(AiPromptPlace::AddShop->text())
        ->call('switchOn')
        ->assertDispatched('ai-feature-switched-on', feature: 'shop_checks')
        ->assertSeeHtml('data-test="ai-feature-on"')
        ->assertDontSeeHtml('data-test="ai-feature-prompt"');

    expect($user->refresh()->shop_checks)->toBeTrue()
        ->and($user->auto_categories)->toBeFalse();
});

test('nothing is offered to a free account, an account that has it on, or one that said not now here lately', function (User $user): void {
    $this->actingAs($user);

    promptOnNewPage('add_shop')->assertDontSeeHtml('data-test="ai-feature-prompt"');
})->with([
    'free account' => fn (): User => User::factory()->create(),
    'already on' => fn (): User => proAccountWithAi(['shop_checks' => true]),
    'said not now here' => fn (): User => proAccountWithAi(['ai_prompt_dismissals' => ['add_shop' => now()->subDays(29)->toIso8601String()]]),
]);

test('not now hides only that place, and only for 30 days', function (): void {
    $user = proAccountWithAi();
    $this->actingAs($user);

    promptOnNewPage('add_shop')->call('dismiss')->assertDontSeeHtml('data-test="ai-feature-prompt"');

    promptOnNewPage('add_shop')->assertDontSeeHtml('data-test="ai-feature-prompt"');
    promptOnNewPage('pack_size')->assertSeeHtml('data-test="ai-feature-prompt"');
    expect($user->refresh()->shop_checks)->toBeFalse();

    $this->travel(AiPromptPlace::QUIET_DAYS + 1)->days();

    promptOnNewPage('add_shop')->assertSeeHtml('data-test="ai-feature-prompt"');
});

test('a second not now keeps the first place quiet', function (): void {
    $this->actingAs(proAccountWithAi());

    promptOnNewPage('add_shop')->call('dismiss');
    promptOnNewPage('pack_size')->call('dismiss');

    promptOnNewPage('add_shop')->assertDontSeeHtml('data-test="ai-feature-prompt"');
});

test('a quiet place does not take the page\'s one prompt for the feature', function (): void {
    $this->actingAs(proAccountWithAi(['ai_prompt_dismissals' => ['shop_suggestions' => now()->toIso8601String()]]));

    promptOnNewPage('shop_suggestions')->assertDontSeeHtml('data-test="ai-feature-prompt"');
    livewire(AiFeaturePrompt::class, ['place' => 'pack_size'])->assertSeeHtml('data-test="ai-feature-prompt"');
});

test('a page offers each feature once, and a switch elsewhere hides the rest', function (): void {
    $user = proAccountWithAi();
    Product::factory()->for($user)->create(['category' => null]);
    $this->actingAs($user);

    $first = promptOnNewPage('shop_suggestions')->assertSeeHtml('data-test="ai-feature-prompt"');
    livewire(AiFeaturePrompt::class, ['place' => 'pack_size'])->assertDontSeeHtml('data-test="ai-feature-prompt"');
    livewire(AiFeaturePrompt::class, ['place' => 'product_category'])->assertSeeHtml('data-test="ai-feature-prompt"');

    $first->dispatch('ai-feature-switched-on', feature: 'categories')->assertSeeHtml('data-test="ai-feature-prompt"');
    $first->dispatch('ai-feature-switched-on', feature: 'shop_checks')->assertDontSeeHtml('data-test="ai-feature-prompt"');
});

test('a free account cannot switch it on, while a click on a prompt left open in another tab still counts', function (): void {
    $free = User::factory()->create();
    $this->actingAs($free);
    promptOnNewPage('add_shop')->call('switchOn');
    expect($free->refresh()->shop_checks)->toBeFalse();

    $pro = proAccountWithAi();
    $this->actingAs($pro);
    $prompt = promptOnNewPage('add_shop');
    $pro->forceFill(['ai_prompt_dismissals' => ['add_shop' => now()->toIso8601String()]])->save();
    $prompt->call('switchOn')->assertSeeHtml('data-test="ai-feature-on"');
    expect($pro->refresh()->shop_checks)->toBeTrue();
});

test('the categories prompt waits until there is a product without a category', function (): void {
    $user = proAccountWithAi();
    $this->actingAs($user);

    promptOnNewPage('product_list')->assertDontSeeHtml('data-test="ai-feature-prompt"');

    Product::factory()->for($user)->create(['category' => null]);

    promptOnNewPage('product_list')->assertSeeHtml('data-test="ai-feature-prompt"');
    app()->forgetScopedInstances();
    livewire(ProductList::class)->assertSeeLivewire(AiFeaturePrompt::class);
});

test('an unknown place cannot be mounted', function (): void {
    $this->actingAs(proAccountWithAi());

    promptOnNewPage('everywhere');
})->throws(ViewException::class, 'is not a valid backing value');

test('dropping the account-wide not now keeps a recent one quiet at every place', function (): void {
    $migration = require database_path('migrations/2026_10_07_220756_drop_ai_prompts_dismissed_at_from_users_table.php');
    $migration->down();
    $recent = proAccountWithAi(['ai_prompt_dismissals' => ['alert' => now()->toIso8601String()]]);
    $old = proAccountWithAi();
    DB::table('users')->where('id', $recent->id)->update(['ai_prompts_dismissed_at' => now()->subDays(10)]);
    DB::table('users')->where('id', $old->id)->update(['ai_prompts_dismissed_at' => now()->subDays(AiPromptPlace::QUIET_DAYS + 1)]);

    $migration->up();

    expect(Schema::hasColumn('users', 'ai_prompts_dismissed_at'))->toBeFalse()
        ->and(array_keys($recent->refresh()->ai_prompt_dismissals ?? []))->toEqualCanonicalizing(array_column(AiPromptPlace::cases(), 'value'))
        ->and($recent->ai_prompt_dismissals['alert'])->toBeGreaterThan($recent->ai_prompt_dismissals['add_shop'])
        ->and($old->refresh()->ai_prompt_dismissals)->toBeNull();
});
