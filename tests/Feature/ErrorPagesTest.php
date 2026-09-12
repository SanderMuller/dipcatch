<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('an unmatched URL renders the branded 404 with a way back', function (): void {
    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertSee(__('This page does not exist'))
        ->assertSee(route('home'), escape: false)
        ->assertSee(route('pricing'), escape: false);
});

test('the 404 tells crawlers not to index it and emits no social card', function (): void {
    $content = (string) $this->get('/this-route-does-not-exist')->assertNotFound()->getContent();

    expect($content)->toContain('<meta name="robots" content="noindex">')
        ->and($content)->not->toContain('<meta property="og:')
        ->and($content)->not->toContain('<link rel="canonical"');
});

test('the 404 shows the same links to a signed-in visitor as to a guest', function (): void {
    // The router throws before the `web` group runs, so no session is started
    // and the page cannot know who is asking. Asserting that keeps anyone from
    // adding an `auth()->check()` branch that silently never fires.
    $guest = (string) $this->get('/this-route-does-not-exist')->getContent();

    $this->actingAs(User::factory()->create());

    $member = (string) $this->get('/this-route-does-not-exist')->getContent();

    expect($member)->toBe($guest)
        ->and($member)->not->toContain(route('profile.edit'));
});

describe('the 500 page', function (): void {
    beforeEach(function (): void {
        // Without this Laravel renders the debug handler and the view never
        // runs, so the assertions below would pass against a stack trace.
        config()->set('app.debug', false);

        Route::get('__throws', function (): never {
            throw new RuntimeException('boom');
        })->middleware('web');
    });

    test('renders the branded page with a way back', function (): void {
        $this->get('/__throws')
            ->assertServerError()
            ->assertSee(__('Something went wrong on our side'))
            ->assertSee(route('home'), escape: false);
    });

    test('tells crawlers not to index it', function (): void {
        $content = (string) $this->get('/__throws')->assertServerError()->getContent();

        expect($content)->toContain('<meta name="robots" content="noindex">')
            ->and($content)->not->toContain('<meta property="og:');
    });

    test('offers the contact address only when one is configured', function (): void {
        config()->set('site.contact_email', 'hello@example.test');

        $this->get('/__throws')->assertServerError()->assertSee('mailto:hello@example.test', escape: false);

        config()->set('site.contact_email');

        $this->get('/__throws')->assertServerError()->assertDontSee('mailto:', escape: false);
    });
});
