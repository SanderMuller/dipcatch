<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

final class SignupSourcesWidget extends TableWidget
{
    use InteractsWithVisitorRange;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Signups by source')
            ->description('Accounts created in the range, by the website and UTM source that first brought the person here.')
            ->records(function (): array {
                $records = [];
                $index = 1;

                foreach (['signup_referrer_host' => 'Referrer', 'signup_utm_source' => 'UTM source'] as $column => $kind) {
                    foreach ($this->countsBy($column) as $value => $signups) {
                        $records[$index++] = [
                            'kind' => $kind,
                            'value' => $value === '' ? ($column === 'signup_referrer_host' ? 'Direct' : 'None') : $value,
                            'signups' => $signups,
                        ];
                    }
                }

                return $records;
            })
            ->paginated(false)
            ->emptyStateHeading('No signups in this range')
            ->columns([
                TextColumn::make('kind')->label('By')->badge()->color('gray'),
                TextColumn::make('value')->label('Source'),
                TextColumn::make('signups')->label('Signups')->alignEnd(),
            ]);
    }

    /**
     * @param  'signup_referrer_host'|'signup_utm_source'  $column
     * @return array<string, int>
     */
    private function countsBy(string $column): array
    {
        $range = $this->visitorRange();
        $source = $column === 'signup_referrer_host' ? "COALESCE(signup_referrer_host, '')" : "COALESCE(signup_utm_source, '')";

        /** @var array<string, int> */
        return User::query()
            ->toBase()
            ->selectRaw($source . ' as source, COUNT(*) as signups')
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<', $range->until->addDay())
            ->groupByRaw($source)
            ->orderByDesc('signups')
            ->limit(10)
            ->pluck('signups', 'source')
            ->map(static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
            ->all();
    }
}
