<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

/**
 * One product a name search found, in one shape for every source. A pick
 * sends `url` through the normal probe, so nothing here is trusted as a
 * price. Stored as an array in Livewire state.
 *
 * @phpstan-type NameSearchResultArray array{source: string, title: string, shopLabel: string, host: string, url: string, ean: ?string, price: ?string, packSize: ?string, photoUrl: ?string, snippet: ?string}
 */
final readonly class NameSearchResult
{
    public function __construct(
        public NameSearchSource $source,
        public string $title,
        public string $shopLabel,
        public string $host,
        public string $url,
        /** Barcode with leading zeros stripped. */
        public ?string $ean = null,
        public ?string $price = null,
        /** The pack size as the source writes it. */
        public ?string $packSize = null,
        public ?string $photoUrl = null,
        /** The search engine's text under the title; web results only. */
        public ?string $snippet = null,
    ) {}

    /**
     * @return NameSearchResultArray
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'title' => $this->title,
            'shopLabel' => $this->shopLabel,
            'host' => $this->host,
            'url' => $this->url,
            'ean' => $this->ean,
            'price' => $this->price,
            'packSize' => $this->packSize,
            'photoUrl' => $this->photoUrl,
            'snippet' => $this->snippet,
        ];
    }

    /**
     * @param  NameSearchResultArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: NameSearchSource::from($data['source']),
            title: $data['title'],
            shopLabel: $data['shopLabel'],
            host: $data['host'],
            url: $data['url'],
            ean: $data['ean'],
            price: $data['price'],
            packSize: $data['packSize'],
            photoUrl: $data['photoUrl'],
            snippet: $data['snippet'],
        );
    }
}
