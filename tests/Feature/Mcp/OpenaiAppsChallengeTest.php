<?php declare(strict_types=1);

it('returns an empty plain-text 404 when the challenge token is missing', function (mixed $token): void {
    config()->set('dipcatch.openai_apps_challenge', $token);

    $response = $this->get('/.well-known/openai-apps-challenge')->assertNotFound();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->getContent())->toBe('');
})->with([
    'unset' => [null],
    'empty' => [''],
]);

it('returns the exact token as plain text when it is set', function (): void {
    config()->set('dipcatch.openai_apps_challenge', 'portal-token-value');

    $response = $this->get('/.well-known/openai-apps-challenge')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->getContent())->toBe('portal-token-value');
});
