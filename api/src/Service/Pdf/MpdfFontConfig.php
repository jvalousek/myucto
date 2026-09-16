<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

/**
 * Sdílená konfigurace fontů pro všechny mPDF rendery (faktury, přijaté faktury,
 * Kniha DPH, výkaz práce, kniha jízd).
 *
 * Primární písmo je Montserrat (SIL OFL) — geometrický bezpatkový font s výraznými
 * tučnými řezy a brandovým charakterem, plná česká diakritika, řezy R/B/I/BI.
 * Monospace (částky, varsymboly, IBANy, datumy) je Geist Mono (SIL OFL) —
 * tabulkové číslice → zarovnání číselných sloupců.
 *
 * Selawik (SIL OFL, Microsoft) je písmo OBCHODNÍCH DOKLADŮ — vystavená faktura,
 * přijatá faktura a výkaz víceprací, tedy vše, co sází `styles/invoice.css`.
 * Je to metricky kompatibilní náhrada Segoe UI; doklad tím sedí na předlohu, podle
 * které je layout navržený. Řezy jen R/B — kurzíva v dokladech není (a Selawik ji
 * nemá, takže ji ani omylem nejde použít). Číslice jsou tabulkové, takže číselné
 * sloupce dokladu drží zarovnání i bez monospace, a nula je plná kulatá (viz níže).
 *
 * Reporty, Kniha DPH, mzdy a kniha jízd zůstávají na Montserratu — `default_font`
 * se nemění, doklady si Selawik berou přes `font-family` v CSS.
 *
 * ⚠️ Nula NESMÍ být tečkovaná. Předchozí JetBrains Mono má uprostřed nuly tečku,
 * která se v 8 pt a zvlášť po tisku na papír slévá s obvodem číslice → 0 se čte
 * jako 8 (issue #35: varsymbol 26088 čten jako 26888, částka 105 400 jako 105 488).
 * Geist Mono má nulu přeškrtnutou — škrt jde přes celou plochu znaku, takže se
 * nemá s čím slít, a je to standardní disambiguátor pro ruční přepis i OCR.
 * Plná kulatá nula je taky v pořádku; tečkovaná ne.
 *
 * Geist Mono je zároveň mono font aplikace (web/src/styles/fonts.css) — doklad
 * a obrazovka sázejí čísla stejně. Při výměně fontu drž obě strany v páru.
 * Doklady na Selawiku mono nepoužívají: tabulkové číslice řeší zarovnání a kulatá
 * nula Selawiku vyhovuje pravidlu výše (netečkovaná), takže disambiguátor nemizí.
 *
 * DejaVu Sans zůstává jako jediný `backupSubsFont` pro glyfy, které Montserrat
 * nemá (✓ ✗ ◆ ⚠ …). Monospace pasáže jedou přes Geist Mono (vlastní font),
 * takže DejaVu Sans Mono se nepoužívá (a `api/bin/cleanup-mpdf-fonts.php` ho maže).
 *
 * Fonty jsou v api/resources/fonts/ — mimo vendor/mpdf/mpdf/ttfonts/, takže je
 * cleanup-mpdf-fonts.php (čistí jen vendor ttfonts) nesmaže.
 *
 * ⚠️ POZOR: tělo dokladu bere font z CSS (`styles/invoice.css`, `dph_book.twig`,
 * inline CSS logbook služeb), které PŘEBÍJÍ `default_font`. Při změně fontu se
 * MUSÍ změnit i `font-family` v těch CSS (viz memory project_pdf_fonts).
 */
final class MpdfFontConfig
{
    public const DEFAULT_FONT = 'montserrat';

    /** Adresář s vlastními TTF fonty. */
    public static function fontDir(): string
    {
        return \dirname(__DIR__, 3) . '/resources/fonts';
    }

    /**
     * Font-related klíče pro Mpdf konstruktor — slij přes array spread / merge
     * do configu daného renderu (přepíše případný 'default_font').
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $defCfg   = (new ConfigVariables())->getDefaults();
        $defFonts = (new FontVariables())->getDefaults();

        // Ponech z defaultní fontdata JEN 'dejavusans' (jediný DejaVu, který
        // api/bin/cleanup-mpdf-fonts.php nemaže). Ostatní default fonty
        // (dejavusansmono, dejavusanscondensed, dejavuserif, freesans, sun-exta…)
        // v image NEJSOU — kdyby zůstaly registrované ve fontdata, mPDF by je při
        // substituci chybějícího glyfu zkusil načíst a shodil render
        // (Cannot find TTF …). Bez nich = chybějící glyf → prázdný box (tofu).
        $fontData = array_intersect_key($defFonts['fontdata'], ['dejavusans' => 1]);
        $fontData['montserrat'] = [
            'R'  => 'Montserrat-Regular.ttf',
            'B'  => 'Montserrat-Bold.ttf',
            'I'  => 'Montserrat-Italic.ttf',
            'BI' => 'Montserrat-BoldItalic.ttf',
        ];
        // Monospace pro číselné pasáže (CSS na ně cílí přes font-family:'geistmono').
        // Kurzíva se v mono nepoužívá → I/BI mapujeme na R/B.
        // Tučný slot drží SemiBold (600), ne Bold (700): 600 je nejtěžší mono řez,
        // který deklaruje aplikace (`font-weight: 400 600` ve web/src/styles/fonts.css),
        // takže součet na dokladu má stejnou váhu jako tentýž údaj na obrazovce.
        // 700 navíc opticky přebíjí zbytek sazby, která je navržená na lehčí tučný řez.
        $fontData['geistmono'] = [
            'R'  => 'GeistMono-Regular.ttf',
            'B'  => 'GeistMono-SemiBold.ttf',
            'I'  => 'GeistMono-Regular.ttf',
            'BI' => 'GeistMono-SemiBold.ttf',
        ];
        // Písmo obchodních dokladů. Kurzíva se na dokladu nepoužívá a Selawik ji nemá —
        // I/BI proto míří na R/B, aby případné `font-style:italic` render nezhodilo
        // (mPDF by jinak hledal neexistující soubor) a jen se ignorovalo.
        $fontData['selawik'] = [
            'R'  => 'Selawik-Regular.ttf',
            'B'  => 'Selawik-Bold.ttf',
            'I'  => 'Selawik-Regular.ttf',
            'BI' => 'Selawik-Bold.ttf',
        ];

        return [
            // PDF/A-3b (ISO 19005-3) — archivní formát pro VŠECHNY PDF výstupy.
            // Tuto sdílenou konfiguraci spreadují všechny renderery (Invoice,
            // PurchaseInvoice, WorkReport, DphBook, 3× Logbook), takže PDF/A se
            // zapne jedním místem. mPDF doplní sRGB OutputIntent + XMP pdfaid;
            // CMYK obrázky PDFAauto převede do sRGB (jeden OutputIntent).
            'PDFA'             => true,
            'PDFAversion'      => '3-B',
            'PDFAauto'         => true,
            'fontDir'          => array_merge($defCfg['fontDir'], [self::fontDir()]),
            'fontdata'         => $fontData,
            'default_font'     => self::DEFAULT_FONT,
            'useSubstitutions' => true,
            'backupSubsFont'   => ['dejavusans'],
            // Generické CSS rodiny musí mířit na fonty, které v image ZŮSTÁVAJÍ
            // (po cleanup-mpdf-fonts.php). mPDF defaultně mapuje sans-serif →
            // DejaVuSansCondensed, monospace → DejaVuSansMono, serif → DejaVuSerif —
            // ty jsme smazali, takže bez tohohle by `font-family: …, sans-serif`
            // shodilo render (Cannot find TTF …). Přemapujeme na Montserrat / Geist Mono
            // / DejaVu Sans (jediný fallback pro symboly).
            'sans_fonts'       => ['montserrat', 'dejavusans'],
            'serif_fonts'      => ['dejavusans'],
            'mono_fonts'       => ['geistmono', 'dejavusans'],
        ];
    }
}
