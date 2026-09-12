<?php declare(strict_types=1);

namespace App\Support;

/**
 * One shop landing page: which shop it is, what DipCatch can read there, and
 * the questions people ask about that particular shop.
 *
 * The copy arrives already translated; {@see ShopPages} owns it.
 */
final readonly class ShopPage
{
    /**
     * @param  list<string>  $facts     What DipCatch reads at this shop.
     * @param  list<array{q: string, a: string}>  $faq
     * @param  list<string>  $useCases  Slugs of the categories this shop serves.
     */
    public function __construct(
        public string $host,
        public string $name,
        public string $slug,
        public string $heading,
        public string $description,
        public string $intro,
        public array $facts,
        public array $faq,
        public array $useCases,
    ) {}

    public function url(?string $lang = null): string
    {
        return route('shop', $lang === null ? ['slug' => $this->slug] : ['slug' => $this->slug, 'lang' => $lang]);
    }

    public function favicon(): string
    {
        return Favicon::url($this->host, 32);
    }

    /**
     * The category pages that name this shop, so each page links to the ones
     * it belongs to rather than to all of them.
     *
     * @return list<UseCase>
     */
    public function relatedUseCases(): array
    {
        $related = [];

        foreach (UseCases::all() as $useCase) {
            if (in_array($useCase->slug, $this->useCases, true)) {
                $related[] = $useCase;
            }
        }

        return $related;
    }
}
