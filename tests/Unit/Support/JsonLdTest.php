<?php declare(strict_types=1);

use App\Support\JsonLd;

test('script wraps the encoded data in a single script element', function (): void {
    $html = (string) JsonLd::script(['@type' => 'FAQPage', 'foo' => 'bar']);

    expect($html)->toStartWith('<script type="application/ld+json">')
        ->toEndWith('</script>');
});

test('script-ending text in the data cannot end the element early', function (): void {
    $data = [
        'note' => 'Before</script><script>alert(1)</script>after',
    ];

    $html = (string) JsonLd::script($data);

    expect(substr_count($html, '</script>'))->toBe(1);

    $jsonBetweenTags = substr(
        $html,
        strlen('<script type="application/ld+json">'),
        -strlen('</script>'),
    );

    $decoded = json_decode($jsonBetweenTags, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe($data);
});
