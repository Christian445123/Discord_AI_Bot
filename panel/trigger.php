<?php
declare(strict_types=1);

/**
 * Loest den Website-Import von aussen aus (z.B. von viennastaterp.at nach einer Aenderung am Regelwerk):
 *   https://<panel>/trigger.php?token=<WEB_TRIGGER_TOKEN>
 * Ohne gesetztes WEB_TRIGGER_TOKEN in der .env ist die Schnittstelle deaktiviert.
 */

require __DIR__ . '/bootstrap.php';

use App\Env;

header('Content-Type: application/json; charset=utf-8');

$expected = Env::get('WEB_TRIGGER_TOKEN');
$given = (string) ($_REQUEST['token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code($expected === '' ? 404 : 403);
    echo json_encode(['ok' => false, 'error' => $expected === '' ? 'deaktiviert' : 'ungueltiges Token']);
    exit;
}

$message = dispatch_job(db(), 'import_web', 'Website einlesen');
echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
