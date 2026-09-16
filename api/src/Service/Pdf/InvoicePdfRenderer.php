<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Oss\OssInvoiceClause;
use MyInvoice\Service\Qr\PaymentQrDueDate;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Service\Vat\VatStatusService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderuje fakturu jako PDF.
 *
 *   1. Načte fakturu (full + items + snapshots)
 *   2. Vyrendrované Twig šablonu invoice.twig
 *   3. Vygeneruje QR (pokud má varsymbol + amount + bank)
 *   4. mPDF z HTML
 *   5. Cache do storage/invoices/YYYY-MM/Faktura-YY-MM-NNN.pdf
 */
final class InvoicePdfRenderer
{
    use SignsPdf;

    private ?Environment $twig = null;

    /** Aktuální locale pro Twig funkci `t()` — přenastavuje renderHtml(). */
    private string $locale = 'cs';

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly QrPaymentGenerator $qr,
        private readonly WorkReportRepository $workReports,
        private readonly SnapshotBuilder $snapshots,
        private readonly PdfArchiveService $archive,
        private readonly IsdocExporter $isdoc,
        private readonly PdfSigningService $pdfSigning,
        private readonly \MyInvoice\Repository\PaymentScheduleRepository $paymentSchedule,
        private readonly VatStatusService $vatStatus,
        private readonly SupplierPaymentQrSettingsRepository $paymentQrSettings,
    ) {}

    /**
     * Vyrendrované PDF do souboru a vrátí cestu.
     *
     * @param array<string,mixed>|null $invoiceData
     * @return string  absolutní cesta k vygenerovanému PDF
     */
    public function render(
        int $invoiceId,
        bool $forceRegenerate = false,
        ?int $userId = null,
        ?array $invoiceData = null,
    ): string
    {
        $persist = !(bool) $this->config->get('demo.enabled', false);
        $invoice = $invoiceData ?? $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }

        $cachedPath = $this->cachePath($invoice);
        $supplierData = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        $signatureCacheDependsOnUser = $this->pdfSigning->outputDependsOnUserProfile(
            $supplierData,
            'invoice',
            $invoiceId,
        );

        // Cache je validní jen když je novější než šablona, CSS a kód renderu.
        // `work_report.twig` do seznamu patří: faktura s výkazem víceprací ho
        // vkládá jako druhou stranu, takže jeho změna mění i tenhle doklad.
        $tplMtime = max(
            @filemtime(Bootstrap::rootDir() . '/styles/invoice.css') ?: 0,
            @filemtime(Bootstrap::rootDir() . '/api/templates/invoice/invoice.twig') ?: 0,
            @filemtime(Bootstrap::rootDir() . '/api/templates/invoice/work_report.twig') ?: 0,
            @filemtime(__DIR__ . '/TimeBillingPdfPresenter.php') ?: 0,
            @filemtime(__FILE__) ?: 0,
        );
        $isFresh = static fn (string $p): bool =>
            is_file($p) && (@filemtime($p) ?: 0) >= $tplMtime;

        // pdf_path je uložený RELATIVNĚ ke storage/invoices (N-016) — na disk se
        // sahá až přes resolvePdfPath(), která snese i legacy absolutní hodnoty.
        $storedPdf = self::resolvePdfPath($invoice['pdf_path'] ?? null);
        if (!$forceRegenerate && !$signatureCacheDependsOnUser && $storedPdf !== null && $isFresh($storedPdf)) {
            return $storedPdf;
        }
        // cachePath fallback je orphan-recovery (pdf_path je null, ale soubor leží na
        // deterministické cestě). MUSÍ vyžadovat pdf_generated_at NOT NULL — jinak
        // by invalidate() s uzamčeným souborem (Windows: PDF otevřené v prohlížeči →
        // rename a unlink selžou) skončila s pdf_path=NULL ale původní soubor zůstal
        // na disku, a tahle větev by ho zde znovu pickla → stale PDF.
        if (
            !$forceRegenerate
            && !$signatureCacheDependsOnUser
            && (!$persist || !empty($invoice['pdf_generated_at']))
            && $isFresh($cachedPath)
        ) {
            if ($persist) {
                $this->updatePdfPath($invoiceId, $cachedPath);
            }
            return $cachedPath;
        }

        // Force regenerate = také obnov supplier/client/bank snapshoty z live dat.
        // (Snapshoty jsou primární zdroj pro issued+ faktury — bez tohoto by se
        // změny v supplier/client tabulkách neprojevily ani po regenerate.)
        if ($forceRegenerate && $persist) {
            $invoice = $this->refreshSnapshots($invoice);
        }

        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        // ISDOC attachment: vyrobíme XML v paměti a předáme do SetAssociatedFiles
        // jako 'content' (žádný tmp soubor — mPDF API umí XML zpracovat in-memory).
        // Na stránku se nic nekreslí; příloha je strojová a prohlížeč ji ohlásí
        // sám (viz komentář v invoice.twig).
        // Gating: jen pokud supplier má embed_isdoc=1 a faktura je v CZK (ISDOC
        // je CZ standard, EUR/USD doklady by accounting SW jen zmátly).
        $isdocXml = null;
        if ($this->shouldEmbedIsdoc($invoice)) {
            try {
                $isdocXml = $this->isdoc->buildXml($invoice);
            } catch (\Throwable) {
                // ISDOC build selhal (chybný snapshot, neexistující data) —
                // PDF renderujeme bez přílohy, nezdržujeme uživatele.
                $isdocXml = null;
            }
        }

        $rendered = $this->renderHtmlAndCss($invoice);

        $mpdf = $this->newMpdf($tmpDir);
        // PDF metadata — bez Title/Author, aby Chrome viewer nezobrazoval text nad PDF.
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyÚčto.cz');

        // PDF/A-3 style associated file: zapsáno do /Names /EmbeddedFiles + /AF
        // catalog entry. Accounting SW (Pohoda, Money S3, …) skenuje tuhle cestu.
        if ($isdocXml !== null) {
            $mpdf->SetAssociatedFiles([[
                'content'        => $isdocXml,
                'name'           => 'invoice.isdoc',
                'mime'           => 'application/x-isdoc',
                'description'    => 'ISDOC ' . IsdocExporter::VERSION . ' invoice data',
                'AFRelationship' => 'Source',
            ]]);
        }

        // CSS separately (mPDF handluje líp než inline <style> tag)
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);

        if (!is_dir(dirname($cachedPath))) {
            @mkdir(dirname($cachedPath), 0755, true);
        }
        // Write to .new sibling first, pak atomický rename — obchází Windows file lock
        // (když je starý PDF otevřený v Chrome PDF viewer, přepis přímo by selhal).
        $tmpPath = $cachedPath . '.new';
        $mpdf->Output($tmpPath, \Mpdf\Output\Destination::FILE);

        // Podpis PDF (PAdES) — má-li dodavatel zapnuto; měkký fallback při chybě.
        $tmpPath = $this->signPdfIfEnabled(
            $tmpPath,
            $supplierData,
            $this->pdfSigning,
            'invoice',
            $invoiceId,
            $userId,
        );

        if (is_file($cachedPath)) {
            @unlink($cachedPath); // pokud locked, fail silently
        }
        if (!@rename($tmpPath, $cachedPath)) {
            // Rename selhal (target locked) — nech temp, vrať tmpPath přímo
            $cachedPath = $tmpPath;
        }

        if ($persist) {
            $this->updatePdfPath($invoiceId, $cachedPath);
        }

        return $cachedPath;
    }

    /**
     * Vyrenderuje pouze samotnou fakturu do zadaného dočasného souboru.
     *
     * Výstup záměrně neobsahuje ISDOC ani výkaz práce a nikdy se zde nepodepisuje.
     * Používá ho hromadný tisk, který jednotlivé dokumenty nejprve spojí a případný
     * elektronický podpis aplikuje až na výsledný celek.
     */
    public function renderUnsignedInvoiceOnly(int $invoiceId, string $outputPath): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }

        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        if (!is_dir(dirname($outputPath))) {
            @mkdir(dirname($outputPath), 0755, true);
        }

        $rendered = $this->renderHtmlAndCss($invoice, includeWorkReport: false);
        $mpdf = $this->newMpdf($tmpDir);
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyInvoice.cz');
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);
        $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
    }

    /**
     * ISDOC se přiloží jen pro CZK faktury dodavatele s embed_isdoc=1.
     * Drafty bez varsymbolu skipujeme — buildXml() by vyrobil placeholder
     * "DRAFT-{id}" jako ID, což účetní SW odmítne.
     */
    private function shouldEmbedIsdoc(array $invoice): bool
    {
        $currency = strtoupper((string) ($invoice['currency'] ?? ''));
        if ($currency !== 'CZK') return false;
        if (empty($invoice['varsymbol'])) return false;
        $supplier = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        return (bool) ($supplier['embed_isdoc'] ?? true);
    }

    /**
     * @return array{body:string, css:string}
     */
    public function renderHtmlAndCss(
        array $invoice,
        bool $includeWorkReport = true,
    ): array
    {
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        // Renderuj template BEZ inline <style> bloku — CSS pošleme do mPDF zvlášť
        $body = $this->renderHtml(
            $invoice,
            includeCss: false,
            includeWorkReport: $includeWorkReport,
        );
        return ['body' => $body, 'css' => $css];
    }

    public function renderHtml(
        array $invoice,
        bool $includeCss = true,
        bool $includeWorkReport = true,
    ): string
    {
        // Použij snapshots pokud jsou (issued+), jinak živá data
        $supplierData = $this->resolveSupplier($invoice);
        if (($invoice['status'] ?? 'draft') === 'draft' || empty($invoice['supplier_snapshot'])) {
            $supplierData = $this->applyLiveBrandingProfile($supplierData, $invoice);
        }
        $clientData   = $this->resolveClient($invoice);
        $bankData     = $this->resolveBank($invoice);

        // QR generování:
        //   CZK SPAYD vyžaduje VS jako mandatory pole → bez varsymbolu skip
        //   SEPA EPC (EUR i další) VS nepoužívá, jen volitelný remittance text
        //     → drafty bez VS dostanou QR taky (preview pro klienta), remittance fallback
        //   Skip pro zaplacené faktury a pro non-bank-transfer payment_method
        $qrUri = null;
        // Částečné úhrady (#89): QR i výzva k platbě znějí na ZBÝVAJÍCÍ částku
        // (amount_to_pay − paid_total) — znovu stažené/poslané PDF po částečné
        // úhradě nesmí chtít celou částku. Cache se při změně plateb invaliduje.
        $remaining = round((float) $invoice['amount_to_pay'] - (float) ($invoice['paid_total'] ?? 0), 2);
        $hasAmount = $remaining > 0;
        $isCzk = ((string) $invoice['currency']) === 'CZK';
        $hasVs = !empty($invoice['varsymbol']);
        $isPaid = ($invoice['status'] ?? '') === 'paid';
        $paymentMethod = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        $isBankTransfer = $paymentMethod === 'bank_transfer';
        // Platební kalendář QR kód nedostane. Žádná jedna platba se na něm nekoná —
        // rozpis jich má dvanáct — takže QR na celkovou částku by vyzýval k úhradě
        // celého roku najednou, přesně proti tomu, co doklad sjednává.
        $isPaymentCalendar = ($invoice['invoice_type'] ?? '') === 'payment_calendar';
        if ($hasAmount && $bankData !== null && (!$isCzk || $hasVs) && !$isPaid && $isBankTransfer && !$isPaymentCalendar) {
            $qrSettings = $this->paymentQrSettings->find((int) ($invoice['supplier_id'] ?? 0));
            $qrUri = $this->qr->generate(
                (string) $invoice['currency'],
                $remaining,
                (string) ($invoice['varsymbol'] ?? ''),
                $bankData,
                (string) ($supplierData['display_name'] ?? $supplierData['company_name'] ?? 'MyÚčto.cz'),
                PaymentQrDueDate::parse($invoice['due_date'] ?? null),
                includeDueDate: (bool) ($qrSettings[SupplierPaymentQrSettingsRepository::INVOICE_FIELD] ?? false),
            );
        }

        $locale = $invoice['language'] ?? 'cs';
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = $includeCss
            ? (is_file($cssPath) ? (string) file_get_contents($cssPath) : '')
            : '';

        // Locale pro `t()` — čte ho closure registrovaná v twig() (viz komentář tam).
        $this->locale = (string) $locale;
        $twig = $this->twig();

        $clientCountry = strtoupper(trim((string) ($clientData['country_iso2'] ?? '')));
        $hidePdfCzkRecap = $clientCountry !== '' && $clientCountry !== 'CZ';
        $pdfCzkVat = [];
        if ($hidePdfCzkRecap
            && ($invoice['invoice_type'] ?? '') !== 'proforma'
            && is_array($invoice['czk_recap'] ?? null)
        ) {
            $exchangeRate = (float) ($invoice['czk_recap']['rate'] ?? 0);
            $items = (array) ($invoice['items'] ?? []);
            $pdfCzkVat = CzkRecap::buildCzechVatForDocument($items, $exchangeRate);

            if ($items === []) {
                foreach ((array) ($invoice['czk_recap']['breakdown'] ?? []) as $row) {
                    if (abs((float) ($row['vat_czk'] ?? 0)) < 0.000001) continue;
                    $pdfCzkVat[] = [
                        'rate'    => (float) ($row['rate'] ?? 0),
                        'vat_czk' => (float) $row['vat_czk'],
                    ];
                }
            }
        }

        $invoice['items'] = TimeBillingPdfPresenter::invoiceItems(
            $this->withBaseQuantity($invoice),
            !empty($invoice['prices_include_vat']),
        );
        $workReport = $includeWorkReport
            ? $this->workReports->findByInvoice((int) $invoice['id'])
            : null;
        if ($workReport !== null) {
            $workReport = TimeBillingPdfPresenter::workReport($workReport);
        }

        $vars = [
            'invoice'           => $invoice,
            'supplier'          => $supplierData,
            'client'            => $clientData,
            'bank'              => $bankData,
            'qr_data_uri'       => $qrUri,
            // Platební VS = jen číslice (max 10) — `varsymbol` může nést pomlčku z čísla
            // dokladu, kterou banka nepřijme. Velký titulek dokladu zůstává s pomlčkou,
            // ale do platebního řádku tiskneme validní VS (shodné s QR a párováním).
            'payment_varsymbol' => VariableSymbolNormalizer::forPayment((string) ($invoice['varsymbol'] ?? '')),
            'is_paid'           => $isPaid,
            'payment_method'    => $paymentMethod,
            'locale'            => $locale,
            'doc_type_label'    => $this->docTypeLabel($invoice, $locale, $supplierData),
            'doc_title'         => $this->docTitle($invoice),
            'parent_varsymbol'  => $this->parentVarsymbol($invoice),
            'work_report'       => $workReport,
            'date_format'       => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep'       => $locale === 'en' ? '.' : ',',
            // Nezlomitelná mezera (NBSP, U+00A0) jako oddělovač tisíců — mPDF v úzkých
            // číselných buňkách (Cena/j, Bez/S DPH) láme i přes white-space:nowrap; NBSP
            // drží celé číslo „298 833,00" na jednom řádku spolehlivě.
            'thousand_sep'      => $locale === 'en' ? ',' : "\u{00A0}",
            'css'               => $css,
            // Patička: kdo doklad vystavil. Autor dokladu, ne stahující uživatel —
            // viz issuedBy().
            'issued_by'         => $this->issuedBy($invoice),
            'hide_pdf_czk_recap'=> $hidePdfCzkRecap,
            'pdf_czk_vat'       => $pdfCzkVat,
            // Doložka o odvodu daně v režimu OSS (§ 110a a násl. ZDPH). Doklad s cizí
            // sazbou musí říct, PROČ ta sazba na něm je — jinak ji příjemce i účetní
            // čtou jako českou daň ve špatné sazbě. Protějšek RC doložky výše.
            'oss_clause'        => $this->ossClause($invoice),
            // § 31a ZDPH — rozpis plateb na předem stanovené období. Právě ten dělá
            // z platebního kalendáře daňový doklad; bez něj se doklad tiskl jako běžná
            // faktura s jediným datem splatnosti, tedy bez toho jediného, kvůli čemu
            // vzniká. U ostatních typů dokladu zůstává prázdný.
            'payment_schedule'  => ($invoice['invoice_type'] ?? '') === 'payment_calendar'
                ? $this->paymentSchedule->forInvoice((int) $invoice['supplier_id'], (int) $invoice['id'])
                : [],
        ];
        return $twig->render('invoice.twig', $vars);
    }

    /**
     * Podklad pro OSS doložku na dokladu — public stejně jako resolve*(), protože
     * ho vedle PDF potřebuje i veřejný HTML náhled (PublicInvoiceGetAction).
     * Rozhodnutí samo dělá {@see OssInvoiceClause}, tady se k němu jen doplní
     * názvy států z číselníku zemí.
     *
     * @param array<string,mixed> $invoice
     * @return array{all_items:bool, countries:list<array{iso2:string,name_cs:string,name_en:string}>}|null
     */
    public function ossClause(array $invoice): ?array
    {
        $items = (array) ($invoice['items'] ?? []);
        $codes = OssInvoiceClause::consumerCountryCodes($items);
        $names = [];
        if ($codes !== []) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT iso2, name_cs, name_en FROM countries WHERE iso2 IN ($in)"
            );
            $stmt->execute($codes);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $names[strtoupper((string) $row['iso2'])] = [
                    'name_cs' => $row['name_cs'] ?? null,
                    'name_en' => $row['name_en'] ?? null,
                ];
            }
        }
        return OssInvoiceClause::build($items, $names);
    }

    private function newMpdf(string $tmpDir): Mpdf
    {
        // Okraje drží sloupcovou mřížku ze `styles/invoice.css` — dělicí linka na
        // 93.8 mm, platební pruh a tabulka položek jsou na ně navázané. Kdo je
        // změní, musí přepočítat i mřížku (komentář v hlavičce stylopisu).
        // margin_footer < margin_bottom, jinak by patička vlezla do sazby.
        return new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 6.6,
            'margin_bottom'     => 20,
            'margin_left'       => 9.5,
            'margin_right'      => 11.8,
            'margin_footer'     => 4.4,
            'tempDir'           => $tmpDir,
            'autoPageBreak'     => true,
            ...MpdfFontConfig::options(),
        ]);
    }

    /**
     * Kdo doklad vystavil — pro patičku dokladu.
     *
     * Bere AUTORA dokladu (`invoices.created_by`), ne přihlášeného uživatele: PDF se
     * cachuje a servíruje opakovaně, takže údaj vázaný na toho, kdo si ho zrovna
     * stáhl, by na dokladu lhal každému dalšímu čtenáři.
     *
     * `created_by_name` dodává rovnou {@see InvoiceRepository::find()}; dopočet z
     * `created_by` je pro volající, kteří si pole faktury sestavili jinak (veřejný
     * náhled, hromadný tisk, testy).
     */
    private function issuedBy(array $invoice): ?string
    {
        $name = trim((string) ($invoice['created_by_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $userId = (int) ($invoice['created_by'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $name = trim((string) ($stmt->fetchColumn() ?: ''));
        return $name !== '' ? $name : null;
    }

    /**
     * Invaliduje cached PDF všech draftů dodavatele — volá se po změně brandingu
     * (barva/logo/toggle), protože ty se v PDF renderují živě (nejsou ve snapshotu),
     * ale mtime-based cache je sama od sebe neobnoví. Drafty mažeme bez archive entry
     * (archive:false) — jsou to jen preview, ne odeslané doklady. Vystavené faktury
     * regenerují při příští změně šablony/CSS/kódu nebo přes ?regenerate=1.
     *
     * Vrací počet invalidovaných draftů.
     */
    public function invalidateDraftsBySupplier(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND status = "draft"'
        );
        $stmt->execute([$supplierId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id, 'invalidate_branding', archive: false);
        return count($ids);
    }

    /**
     * Invaliduje cached CZK PDF, jejichž QR může obsahovat SPAYD datum splatnosti.
     * Vystavené verze archivuje, draft preview pouze smaže. Volá se jen při
     * skutečné změně invoice_qr_include_due_date.
     */
    public function invalidatePaymentQrBySupplier(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.status
               FROM invoices i
               JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ?
                AND c.code = "CZK"
                AND i.status <> "paid"
                AND i.payment_method = "bank_transfer"
                AND i.invoice_type <> "payment_calendar"
                AND i.varsymbol IS NOT NULL AND i.varsymbol <> ""
                AND (i.pdf_path IS NOT NULL OR i.pdf_generated_at IS NOT NULL)'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $this->invalidate(
                (int) $row['id'],
                'invalidate_payment_qr_settings',
                archive: (string) $row['status'] !== 'draft',
            );
        }
        return count($rows);
    }

    private function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice');
        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
        ] + TwigCache::options('invoice'));

        // `t` se registruje jen jednou (addFunction() jde volat pouze před prvním
        // renderem). Locale proto NESMÍ být zachycené `use ($locale)` — closure
        // čte mutovatelný stav, který renderHtml() přenastaví před každým renderem.
        $this->twig->addFunction(new \Twig\TwigFunction('t', function (string $cs, string $en) {
            return $this->locale === 'en' ? $en : $cs;
        }));

        return $this->twig;
    }

    /** @param array<string,mixed> $supplier @param array<string,mixed> $invoice */
    private function applyLiveBrandingProfile(array $supplier, array $invoice): array
    {
        if (empty($invoice['branding_profile_id'])) return $supplier;
        $stmt = $this->db->pdo()->prepare(
            'SELECT bp.* FROM supplier s
               JOIN branding_profiles bp ON bp.id = ?
                                        AND bp.supplier_id = s.id AND bp.is_active = 1
              WHERE s.id = ? AND s.branding_profiles_enabled = 1'
        );
        $stmt->execute([
            (int) $invoice['branding_profile_id'],
            (int) ($invoice['supplier_id'] ?? 0),
        ]);
        $profile = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($profile === false) return $supplier;
        return \MyInvoice\Service\Branding\BrandingProfileOverlay::apply($supplier, $profile);
    }

    /**
     * resolveSupplier/resolveClient/resolveBank jsou public — kromě PDF renderu
     * je používá i veřejná web faktura (PublicInvoiceGetAction), aby HTML náhled
     * ukazoval STEJNÁ data jako PDF (snapshot-first s defensive merge).
     */
    public function resolveSupplier(array $invoice): array
    {
        $live = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        $snap = null;
        if (!empty($invoice['supplier_snapshot'])) {
            $decoded = is_string($invoice['supplier_snapshot']) ? json_decode($invoice['supplier_snapshot'], true) : $invoice['supplier_snapshot'];
            if (is_array($decoded)) {
                $snap = $decoded;
            }
        }
        // Defensive merge: snapshot je primární (zachovává historické údaje), ale chybějící
        // klíče (př. legacy snapshoty bez street) doplníme z live supplier dat. Zabrání
        // tomu, aby se vystavená faktura ze stubu vykreslila jen s názvem firmy.
        $row = $snap !== null ? array_merge($live, $snap) : $live;

        // Plátcovství DPH ale z live řádku doplňovat NESMÍME — je to cache dneška.
        // Draft (bez snapshotu) i legacy snapshot bez is_vat_payer dostanou stav
        // k rozhodnému datu dokladu z historie (VatStatusService).
        if (($snap === null || !array_key_exists('is_vat_payer', $snap))
            && !empty($invoice['supplier_id'])
            && $row !== []
        ) {
            $row['is_vat_payer'] = $this->vatStatus->isVatPayerAt(
                (int) $invoice['supplier_id'],
                (string) (($invoice['tax_date'] ?? null) ?: ($invoice['issue_date'] ?? date('Y-m-d'))),
            );
        }
        return $row;
    }

    public function resolveClient(array $invoice): array
    {
        // Defensive merge: snapshot je primární (historický stav), live data
        // doplní chybějící klíče. Bez merge by legacy/cizí snapshoty (import
        // z ISDOC/Pohody, ruční insert) vykreslily fakturu s prázdnou adresou.
        $live = [];
        if (!empty($invoice['client_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
                   FROM clients c JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ?'
            );
            $stmt->execute([$invoice['client_id']]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) {
                return array_merge($live, $snap);
            }
        }
        return $live;
    }

    public function resolveBank(array $invoice): ?array
    {
        // Live data z currencies (account/bank/IBAN/BIC podle currency_id).
        // Stejný defensive-merge pattern jako u supplier/client — snapshot vyhrává,
        // live doplní chybějící IBAN/BIC/bank_name v legacy snapshotech.
        $live = [];
        if (!empty($invoice['currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?'
            );
            $stmt->execute([(int) $invoice['currency_id']]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        $row = $live;
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) {
                $row = array_merge($live, $snap);
                // Snapshot je primární (historický stav účtu), ALE prázdná hodnota ve snapshotu
                // nesmí přebít neprázdné live pole. Doklad vystavený dřív, než se do nastavení
                // doplnil IBAN, SWIFT nebo název banky, má v snapshotu prázdno a array_merge
                // ho nechá vyhrát — na faktuře pak IBAN chybí napořád, i když ho firma
                // v nastavení dávno má.
                //
                // Doplňujeme JEN prázdná pole a JEN u prokazatelně TÉHOŽ účtu. Tuzemské číslo
                // a IBAN jsou dva zápisy jednoho účtu, takže shoda kteréhokoli z nich stačí;
                // rozdílný IBAN na obou stranách je naopak důkaz, že jde o jiný účet, a tam
                // se ze snapshotu nesahá na nic. Hodnotu, kterou snapshot NESE, nepřepisujeme
                // nikdy — to je ten immutable záznam o tom, kam se mělo platit.
                $norm = static fn (mixed $v): string => strtoupper(preg_replace('/\s+/', '', (string) $v) ?? '');

                $snapDomestic = $norm($snap['account_number'] ?? '') . '/' . $norm($snap['bank_code'] ?? '');
                $liveDomestic = $norm($live['account_number'] ?? '') . '/' . $norm($live['bank_code'] ?? '');
                $snapIban = $norm($snap['iban'] ?? '');
                $liveIban = $norm($live['iban'] ?? '');

                $ibanConflict  = $snapIban !== '' && $liveIban !== '' && $snapIban !== $liveIban;
                $domesticMatch = $snapDomestic !== '/' && $snapDomestic === $liveDomestic;
                $ibanMatch     = $snapIban !== '' && $snapIban === $liveIban;

                if (!$ibanConflict && ($domesticMatch || $ibanMatch)) {
                    foreach (['bank_name', 'bic', 'iban'] as $k) {
                        if (empty($row[$k]) && !empty($live[$k])) {
                            $row[$k] = $live[$k];
                        }
                    }
                }
            }
        }
        if (empty($row)) return null;
        $hasCzk = !empty($row['account_number']) && !empty($row['bank_code']);
        $hasIban = !empty($row['iban']);
        return ($hasCzk || $hasIban) ? $row : null;
    }

    /**
     * Balení na PDF (issue #17): řádek skladové karty fakturovaný v balení dostane
     * `base_qty` a `base_unit` a šablona pod jednotku doplní „(celkem 80 ks)".
     * Přepíná `supplier.invoice_pdf_show_base_qty` (čte se živě — je to volba
     * vzhledu, ne údaj dokladu). Převod dělá jen StockUnitConverter; řádek
     * v základní nebo neznámé jednotce zůstane beze změny.
     *
     * @param array<string,mixed> $invoice
     * @return list<array<string,mixed>>|array<int|string,mixed>
     */
    private function withBaseQuantity(array $invoice): array
    {
        $items = (array) ($invoice['items'] ?? []);
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $lines = [];
        foreach ($items as $i => $item) {
            if (is_array($item) && !empty($item['stock_item_id']) && trim((string) ($item['unit'] ?? '')) !== '') {
                $lines[$i] = ['stock_item_id' => (int) $item['stock_item_id'], 'unit' => (string) $item['unit']];
            }
        }
        if ($lines === [] || $supplierId <= 0) {
            return $items;
        }
        // Jen firma se zapnutým skladem A zapnutým rozpisem — jinak PDF beze změny.
        $flag = $this->db->pdo()->prepare('SELECT invoice_pdf_show_base_qty, stock_enabled FROM supplier WHERE id = ?');
        $flag->execute([$supplierId]);
        $settings = $flag->fetch(\PDO::FETCH_ASSOC);
        if ($settings === false || (int) $settings['invoice_pdf_show_base_qty'] !== 1 || (int) $settings['stock_enabled'] !== 1) {
            return $items;
        }
        $converter = new \MyInvoice\Service\Stock\StockUnitConverter($this->db);
        foreach ($converter->ratios($supplierId, $lines) as $i => $ratio) {
            if ($ratio['is_base']) {
                continue;
            }
            $items[$i]['base_qty'] = (float) \MyInvoice\Service\Stock\StockUnitConverter::applyRatio(
                (string) ($items[$i]['quantity'] ?? '0'),
                $ratio['numerator'],
                $ratio['denominator'],
            );
            $items[$i]['base_unit'] = $ratio['base_unit'];
        }
        return $items;
    }

    private function getSupplierData(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function docTypeLabel(array $invoice, string $locale, array $supplier = []): string
    {
        // Identifikovaná osoba (§ 6g–6l, #94): její RC faktura do EU JE daňový
        // doklad (povinnost ho vystavit do 15 dnů od konce měsíce, § 28) —
        // label „daňový doklad" si zaslouží stejně jako plátcovská.
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? true)
            || ((bool) ($supplier['is_identified'] ?? false) && !empty($invoice['reverse_charge']));
        $labels = [
            'cs' => [
                'invoice'      => $isVatPayer ? 'Faktura — daňový doklad' : 'Faktura',
                'proforma'     => 'Zálohová faktura',
                'credit_note'  => $isVatPayer ? 'Opravný daňový doklad' : 'Opravná faktura',
                'cancellation' => 'Storno (interní)',
                'tax_document' => 'Daňový doklad k přijaté platbě',
                // § 31a ZDPH: platební kalendář JE daňový doklad, obsahuje-li rozpis plateb
                // na předem stanovené období. Bez vlastního popisku se tiskl jako „Faktura"
                // a příjemce z něj nepoznal, o jaký doklad jde.
                'payment_calendar' => $isVatPayer ? 'Platební kalendář — daňový doklad' : 'Platební kalendář',
            ],
            'en' => [
                'invoice'      => $isVatPayer ? 'Invoice — Tax document' : 'Invoice',
                'proforma'     => 'Proforma invoice',
                'credit_note'  => $isVatPayer ? 'Credit note — Tax adjustment' : 'Credit note',
                'cancellation' => 'Cancellation (internal)',
                'tax_document' => 'Tax document for payment received',
                'payment_calendar' => $isVatPayer ? 'Payment calendar — Tax document' : 'Payment calendar',
            ],
        ];
        return $labels[$locale][$invoice['invoice_type']] ?? $labels['cs'][$invoice['invoice_type']] ?? '';
    }

    private function docTitle(array $invoice): string
    {
        $vs = $invoice['varsymbol'] ?? ('DRAFT-' . $invoice['id']);
        $t = match ($invoice['invoice_type']) {
            'proforma'     => 'Zálohová faktura',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            'tax_document' => 'Daňový doklad k platbě',
            'payment_calendar' => 'Platební kalendář',
            default        => 'Faktura',
        };
        return "$t $vs";
    }

    private function parentVarsymbol(array $invoice): ?string
    {
        if (!$invoice['parent_invoice_id']) return null;
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoice['parent_invoice_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Resnapshot supplier/client/bank z live dat a uloží do invoices. Volá se při
     * forceRegenerate, aby `regenerate=1` propsalo i změny v supplier/client/banku.
     * Drafty (bez existujících snapshotů) přeskoč — ty stejně renderují z live.
     *
     * Defensive guard: pro non-draft fakturu (issued/sent/reminded/paid) NIKDY
     * nepřepisuj snapshot, i když si někdo vynutí ?regenerate=1. Snapshoty u
     * vystavených faktur jsou immutable audit trail toho, co bylo na dokladu.
     *
     * @return array  invoice array s aktualizovanými snapshoty (in-memory)
     */
    private function refreshSnapshots(array $invoice): array
    {
        $status = (string) ($invoice['status'] ?? 'draft');
        if ($status !== 'draft') {
            return $invoice;
        }
        $hasAny = !empty($invoice['supplier_snapshot'])
            || !empty($invoice['client_snapshot'])
            || !empty($invoice['bank_snapshot']);
        if (!$hasAny) return $invoice;

        return $this->writeSnapshots($invoice);
    }

    /**
     * Přepíše snapshoty (supplier/client/bank) z aktuálních live dat — voláno při
     * admin force-editu VYSTAVENÉ faktury, aby se opravené údaje stran (adresa/IČO/
     * název odběratele, banka) promítly do nově generovaného PDF.
     *
     * Narozdíl od refreshSnapshots() (regenerate cesta, jen drafty) tahle metoda
     * ZÁMĚRNĚ přepíše snapshot i u issued/sent/paid faktury — je to vědomá oprava
     * dokladu, kterou UI uživateli avizuje („Změny přepíšou snapshoty"). Auditní
     * stopu (kdo/kdy/co) zajišťuje volající přes ActivityLogger.
     */
    public function rebuildSnapshots(int $invoiceId): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) return;
        $this->writeSnapshots($invoice);
    }

    /**
     * Postaví snapshoty z live dat a uloží do invoices. Sdílené jádro pro
     * refreshSnapshots() (drafty) i rebuildSnapshots() (force-edit vystavené).
     *
     * @return array  invoice array s aktualizovanými snapshoty (in-memory)
     */
    private function writeSnapshots(array $invoice): array
    {
        try {
            // Supplier část se staví k rozhodnému datu DOKLADU (tax ?? issue) — rebuild
            // opravuje adresy/názvy, nesmí přepsat historické plátcovství dnešní cache.
            $built = $this->snapshots->build(
                (int) $invoice['client_id'],
                (int) $invoice['currency_id'],
                (int) ($invoice['supplier_id'] ?? 0),
                isset($invoice['branding_profile_id']) ? (int) $invoice['branding_profile_id'] : null,
                (string) (($invoice['tax_date'] ?? null) ?: ($invoice['issue_date'] ?? date('Y-m-d'))),
            );
        } catch (\Throwable) {
            // Pokud klient/dodavatel neexistuje (smazaný), zachovej původní snapshot.
            return $invoice;
        }

        // Plátcovství klienta u VYSTAVENÉHO dokladu: živý stav dneška nemusí odpovídat
        // stavu při vystavení a klient historii nemá — zachovej hodnotu ze stávajícího
        // snapshotu, pokud existuje; jinak (legacy snapshot bez klíče) vezmi živou.
        if ((string) ($invoice['status'] ?? 'draft') !== 'draft' && !empty($invoice['client_snapshot'])) {
            $prevClient = is_string($invoice['client_snapshot'])
                ? json_decode($invoice['client_snapshot'], true)
                : $invoice['client_snapshot'];
            if (is_array($prevClient) && array_key_exists('is_vat_payer', $prevClient)) {
                $built['client']['is_vat_payer'] = $prevClient['is_vat_payer'] !== null
                    ? (bool) $prevClient['is_vat_payer']
                    : null;
            }
        }

        $supplierJson = json_encode($built['supplier'], JSON_UNESCAPED_UNICODE);
        $clientJson   = json_encode($built['client'], JSON_UNESCAPED_UNICODE);
        $bankJson     = $built['bank'] !== null ? json_encode($built['bank'], JSON_UNESCAPED_UNICODE) : null;

        $this->db->pdo()->prepare(
            'UPDATE invoices SET supplier_snapshot = ?, client_snapshot = ?, bank_snapshot = ? WHERE id = ?'
        )->execute([$supplierJson, $clientJson, $bankJson, (int) $invoice['id']]);

        $invoice['supplier_snapshot'] = $supplierJson;
        $invoice['client_snapshot']   = $clientJson;
        $invoice['bank_snapshot']     = $bankJson;
        return $invoice;
    }

    /**
     * Archivuje cached PDF (pokud existuje) a vynuluje invoices.pdf_path.
     * Volá se po změnách, které ovlivní obsah PDF nad rámec items
     * (např. work_report, edit faktury, vystavení).
     *
     * Reason je uložen do invoice_pdfs.reason a pomáhá v UI rozlišit,
     * proč se historická verze archivovala (edit / issue / workreport / ...).
     */
    public function invalidate(int $invoiceId, string $reason = 'invalidate_manual', bool $archive = true): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) return;

        $paths = array_unique(array_filter([
            self::resolvePdfPath($invoice['pdf_path'] ?? null),
            $this->cachePath($invoice),
        ]));
        foreach ($paths as $p) {
            if (!is_file($p)) continue;
            if ($archive) {
                // Archivuj místo unlink — zachová verzi pro audit a UI historii.
                // archive() přesune soubor do _archive/ (atomic rename, fallback copy+unlink).
                $this->archive->archive($invoiceId, $p, $reason);
            } else {
                // Pro draft cache (např. před alokací VS): jen smaž, bez archive entry.
                @unlink($p);
            }
        }
        $this->db->pdo()->prepare('UPDATE invoices SET pdf_path = NULL, pdf_generated_at = NULL WHERE id = ?')
            ->execute([$invoiceId]);
    }

    /**
     * Bulk invalidate — pro všechny faktury v dané měně, které renderují bank info live
     * (drafts + faktury bez snapshotu). Issued/sent/paid s bank_snapshot mají immutable kopii
     * bank údajů a invalidace by zbytečně regenerovala stejný PDF.
     *
     * Vrací počet invalidovaných faktur.
     */
    public function invalidateByCurrency(int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE currency_id = ? AND (status = "draft" OR bank_snapshot IS NULL)'
        );
        $stmt->execute([$currencyId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id, 'invalidate_currency');
        return count($ids);
    }

    private function cachePath(array $invoice): string
    {
        $issueDate = new \DateTimeImmutable($invoice['issue_date']);
        // Multi-supplier: supplier subfolder zabraňuje kolizi varsymbolu mezi suppliery
        $supplierId = (int) ($invoice['supplier_id'] ?? 1);
        $dir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/' . $issueDate->format('Y-m');

        $vs = $invoice['varsymbol'] ?? ('draft-' . $invoice['id']);
        // Sanitize varsymbol pro filesystem — defense-in-depth proti path traversal
        // přes importovaný varsymbol (security report @andrejtomci #3 — `varsymbol`
        // se sice už validuje na vstupu ImportService::processOne, ale tady je to
        // belt-and-braces pro případ legacy řádků v DB nebo jiných cest vstupu).
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $vs);
        $type = match ($invoice['invoice_type']) {
            'proforma'     => 'Proforma',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            'tax_document' => 'DanovyDoklad',
            'payment_calendar' => 'PlatebniKalendar',
            default        => 'Faktura',
        };
        return "$dir/$type-$vs.pdf";
    }

    private function updatePdfPath(int $invoiceId, string $path): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET pdf_path = ?, pdf_generated_at = NOW() WHERE id = ?'
        )->execute([self::toRelativePdfPath($path), $invoiceId]);
    }

    /**
     * N-016: do `invoices.pdf_path` patří cesta RELATIVNÍ ke `storage/invoices`.
     *
     * Absolutní cesta přežije jen do prvního přesunu instance — v produkci se takhle
     * nasbíralo 43 řádků ze dvou různých kořenů (instance byla přejmenována) a žádný
     * z nich už netrefil existující soubor. Přijatá větev (`purchase_invoices.pdf_path`)
     * relativní cesty ukládá odjakživa, tohle je dorovnání na stejný tvar.
     *
     * Ne-katastrofa jen shodou okolností: sloupec je cache renderu, takže netrefená
     * cesta znamená zbytečné přegenerování PDF, ne ztracený doklad.
     */
    private static function toRelativePdfPath(string $absolute): string
    {
        $root = str_replace('\\', '/', \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices'));
        $norm = str_replace('\\', '/', $absolute);
        if ($root !== '' && str_starts_with($norm, rtrim($root, '/') . '/')) {
            return ltrim(substr($norm, strlen(rtrim($root, '/'))), '/');
        }
        // Cesta mimo očekávaný kořen (jiná konfigurace, test) — ulož, jak přišla.
        // Resolve níž si s absolutní hodnotou poradí.
        return $norm;
    }

    /**
     * Protějšek {@see toRelativePdfPath} — z uložené hodnoty udělá absolutní cestu.
     *
     * Musí snést i LEGACY absolutní hodnoty: backfill je převádí, ale řádky ze záloh
     * a z jiných prostředí se můžou objevit kdykoliv. Rozpoznání absolutní cesty
     * pokrývá i Windows (`C:/…`) a UNC (`//server/…`).
     */
    public static function resolvePdfPath(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }
        $norm = str_replace('\\', '/', $stored);
        $isAbsolute = str_starts_with($norm, '/')
            || str_starts_with($norm, '//')
            || preg_match('#^[A-Za-z]:/#', $norm) === 1;

        return $isAbsolute
            ? $norm
            : rtrim(str_replace('\\', '/', \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices')), '/')
                . '/' . ltrim($norm, '/');
    }
}
