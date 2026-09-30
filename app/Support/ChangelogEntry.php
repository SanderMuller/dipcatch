<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ChangelogCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * One entry on the "What's new" page. {@see Changelog} reads them.
 */
final readonly class ChangelogEntry
{
    /**
     * @param  array{route: string, label: string}|null  $link  Where the feature lives in the app.
     * @param  string|null  $video  Slug of `public/changelog/<slug>.mp4` and its `.jpg` poster.
     * @param  array{src: string, alt: string}|null  $image  A screenshot, `src` relative to `public/`.
     */
    public function __construct(
        public CarbonImmutable $date,
        public ChangelogCategory $category,
        public string $title,
        public string $body,
        public ?array $link = null,
        public ?string $video = null,
        public ?array $image = null,
    ) {}

    public function linkUrl(): ?string
    {
        return $this->link === null ? null : route($this->link['route']);
    }

    /**
     * Whether the link stays in the signed-in app, where `wire:navigate`
     * swaps the page. A public page such as the shops list has its own
     * layout and needs a full page load.
     */
    public function linkIsInApp(): bool
    {
        $route = $this->link === null ? null : Route::getRoutes()->getByName($this->link['route']);

        return $route !== null && in_array('auth', $route->gatherMiddleware(), strict: true);
    }

    public function videoUrl(): ?string
    {
        return $this->video === null ? null : asset("changelog/{$this->video}.mp4");
    }

    public function posterUrl(): ?string
    {
        return $this->video === null ? null : asset("changelog/{$this->video}.jpg");
    }

    /**
     * The screenshot's pixel size, so the page reserves its space before it
     * loads. Null when the file is missing; the page then lets it size itself.
     *
     * @return array{width: int, height: int}|null
     */
    public function imageSize(): ?array
    {
        if ($this->image === null) {
            return null;
        }

        $size = @getimagesize(public_path($this->image['src']));

        return $size === false ? null : ['width' => $size[0], 'height' => $size[1]];
    }

    /**
     * The body split on blank lines, so the view can print plain text as
     * paragraphs without rendering any markup.
     *
     * @return list<string>
     */
    public function paragraphs(): array
    {
        $paragraphs = preg_split('/\R\s*\R/', trim($this->body)) ?: [];

        return array_values(array_filter(array_map(trim(...), $paragraphs), static fn (string $paragraph): bool => $paragraph !== ''));
    }
}
