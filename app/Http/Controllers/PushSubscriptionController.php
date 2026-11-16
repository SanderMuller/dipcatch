<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PushServiceEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SanderMuller\FluentValidation\FluentRule;

final class PushSubscriptionController extends Controller
{
    public const int SUBSCRIPTIONS_PER_USER = 10;

    public function store(Request $request): JsonResponse
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null} $data */
        $data = $request->validate([
            'endpoint' => FluentRule::url()->required()->max(500)->rule(new PushServiceEndpoint()),
            'keys.p256dh' => FluentRule::string()->required()->max(255),
            'keys.auth' => FluentRule::string()->required()->max(255),
            'contentEncoding' => FluentRule::string()->nullable()->max(64),
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? null,
        );

        // Browsers replace their endpoint now and then, so the oldest goes
        // rather than the new one being refused.
        $newest = $user->pushSubscriptions()
            ->latest('id')
            ->limit(self::SUBSCRIPTIONS_PER_USER)
            ->pluck('id');

        $user->pushSubscriptions()->whereNotIn('id', $newest)->delete();

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var array{endpoint: string} $data */
        $data = $request->validate([
            'endpoint' => FluentRule::url()->required()->max(500),
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->deletePushSubscription($data['endpoint']);

        return response()->json(['ok' => true]);
    }
}
