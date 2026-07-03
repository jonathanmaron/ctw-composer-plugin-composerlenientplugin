<?php
declare(strict_types=1);

use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultFileExtensions;
use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultIndentation;
use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultLineEnding;
use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultRules;
use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultRulesWithConfiguration;
use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultSets;
use Ctw\Qa\EasyCodingStandard\Config\ECSConfig\DefaultSkip;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\Configuration\ECSConfigBuilder;

// Wrapped in an immediately-invoked closure: ECS require()s this file in the
// scope of its container factory, where the container is held in a variable
// named $ecsConfig. Building at file scope would clobber it; the closure keeps
// every local contained.
return (static function (): ECSConfigBuilder {
    $fileExtensions = new DefaultFileExtensions();
    $indentation    = new DefaultIndentation();
    $lineEnding     = new DefaultLineEnding();
    $rules          = new DefaultRules();
    $rulesConfig    = new DefaultRulesWithConfiguration();
    $sets           = new DefaultSets();
    $skip           = new DefaultSkip();

    $ecsConfig = ECSConfig::configure()
        // Parallel mode spawns worker sub-processes that coordinate over a
        // React TCP socket. Because this is a Composer plugin, the project
        // pulls in composer/composer -> react/promise, whose Composer
        // file-id collides with the copy php-scoper bundles inside ECS. The
        // unprefixed react/promise loads first and marks that file-id as
        // already-loaded, so ECS's scoped functions_include.php is skipped and
        // ECSPrefix...\React\Promise\resolve() is never defined — the worker
        // then fatals. Running single-process sidesteps the socket path.
        ->withoutParallel()
        ->withFileExtensions($fileExtensions())
        ->withSpacing($indentation(), $lineEnding())
        ->withPaths([
            sprintf('%s/src', __DIR__),
            sprintf('%s/test', __DIR__),
            sprintf('%s/ecs.php', __DIR__),
            sprintf('%s/rector.php', __DIR__),
        ])
        ->withSets($sets())
        ->withRules($rules())
        ->withSkip($skip());

    foreach ($rulesConfig() as $checkerClass => $configuration) {
        $ecsConfig->withConfiguredRule($checkerClass, $configuration);
    }

    return $ecsConfig;
})();
