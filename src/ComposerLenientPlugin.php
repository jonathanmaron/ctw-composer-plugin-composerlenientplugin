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
 * Relaxes the upper bound of selected packages' `php` requirement at the moment
 * the dependency solver pool is built.
 *
 * Some upstream packages run cleanly on a newer PHP but cap their declared
 * `php` constraint (e.g. `~8.4.0`), which blocks installation even though the
 * code is compatible. This plugin widens that constraint in memory for an
 * explicit allowlist, leaving every other platform requirement enforced and
 * the real platform version untouched — so packages that genuinely require the
 * newer PHP still resolve normally.
 *
 * The relaxation is written into `composer.lock` during `composer update`, so
 * downstream `composer install` (deploys) read the relaxed constraint from the
 * lock and pass the platform check without any flag.
 *
 * Configuration lives in the root package `extra` block:
 *
 *     "extra": {
 *         "ctw": {
 *             "ctw-composer-plugin-composerlenientplugin": {
 *                 "allow": "^8.5",
 *                 "packages": [
 *                     "laminas/laminas-serializer",
 *                     "laminas/laminas-tag"
 *                 ]
 *             }
 *         }
 *     }
 *
 * `allow` defaults to `^8.5` when omitted (8.5 up to, but not including, 9.0).
 * Only packages named in `packages`
 * are touched; everything else resolves untouched.
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

    /**
     * Default relaxation applied when
     * `extra.ctw.ctw-composer-plugin-composerlenientplugin.allow` is unset.
     */
    private const string DEFAULT_ALLOW = '^8.5';

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
        $config = $this->resolveConfig();

        if ([] === $config['packages']) {
            return;
        }

        $allow    = (new VersionParser())->parseConstraints($config['allow']);
        $packages = $event->getPackages();

        foreach ($packages as $package) {
            foreach ($this->mutableTargets($package) as $target) {
                if (false === \in_array($target->getName(), $config['packages'], true)) {
                    continue;
                }

                $this->relaxPhpRequirement($target, $allow, $config['allow']);
            }
        }

        $event->setPackages($packages);
    }

    /**
     * Resolves the allowlist and relaxation target from the root package extra.
     *
     * @return array{allow: string, packages: list<string>}
     */
    private function resolveConfig(): array
    {
        $extra  = $this->composer->getPackage()
            ->getExtra();
        $vendor = (\is_array($extra[self::EXTRA_VENDOR_KEY] ?? null)) ? $extra[self::EXTRA_VENDOR_KEY] : [];
        $raw    = (\is_array($vendor[self::EXTRA_PLUGIN_KEY] ?? null)) ? $vendor[self::EXTRA_PLUGIN_KEY] : [];

        $allow = (\is_string($raw['allow'] ?? null)) ? $raw['allow'] : self::DEFAULT_ALLOW;

        $packages = [];
        foreach ((array) ($raw['packages'] ?? []) as $name) {
            if (\is_string($name) && '' !== $name) {
                $packages[] = $name;
            }
        }

        return [
            'allow'    => $allow,
            'packages' => $packages,
        ];
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
     * Replaces the package's `php` requirement with `original || allow`,
     * preserving the original lower bound while permitting the newer PHP.
     */
    private function relaxPhpRequirement(Package $package, ConstraintInterface $allow, string $allowPretty): void
    {
        $requires = $package->getRequires();

        if (false === isset($requires['php'])) {
            return;
        }

        $php = $requires['php'];

        $requires['php'] = new Link(
            $php->getSource(),
            'php',
            MultiConstraint::create([$php->getConstraint(), $allow], false),
            Link::TYPE_REQUIRE,
            $php->getPrettyConstraint() . ' || ' . $allowPretty,
        );

        $package->setRequires($requires);

        $this->io->write(
            \sprintf(
                '<info>ctw-composer-plugin-composerlenientplugin:</info> relaxed php for <comment>%s</comment> -> %s',
                $package->getName(),
                $requires['php']->getPrettyConstraint(),
            ),
            true,
            IOInterface::VERBOSE,
        );
    }
}
