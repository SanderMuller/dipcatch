<?php declare(strict_types=1);

/**
 * The header contract, asserted on a real request. This is the regression guard
 * for the middleware stack: reorder it, drop the global registration, or let a
 * package strip a header, and this fails.
 */
test('a web response carries every security header', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-XSS-Protection', '1; mode=block')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

test('the HSTS header covers subdomains and declares preload', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000;includeSubDomains;preload');
});

test('a response outside the web group carries the security headers too', function (): void {
    // `/up` is registered by the framework with no middleware group at all, so
    // it only sees the headers when the middleware is globally appended. Under
    // the previous `web(append:)` registration this request had none of them.
    $this->get('/up')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000;includeSubDomains;preload');
});

test('a web response sends no CORS headers', function (): void {
    // A wildcard CORS config is what this catches: the absent header is the
    // assertion, and a test that only checks present headers would miss it.
    $this->get(route('home'))
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

test('a missing page returns a 404', function (): void {
    $this->get('/this/page/should/not/exist/and/return/a/404')
        ->assertNotFound();
});
