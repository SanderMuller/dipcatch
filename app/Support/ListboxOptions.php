<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The options of a Flux listbox select, rendered once for each list.
 *
 * Flux renders every option as a Blade component with its own check icon,
 * about 0.13 ms apiece. The 666 timezones and countries in the regional
 * settings cost about 85 ms on each render, and Livewire renders the
 * component again on every save. The markup holds no selection, because the
 * browser marks the selected option, so one rendering serves every later
 * render in the same process.
 */
final class ListboxOptions
{
    /** @var array<string, HtmlString> */
    private static array $rendered = [];

    /**
     * @param  array<string, string>  $options  value => label
     */
    public static function html(array $options): HtmlString
    {
        return self::$rendered[hash('xxh128', serialize($options))] ??= new HtmlString(
            view('partials.listbox-options', ['options' => $options])->render(),
        );
    }
}
