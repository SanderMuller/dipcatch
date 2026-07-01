<?php declare(strict_types=1);

use Hihaho\RectorRules\Rector\CodeQuality\FirstPartyFlagArgumentToNamedRector;
use Hihaho\RectorRules\Rector\CodeQuality\NamedArgumentFromManifestRector;
use Hihaho\RectorRules\Rector\CodeQuality\NativeFunctionFlagArgumentToNamedRector;
use Hihaho\RectorRules\Rector\CodeQuality\RemoveDefaultValuedArgumentRector;
use Hihaho\RectorRules\Rector\Routing\MiddlewareStringToClassRector;
use Hihaho\RectorRules\Rector\Testing\TestFieldStringToConstantRector;
use Hihaho\RectorRules\Set\HihahoSetList;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Rector\Arguments\Rector\ClassMethod\ArgumentAdderRector;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\FuncCall\InlineIsAInstanceOfRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\CombineIfRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Php70\Rector\StaticCall\StaticCallOnNonStaticToInstanceCallRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Php82\Rector\Param\AddSensitiveParameterAttributeRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitSelfCallRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\ArrayDimFetch\RequestVariablesToRequestFacadeRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\MethodCall\RedirectRouteToToRouteHelperRector;
use RectorLaravel\Rector\MethodCall\ReplaceServiceContainerCallArgRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\Packages\Livewire\LivewireLevelSetList;
use SanderMuller\FluentValidationRector\Rector\SimplifyRuleWrappersRector;
use SanderMuller\FluentValidationRector\Rector\UpdateRulesReturnTypeDocblockRector;
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;

// Match worker count to the host's cores. A fixed count oversubscribes a 2-4 vCPU
// CI runner several times over (each worker bootstraps the full container), which is
// slower than matching cores. Detected inline to keep this config self-contained.
// Clamp between 2 and 15: the floor keeps some parallelism, the -1 reserves a core,
// and the ceiling caps the worst case at the previous fixed value, so a host that
// reports its full core count (e.g. a CPU-quota limited container that sees host
// cores rather than its vCPU share) can never spawn more workers than before.
// Resolves to ~13 on a 14-core dev machine and 2-3 on CI.
$detectCpuCores = static function (): int {
    // Linux and CI runners: count processor entries directly, which also works when
    // shell_exec is disabled. Anchored per line so the first entry is not missed.
    if (is_readable('/proc/cpuinfo')) {
        $processors = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));

        if ($processors > 0) {
            return $processors;
        }
    }

    // macOS (sysctl), with an nproc fallback for other Unixes. Guarded so a disabled
    // shell_exec (disable_functions) falls through to the default instead of erroring;
    // the command is a fixed literal, so there is no injection surface.
    if (function_exists('shell_exec')) {
        $cores = trim((string) shell_exec('sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null'));

        if (is_numeric($cores)) {
            return (int) $cores;
        }
    }

    return 4;
};

$maxParallelProcesses = max(2, min(15, $detectCpuCores() - 1));

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
 * @see https://github.com/rectorphp/rector/blob/main/docs/rector_rules_overview.md
 * @see https://github.com/driftingly/rector-laravel/blob/main/docs/rector_rules_overview.md
 */
return RectorConfig::configure()
    ->withCache(
        cacheDirectory: './.cache/rector',
        cacheClass: FileCacheStorage::class,
        containerCacheDirectory: './.cache/rectorContainer',
    )
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/config',
        __DIR__ . '/database/factories',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
        __DIR__ . '/bootstrap',
    ])
    ->withConfiguredRule(SimplifyRuleWrappersRector::class, [
        SimplifyRuleWrappersRector::ALLOW_CHAIN_TAIL_ON_ALLOWLISTED => true,
    ])
    ->withConfiguredRule(UpdateRulesReturnTypeDocblockRector::class, [
        SimplifyRuleWrappersRector::ALLOW_CHAIN_TAIL_ON_ALLOWLISTED => true,
    ])
    ->withConfiguredRule(AddSensitiveParameterAttributeRector::class, [
        'sensitive_parameters' => [
            'appKey',
            'confirm_password',
            'confirmPassword',
            'old_password',
            'oldPassword',
            'confirmed_password',
            'confirmedPassword',
            'current_password',
            'currentPassword',
            'newPassword',
            'password',
            'plainTextPassword',
            'secret',
            'token',
            'two_factor_secret',
        ],
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
        // declared on ThrottleRequests, so excluding the base covers the
        // ThrottleRequestsWithRedis subclass this app actually calls.
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
        // `throttle` resolves to ThrottleRequestsWithRedis in this app (Kernel.php), so
        // the class is safe to supply explicitly — the rule cannot infer it from the call site.
        MiddlewareStringToClassRector::INCLUDE_THROTTLE => true,
        MiddlewareStringToClassRector::THROTTLE_CLASS => ThrottleRequestsWithRedis::class,
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
    ->withRules([
        PrivatizeFinalClassPropertyRector::class,
        PreferPHPUnitSelfCallRector::class,
    ])
    /**
     * Prevent syntax like `$request->validated(null, null)`
     * @see https://getrector.org/documentation/ignoring-rules-or-paths
     * @see vendor/driftingly/rector-laravel/config/sets/laravel90.php:61
     */
    ->withSkip([
        AddSensitiveParameterAttributeRector::class => [
            __DIR__ . '/tests/*',
        ],
        AddOverrideAttributeToOverriddenMethodsRector::class,
        AddOverrideAttributeToOverriddenPropertiesRector::class,
        // Larastan can't introspect models that declare their table via
        // #[Table] attribute — it still reads `protected $table`. Keep the
        // property form until the larastan upgrade resolves.
        TablePropertyToTableAttributeRector::class,
        AppToResolveRector::class,
        ApplyDefaultInsteadOfNullCoalesceRector::class,
        ArgumentAdderRector::class,
        CarbonToDateFacadeRector::class,
        ChangeOrIfContinueToMultiContinueRector::class,
        ClosureToArrowFunctionRector::class,
        CombineIfRector::class,
        CompleteDynamicPropertiesRector::class,
        DeclareStrictTypesRector::class, // Performed by Pint
        ExplicitBoolCompareRector::class,
        EnvVariableToEnvHelperRector::class,
        ExplicitBoolCompareRector::class,
        ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/routes',
            __DIR__ . '/config',
        ],
        FlipTypeControlToUseExclusiveTypeRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        InlineIsAInstanceOfRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        NullToStrictStringFuncCallArgRector::class,
        PostIncDecToPreIncDecRector::class,
        PreferPHPUnitThisCallRector::class,
        RedirectRouteToToRouteHelperRector::class,
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
        ReplaceServiceContainerCallArgRector::class,
        RestoreDefaultNullToNullableTypePropertyRector::class,
        ReturnBinaryOrToEarlyReturnRector::class,
        RequestVariablesToRequestFacadeRector::class => [
            __DIR__ . '/tests/*',
        ],
        ServerVariableToRequestFacadeRector::class => [
            __DIR__ . '/tests/*',
        ],
        SimplifyIfElseToTernaryRector::class,
        SimplifyIfReturnBoolRector::class,
        StaticCallOnNonStaticToInstanceCallRector::class,
        __DIR__ . '/bootstrap/cache',
        __DIR__ . '/.cache',
    ])
    ->withSets([
        FluentValidationSetList::ALL,
        FluentValidationSetList::POLISH,
        FluentValidationSetList::SIMPLIFY,
        HihahoSetList::ALL,
        LaravelSetList::LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LivewireLevelSetList::UP_TO_LIVEWIRE,
    ])
    // If needed, we can update the parallel settings to make sure Rector doesn't start generating errors on large codebases
    ->withParallel(300, $maxParallelProcesses, 15)
    // here we can define, what prepared sets of rules will be applied
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: false,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: false,
        instanceOf: false,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withAttributesSets()
    ->withImportNames()
    ->withMemoryLimit('3G')
    ->withPhpSets(php85: true);
