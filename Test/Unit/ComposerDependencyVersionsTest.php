<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Each magento/module-* package version is independent of the others (e.g.
 * magento/module-checkout tops out around 100.x while magento/module-catalog is at 104.x on the
 * very same Magento release) — there is no shared "platform version" across them. A previous
 * version of composer.json applied a single ">=103.0.4" floor to every magento/module-*
 * dependency, which made the module impossible to `composer require` on *any* real Magento
 * release, including the latest: several of those packages (checkout, config, email,
 * media-storage, quote, store, ui) never reach 103.x. This test locks in per-package floors
 * verified against the real Magento 2.4.4-2.4.9 dependency trees (repo.magento.com) so a
 * blanket constraint can't silently creep back in.
 */
class ComposerDependencyVersionsTest extends TestCase
{
    /**
     * Verified against magento/product-community-edition 2.4.4 (repo.magento.com dry-run,
     * 2026-08-06) — the lowest Magento version this module's PHP constraint (~8.1.0) supports.
     */
    private const EXPECTED_MINIMUMS = [
        'magento/framework' => '103.0.4',
        'magento/module-backend' => '102.0.4',
        'magento/module-catalog' => '104.0.4',
        'magento/module-checkout' => '100.4.4',
        'magento/module-config' => '101.2.4',
        'magento/module-customer' => '103.0.4',
        'magento/module-email' => '101.1.4',
        'magento/module-media-storage' => '100.4.3',
        'magento/module-quote' => '101.2.4',
        'magento/module-sales' => '103.0.4',
        'magento/module-store' => '101.1.4',
        'magento/module-ui' => '101.2.4',
    ];

    public function testEachMagentoModuleDependencyUsesItsOwnVerifiedFloor(): void
    {
        $path = __DIR__ . '/../../composer.json';
        self::assertFileExists($path);

        $composer = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $require = $composer['require'];

        foreach (self::EXPECTED_MINIMUMS as $package => $expectedMinimum) {
            self::assertArrayHasKey($package, $require, "$package must be declared in require.");
            self::assertSame(
                ">=$expectedMinimum",
                $require[$package],
                "$package must require exactly \">=$expectedMinimum\" (its own verified floor), not a "
                . "blanket version borrowed from a different magento/module-* package — that made the "
                . "module uninstallable via Composer on every real Magento release."
            );
        }
    }
}
