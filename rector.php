<?php declare(strict_types=1);

use Hihaho\RectorRules\Set\HihahoSetList;
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
use RectorLaravel\Rector\ClassMethod\ScopeNamedClassMethodToScopeAttributedClassMethodRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
use RectorLaravel\Rector\MethodCall\RedirectRouteToToRouteHelperRector;
use RectorLaravel\Rector\MethodCall\ReplaceServiceContainerCallArgRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\Packages\Livewire\LivewireLevelSetList;
use SanderMuller\FluentValidationRector\Rector\SimplifyRuleWrappersRector;
use SanderMuller\FluentValidationRector\Rector\UpdateRulesReturnTypeDocblockRector;
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;

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
        ContainerBindConcreteWithClosureOnlyRector::class,
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
        ScopeNamedClassMethodToScopeAttributedClassMethodRector::class,
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
    ->withParallel(300, 15, 15)
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
//    ->withIndent()
//    ->withFluentCallNewLine()
    ->withAttributesSets()
    ->withImportNames()
    ->withMemoryLimit('3G')
    ->withPhpSets(php85: true);
