<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Subscribing now accepts only a browser push service endpoint and keeps
 * the newest 10 rows per account. This removes the rows stored before
 * those checks. The host list is a copy of the one in
 * `App\Rules\PushServiceEndpoint` on this date.
 */
return new class extends Migration {
    private const array HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];

    private const array HOST_SUFFIXES = [
        '.notify.windows.com',
    ];

    private const int SUBSCRIPTIONS_PER_ACCOUNT = 10;

    public function up(): void
    {
        DB::table('push_subscriptions')->select(['id', 'endpoint'])->chunkById(500, function (Collection $rows): void {
            $refused = $rows->reject(fn (object $row): bool => is_string($row->endpoint) && $this->isPushServiceEndpoint($row->endpoint))->pluck('id');

            if ($refused->isNotEmpty()) {
                DB::table('push_subscriptions')->whereIn('id', $refused)->delete();
            }
        });

        DB::statement(
            'DELETE FROM push_subscriptions WHERE id IN (
                SELECT id FROM (
                    SELECT id, row_number() OVER (PARTITION BY subscribable_type, subscribable_id ORDER BY id DESC) AS position
                    FROM push_subscriptions
                ) ranked
                WHERE position > ?
            )',
            [self::SUBSCRIPTIONS_PER_ACCOUNT],
        );
    }

    public function down(): void {}

    private function isPushServiceEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        return in_array($host, self::HOSTS, strict: true)
            || array_any(self::HOST_SUFFIXES, static fn (string $suffix): bool => str_ends_with($host, $suffix));
    }
};
