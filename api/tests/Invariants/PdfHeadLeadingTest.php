<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proklad hlavičky dokladu — dvě pasti mPDF, obě proměřené proti předloze.
 *
 * 1) `line-height` mPDF k blokům uvnitř tabulky NEDĚDÍ z `body`. Bez zápisu na
 *    buňce sází bezejmenné `<div>`y adresy na výchozí proklad písma (8 pt → 9.6
 *    místo 12 pt) a hlavička se proti předloze scvrkne o 2.5 pt na řádek. Zápis
 *    na ty `<div>`y samotné to nespraví — ani třídou, ani inline stylem.
 *
 * 2) Za VNOŘENOU tabulkou mPDF proklad buňky zapomene a zbytek jejího obsahu
 *    sází zase na výchozí. Blok odběratele stojí pod `table.doc-title`, takže
 *    musí mít vlastní buňku (`table.party-block`), jinak se proti dodavateli
 *    v protějším sloupci opticky scvrkne.
 *
 * Obojí je neviditelné v HTML náhledu a projeví se až v PDF, proto invariant.
 */
#[Group('invariants')]
final class PdfHeadLeadingTest extends TestCase
{
    private const LEADING = '1.5';

    public function testStylesheetSetsLeadingOnTheHeadCell(): void
    {
        $css = self::read('/../styles/invoice.css');

        self::assertMatchesRegularExpression(
            '/table\.head\s+td\s*\{[^}]*line-height:\s*' . preg_quote(self::LEADING, '/') . '\b/',
            $css,
            '`table.head td` nemá line-height — mPDF sníží proklad hlavičky na výchozí '
            . 'proklad písma a doklad přestane sedět na předlohu.',
        );
    }

    /** @return array<string, array{string}> */
    public static function templatesWithCustomerBlock(): array
    {
        return [
            'invoice'          => ['invoice/invoice.twig'],
            'purchase_invoice' => ['purchase-invoice/purchase-invoice.twig'],
        ];
    }

    #[DataProvider('templatesWithCustomerBlock')]
    public function testCustomerBlockHasItsOwnCell(string $template): void
    {
        $html = self::read('/templates/' . $template);

        $headRight = self::sliceHeadRight($html);
        $docTitleAt    = strpos($headRight, 'class="doc-title"');
        $partyBlockAt  = strpos($headRight, 'class="party-block"');
        $partyLabelAt  = strpos($headRight, 'class="party-label"');

        self::assertNotFalse($docTitleAt, $template . ': v pravém sloupci chybí table.doc-title.');
        self::assertNotFalse($partyLabelAt, $template . ': v pravém sloupci chybí blok strany.');
        self::assertNotFalse(
            $partyBlockAt,
            $template . ': blok odběratele není v `table.party-block`. Stojí za vnořenou '
            . 'tabulkou, takže mu mPDF sebere proklad buňky.',
        );
        self::assertLessThan(
            $partyLabelAt,
            $partyBlockAt,
            $template . ': `table.party-block` musí blok odběratele OBALOVAT, ne stát za ním.',
        );
    }

    /**
     * Pravý sloupec hlavičky = od `head-right` po řádek se způsobem úhrady, který
     * v obou šablonách následuje. Řezat na první `</table>` nejde — tím končí
     * vnořená `table.doc-title`, tedy přesně před blokem, na který se ptáme.
     */
    private static function sliceHeadRight(string $html): string
    {
        $start = strpos($html, 'class="head-right"');
        self::assertNotFalse($start, 'Šablona nemá `td.head-right`.');
        $end = strpos($html, 'class="paymethod-left"', $start);
        self::assertNotFalse($end, 'Šablona nemá řádek `td.paymethod-left`.');

        return substr($html, $start, $end - $start);
    }

    private static function read(string $relative): string
    {
        $path = \dirname(__DIR__, 2) . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
