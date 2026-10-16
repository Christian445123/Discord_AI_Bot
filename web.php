<?php
declare(strict_types=1);

/** Liest die Website (WEBSITE_URLS) und speichert den Text als Wissen:  php web.php */

if (!is_file(__DIR__ . '/vendor/autoload.php') || !is_file(__DIR__ . '/.env')) {
    fwrite(STDERR, "Bot ist noch nicht eingerichtet. Bitte zuerst ausfuehren: php install.php\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Env;
use App\Knowledge;
use App\WebImporter;

Env::load(__DIR__);

echo "=== Website einlesen ===\n";
$db = new Database();
$knowledge = new Knowledge($db);
$result = (new WebImporter($db, $knowledge))->run(fn(string $m) => print($m . "\n"));

// Gesichertes Grundwissen aus der Website (haeufige Fragen mit kurzen Antworten)
$seeded = App\Seed::load($knowledge);
echo "  [Grundwissen] {$seeded} haeufige Fragen mit Antworten aus der Website geladen\n";

echo sprintf("\nFertig: %d Seiten gelesen, %d Wissens-Abschnitte gespeichert.\n", $result['pages'], $result['chunks']);
foreach ($result['errors'] as $e) {
    echo "  Fehler: {$e}\n";
}
exit($result['pages'] > 0 || $result['errors'] === [] ? 0 : 1);
