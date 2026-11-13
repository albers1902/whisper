<?php
declare(strict_types=1);

/**
 * Fælles hjælpere til F008-testene (ikke selv en test: run.php kører kun test_*.php). Bygger på F007 (F004, F005_ui,
 * F007_kilder) og tænder F008_leads oveni.
 */

require_once __DIR__ . '/f007_lib.php';

use Whisper\DB;
use Whisper\Flags;
use Whisper\Leads;
use Whisper\Response;

final class F008Check
{
    public static int $fails = 0;
    /** @var list<string> */
    private static array $failed = [];

    public static function that(string $ak, bool $cond, string $msg): void
    {
        echo ($cond ? 'ok:   ' : 'FAIL: ') . "F008-$ak $msg\n";
        if (!$cond) {
            self::$fails++;
            self::$failed[] = "F008-$ak $msg";
        }
    }

    public static function done(): never
    {
        foreach (self::$failed as $f) {
            echo 'FAIL (gentaget): ' . $f . "\n";
        }
        echo self::$fails === 0 ? "alle tjek bestået\n" : self::$fails . " tjek fejlede\n";
        exit(self::$fails === 0 ? 0 : 1);
    }
}

function f008_setup(bool $on = true): string
{
    $dir = f007_setup();
    Flags::override(Leads::FLAG, $on);
    return $dir;
}

/**
 * Et Adversus-opkald direkte i databasen.
 *
 * @param array<string, mixed> $o  lead_id, started_at, disposition, transcript_status, audio_status, is_success, agent_name, …
 */
function f008_call(array $o): int
{
    static $n = 0;
    $n++;
    $r = $o + ['lead_id' => null, 'campaign_id' => 31, 'is_success' => 0, 'direction' => 'outbound', 'disposition' => null,
        'customer_phone' => '+45 12 34 56 78', 'agent_name' => 'Sara', 'started_at' => '2026-11-10 10:00:00', 'duration_sec' => 120,
        'audio_status' => 'venter', 'transcript_status' => 'ikke_valgt'];
    DB::exec('INSERT INTO calls (`source`, external_id, lead_id, campaign_id, is_success, direction, disposition, customer_phone, agent_name, '
        . "started_at, duration_sec, recording_url, audio_status, transcript_status, created_at, updated_at) VALUES ('adversus', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, NOW(), NOW())",
        ['f8-' . $n . '-' . random_int(0, 999999), $r['lead_id'], $r['campaign_id'], $r['is_success'], $r['direction'], $r['disposition'],
            $r['customer_phone'], $r['agent_name'], $r['started_at'], $r['duration_sec'], $r['audio_status'], $r['transcript_status']]);
    return (int) DB::scalar('SELECT LAST_INSERT_ID()');
}

function f008_lead(int $lead, string $status): void
{
    DB::exec('INSERT INTO adversus_leads (lead_id, `status`, last_updated_at, synced_at) VALUES (?, ?, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE `status` = VALUES(`status`)', [$lead, $status]);
}

/** Lead-id'erne på kortene, i rækkefølge. @return list<int> */
function f008_leads(Response $r): array
{
    preg_match_all('#<a class="card-link" href="/lead/([0-9]+)#', $r->body, $m);
    return array_map('intval', $m[1]);
}

/** Et færdigt transcript med lyd (hentet og på disk). */
function f008_done(int $id, string $text, array $segments = []): void
{
    f002_audio($id);
    f002_transcript($id, $text, $segments);
}
