<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\TwigCache;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Regrese: po deploy se nová šablona dokladu nevykreslila.
 *
 * Twig klíčuje zkompilovanou šablonu podle její CESTY, ne podle zdroje. Dokud
 * bylo `auto_reload` v produkci vypnuté, `Environment::loadTemplate()` načetl
 * starý zkompilovaný soubor bez jediné kontroly — a protože `storage/` leží
 * v Dockeru na persistentním volume, přežila cache výměnu image a doklad se
 * sázel podle šablony z minulého nasazení.
 */
final class TwigCacheTest extends TestCase
{
    private string $tmpDir;
    private string|false $envBackup;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/twig-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/templates', 0775, true);
        $this->envBackup = getenv('MYINVOICE_APP_ENV');
        // Rozbité to bylo právě a jen v produkci — test musí běžet v ní.
        putenv('MYINVOICE_APP_ENV=production');
    }

    protected function tearDown(): void
    {
        $this->envBackup === false
            ? putenv('MYINVOICE_APP_ENV')
            : putenv('MYINVOICE_APP_ENV=' . $this->envBackup);
        self::removeTree($this->tmpDir);
    }

    /**
     * Jádro věci: s nastavením, které dostane produkce, musí druhý render VIDĚT
     * změněný zdroj. Test běží proti reálnému Twigu, takže chytí i změnu jeho
     * chování, nejen překlopený boolean v našem kódu.
     */
    public function testChangedTemplateIsRecompiledUnderProductionOptions(): void
    {
        $template = $this->tmpDir . '/templates/doc.twig';
        file_put_contents($template, 'PŮVODNÍ LAYOUT');

        self::assertSame('PŮVODNÍ LAYOUT', $this->render());

        // Nový deploy: stejná cesta, jiný obsah. mtime musí povyskočit, jinak by
        // test měřil rozlišení souborového systému, ne chování cache.
        file_put_contents($template, 'NOVÝ LAYOUT');
        touch($template, time() + 5);
        clearstatcache();

        self::assertSame(
            'NOVÝ LAYOUT',
            $this->render(),
            'Twig vrátil šablonu z cache minulého deploye — zkontroluj auto_reload v TwigCache.',
        );
    }

    /** Cache se nesmí vypnout jako „řešení" — kompilace na každý render je drahá. */
    public function testCacheStaysEnabledWhenTheDirectoryIsWritable(): void
    {
        $options = TwigCache::options('test-namespace');

        self::assertNotFalse($options['cache'], 'Kompilační cache musí zůstat zapnutá.');
        self::assertTrue($options['auto_reload'], 'auto_reload musí platit i v produkci.');
    }

    /**
     * Render v SAMOSTATNÉM procesu — jinak by test neměřil nic.
     *
     * Název zkompilované třídy Twig odvozuje z cesty šablony, takže po prvním
     * renderu je třída v procesu deklarovaná a `loadTemplate()` se k cache ani
     * nedostane (`class_exists($cls, false)`). Uvnitř jednoho requestu je to
     * správně; nasazení nové verze ale znamená nový proces, a právě ten musí
     * změnu zdroje uvidět. Cache dir je z testu (do storage se nezapisuje),
     * `auto_reload` z ostrého nastavení — tím je test svázaný s produkcí.
     */
    private function render(): string
    {
        $script = $this->tmpDir . '/render.php';
        if (!is_file($script)) {
            file_put_contents($script, <<<'PHP'
                <?php
                require $argv[1];
                $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($argv[2]), [
                    'autoescape'  => false,
                    'cache'       => $argv[3],
                    'auto_reload' => $argv[4] === '1',
                ]);
                echo $twig->render('doc.twig');
                PHP);
        }

        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            $script,
            \dirname(__DIR__, 4) . '/vendor/autoload.php',
            $this->tmpDir . '/templates',
            $this->tmpDir . '/cache',
            TwigCache::options('test-namespace')['auto_reload'] ? '1' : '0',
        ]));

        return trim((string) shell_exec($cmd . ' 2>&1'));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
