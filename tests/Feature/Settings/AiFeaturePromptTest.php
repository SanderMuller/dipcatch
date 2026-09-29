<?php declare(strict_types=1);

use App\Livewire\AiFeaturePrompt;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\User;
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

test('a Pro account with the feature off is offered it, and one click switches it on', function (): void {
    $user = proAccountWithAi();
    $this->actingAs($user);

    livewire(AiFeaturePrompt::class, ['feature' => 'shop_checks'])
        ->assertSeeHtml('data-test="ai-feature-prompt"')
        ->call('switchOn')
        ->assertSeeHtml('data-test="ai-feature-on"')
        ->assertDontSeeHtml('data-test="ai-feature-prompt"');

    expect($user->refresh()->shop_checks)->toBeTrue()
        ->and($user->auto_categories)->toBeFalse();
});

test('nothing is offered to a free account, an account that has it on, or one that said not now', function (User $user): void {
    $this->actingAs($user);

    livewire(AiFeaturePrompt::class, ['feature' => 'shop_checks'])
        ->assertDontSeeHtml('data-test="ai-feature-prompt"');
})->with([
    'free account' => fn (): User => User::factory()->create(),
    'already on' => fn (): User => proAccountWithAi(['shop_checks' => true]),
    'said not now' => fn (): User => proAccountWithAi(['ai_prompts_dismissed_at' => now()]),
]);

test('a free account cannot switch it on, while a click on a prompt left open in another tab still counts', function (): void {
    $free = User::factory()->create();
    $this->actingAs($free);
    livewire(AiFeaturePrompt::class, ['feature' => 'shop_checks'])->call('switchOn');
    expect($free->refresh()->shop_checks)->toBeFalse();

    $pro = proAccountWithAi();
    $this->actingAs($pro);
    $prompt = livewire(AiFeaturePrompt::class, ['feature' => 'shop_checks']);
    $pro->forceFill(['ai_prompts_dismissed_at' => now()])->save();
    $prompt->call('switchOn')->assertSeeHtml('data-test="ai-feature-on"');
    expect($pro->refresh()->shop_checks)->toBeTrue();
});

test('not now hides every AI prompt and switches nothing on', function (): void {
    $user = proAccountWithAi();
    Product::factory()->for($user)->create(['category' => null]);
    $this->actingAs($user);

    livewire(AiFeaturePrompt::class, ['feature' => 'shop_checks'])
        ->call('dismiss')
        ->assertDontSeeHtml('data-test="ai-feature-prompt"');

    livewire(AiFeaturePrompt::class, ['feature' => 'categories'])
        ->assertDontSeeHtml('data-test="ai-feature-prompt"');

    expect($user->refresh()->shop_checks)->toBeFalse()
        ->and($user->auto_categories)->toBeFalse()
        ->and($user->ai_prompts_dismissed_at)->not->toBeNull();
});

test('the categories prompt waits until there is a product without a category', function (): void {
    $user = proAccountWithAi();
    $this->actingAs($user);

    livewire(AiFeaturePrompt::class, ['feature' => 'categories'])->assertDontSeeHtml('data-test="ai-feature-prompt"');

    Product::factory()->for($user)->create(['category' => null]);

    livewire(AiFeaturePrompt::class, ['feature' => 'categories'])->assertSeeHtml('data-test="ai-feature-prompt"');
    livewire(ProductList::class)->assertSeeLivewire(AiFeaturePrompt::class);
});

test('an unknown feature cannot be mounted', function (): void {
    $this->actingAs(proAccountWithAi());

    livewire(AiFeaturePrompt::class, ['feature' => 'everything']);
})->throws(ViewException::class, 'is not a valid backing value');
