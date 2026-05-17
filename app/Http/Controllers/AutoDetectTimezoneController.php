<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\IanaTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Records the browser-detected IANA timezone for the authenticated user.
 *
 * The JS in the Filament app panel POSTs here fire-and-forget on every page
 * load while `timezone_detected_at IS NULL`. The update runs as a single
 * conditional SQL statement so a concurrent explicit save in
 * NotificationSettings cannot be clobbered: rows matching `id` but already
 * carrying a non-null `timezone_detected_at` are not touched.
 */
final class AutoDetectTimezoneController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{timezone: string} $data */
        $data = $request->validate([
            'timezone' => FluentRule::string()->required()->rule(new IanaTimezone()),
        ]);

        /** @var User $user */
        $user = $request->user();

        User::query()
            ->whereKey($user->id)
            ->whereNull('timezone_detected_at')
            ->update([
                'timezone' => $data['timezone'],
                'timezone_detected_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }
}
