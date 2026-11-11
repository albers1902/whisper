<?php
declare(strict_types=1);

/**
 * GET /health — frameworkets driftsbevis (LIFECYCLE §8). Læses af deploy.sh (ny SHA + db_read + db_write)
 * og monitor.sh. Offentligt: ingen hemmeligheder, ingen persondata.
 * /health (uden .php) rewrites hertil i public_html/.htaccess.
 */

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\DB;
use Whisper\Health;

$report = Health::report(\Whisper\Config::baseDir(), static fn(): PDO => DB::pdo());

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(200);
echo json_encode($report, JSON_UNESCAPED_SLASHES);
