<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One day of calls to one outside service for one purpose: how many went
 * out, how many failed, how many a daily cap refused, and Jev's tokens.
 *
 * @property int $id
 * @property CarbonImmutable $day
 * @property ApiService $service
 * @property string $purpose
 * @property int $calls
 * @property int $failures
 * @property int $refusals
 * @property int $input_tokens
 * @property int $output_tokens
 */
#[WithoutTimestamps]
#[Unguarded]
final class ApiUsageDay extends Model
{
    /**
     * A call that went out, answered or not. A Jev call its client retried
     * counts once, with the last attempt's outcome: the retries are for a
     * dropped connection, a rate limit or a server error.
     */
    public static function call(ApiService $service, string $purpose, bool $failed = false, int $inputTokens = 0, int $outputTokens = 0): void
    {
        self::add($service, $purpose, ['calls' => 1, 'failures' => $failed ? 1 : 0, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]);
    }

    /** A Jev call, with the tokens its answer reports. */
    public static function typeSafeCall(string $purpose, bool $failed, mixed $payload): void
    {
        $usage = is_array($payload) && is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        self::call(
            ApiService::TypeSafe,
            $purpose,
            failed: $failed,
            inputTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0,
            outputTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0,
        );
    }

    /** A call a daily cap kept from going out. */
    public static function refused(ApiService $service, string $purpose): void
    {
        self::add($service, $purpose, ['refusals' => 1]);
    }

    /**
     * Counting never stands in the way of the call it counts: a failed write
     * is logged and dropped.
     *
     * @param  array<string, int>  $amounts
     */
    private static function add(ApiService $service, string $purpose, array $amounts): void
    {
        $amounts += ['calls' => 0, 'failures' => 0, 'refusals' => 0, 'input_tokens' => 0, 'output_tokens' => 0];

        try {
            // In a transaction of its own: inside a caller's open transaction
            // that is a savepoint, so a failed write rolls back only itself
            // rather than aborting the caller's work on PostgreSQL.
            DB::transaction(fn () => self::query()->upsert(
                [['day' => now()->toDateString(), 'service' => $service->value, 'purpose' => $purpose, ...$amounts]],
                ['day', 'service', 'purpose'],
                [
                    'calls' => DB::raw('api_usage_days.calls + excluded.calls'),
                    'failures' => DB::raw('api_usage_days.failures + excluded.failures'),
                    'refusals' => DB::raw('api_usage_days.refusals + excluded.refusals'),
                    'input_tokens' => DB::raw('api_usage_days.input_tokens + excluded.input_tokens'),
                    'output_tokens' => DB::raw('api_usage_days.output_tokens + excluded.output_tokens'),
                ],
            ));
        } catch (Throwable $e) {
            Log::warning('API usage was not counted.', ['service' => $service->value, 'purpose' => $purpose, 'exception' => $e]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'service' => ApiService::class,
            'calls' => 'integer',
            'failures' => 'integer',
            'refusals' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }
}
