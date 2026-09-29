<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Enums\TrackingIdea;
use App\Enums\TrackingIdeaMarkState;
use App\Livewire\Dashboard\TrackingIdeas;
use App\Livewire\Products\CreateProductFromUrl;
use App\Models\Product;
use App\Models\User;
use App\Support\TrackingIdeaChecklist;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

test('a tracked product in an idea category ticks it', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Kattenbrokjes', 'category' => ProductCategory::PetFood]);

    $checklist = TrackingIdeaChecklist::for($user);

    expect($checklist->tracked)->toContain(TrackingIdea::PetFood)
        ->and($checklist->open)->not->toContain(TrackingIdea::PetFood);
});

test('a title keyword ticks an idea on a product with no category', function (): void {
    // Free accounts get no automatic category, so their titles carry the list.
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Nivea Men Deo Roller 50 ml', 'category' => null]);

    expect(TrackingIdeaChecklist::for($user)->tracked)->toContain(TrackingIdea::DeodorantShower);
});

test('a word that merely contains a keyword ticks nothing', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Ring Video Doorbell', 'category' => null]);

    expect(TrackingIdeaChecklist::for($user)->tracked)->toBe([]);
});

test('a shower gel does not tick sunscreen or skincare, though they share its category', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Dove douchegel 250 ml', 'category' => ProductCategory::SkinBody]);

    $checklist = TrackingIdeaChecklist::for($user);

    expect($checklist->tracked)->toBe([TrackingIdea::DeodorantShower])
        ->and($checklist->open)->toContain(TrackingIdea::Sunscreen, TrackingIdea::SkincareMakeup);
});

test('every keyword idea recognises a typical product title', function (TrackingIdea $idea, string $title): void {
    expect($idea->isTrackedBy($title, category: null))->toBeTrue();
})->with([
    [TrackingIdea::Sunscreen, 'Garnier Ambre Solaire SPF50+'],
    [TrackingIdea::VacuumBags, 'Philips stofzuigerzakken s-bag 4 stuks'],
    [TrackingIdea::Paper, 'Page toiletpapier 24 rollen'],
    [TrackingIdea::Dishwasher, 'Finish vaatwastabletten All in 1'],
    [TrackingIdea::Batteries, 'Duracell Plus AA batterijen 12 stuks'],
    [TrackingIdea::ContactLenses, 'Acuvue Oasys contactlenzen'],
    [TrackingIdea::CatLitter, 'Catsan kattenbakvulling 10 l'],
    [TrackingIdea::FleaTreatment, 'Frontline Combo kat'],
    [TrackingIdea::WaterFilters, 'Brita Maxtra Pro filterpatronen 3 stuks'],
    [TrackingIdea::Car, 'AdBlue 10 liter'],
    // Dutch runs words together.
    [TrackingIdea::DeodorantShower, 'Axe deospray Africa'],
    [TrackingIdea::CoffeeTea, 'Pickwick theezakjes'],
    [TrackingIdea::BeerWine, 'Heineken pils 24 x 33 cl'],
    [TrackingIdea::PrinterInk, 'Canon PG-545 inktpatroon'],
    [TrackingIdea::Nappies, 'Pampers Baby-Dry maat 4'],
    [TrackingIdea::Shaving, 'Gillette Fusion mesjes 8 stuks'],
    [TrackingIdea::OralCare, 'Oral-B tandenborstel opzetborstels'],
    [TrackingIdea::LightBulbs, 'Philips LED kaarslamp E14'],
]);

test('a title that only looks like an idea does not tick it', function (TrackingIdea $idea, string $title): void {
    expect($idea->isTrackedBy($title, category: null))->toBeFalse();
})->with([
    [TrackingIdea::PrinterInk, 'Hydrating Toner 200 ml'],
    [TrackingIdea::BeerWine, 'Wijnglazen 6 stuks'],
    [TrackingIdea::BeerWine, 'Wijnazijn 500 ml'],
    [TrackingIdea::HairCare, "De'Longhi Pinguino air conditioner"],
    [TrackingIdea::Candles, 'LED kaarslamp E14'],
    [TrackingIdea::Laundry, 'Laundry basket'],
    [TrackingIdea::CoffeeTea, 'IKEA tea lights'],
    [TrackingIdea::CoffeeTea, 'Lipton Ice Tea'],
    [TrackingIdea::Dishwasher, 'Bosch Serie 4 vaatwasser'],
    [TrackingIdea::Nappies, 'Luiertas zwart'],
    [TrackingIdea::FleaTreatment, 'Flea market finds'],
]);

test('a tracked product outranks an idea marked not for me', function (): void {
    $user = User::factory()->create();
    $user->trackingIdeaMarks()->create(['idea' => TrackingIdea::Nappies, 'state' => TrackingIdeaMarkState::Skipped, 'marked_at' => now()]);
    Product::factory()->for($user)->create(['title' => 'Pampers Baby-Dry maat 4', 'category' => null]);

    $checklist = TrackingIdeaChecklist::for($user);

    expect($checklist->tracked)->toContain(TrackingIdea::Nappies)
        ->and($checklist->skipped)->toBe([]);
});

test('an idea ticked by hand moves out of the open list, and undo puts it back', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(TrackingIdeas::class)->call('markDone', TrackingIdea::Hobby->value);

    expect(TrackingIdeaChecklist::for($user)->done)->toBe([TrackingIdea::Hobby]);

    livewire(TrackingIdeas::class)->call('undo', TrackingIdea::Hobby->value);

    expect(TrackingIdeaChecklist::for($user)->open)->toContain(TrackingIdea::Hobby);
});

test('an idea marked not for me leaves the list', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(TrackingIdeas::class)
        ->call('skip', TrackingIdea::Nappies->value)
        ->assertSeeHtml('data-test="tracking-ideas-skipped"');

    expect(TrackingIdeaChecklist::for($user)->skipped)->toBe([TrackingIdea::Nappies])
        ->and($user->trackingIdeaMarks()->sole()->state)->toBe(TrackingIdeaMarkState::Skipped);
});

test('an unknown idea is ignored', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(TrackingIdeas::class)->call('skip', 'not-an-idea');

    expect($user->trackingIdeaMarks()->exists())->toBeFalse();
});

test('the idea Jev stored on a product ticks it, even one no word or category names', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Drops Air haakgaren 50 g', 'tracking_idea' => TrackingIdea::Hobby]);

    expect(TrackingIdeaChecklist::for($user)->tracked)->toBe([TrackingIdea::Hobby]);
});

test('another account\'s products and marks tick nothing here', function (): void {
    $other = User::factory()->create();
    Product::factory()->for($other)->create(['title' => 'Kattenbrokjes', 'category' => ProductCategory::PetFood]);
    $other->trackingIdeaMarks()->create(['idea' => TrackingIdea::Hobby, 'state' => TrackingIdeaMarkState::Done, 'marked_at' => now()]);

    $checklist = TrackingIdeaChecklist::for(User::factory()->create());

    expect($checklist->open)->toHaveCount(count(TrackingIdea::cases()));
});

test('the card can be hidden, and shows on the dashboard until then', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('app.dashboard'))->assertOk()->assertSeeHtml('data-test="tracking-ideas"');

    livewire(TrackingIdeas::class)
        ->call('hide')
        ->assertDontSeeHtml('data-test="tracking-ideas"');

    $this->get(route('app.dashboard'))->assertOk()->assertDontSeeHtml('data-test="tracking-ideas"');
});

test('a hidden card leaves a link that shows it again', function (): void {
    $user = User::factory()->create(['tracking_ideas_hidden_at' => now()]);
    $this->actingAs($user);

    livewire(TrackingIdeas::class)
        ->assertSeeHtml('data-test="tracking-ideas-show"')
        ->call('show')
        ->assertSeeHtml('data-test="tracking-ideas"')
        ->assertDontSeeHtml('data-test="tracking-ideas-show"');

    expect($user->fresh()->tracking_ideas_hidden_at)->toBeNull();
});

test('the show link stays away while the card is visible or everything is covered', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(TrackingIdeas::class)->assertDontSeeHtml('data-test="tracking-ideas-show"');

    foreach (TrackingIdea::cases() as $idea) {
        $user->trackingIdeaMarks()->create(['idea' => $idea, 'state' => TrackingIdeaMarkState::Done, 'marked_at' => now()]);
    }

    livewire(TrackingIdeas::class)
        ->assertDontSeeHtml('data-test="tracking-ideas"')
        ->assertDontSeeHtml('data-test="tracking-ideas-show"');

    $user->forceFill(['tracking_ideas_hidden_at' => now()])->save();

    livewire(TrackingIdeas::class)->assertDontSeeHtml('data-test="tracking-ideas-show"');
});

test('the add-product page names shops for the idea the person came from', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['idea' => 'pet_food'])
        ->test(CreateProductFromUrl::class)
        ->assertSeeHtml('data-test="tracking-idea-hint"')
        ->assertSee('zooplus.nl');

    Livewire::withQueryParams(['idea' => 'nonsense'])
        ->test(CreateProductFromUrl::class)
        ->assertDontSeeHtml('data-test="tracking-idea-hint"');
});
