<?php
declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$configuration = new Configuration();

/*
 * A Composer plugin is loaded by Composer itself, so every Composer\* symbol
 * its source names is provided by the host rather than by a dependency of this
 * package. Both findings below say the same thing in the analyser's vocabulary,
 * and neither has a fix in composer.json that would be an improvement.
 *
 * composer/composer is in require-dev, for the tests, and must not move to
 * require: a plugin that requires Composer would install a second copy of it
 * inside the project it is there to extend. Naming its classes from src/ is
 * therefore correct, and is reported as a dev dependency in production code.
 *
 * composer/semver arrives transitively, through composer/composer, so it is
 * reported as a shadow dependency. Declaring it would pin a version of a
 * package the host already supplies, which is the same mistake in smaller
 * print.
 *
 * Only the one error type is excluded for each, so a package that turns into a
 * genuine error of another kind is still reported.
 */
$configuration->ignoreErrorsOnPackage('composer/composer', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
$configuration->ignoreErrorsOnPackage('composer/semver', [ErrorType::SHADOW_DEPENDENCY]);

return $configuration;
