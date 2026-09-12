<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

final class OpenaiAppsChallengeController extends Controller
{
    public function __invoke(): Response
    {
        $token = Config::get('dipcatch.openai_apps_challenge');

        if (! is_string($token) || $token === '') {
            return response('', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return response($token, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
