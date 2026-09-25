<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Turns one systemone answer into a verdict: every real path scored as the
 * geometric mean of its department and leaf probabilities, the best path
 * checked against the floor and the separation ratio, and `Other` judged
 * alone on the department answer.
 */
final class CategoryScorer
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TypeSafeRequestFailed
     */
    public static function verdict(array $payload): CategoryVerdict
    {
        $answers = $payload['answers'] ?? null;
        $departmentAnswer = is_array($answers) ? ($answers[TypeSafeClient::DEPARTMENT_QUESTION] ?? null) : null;
        $departmentProbabilities = is_array($departmentAnswer) ? ($departmentAnswer['probabilities'] ?? null) : null;

        if (! is_array($answers) || ! is_array($departmentProbabilities) || $departmentProbabilities === []) {
            throw new TypeSafeRequestFailed('TypeSafe answered without department probabilities.');
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : null;

        if ($usage === null) {
            Log::warning('TypeSafe answered without a usage block; token cost is unknown for this call.');
        }

        $inputTokens = self::count($usage['input_tokens'] ?? null);
        $outputTokens = self::count($usage['output_tokens'] ?? null);

        Log::info('TypeSafe categorisation answered.', [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);

        /** @var array<string, float> $scores category value => path score */
        $scores = [];

        self::assertRecognised($departmentProbabilities, array_map(
            static fn (ProductDepartment $department): string => $department->value,
            ProductDepartment::cases(),
        ), 'department');

        foreach (ProductDepartment::real() as $department) {
            $leafAnswer = $answers[TypeSafeClient::LEAF_QUESTION_PREFIX . $department->value] ?? null;
            $leafProbabilities = is_array($leafAnswer) ? ($leafAnswer['probabilities'] ?? null) : null;

            if (! is_array($leafProbabilities)) {
                throw new TypeSafeRequestFailed('TypeSafe answered without the leaf answer ' . TypeSafeClient::LEAF_QUESTION_PREFIX . "{$department->value}.");
            }

            $leafKeys = array_map(static fn (ProductCategory $category): string => $category->leafKey(), $department->categories());
            self::assertRecognised($leafProbabilities, $leafKeys, "leaf_{$department->value}");

            $departmentProbability = self::probability($departmentProbabilities, $department->value);

            foreach ($department->categories() as $category) {
                $leafProbability = self::probability($leafProbabilities, $category->leafKey());
                $scores[$category->value] = sqrt($departmentProbability * $leafProbability);
            }
        }

        arsort($scores, SORT_NUMERIC);

        $ranked = array_keys($scores);
        $winner = isset($ranked[0]) ? ProductCategory::from((string) $ranked[0]) : null;
        $runnerUp = isset($ranked[1]) ? ProductCategory::from((string) $ranked[1]) : null;
        $pathScore = $winner === null ? 0.0 : $scores[$winner->value];
        $runnerUpScore = $runnerUp === null ? 0.0 : $scores[$runnerUp->value];
        $separation = $runnerUpScore > 0.0 ? $pathScore / $runnerUpScore : INF;

        // `>=`: an exact tie between Other and a real department reads as Other.
        $otherOnTop = self::probability($departmentProbabilities, ProductDepartment::Other->value) >= self::highest($departmentProbabilities);

        $stored = $winner !== null
            && ! $otherOnTop
            && $pathScore >= Config::float('dipcatch.categories.min_path_score')
            && $separation >= Config::float('dipcatch.categories.min_separation');

        return new CategoryVerdict(
            category: $stored ? $winner : null,
            winner: $winner,
            runnerUp: $runnerUp,
            pathScore: $pathScore,
            separation: $separation,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    /**
     * @param  array<mixed>  $probabilities
     */
    private static function probability(array $probabilities, string $key): float
    {
        $value = $probabilities[$key] ?? 0.0;

        return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    /**
     * An answer whose keys or values match nothing the request asked for is
     * a broken integration, not a shy model. Scoring it would report a
     * plausible winner at score zero and pay for the same rows every run.
     *
     * @param  array<mixed>  $probabilities
     * @param  list<string>  $expectedKeys
     *
     * @throws TypeSafeRequestFailed
     */
    private static function assertRecognised(array $probabilities, array $expectedKeys, string $question): void
    {
        foreach ($expectedKeys as $key) {
            if (is_numeric($probabilities[$key] ?? null)) {
                return;
            }
        }

        throw new TypeSafeRequestFailed("TypeSafe answered {$question} with no recognisable probability.");
    }

    /**
     * @param  array<mixed>  $probabilities
     */
    private static function highest(array $probabilities): float
    {
        $highest = 0.0;

        foreach ($probabilities as $value) {
            $highest = max($highest, is_numeric($value) ? (float) $value : 0.0);
        }

        return $highest;
    }

    private static function count(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
