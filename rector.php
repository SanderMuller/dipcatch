<?php declare(strict_types=1);

use Hihaho\RectorRules\Rector\CodeQuality\FirstPartyFlagArgumentToNamedRector;
use Hihaho\RectorRules\Rector\CodeQuality\NamedArgumentFromManifestRector;
use Hihaho\RectorRules\Rector\CodeQuality\NativeFunctionFlagArgumentToNamedRector;
use Hihaho\RectorRules\Rector\CodeQuality\RemoveDefaultValuedArgumentRector;
use Hihaho\RectorRules\Rector\Routing\MiddlewareStringToClassRector;
use Hihaho\RectorRules\Rector\Testing\TestFieldStringToConstantRector;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Pest\Rector\Rules\SimplifyToLiteralBooleanRector;
use Pest\Rector\Rules\UseToStartWithRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\FuncCall\InlineIsAInstanceOfRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\DeadCode\Rector\StmtsAwareInterface\RemoveDeadInstanceOfAssertRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php81\Rector\MethodCall\SpatieEnumMethodCallToEnumConstRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\ArrayDimFetch\RequestVariablesToRequestFacadeRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\MethodCall\RedirectRouteToToRouteHelperRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use SanderMuller\FluentValidationRector\Rector\SimplifyRuleWrappersRector;
use SanderMuller\FluentValidationRector\Rector\UpdateRulesReturnTypeDocblockRector;
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;
use SanderMuller\RectorPreset\Preset;

// Mirror RouteServiceProvider's recursive File::allFiles(base_path('routes')) scan so
// TestFieldStringToConstantRector classifies endpoints declared in nested route files too.
$routeFiles = [];

foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/routes', FilesystemIterator::SKIP_DOTS)
    ) as $routeFile
) {
    if ($routeFile->getExtension() === 'php') {
        $routeFiles[] = $routeFile->getPathname();
    }
}

sort($routeFiles);

/**
 * The preset sets up the cache, the parallel workers, the paths, the prepared sets, the
 * extension sets and the shared skips. This file keeps only what is specific to this app.
 *
 * @see https://github.com/SanderMuller/rector-preset
 */
return Preset::app(typeDeclarationDocblocks: true)
    ->withConfiguredRule(SimplifyRuleWrappersRector::class, [
        SimplifyRuleWrappersRector::ALLOW_CHAIN_TAIL_ON_ALLOWLISTED => true,
    ])
    ->withConfiguredRule(UpdateRulesReturnTypeDocblockRector::class, [
        SimplifyRuleWrappersRector::ALLOW_CHAIN_TAIL_ON_ALLOWLISTED => true,
    ])
    ->withConfiguredRule(FirstPartyFlagArgumentToNamedRector::class, [
        FirstPartyFlagArgumentToNamedRector::FIRST_PARTY_NAMESPACES => ['App\\', 'Database\\Factories\\', 'Tests\\'],
        FirstPartyFlagArgumentToNamedRector::CASCADE_TRAILING_ARGS => true,
    ])
    ->withConfiguredRule(RemoveDefaultValuedArgumentRector::class, [
        RemoveDefaultValuedArgumentRector::FIRST_PARTY_NAMESPACES => ['App\\', 'Database\\Factories\\', 'Tests\\'],
        RemoveDefaultValuedArgumentRector::CASCADE_DROP => true,
        // The throttle middleware factory stringifies its arguments into a route
        // signature (`ThrottleRequests::with(60, 1)` → `throttle:60,1`), so dropping a
        // default that equals its parameter default still changes the serialized string
        // and makes the rate limit implicit on a security-adjacent route. `with()` is
        // declared on ThrottleRequests, so excluding the base also covers the
        // ThrottleRequestsWithRedis subclass.
        RemoveDefaultValuedArgumentRector::EXCLUDE_CALLS => [
            ThrottleRequests::class => ['with'],
        ],
    ])
    ->withConfiguredRule(NativeFunctionFlagArgumentToNamedRector::class, [
        NativeFunctionFlagArgumentToNamedRector::FUNCTION_FLAG_ARGUMENTS => [
            'in_array' => [2 => 'strict'],
            'array_search' => [2 => 'strict'],
        ],
    ])
    ->withConfiguredRule(MiddlewareStringToClassRector::class, [
        MiddlewareStringToClassRector::CONVERT_BARE_ALIASES => true,
        // `throttle` is left as an alias. This app has no `App\Http\Kernel` and
        // registers no throttle override, so the alias resolves to
        // `Illuminate\Routing\Middleware\ThrottleRequests`, not to the Redis
        // subclass. Naming a class here freezes that choice at the call site and
        // swaps the limiter on `routes/ai.php`, which guards an authenticated
        // endpoint. The alias keeps following whatever the app registers.
        MiddlewareStringToClassRector::INCLUDE_THROTTLE => false,
    ])
    /**
     * Consumes the manifest phpstan produces (see phpstan.neon namedArgumentManifest).
     * No-ops until phpstan has written the manifest, so run phpstan before rector to
     * apply it.
     */
    ->withConfiguredRule(NamedArgumentFromManifestRector::class, [
        NamedArgumentFromManifestRector::MANIFEST => __DIR__ . '/.config/named-arguments-manifest.json',
    ])
    /**
     * Aligns test request-payload keys with the endpoint's FormRequest constants, resolved
     * statically from the route files (internal endpoints → constant, public → wire literal).
     * Route files come from the recursive routes/ scan above (mirroring RouteServiceProvider);
     * the internal boundary is the bare Authenticate token (never injected via a group/alias in this app).
     */
    ->withConfiguredRule(TestFieldStringToConstantRector::class, [
        TestFieldStringToConstantRector::ROUTE_FILES => $routeFiles,
        TestFieldStringToConstantRector::INTERNAL_MIDDLEWARE => [Authenticate::class],
        TestFieldStringToConstantRector::FIRST_PARTY_PREFIX => 'App\\',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenPropertiesRector::class,
        // A queued job's or notification's declared default is what a payload
        // serialised before the property existed reads; a promoted property has none.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/app/Jobs/PrewarmShopSearches.php',
            __DIR__ . '/app/Notifications/PriceDropNotification.php',
            __DIR__ . '/app/Notifications/TargetPriceNotification.php',
            __DIR__ . '/app/Notifications/UnitPriceTargetNotification.php',
        ],
        RemoveDefaultValueFromAssignedPropertyRector::class => [
            __DIR__ . '/app/Jobs/PrewarmShopSearches.php',
            __DIR__ . '/app/Notifications/PriceDropNotification.php',
            __DIR__ . '/app/Notifications/TargetPriceNotification.php',
            __DIR__ . '/app/Notifications/UnitPriceTargetNotification.php',
        ],
        // Larastan can't introspect models that declare their table via
        // #[Table] attribute — it still reads `protected $table`. Keep the
        // property form until the larastan upgrade resolves.
        TablePropertyToTableAttributeRector::class,
        AppToResolveRector::class,
        CarbonToDateFacadeRector::class,
        EnvVariableToEnvHelperRector::class,
        ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/routes',
            __DIR__ . '/config',
        ],
        InlineIsAInstanceOfRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        RedirectRouteToToRouteHelperRector::class,
        // `assert($x instanceof Foo)` after a container `make()` is what narrows
        // `mixed` to the concrete type for PHPStan. Rector reads those asserts as
        // dead and drops them, which turns the call site into a `return.type` error.
        RemoveDeadInstanceOfAssertRector::class,
        RemoveNullPropertyInitializationRector::class => [
            __DIR__ . '/app/Http/Resources/*',
            __DIR__ . '/app/Http/Controllers/*',
        ],
        RemoveUnreachableStatementRector::class => [
            __DIR__ . '/tests/*',
        ],
        RenameClassRector::class,
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/app/Http/Controllers/*',
            __DIR__ . '/app/Policies/*',
        ],
        RequestVariablesToRequestFacadeRector::class => [
            __DIR__ . '/tests/*',
        ],
        ServerVariableToRequestFacadeRector::class => [
            __DIR__ . '/tests/*',
        ],
        // `->not->toBe('')` also narrows the value to `non-empty-string` for
        // PHPStan. `->not->toBeEmpty()` does not, so a later `toStartWith()` on
        // the same value fails. Only these two files depend on that narrowing.
        SimplifyToLiteralBooleanRector::class => [
            __DIR__ . '/tests/Feature/CrawlPolicyTest.php',
            __DIR__ . '/tests/Feature/StructuredDataTest.php',
        ],
        SimplifyIfReturnBoolRector::class,
        // `Spatie\Health\Enums\Status` extends `spatie/enum`, so its cases are
        // static methods, not constants. The rule rewrites `Status::ok()` to
        // `Status::OK` and that constant does not exist: a fatal at runtime.
        SpatieEnumMethodCallToEnumConstRector::class,
        // `toStartWith()` takes a `non-empty-string`, so it does not accept a
        // prefix that is read at runtime. The `str_starts_with()` form these
        // two files use also carries a failure message naming both values.
        UseToStartWithRector::class => [
            __DIR__ . '/tests/Feature/CrawlPolicyTest.php',
            __DIR__ . '/tests/Feature/StructuredDataTest.php',
        ],
    ])
    // The preset detects ALL, POLISH and SIMPLIFY.
    ->withSets([
        FluentValidationSetList::SCHEMA,
    ])
    // The preset turns this set off on a Pest project. The tests still extend PHPUnit's TestCase.
    ->withComposerBased(phpunit: true)
    ->withPhpSets(php85: true);
