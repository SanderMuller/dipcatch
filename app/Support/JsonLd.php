<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;
use JsonException;

/**
 * Renders a PHP array as a JSON-LD `<script>` element.
 *
 * The JSON is encoded with JSON_HEX_TAG so a literal `</script>` inside the
 * data (for example inside a translated FAQ answer) cannot end the script
 * element early — it comes out as `</script>` instead.
 */
final class JsonLd
{
    /**
     * @param  array<mixed>  $data
     *
     * @throws JsonException
     */
    public static function script(array $data): HtmlString
    {
        $json = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new HtmlString('<script type="application/ld+json">' . $json . '</script>');
    }
}
