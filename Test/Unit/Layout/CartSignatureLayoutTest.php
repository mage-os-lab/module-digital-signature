<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the "checkout.cart.summary → cart.summary" core rename
 * that made the mandatory signature checkbox silently vanish from the cart page.
 * Catches an accidental revert or edit of this module's own layout XML;
 * it cannot foresee a future core rename before it happens (that needs an
 * E2E check against a real Magento instance).
 */
class CartSignatureLayoutTest extends TestCase
{
    private const LAYOUT_FILE = __DIR__ . '/../../../view/frontend/layout/checkout_cart_index.xml';

    public function testSignatureBlockIsWiredIntoTheCurrentCartSummaryContainer(): void
    {
        $xml = new \SimpleXMLElement((string)file_get_contents(self::LAYOUT_FILE));

        $containers = $xml->xpath('//referenceContainer[@name="cart.summary"]');
        self::assertNotEmpty(
            $containers,
            'Expected a <referenceContainer name="cart.summary"> — '
            . 'if this fails, either the container was renamed again or the reference was removed.'
        );

        $blocks = $containers[0]->xpath('.//block[@name="digitalsignature.cart.signature"]');
        self::assertNotEmpty(
            $blocks,
            'The mandatory signature checkbox block must be declared inside the cart.summary container.'
        );
    }

    public function testSignatureBlockIsNotWiredIntoTheOldRenamedContainer(): void
    {
        $xml = new \SimpleXMLElement((string)file_get_contents(self::LAYOUT_FILE));

        $legacyContainers = $xml->xpath('//referenceContainer[@name="checkout.cart.summary"]');
        self::assertEmpty(
            $legacyContainers,
            'checkout.cart.summary was renamed to cart.summary; a reference to the old name would be dead.'
        );
    }
}
