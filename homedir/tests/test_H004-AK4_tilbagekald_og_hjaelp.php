<?php
declare(strict_types=1);

// H004-AK4: dobbelt tilbagekald, en andens nøgle og et ukendt id giver alle redirect til /konto med samme flash, så id'er
// ikke kan afprøves. Hjælpeteksten om genindlæsning står under "Opret nøgle" (QA F003 F-3 og F-4).

require_once __DIR__ . '/h004_lib.php';

use Whisper\App;
use Whisper\DB;
use Whisper\Flags;
use Whisper\Mcp\Tokens;
use Whisper\Ui;
use Whisper\View;

f003_setup();
[$c, $uid] = f003_login();
f003_token($uid, 'Min');
$mine = (int) DB::scalar('SELECT id FROM mcp_tokens WHERE user_id = ?', [$uid]);
$other = f001_user('anden@h004.test');
Tokens::create($other, 'Anden', F003_NOW);
$otherId = (int) DB::scalar('SELECT id FROM mcp_tokens WHERE user_id = ?', [$other]);

$first = $c->post('/konto/mcp-noegle/' . $mine . '/tilbagekald');
H004Check::that('AK4', $first->status === 303 && str_contains($c->get('/konto')->body, 'Nøglen «Min» er tilbagekaldt.'),
    'første tilbagekald: som før');

$answers = [];
foreach (['dobbelt' => $mine, 'en andens' => $otherId, 'ukendt' => 999999] as $case => $id) {
    $r = $c->post('/konto/mcp-noegle/' . $id . '/tilbagekald');
    $page = $c->get('/konto')->body;
    H004Check::that('AK4', $r->status === 303 && $r->header('Location') === '/konto' && str_contains($page, App::ALREADY_REVOKED),
        "$case nøgle: redirect til /konto med \"" . App::ALREADY_REVOKED . '"');
    $answers[] = $r->status . ' ' . $r->header('Location') . ' ' . $r->body;
}
H004Check::that('AK4', count(array_unique($answers)) === 1, 'de tre svar er identiske');
H004Check::that('AK4', DB::scalar('SELECT revoked_at FROM mcp_tokens WHERE id = ?', [$otherId]) === null, 'den andens nøgle er urørt');

foreach ([false => 'gammelt UI', true => 'nyt UI (F005)'] as $modern => $label) {
    Flags::override(Ui::FLAG, (bool) $modern);
    $body = $c->get('/konto')->body;
    $form = strpos($body, 'action="/konto/mcp-noegle"');
    $hint = strpos($body, View::MCP_RELOAD);
    H004Check::that('AK4', $form !== false && $hint !== false && $hint > $form, "hjælpeteksten om genindlæsning står under formularen ($label)");
}

H004Check::done();
