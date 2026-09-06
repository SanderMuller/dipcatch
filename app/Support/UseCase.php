<?php declare(strict_types=1);

namespace App\Support;

/**
 * One use-case landing page: its slug, its own copy, and the shops it names.
 * The copy arrives already translated; {@see UseCases} owns it.
 */
final readonly class UseCase
{
    /**
     * @param  list<string>  $hosts
     * @param  list<array{q: string, a: string}>  $faq
     */
    public function __construct(
        public string $slug,
        public string $heading,
        public string $description,
        public string $intro,
        public string $example,
        public array $hosts,
        public array $faq,
    ) {}

    public function url(?string $lang = null): string
    {
        return route('use-case', $lang === null ? ['slug' => $this->slug] : ['slug' => $this->slug, 'lang' => $lang]);
    }

    /**
     * Limited to hosts still in `site.supported_hosts`, so a shop dropped from
     * the config stops being promised here too.
     *
     * @return list<array{host: string, favicon: string, name: string}>
     */
    public function shops(): array
    {
        $supported = [];
        foreach (SupportedShops::rows() as $row) {
            $supported[$row['host']] = $row;
        }

        $rows = [];
        foreach ($this->hosts as $host) {
            if (isset($supported[$host])) {
                $rows[] = $supported[$host];
            }
        }

        return $rows;
    }
}
