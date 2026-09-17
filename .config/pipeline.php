<?php declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\Refactoring;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/*
 * Three pipelines, one question each — `withPurpose()` states which, and
 * `php artisan pipeline:list` prints them.
 *
 * What the purposes cannot say, because it is the reason for the split:
 * `closeout` holds no agent steps, so `pipeline:verify --pipeline=closeout`
 * can exit 0. `review` cannot live inside it, because Agent runs after Tests
 * and a red suite would then skip every review.
 */

/*
 * Every clone on this machine shares one PostgreSQL server, and `phpunit.xml.dist`
 * pins `DB_DATABASE=dipcatch_test` with no `force` attribute, so an exported value
 * wins. Two clones running the suite at once both `migrate:fresh`-wipe that one
 * database and corrupt each other mid-run. The primary checkout keeps the bare
 * name; a clone or worktree gets its own, derived from the path so the same
 * checkout reuses one database instead of multiplying them.
 */
if (! function_exists('pipelineTestDatabaseName')) {
    function pipelineTestDatabaseName(string $basePath): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '_', basename($basePath)));

        return $slug === 'dipcatch' ? 'dipcatch_test' : 'dipcatch_test_' . $slug;
    }
}

$testDatabase = pipelineTestDatabaseName(base_path());

$withTestDatabase = static fn (Shell $step): Shell => $step->withEnv(['DB_DATABASE' => $testDatabase]);

$formatting = static function (StepCollection $steps): void {
    // Pint skips dot-directories unless the path is named, and `.config/` holds
    // PHP that runs in the application. Untagged, so a frontend-scoped run still
    // checks PHP.
    $steps->append(Shell::run('vendor/bin/pint --test --parallel . .config'));
    // `yarn lint` is scoped to `git diff`, so it exits 0 without linting when no
    // JavaScript changed. `lint-all` is the honest gate.
    $steps->append(Shell::run('yarn lint-all')->tagged('frontend'));
};

$staticAnalysis = static function (StepCollection $steps): void {
    $steps->append(Shell::run('yarn typecheck')->tagged('frontend'));
    $steps->append(Shell::run('composer phpstan')->timeout(900)->tagged('backend'));
};

/*
 * The wrapper decides what "affected" means, so a change to it has to be checked
 * before its selection is trusted.
 */
$wrapperTest = static fn (): Shell => Shell::run(
    'bash tools/verify/affected-tests.test.sh tools/verify/affected-tests.sh',
    id: 'affected-tests-wrapper',
)->tagged('backend');

$affectedTests = static function (StepCollection $steps) use ($withTestDatabase, $wrapperTest): void {
    $steps->append($withTestDatabase(
        Shell::run('bash tools/verify/affected-tests.sh', id: 'affected-tests')->timeout(1500)->tagged('backend')
    ));
    $steps->append($wrapperTest());
};

return [
    'closeout' => Pipeline::configure()
        ->withPurpose('Gate the branch before a pull request. Adds Rector and the whole PHP suite to the change checks.')
        ->withSteps(static function (Steps $steps) use ($withTestDatabase, $formatting, $staticAnalysis, $wrapperTest): void {
            $steps->in(Refactoring::class)
                ->append(Shell::run('vendor/bin/rector process --dry-run')->timeout(900)->tagged('backend'));

            $steps->in(Formatting::class)->parallel($formatting);
            $steps->in(StaticAnalysis::class)->parallel($staticAnalysis);

            $steps->in(Tests::class)->parallel(static function (StepCollection $steps) use ($withTestDatabase, $wrapperTest): void {
                // The default 128M limit runs out partway through the whole suite
                // and reads like a test failure, so the limit is raised here.
                $steps->append($withTestDatabase(
                    Shell::run('php -d memory_limit=1G vendor/bin/pest --compact', id: 'test-php')->timeout(1500)->tagged('backend')
                ));
                $steps->append($wrapperTest());
            });
        }),

    'change' => Pipeline::configure()
        ->withPurpose('Check a change while the work continues. No Rector, no whole PHP suite — affected tests only.')
        ->withSteps(static function (Steps $steps) use ($formatting, $staticAnalysis, $affectedTests): void {
            $steps->in(Formatting::class)->parallel($formatting);
            $steps->in(StaticAnalysis::class)->parallel($staticAnalysis);
            $steps->in(Tests::class)->parallel($affectedTests);
        }),

    'review' => Pipeline::configure()
        ->withPurpose('Judge a green closeout. Agent steps only, so it never reports all_verified.')
        ->withSteps(static function (Steps $steps): void {
            $steps->in(Agent::class)
                ->append(
                    Skill::run(
                        '/evaluate',
                        id: 'self-review',
                        instruction: 'Run the review phases over the resolved scope. Skip the mechanical checks — closeout already ran them — and skip code-review and codex-review, which the steps after this one own.',
                    )->mutating()
                )
                ->append(Skill::run('/code-review'))
                ->append(Skill::run('/codex-review'));
        }),
];
