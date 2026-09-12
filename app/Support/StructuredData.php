<?php declare(strict_types=1);

namespace App\Support;

use App\Billing\BillingGate;
use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\ProPrice;
use Illuminate\Support\Facades\Config;

/**
 * Schema.org graphs for the public marketing pages.
 *
 * Built in PHP rather than inline in a Blade array literal, because Laravel 13
 * compiles `@context` as a Blade directive: a literal `'@context' => …` inside
 * a view is rewritten into a PHP context block and the resulting JSON-LD is
 * unparseable. Keeping the arrays here also lets the tests assert on data
 * instead of on markup.
 *
 * Every `@id` is absolute, references included. A bare `#org` would resolve
 * against whichever page rendered it, so the pricing page would point at
 * `…/pricing#org` and never reach the node the homepage defines.
 */
final class StructuredData
{
    /**
     * The full homepage graph: who publishes the site, the site itself, the
     * app it offers, and the FAQ.
     *
     * @param  list<array{q: string, a: string}>  $faq
     * @return array<string, mixed>
     */
    public static function home(array $faq, string $canonical, string $description): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::organization(),
                self::website(),
                self::application($description),
                self::faqPage($faq, $canonical),
            ],
        ];
    }

    /**
     * The pricing page repeats the application node so the offers hang off the
     * same entity, plus a trail back to the homepage.
     *
     * @return array<string, mixed>
     */
    public static function pricing(string $description): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::application($description),
                self::breadcrumb(__('Pricing'), route('pricing')),
            ],
        ];
    }

    /**
     * A use-case landing page: the same application node every other page
     * uses, so the graph stays one entity, plus this page's own questions and
     * a trail back to the homepage.
     *
     * @return array<string, mixed>
     */
    public static function useCase(UseCase $useCase, string $canonical): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::application($useCase->description),
                self::faqPage($useCase->faq, $canonical),
                self::breadcrumb($useCase->heading, $canonical),
            ],
        ];
    }

    /**
     * A shop landing page: the same application node, that shop's questions,
     * and a trail through the shops hub rather than straight to the homepage,
     * so the crawler sees the hierarchy the pages actually have.
     *
     * @return array<string, mixed>
     */
    public static function shop(ShopPage $shop, string $canonical): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::application($shop->description),
                self::faqPage($shop->faq, $canonical),
                self::shopBreadcrumb($shop, $canonical),
            ],
        ];
    }

    /**
     * The hub: an ItemList of the shops, in the order the page renders them,
     * so the list a reader sees is the list a crawler reads.
     *
     * @param  list<ShopPage>  $shops
     * @return array<string, mixed>
     */
    public static function shopsHub(array $shops, string $canonical, string $description): array
    {
        $items = [];

        foreach ($shops as $index => $shop) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $shop->name,
                'url' => $shop->url(),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::application($description),
                [
                    '@type' => 'ItemList',
                    '@id' => $canonical . '#shops',
                    'name' => __('Supported shops'),
                    'numberOfItems' => count($items),
                    'itemListElement' => $items,
                ],
                self::breadcrumb(__('Supported shops'), $canonical),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shopBreadcrumb(ShopPage $shop, string $canonical): array
    {
        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => Config::string('app.name', 'DipCatch'), 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => __('Supported shops'), 'item' => route('shops')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $shop->heading, 'item' => $canonical],
            ],
        ];
    }

    /**
     * The support page: a ContactPage, so an assistant asked how to reach us
     * has something to answer with.
     *
     * @return array<string, mixed>
     */
    public static function support(string $canonical, string $description): array
    {
        $page = [
            '@type' => 'ContactPage',
            '@id' => $canonical . '#page',
            'url' => $canonical,
            'name' => __('Support'),
            'description' => $description,
            'isPartOf' => ['@id' => self::id('#site')],
        ];

        $email = Config::get('site.contact_email');

        if (is_string($email) && $email !== '') {
            $page['mainEntity'] = [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => $email,
                'availableLanguage' => ['en', 'nl'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$page, self::breadcrumb(__('Support'), $canonical)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function terms(string $canonical, string $description): array
    {
        $page = [
            '@type' => 'WebPage',
            '@id' => $canonical . '#page',
            'url' => $canonical,
            'name' => __('Terms of service'),
            'description' => $description,
            'isPartOf' => ['@id' => self::id('#site')],
        ];

        $updated = Config::get('site.terms_updated_at');

        if (is_string($updated) && $updated !== '') {
            $page['dateModified'] = $updated;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$page, self::breadcrumb(__('Terms of service'), $canonical)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function privacy(string $canonical, string $description): array
    {
        $page = [
            '@type' => 'WebPage',
            '@id' => $canonical . '#page',
            'url' => $canonical,
            'name' => __('Privacy'),
            'description' => $description,
            'isPartOf' => ['@id' => self::id('#site')],
        ];

        $updated = Config::get('site.privacy_updated_at');

        if (is_string($updated) && $updated !== '') {
            $page['dateModified'] = $updated;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$page, self::breadcrumb(__('Privacy'), route('privacy'))],
        ];
    }

    /**
     * What the plans cost, as offers on the application.
     *
     * The paid offer is absent until Stripe is configured. When it is
     * configured but the gate is shut, the offer is a PreOrder: the pricing
     * page says "coming soon" while the price itself is already true.
     *
     * @return list<array<string, mixed>>
     */
    public static function offers(): array
    {
        $currency = ProPrice::currency();
        $free = Entitlements::of(Plan::Free);

        $offers = [[
            '@type' => 'Offer',
            'name' => __('Free'),
            'price' => '0',
            'priceCurrency' => $currency,
            'description' => self::freeLimits($free),
            'availability' => 'https://schema.org/InStock',
        ]];

        if (! ProPrice::isConfigured()) {
            return $offers;
        }

        $offers[] = [
            '@type' => 'Offer',
            'name' => __('Pro'),
            'price' => ProPrice::amount(),
            'priceCurrency' => $currency,
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => ProPrice::amount(),
                'priceCurrency' => $currency,
                'billingIncrement' => 1,
                'unitCode' => 'MON',
            ],
            'availability' => BillingGate::isOpen()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/PreOrder',
        ];

        $trialDays = ProPrice::trialDays();

        if ($trialDays > 0) {
            // `eligibleDuration` says how long an offer stays valid, not how
            // long a subscription runs, so the trial is its own free offer
            // rather than a property of the paid one.
            $offers[] = [
                '@type' => 'Offer',
                'name' => __('Pro trial'),
                'price' => '0',
                'priceCurrency' => $currency,
                'eligibleDuration' => [
                    '@type' => 'QuantitativeValue',
                    'value' => $trialDays,
                    'unitCode' => 'DAY',
                ],
            ];
        }

        return $offers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function organization(): array
    {
        $organization = [
            '@type' => 'Organization',
            '@id' => self::id('#org'),
            'name' => self::appName(),
            'url' => self::baseUrl(),
            'logo' => asset('images/dipcatch-logo.png'),
            'areaServed' => 'NL',
        ];

        $email = Config::get('site.contact_email');

        if (is_string($email) && $email !== '') {
            $organization['email'] = $email;
        }

        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    private static function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::id('#site'),
            'url' => self::baseUrl(),
            'name' => self::appName(),
            'inLanguage' => ['en', 'nl'],
            'publisher' => ['@id' => self::id('#org')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function application(string $description): array
    {
        return [
            '@type' => 'SoftwareApplication',
            '@id' => self::id('#app'),
            'name' => self::appName(),
            'url' => self::baseUrl(),
            'description' => $description,
            'applicationCategory' => 'ShoppingApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => ['en', 'nl'],
            'provider' => ['@id' => self::id('#org')],
            'offers' => self::offers(),
        ];
    }

    /**
     * @param  list<array{q: string, a: string}>  $faq
     * @return array<string, mixed>
     */
    private static function faqPage(array $faq, string $canonical): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $canonical . '#faq',
            'isPartOf' => ['@id' => self::id('#site')],
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['a'],
                ],
            ], $faq),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function breadcrumb(string $name, string $url): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => self::appName(),
                    'item' => route('home'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $name,
                    'item' => $url,
                ],
            ],
        ];
    }

    /**
     * Both limits are nullable, where null means unlimited, so neither can be
     * interpolated without a branch.
     */
    private static function freeLimits(Entitlements $free): string
    {
        $products = $free->maxProducts();
        $shops = $free->maxShopsPerProduct();

        if ($products === null) {
            return __('Unlimited products.');
        }

        if ($shops === null) {
            return __('Up to :count products, with as many shops each as you like.', ['count' => $products]);
        }

        return __('Up to :products products, with up to :shops shops each.', [
            'products' => $products,
            'shops' => $shops,
        ]);
    }

    private static function id(string $fragment): string
    {
        return self::baseUrl() . '/' . $fragment;
    }

    private static function baseUrl(): string
    {
        return rtrim(route('home'), '/');
    }

    private static function appName(): string
    {
        $name = Config::get('app.name');

        return is_string($name) && $name !== '' ? $name : 'DipCatch';
    }
}
