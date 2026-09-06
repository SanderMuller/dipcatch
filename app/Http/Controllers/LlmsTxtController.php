<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\Plan;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

/**
 * `/llms.txt`, the plain-text summary of this site for AI assistants.
 *
 * A controller rather than `Route::view` because the response has to declare
 * `text/plain`. The numbers come from config, so the file cannot drift from
 * what the app actually does.
 *
 * @see https://llmstxt.org
 */
final class LlmsTxtController extends Controller
{
    public function __invoke(): Response
    {
        $free = Entitlements::of(Plan::Free);

        $body = view('llms', [
            'maxProducts' => $free->maxProducts(),
            'maxShopsPerProduct' => $free->maxShopsPerProduct(),
            'recheckIntervalHours' => Config::get('dipcatch.recheck.interval_hours', 6),
            'contactEmail' => Config::get('site.contact_email'),
        ])->render();

        return response($body)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
