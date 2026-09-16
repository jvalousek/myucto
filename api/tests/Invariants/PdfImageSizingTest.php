<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Obrázky na obchodním dokladu — dvě pravidla, obě vykoupená regresí.
 *
 * 1) Každý `<img>` v šabloně dokladu musí mít rozměr INLINE. mPDF neaplikuje
 *    `max-width`/`width` z `<style>` na `<img>` a obrázek vykreslí v nativní
 *    velikosti — tak se v issue #37 roztáhlo logo přes půl stránky. Dnes je na
 *    dokladu jediný obrázek, platební QR, a stejné pravidlo drží jeho 32 mm.
 *
 * 2) Na dokladu není logo dodavatele. Doklad je achromatický a bez brandingu:
 *    typ dokladu říká jeho popisek, ne odstín ani značka (viz hlavička
 *    `styles/invoice.css`). Kdo logo vrátí, musí vrátit i celý branding pipeline
 *    (SafeLogoPath, PNG flatten, SVG blocklist) — shoďme ho radši tady.
 */
#[Group('invariants')]
final class PdfImageSizingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documentTemplates(): array
    {
        return [
            'invoice'          => ['invoice/invoice.twig'],
            'work_report'      => ['invoice/work_report.twig'],
            'purchase_invoice' => ['purchase-invoice/purchase-invoice.twig'],
        ];
    }

    #[DataProvider('documentTemplates')]
    public function testEveryImageCarriesInlineSize(string $template): void
    {
        $html = self::templateSource($template);
        preg_match_all('/<img\b[^>]*>/i', $html, $m);

        foreach ($m[0] as $tag) {
            self::assertMatchesRegularExpression(
                '/style="[^"]*\b(?:max-)?width:\s*\d+(?:\.\d+)?mm[^"]*\b(?:max-)?height:\s*\d+(?:\.\d+)?mm/i',
                $tag,
                '<img> bez inline width/height — mPDF ho vykreslí v nativní velikosti '
                . 'a rozbije sazbu (issue #37): ' . $tag,
            );
        }
    }

    #[DataProvider('documentTemplates')]
    public function testDocumentCarriesNoSupplierLogo(string $template): void
    {
        $html = self::templateSource($template);

        self::assertDoesNotMatchRegularExpression(
            '/<img\b[^>]*\balt="logo"/i',
            $html,
            'Šablona ' . $template . ' kreslí logo dodavatele — doklad je bez brandingu.',
        );
        self::assertStringNotContainsString(
            'logo_path',
            $html,
            'Šablona ' . $template . ' sahá po logo_path — renderery ho už neposílají.',
        );
    }

    private static function templateSource(string $template): string
    {
        $path = \dirname(__DIR__, 2) . '/templates/' . $template;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }
}
