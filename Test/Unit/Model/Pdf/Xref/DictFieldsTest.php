<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\DictFields;
use PHPUnit\Framework\TestCase;

class DictFieldsTest extends TestCase
{
    public function testExtractIntReadsValue(): void
    {
        self::assertSame(42, DictFields::extractInt('/Size 42 /Root 1 0 R', 'Size'));
    }

    public function testExtractIntReturnsNullWhenKeyAbsent(): void
    {
        self::assertNull(DictFields::extractInt('/Root 1 0 R', 'Size'));
    }

    public function testExtractIntDoesNotMatchPrefixOfLongerKey(): void
    {
        self::assertNull(DictFields::extractInt('/SizeXtra 42', 'Size'));
    }

    public function testExtractIntArrayReadsValues(): void
    {
        self::assertSame([1, 4, 2], DictFields::extractIntArray('/W [1 4 2] /Size 10', 'W'));
    }

    public function testExtractIntArrayReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractIntArray('/Size 10', 'Index'));
    }

    public function testExtractRefReadsIndirectReference(): void
    {
        self::assertSame('1 0 R', DictFields::extractRef('/Size 10 /Root 1 0 R', 'Root'));
    }

    public function testExtractSubDictReadsNestedDictionary(): void
    {
        self::assertSame(
            ' /Predictor 12 /Columns 5 ',
            DictFields::extractSubDict('/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 5 >>', 'DecodeParms')
        );
    }

    public function testExtractSubDictReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractSubDict('/Size 10', 'DecodeParms'));
    }

    public function testHasKeyDetectsPresence(): void
    {
        self::assertTrue(DictFields::hasKey('/Size 10 /Encrypt 3 0 R', 'Encrypt'));
        self::assertFalse(DictFields::hasKey('/Size 10 /EncryptMetadata false', 'Encrypt'));
    }

    public function testExtractNameReadsSimpleValue(): void
    {
        self::assertSame('Page', DictFields::extractName('/Type /Page /Parent 2 0 R', 'Type'));
    }

    public function testExtractNameReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractName('/Parent 2 0 R', 'Type'));
    }

    public function testExtractRefArrayReadsMultipleReferences(): void
    {
        self::assertSame(
            ['3 0 R', '4 0 R', '5 0 R'],
            DictFields::extractRefArray('/Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3', 'Kids')
        );
    }

    public function testExtractRefArrayReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractRefArray('/Type /Pages /Count 0', 'Kids'));
    }

    public function testExtractRefArrayReturnsEmptyArrayWhenBracketsEmpty(): void
    {
        self::assertSame([], DictFields::extractRefArray('/Kids [] /Count 0', 'Kids'));
    }

    public function testExtractBalancedDictReadsFlatDict(): void
    {
        $text = '<</A 1 /B 2>>';

        [$content, $closeStart] = DictFields::extractBalancedDict($text, 2);

        self::assertSame('/A 1 /B 2', $content);
        self::assertSame('>>', substr($text, $closeStart, 2));
    }

    public function testExtractBalancedDictHandlesNestedDict(): void
    {
        $text = '/Resources <</Font <</F1 3 0 R>> /ProcSet [/PDF /Text]>> /MediaBox [0 0 612 792]';
        $openPos = strpos($text, '<<');

        [$content, $closeStart] = DictFields::extractBalancedDict($text, $openPos + 2);

        self::assertSame('/Font <</F1 3 0 R>> /ProcSet [/PDF /Text]', $content);
        self::assertSame(' /MediaBox [0 0 612 792]', substr($text, $closeStart + 2));
    }

    public function testExtractBalancedDictThrowsWhenUnterminated(): void
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        DictFields::extractBalancedDict('<</A 1 /B 2', 2);
    }
}
