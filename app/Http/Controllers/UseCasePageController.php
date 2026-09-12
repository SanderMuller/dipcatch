<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\UseCases;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One landing page per repeat-purchase category. The route's slug constraint
 * is built from config, so a slug configured without copy reaches this and
 * would render an empty page. Hence the guard.
 */
final class UseCasePageController extends Controller
{
    public function __invoke(string $slug): View
    {
        $useCase = UseCases::find($slug);

        if ($useCase === null) {
            throw new NotFoundHttpException();
        }

        return view('use-case', ['useCase' => $useCase]);
    }
}
