<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Products\FindCataloguePhoto;
use App\Support\ImageUrl;
use Illuminate\Http\Request;
use SanderMuller\FluentValidation\FluentRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `<img>` source of a name search row without a photo. The link is
 * signed, so it only serves a page the wizard listed. No photo answers an
 * empty pixel rather than an error the browser logs, and the row keeps the
 * shop's icon.
 */
final class NameSearchPhotoController
{
    private const int BROWSER_CACHE_SECONDS = 86_400;

    /** A transparent 1×1 GIF. */
    private const string EMPTY_PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __invoke(Request $request, FindCataloguePhoto $findPhoto): Response
    {
        /** @var array{url: string} $data */
        $data = $request->validate([
            'url' => FluentRule::string()->required()->max(2048),
        ]);
        $url = $data['url'];

        abort_unless($findPhoto->covers($url), Response::HTTP_NOT_FOUND);

        $photo = $findPhoto($url);

        if ($photo === null) {
            return response(base64_decode(self::EMPTY_PIXEL), Response::HTTP_OK, [
                'Content-Type' => 'image/gif',
                // Short: the next search may find a photo the shop refused now.
                'Cache-Control' => 'private, max-age=600',
            ]);
        }

        return redirect()->away(ImageUrl::thumbnail($photo))
            ->header('Cache-Control', 'private, max-age=' . self::BROWSER_CACHE_SECONDS);
    }
}
