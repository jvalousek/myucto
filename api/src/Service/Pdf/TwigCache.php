<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Společné nastavení kompilační cache Twigu pro PDF renderery.
 *
 * Bez cache Twig při každém renderu šablonu znovu lexuje, parsuje, kompiluje
 * a spouští přes `eval()`. Se zapnutou cache se zkompilovaná šablona uloží na
 * disk a načte přes `include`, takže ji chytne i OPcache.
 *
 * Nepatří do Redisu — je to lokální soubor, ne sdílený stav. Smazání adresáře
 * je vždy bezpečné; nejbližší render si šablony zkompiluje znovu.
 */
final class TwigCache
{
    /**
     * Volby do konstruktoru `Twig\Environment`.
     *
     * `auto_reload` nastavujeme EXPLICITNĚ a VŽDY na `true` — v produkci stejně
     * jako ve vývoji. Twig si klíčuje zkompilovanou šablonu podle její CESTY
     * (`FilesystemLoader::getCacheKey()` vrací relativní path, `FilesystemCache`
     * ji jen zahashuje), NE podle zdroje. S vypnutým `auto_reload` proto
     * `Environment::loadTemplate()` načte starý zkompilovaný soubor bez jediné
     * kontroly a změna šablony se neprojeví NIKDY.
     *
     * V Dockeru to není teoretická vada: `storage/` leží na persistentním
     * volume, takže cache přežije výměnu image a nový deploy sází pořád starý
     * doklad. Přesně tak zůstal po nasazení neviditelný nový layout faktury.
     *
     * Cena za správnost je jeden `filemtime()` na šablonu a render — proti běhu
     * mPDF neměřitelná. Navíc tím `FilesystemCache` dostane
     * FORCE_BYTECODE_INVALIDATION, tedy `opcache_invalidate()` při zápisu; bez
     * něj by při `opcache.validate_timestamps=0` (produkční ini image) i nově
     * zkompilovaná šablona běžela ze starých opkódů.
     *
     * @return array{cache:string|false,auto_reload:bool}
     */
    public static function options(string $namespace): array
    {
        $dir = RuntimePaths::storage('cache/twig/' . $namespace);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // Neexistuje a nejde vyrobit (read-only FS, práva) — jeď bez cache.
            return ['cache' => false, 'auto_reload' => true];
        }

        return [
            'cache'       => $dir,
            'auto_reload' => true,
        ];
    }
}
