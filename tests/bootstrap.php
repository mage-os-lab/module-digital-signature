<?php
/**
 * Bootstrap dei test unit.
 *
 * Standalone (locale/CI senza Magento): carica gli stub minimi delle classi
 * framework usate dalle classi sotto test. Se il framework Magento reale è
 * presente nell'autoload (esecuzione dentro un'installazione), gli stub NON
 * vengono caricati.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Magento\Framework\Phrase::class)) {
    require __DIR__ . '/stubs/MagentoStubs.php';
}
