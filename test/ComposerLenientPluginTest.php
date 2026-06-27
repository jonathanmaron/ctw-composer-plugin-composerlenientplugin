<?php
declare(strict_types=1);

namespace Ctw\Composer\Plugin\Test;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Ctw\Composer\Plugin\ComposerLenientPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerLenientPlugin::class)]
final class ComposerLenientPluginTest extends TestCase
{
    /**
     * A package that sits on the plugin's allowlist throughout the suite.
     */
    private const string ALLOWED = 'laminas/laminas-tag';

    public function testSubscribesToThePrePoolCreateEvent(): void
    {
        self::assertSame(
            [
                PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
            ],
            ComposerLenientPlugin::getSubscribedEvents(),
        );
    }

    public function testRelaxesPhpUpperBoundForAnAllowlistedPackage(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0');
        $plugin  = $this->pluginWithConfig([
            'allow' => '>=8.5',
            'packages' => [self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        $php = $package->getRequires()['php']
            ->getPrettyConstraint();
        self::assertStringContainsString('|| >=8.5', $php);
        self::assertTrue(Semver::satisfies('8.5.0', $php), 'newer PHP is now permitted');
        self::assertTrue(Semver::satisfies('8.3.0', $php), 'original lower bound is preserved');
        self::assertFalse(
            Semver::satisfies('8.5.0', '~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0'),
            'sanity: the original constraint really did block 8.5',
        );
    }

    public function testLeavesPackagesOutsideTheAllowlistUntouched(): void
    {
        $package = $this->packageWithPhp('laminas/laminas-other', '~8.1.0 || ~8.2.0');
        $plugin  = $this->pluginWithConfig([
            'allow' => '>=8.5',
            'packages' => [self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.1.0 || ~8.2.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    public function testDoesNothingWhenNoPackagesAreConfigured(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        $plugin  = $this->pluginWithConfig([
            'allow' => '>=8.5',
            'packages' => [],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    public function testDoesNothingWhenTheExtraConfigIsMissing(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        $plugin  = $this->pluginWithExtra([]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    public function testIgnoresAnAllowlistedPackageWithoutAPhpRequirement(): void
    {
        $package = new Package(self::ALLOWED, '2.13.0.0', '2.13.0');
        $package->setRequires([
            'laminas/laminas-stdlib' => new Link(
                self::ALLOWED,
                'laminas/laminas-stdlib',
                (new VersionParser())->parseConstraints('^3.6'),
                Link::TYPE_REQUIRE,
                '^3.6',
            ),
        ]);
        $plugin = $this->pluginWithConfig([
            'allow' => '>=8.5',
            'packages' => [self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertArrayNotHasKey('php', $package->getRequires());
    }

    public function testHonorsACustomAllowConstraint(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        $plugin  = $this->pluginWithConfig([
            'allow' => '>=8.6',
            'packages' => [self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        $php = $package->getRequires()['php']
            ->getPrettyConstraint();
        self::assertStringContainsString('|| >=8.6', $php);
        self::assertTrue(Semver::satisfies('8.6.0', $php));
        self::assertFalse(Semver::satisfies('8.5.0', $php));
    }

    public function testDefaultsToGreaterOrEqual85WhenAllowIsOmitted(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        $plugin  = $this->pluginWithConfig([
            'packages' => [self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        $php = $package->getRequires()['php']
            ->getPrettyConstraint();
        self::assertStringContainsString('|| >=8.5', $php);
        self::assertTrue(Semver::satisfies('8.5.0', $php));
    }

    public function testUnwrapsAnAliasPackageToRelaxTheUnderlyingPackage(): void
    {
        $real   = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        $alias  = new AliasPackage($real, '2.99.0.0', '2.99.0');
        $plugin = $this->pluginWithConfig([
            'allow' => '>=8.5',
            'packages' => [self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$alias]));

        self::assertStringContainsString('|| >=8.5', $real->getRequires()['php'] ->getPrettyConstraint());
    }

    public function testDoesNotReadTheLegacyFlatExtraKey(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        // Configuration deliberately placed at the old, un-namespaced location.
        $plugin = $this->pluginWithExtra([
            'ctw-composer-plugin-composerlenientplugin' => [
                'allow' => '>=8.5',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    public function testSkipsEmptyPackageNamesInTheAllowlist(): void
    {
        $package = $this->packageWithPhp(self::ALLOWED, '~8.4.0');
        $plugin  = $this->pluginWithConfig([
            'allow' => '>=8.5',
            'packages' => ['', self::ALLOWED],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertStringContainsString('|| >=8.5', $package->getRequires()['php'] ->getPrettyConstraint());
    }

    /**
     * Builds a concrete package carrying a single `php` requirement.
     */
    private function packageWithPhp(string $name, string $phpConstraint): Package
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');
        $package->setRequires([
            'php' => new Link(
                $name,
                'php',
                (new VersionParser())->parseConstraints($phpConstraint),
                Link::TYPE_REQUIRE,
                $phpConstraint,
            ),
        ]);

        return $package;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pluginWithConfig(array $config): ComposerLenientPlugin
    {
        return $this->pluginWithExtra([
            'ctw' => [
                'ctw-composer-plugin-composerlenientplugin' => $config,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function pluginWithExtra(array $extra): ComposerLenientPlugin
    {
        $root = new RootPackage('ctw/app', '1.0.0.0', '1.0.0');
        $root->setExtra($extra);

        $composer = $this->createMock(Composer::class);
        $composer->method('getPackage')
            ->willReturn($root);

        $plugin = new ComposerLenientPlugin();
        $plugin->activate($composer, $this->createMock(IOInterface::class));

        return $plugin;
    }

    /**
     * @param list<BasePackage> $packages
     */
    private function eventFor(array $packages): PrePoolCreateEvent
    {
        $event = $this->createMock(PrePoolCreateEvent::class);
        $event->method('getPackages')
            ->willReturn($packages);

        return $event;
    }
}
