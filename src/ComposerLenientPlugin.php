<?php
declare(strict_types=1);

namespace Ctw\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\VersionParser;

/**
 * Relaxes the upper bound of selected packages' requirements at the moment the
 * dependency solver pool is built.
 *
 * A requirement is widened to `original || allow`, preserving the original lower
 * bound. Two kinds of requirement are handled identically:
 *
 *  - The `php` platform requirement. Some upstream packages run cleanly on a
 *    newer PHP but cap their declared `php` constraint (e.g. `~8.4.0`), which
 *    blocks installation even though the code is compatible.
 *  - Any other package requirement (e.g. `laminas/laminas-servicemanager`). Some
 *    packages are already compatible with a newer major of a dependency but have
 *    not yet released the widened constraint (the upstream change is a one-line
 *    `composer.json` edit on a not-yet-tagged branch). This lets the newer major
 *    resolve without forking or editing vendor.
 *
 * Packages that genuinely require the newer version still resolve normally, and
 * the real platform version is never changed.
 *
 * The relaxation is written into `composer.lock` during `composer update`, so
 * downstream `composer install` (deploys) read the relaxed constraint from the
 * lock and pass resolution without any flag.
 *
 * Configuration is a list of rules under the root package `extra` block. `php`
 * is just another requirement, so every rule has the same shape — there is no
 * special case and no default; all values are set explicitly:
 *
 *     "extra": {
 *         "ctw": {
 *             "ctw-composer-plugin-composerlenientplugin": [
 *                 {
 *                     "require": "php",
 *                     "allow": "^8.5",
 *                     "packages": [
 *                         "laminas/laminas-serializer",
 *                         "laminas/laminas-tag"
 *                     ]
 *                 },
 *                 {
 *                     "require": "laminas/laminas-servicemanager",
 *                     "allow": "^4.0",
 *                     "packages": [
 *                         "laminas/laminas-form",
 *                         "laminas/laminas-inputfilter"
 *                     ]
 *                 }
 *             ]
 *         }
 *     }
 *
 * Each rule names the requirement to widen (`require`), the constraint to OR onto
 * it (`allow`), and the `packages` whose declared constraint on that requirement
 * should be widened. A rule missing any of the three, or naming a requirement a
 * package does not declare, is skipped.
 *
 * Note: the allowlist targets tagged upstream releases. Branch/dev aliases are
 * intentionally out of scope, as the affected packages resolve to plain tags.
 */
final class ComposerLenientPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * Root `extra` key namespacing CTW package configuration, so further CTW
     * packages can register their own sub-keys beside this plugin's.
     */
    private const string EXTRA_VENDOR_KEY = 'ctw';

    /**
     * Key under `extra.ctw` holding this plugin's configuration.
     */
    private const string EXTRA_PLUGIN_KEY = 'ctw-composer-plugin-composerlenientplugin';

    private Composer $composer;

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
        ];
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        $rules = $this->resolveRules();

        if ([] === $rules) {
            return;
        }

        $packages = $event->getPackages();

        foreach ($packages as $package) {
            foreach ($this->mutableTargets($package) as $target) {
                $name = $target->getName();

                foreach ($rules as $rule) {
                    if (\in_array($name, $rule['packages'], true)) {
                        $this->relaxRequirement($target, $rule['require'], $rule['allow'], $rule['allowPretty']);
                    }
                }
            }
        }

        $event->setPackages($packages);
    }

    /**
     * Resolves the list of relaxation rules from the root package extra.
     *
     * @return list<array{require: string, allow: ConstraintInterface, allowPretty: string, packages: list<string>}>
     */
    private function resolveRules(): array
    {
        $extra  = $this->composer->getPackage()
            ->getExtra();
        $vendor = (\is_array($extra[self::EXTRA_VENDOR_KEY] ?? null)) ? $extra[self::EXTRA_VENDOR_KEY] : [];
        $raw    = (\is_array($vendor[self::EXTRA_PLUGIN_KEY] ?? null)) ? $vendor[self::EXTRA_PLUGIN_KEY] : [];

        $parser = new VersionParser();

        $rules = [];
        foreach ($raw as $entry) {
            if (false === \is_array($entry)) {
                continue;
            }

            $require = (\is_string($entry['require'] ?? null)) ? $entry['require'] : '';
            $allow   = (\is_string($entry['allow'] ?? null)) ? $entry['allow'] : '';
            if ('' === $require || '' === $allow) {
                continue;
            }

            $packages = $this->parseNames($entry['packages'] ?? []);
            if ([] === $packages) {
                continue;
            }

            $rules[] = [
                'require'     => $require,
                'allow'       => $parser->parseConstraints($allow),
                'allowPretty' => $allow,
                'packages'    => $packages,
            ];
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function parseNames(mixed $raw): array
    {
        $names = [];
        foreach ((array) $raw as $name) {
            if (\is_string($name) && '' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Returns every concrete, mutable Package behind a pool entry, unwrapping
     * aliases so the relaxation lands on the real package definition.
     *
     * @return list<Package>
     */
    private function mutableTargets(object $package): array
    {
        $targets = [];

        if ($package instanceof Package) {
            $targets[] = $package;
        }

        if ($package instanceof AliasPackage) {
            $aliasOf = $package->getAliasOf();
            if ($aliasOf instanceof Package) {
                $targets[] = $aliasOf;
            }
        }

        return $targets;
    }

    /**
     * Replaces the named requirement with `original || allow`, preserving the
     * original lower bound while permitting the newer version.
     */
    private function relaxRequirement(
        Package $package,
        string $require,
        ConstraintInterface $allow,
        string $allowPretty
    ): void {
        $requires = $package->getRequires();

        if (false === isset($requires[$require])) {
            return;
        }

        $link = $requires[$require];

        $requires[$require] = new Link(
            $link->getSource(),
            $require,
            MultiConstraint::create([$link->getConstraint(), $allow], false),
            Link::TYPE_REQUIRE,
            $link->getPrettyConstraint() . ' || ' . $allowPretty,
        );

        $package->setRequires($requires);

        $this->io->write(
            \sprintf(
                '<info>ctw-composer-plugin-composerlenientplugin:</info> relaxed %s for <comment>%s</comment> -> %s',
                $require,
                $package->getName(),
                $requires[$require]->getPrettyConstraint(),
            ),
            true,
            IOInterface::VERBOSE,
        );
    }
}
