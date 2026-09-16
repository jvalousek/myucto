<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Render přijaté faktury jako PDF (naše rekonstrukce).
 *
 * **Use case:** Když nemáme originální PDF od dodavatele (importované jen metadata,
 * nebo zadané ručně), generujeme vlastní PDF pro účetní archiv. Sází se stejným
 * stylopisem, okraji i písmem jako vystavená faktura — složka dokladů tak drží
 * jeden vizuální jazyk.
 *
 * Layout:
 *   - Header: vendor (jako "supplier" v rekonstrukci) + "Rekonstrukce" badge
 *   - Parties: vendor (zleva) + naše firma jako odběratel (zprava)
 *   - Meta: issue/tax/due dates + currency
 *   - Items table
 *   - Totals (bez DPH / DPH / s DPH)
 *   - Footer s attribution + warning že originál je závazný
 */
final class PurchaseInvoicePdfRenderer
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Render do PDF binary string.
     */
    public function render(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->repo->find($purchaseInvoiceId, $supplierId);
        if ($invoice === null) {
            throw new \RuntimeException("Přijatá faktura #{$purchaseInvoiceId} nenalezena.");
        }
        $vendor = $this->loadVendor((int) $invoice['vendor_id']);
        $ourCompany = $this->loadOurCompany($supplierId);
        $items = $invoice['items'] ?? [];

        // Totals — preferuj sub-object, fallback na top-level columns
        $totals = $invoice['totals'] ?? [
            'without_vat' => $invoice['total_without_vat'] ?? 0,
            'vat'         => $invoice['total_vat'] ?? 0,
            'with_vat'    => $invoice['total_with_vat'] ?? 0,
        ];

        // Režim „ceny s DPH": unit_price_without_vat nese BRUTTO (kvůli haléřově přesnému
        // výpočtu DPH koeficientem). Jednotkovou cenu zobrazujeme vždy jako NETTO (dopočtenou
        // z řádkového základu), ale řádkový SOUČET ukazujeme S DPH — řádek je tak standardní
        // (cena/j bez DPH + sazba + celkem s DPH) a odráží, že jde o doklad s cenami vč. DPH.
        $pricesIncludeVat = !empty($invoice['prices_include_vat']);

        $itemsNorm = TimeBillingPdfPresenter::purchaseInvoiceItems($items, $pricesIncludeVat);

        $locale = $invoice['language'] ?? 'cs';
        $docTypeLabel = $this->docTypeLabel($invoice['document_kind'] ?? 'invoice', $locale);
        $currency = $invoice['currency'] ?? 'CZK';

        $css = $this->loadCss();
        $body = $this->twig()->render('purchase-invoice.twig', [
            'invoice'        => $invoice,
            'vendor'         => $vendor,
            'our_company'    => $ourCompany,
            'items'          => $itemsNorm,
            'totals'         => $totals,
            'currency'       => $currency,
            'doc_type_label' => $docTypeLabel,
            'locale'         => $locale,
            'css'            => $css,
        ]);

        // Okraje shodné s vystavenou fakturou — rekonstrukce sází týž stylopis a jeho
        // mřížka je na ně navázaná (viz hlavička styles/invoice.css).
        // margin_footer < margin_bottom, jinak by patička vlezla do sazby.
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 9.5,
            'margin_right' => 11.8,
            'margin_top' => 6.6,
            'margin_bottom' => 20,
            'margin_footer' => 4.4,
            'tempDir' => \MyInvoice\Infrastructure\Config\RuntimePaths::storage('mpdf-temp'),
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle(($docTypeLabel ?: 'Faktura') . ' ' . ($invoice['vendor_invoice_number'] ?? '#' . $invoice['id']));
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    private function loadVendor(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id, c.company_name, c.street, c.city, c.zip, c.ic, c.dic,
                    c.main_email AS email, c.phone, COALESCE(cnt.iso2, 'CZ') AS country_iso2
               FROM clients c
          LEFT JOIN countries cnt ON cnt.id = c.country_id
              WHERE c.id = ?"
        );
        $stmt->execute([$vendorId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function loadOurCompany(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip, s.ic, s.dic,
                    s.email, s.phone, COALESCE(c.iso2, 'CZ') AS country_iso2
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function docTypeLabel(string $kind, string $locale): string
    {
        if ($locale === 'en') {
            return match ($kind) {
                'receipt'      => 'Receipt',
                'credit_note'  => 'Credit note',
                'advance'      => 'Advance',
                'tax_document' => 'Tax document for payment',
                default        => 'Invoice',
            };
        }
        return match ($kind) {
            'receipt'      => 'Přijatá účtenka',
            'credit_note'  => 'Přijatý dobropis',
            'advance'      => 'Přijatá záloha',
            'tax_document' => 'Daňový doklad k platbě',
            default        => 'Přijatá faktura',
        };
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/purchase-invoice',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'strict_variables' => false,
            ] + TwigCache::options('purchase-invoice'));
        }
        return $this->twig;
    }

    /**
     * Rekonstrukce sází TÝŽ stylopis jako vystavená faktura — žádné doplňky.
     *
     * Dřív se za base CSS lepil blok vlastních pravidel (`.reconstruction-badge`,
     * `.meta-info`, barevné `.note*`). Ta jsou dnes buď přímo ve `styles/invoice.css`,
     * nebo bez markupu v šabloně — a barvy z nich by achromatický doklad rozbily
     * tím spíš, že base pravidla přebíjely pořadím.
     */
    private function loadCss(): string
    {
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        return is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
    }
}
