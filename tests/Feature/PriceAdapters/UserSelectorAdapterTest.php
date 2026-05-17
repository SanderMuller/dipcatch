<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\UserSelectorAdapter;

beforeEach(function (): void {
    $this->adapter = new UserSelectorAdapter();
});

test('skips when no context is provided', function (): void {
    $result = $this->adapter->extract('https://x.test', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('skips when context lacks a price selector', function (): void {
    $result = $this->adapter->extract(
        'https://x.test',
        '<html></html>',
        new AdapterContext(selectors: [], fallbackCurrency: 'EUR'),
    );

    expect($result->isSkip())->toBeTrue();
});

test('extracts price using user selector + fallback currency', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1>User Selector Item</h1>
  <span class="my-price">€ 12,50</span>
</body></html>
HTML;

    $result = $this->adapter->extract(
        'https://x.test',
        $html,
        new AdapterContext(
            selectors: ['price' => '.my-price'],
            fallbackCurrency: 'EUR',
        ),
    );

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('12.50')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('User Selector Item');
});

test('uses provided title selector', function (): void {
    $html = '<h2 class="real-title">Real Title</h2><span class="p">10.00</span>';

    $result = $this->adapter->extract('https://x.test', $html, new AdapterContext(
        selectors: ['price' => '.p', 'title' => '.real-title'],
        fallbackCurrency: 'USD',
    ));

    expect($result->snapshot?->title)->toBe('Real Title');
});

test('fails when selector matches nothing', function (): void {
    $result = $this->adapter->extract('https://x.test', '<html><body></body></html>', new AdapterContext(
        selectors: ['price' => '.absent'],
        fallbackCurrency: 'EUR',
    ));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('user_selector_no_match');
});

test('fails when matched element has no parseable number', function (): void {
    $result = $this->adapter->extract('https://x.test', '<span class="p">free</span>', new AdapterContext(
        selectors: ['price' => '.p'],
        fallbackCurrency: 'EUR',
    ));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('user_selector_no_price');
});

test('fails when fallback currency is missing', function (): void {
    $result = $this->adapter->extract('https://x.test', '<span class="p">10.00</span>', new AdapterContext(
        selectors: ['price' => '.p'],
        fallbackCurrency: null,
    ));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('user_selector_no_currency');
});

test('reads data-price attribute when present', function (): void {
    $html = '<div class="p" data-price="42.50">Forty-two-fifty</div>';

    $result = $this->adapter->extract('https://x.test', $html, new AdapterContext(
        selectors: ['price' => '.p'],
        fallbackCurrency: 'EUR',
    ));

    expect($result->snapshot?->price)->toBe('42.50');
});
