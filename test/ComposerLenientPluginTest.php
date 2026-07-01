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
     * A package that sits on a rule's allowlist throughout the suite.
     */
    private const string ALLOWED = 'laminas/laminas-tag';

    /**
     * Test that getSubscribedEvents subscribes onPrePoolCreate to the PRE_POOL_CREATE event.
     */
    public function testSubscribesToThePrePoolCreateEvent(): void
    {
        self::assertSame(
            [
                PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
            ],
            ComposerLenientPlugin::getSubscribedEvents(),
        );
    }

    /**
     * Test that onPrePoolCreate widens the php requirement with the allow constraint when the package is allowlisted.
     */
    public function testRelaxesThePhpUpperBoundForAnAllowlistedPackage(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => [self::ALLOWED],
            ],
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

    /**
     * Test that onPrePoolCreate widens a non-php requirement with the allow constraint when the package is allowlisted.
     */
    public function testRelaxesAnArbitraryRequirementForAnAllowlistedPackage(): void
    {
        $package = $this->packageWithRequire('laminas/laminas-form', 'laminas/laminas-servicemanager', '^3.22.1');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'laminas/laminas-servicemanager',
                'allow' => '^4.0',
                'packages' => ['laminas/laminas-form'],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        $constraint = $package->getRequires()['laminas/laminas-servicemanager']
            ->getPrettyConstraint();
        self::assertStringContainsString('|| ^4.0', $constraint);
        self::assertTrue(Semver::satisfies('4.5.0', $constraint), 'servicemanager 4 is now permitted');
        self::assertTrue(Semver::satisfies('3.24.0', $constraint), 'the original range is preserved');
        self::assertFalse(Semver::satisfies('4.5.0', '^3.22.1'), 'sanity: the original constraint blocked v4');
    }

    /**
     * Test that onPrePoolCreate applies every matching rule when a single package matches more than one rule.
     */
    public function testAppliesEveryMatchingRuleToTheSamePackage(): void
    {
        $package = new Package('laminas/laminas-form', '1.0.0.0', '1.0.0');
        $package->setRequires([
            'php' => new Link(
                'laminas/laminas-form',
                'php',
                (new VersionParser())->parseConstraints('~8.4.0'),
                Link::TYPE_REQUIRE,
                '~8.4.0',
            ),
            'laminas/laminas-servicemanager' => new Link(
                'laminas/laminas-form',
                'laminas/laminas-servicemanager',
                (new VersionParser())->parseConstraints('^3.22.1'),
                Link::TYPE_REQUIRE,
                '^3.22.1',
            ),
        ]);
        $plugin = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => ['laminas/laminas-form'],
            ],
            [
                'require' => 'laminas/laminas-servicemanager',
                'allow' => '^4.0',
                'packages' => ['laminas/laminas-form'],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertStringContainsString('|| >=8.5', $package->getRequires()['php']->getPrettyConstraint());
        self::assertStringContainsString(
            '|| ^4.0',
            $package->getRequires()['laminas/laminas-servicemanager']
                ->getPrettyConstraint(),
        );
    }

    /**
     * Test that onPrePoolCreate leaves a requirement unchanged when the package is not on any rule's allowlist.
     */
    public function testLeavesPackagesOutsideTheAllowlistUntouched(): void
    {
        $package = $this->packageWithRequire('laminas/laminas-other', 'php', '~8.1.0 || ~8.2.0');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.1.0 || ~8.2.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate leaves packages unchanged when the plugin configuration lists no rules.
     */
    public function testDoesNothingWhenNoRulesAreConfigured(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithRules([]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate leaves packages unchanged when the extra configuration block is absent.
     */
    public function testDoesNothingWhenTheExtraConfigIsMissing(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithExtra([]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate skips an allowlisted package that does not declare the targeted requirement.
     */
    public function testIgnoresAnAllowlistedPackageThatDoesNotDeclareTheRequirement(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'laminas/laminas-stdlib', '^3.6');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertArrayNotHasKey('php', $package->getRequires());
        self::assertSame('^3.6', $package->getRequires()['laminas/laminas-stdlib']->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate applies the exact allow constraint configured on the matching rule.
     */
    public function testHonorsThePerRuleAllowConstraint(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.6',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        $php = $package->getRequires()['php']
            ->getPrettyConstraint();
        self::assertStringContainsString('|| >=8.6', $php);
        self::assertTrue(Semver::satisfies('8.6.0', $php));
        self::assertFalse(Semver::satisfies('8.5.0', $php));
    }

    /**
     * Test that onPrePoolCreate unwraps an alias package and relaxes the underlying real package.
     */
    public function testUnwrapsAnAliasPackageToRelaxTheUnderlyingPackage(): void
    {
        $real   = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $alias  = new AliasPackage($real, '2.99.0.0', '2.99.0');
        $plugin = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$alias]));

        self::assertStringContainsString('|| >=8.5', $real->getRequires()['php'] ->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate ignores configuration placed at the legacy un-namespaced extra key.
     */
    public function testDoesNotReadTheLegacyUnNamespacedExtraKey(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        // Configuration deliberately placed at the old, un-namespaced location.
        $plugin = $this->pluginWithExtra([
            'ctw-composer-plugin-composerlenientplugin' => [
                [
                    'require' => 'php',
                    'allow' => '>=8.5',
                    'packages' => [self::ALLOWED],
                ],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that resolveRules skips empty package names while still applying the rule to its valid names.
     */
    public function testSkipsEmptyPackageNamesInARule(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => ['', self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertStringContainsString('|| >=8.5', $package->getRequires()['php'] ->getPrettyConstraint());
    }

    /**
     * Test that resolveRules skips rules missing the require, allow, or packages field.
     */
    public function testSkipsRulesMissingRequireAllowOrPackages(): void
    {
        $package = $this->packageWithRequire('laminas/laminas-form', 'laminas/laminas-servicemanager', '^3.0');
        $plugin  = $this->pluginWithRules([
            [
                'allow' => '^4.0',
                'packages' => ['laminas/laminas-form'],
            ],
            [
                'require' => 'laminas/laminas-servicemanager',
                'packages' => ['laminas/laminas-form'],
            ],
            [
                'require' => 'laminas/laminas-servicemanager',
                'allow' => '^4.0',
                'packages' => [],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('^3.0', $package->getRequires()['laminas/laminas-servicemanager']->getPrettyConstraint());
    }

    /**
     * Test that resolveRules skips non-string package names while still applying the rule to its valid names.
     */
    public function testSkipsNonStringPackageNamesInARule(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => [123, self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertStringContainsString('|| >=8.5', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that resolveRules skips a rule entry that is not an array while still applying the valid entries.
     */
    public function testSkipsANonArrayRuleEntry(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithExtra([
            'ctw' => [
                'ctw-composer-plugin-composerlenientplugin' => [
                    'this entry is not an array',
                    [
                        'require' => 'php',
                        'allow' => '>=8.5',
                        'packages' => [self::ALLOWED],
                    ],
                ],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertStringContainsString('|| >=8.5', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate does not append a duplicate allow segment when the constraint already permits the allowed version.
     */
    public function testDoesNotWidenAgainWhenTheConstraintAlreadyPermitsTheAllowedVersion(): void
    {
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0 || ^8.5');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '^8.5',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$package]));

        self::assertSame('~8.4.0 || ^8.5', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that onPrePoolCreate ignores pool entries that are neither a Package nor an AliasPackage.
     */
    public function testIgnoresPoolEntriesThatAreNeitherAPackageNorAnAliasPackage(): void
    {
        $foreign = self::createStub(BasePackage::class);
        $package = $this->packageWithRequire(self::ALLOWED, 'php', '~8.4.0');
        $plugin  = $this->pluginWithRules([
            [
                'require' => 'php',
                'allow' => '>=8.5',
                'packages' => [self::ALLOWED],
            ],
        ]);

        $plugin->onPrePoolCreate($this->eventFor([$foreign, $package]));

        self::assertStringContainsString('|| >=8.5', $package->getRequires()['php']->getPrettyConstraint());
    }

    /**
     * Test that deactivate performs no action and does not throw.
     */
    public function testDeactivatePerformsNoActionAndDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $plugin = new ComposerLenientPlugin();
        $plugin->deactivate(self::createStub(Composer::class), self::createStub(IOInterface::class));
    }

    /**
     * Test that uninstall performs no action and does not throw.
     */
    public function testUninstallPerformsNoActionAndDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $plugin = new ComposerLenientPlugin();
        $plugin->uninstall(self::createStub(Composer::class), self::createStub(IOInterface::class));
    }

    /**
     * Builds a concrete package carrying a single requirement.
     */
    private function packageWithRequire(string $name, string $require, string $constraint): Package
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');
        $package->setRequires([
            $require => new Link(
                $name,
                $require,
                (new VersionParser())->parseConstraints($constraint),
                Link::TYPE_REQUIRE,
                $constraint,
            ),
        ]);

        return $package;
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private function pluginWithRules(array $rules): ComposerLenientPlugin
    {
        return $this->pluginWithExtra([
            'ctw' => [
                'ctw-composer-plugin-composerlenientplugin' => $rules,
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

        $composer = self::createStub(Composer::class);
        $composer->method('getPackage')
            ->willReturn($root);

        $plugin = new ComposerLenientPlugin();
        $plugin->activate($composer, self::createStub(IOInterface::class));

        return $plugin;
    }

    /**
     * @param list<BasePackage> $packages
     */
    private function eventFor(array $packages): PrePoolCreateEvent
    {
        $event = self::createStub(PrePoolCreateEvent::class);
        $event->method('getPackages')
            ->willReturn($packages);

        return $event;
    }
}
