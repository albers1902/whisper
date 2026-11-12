<?php
declare(strict_types=1);

namespace Whisper;

use Throwable;
use Whisper\Adversus\AdversusConfig;
use Whisper\Adversus\AdversusError;
use Whisper\Adversus\Feature;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
use Whisper\Transcription\QueueRules;
use Whisper\Zendesk\ZendeskConfig;
use Whisper\Zendesk\ZendeskError;

/**
 * Controllere for F002 bag flaget F002_zendesk. App har allerede tjekket login (alle roller), så her er kun det,
 * der er særligt for opkald: filtre, lyd med Range, transcript som .txt og zip af alle i filtret.
 * Lyd ligger aldrig i webroden; den streames herfra med Cache-Control: private, no-store.
 */
final class CallPages
{
    public const FLAG = 'F002_zendesk';
    public const CFG_MISSING = 'Zendesk er ikke sat op.';
    public const CFG_INVALID = 'Zendesk-opsætningen i config.ini er ugyldig.';
    private const ZIP_TMP_MAX_AGE = 3600;
    /** Højst så mange manuelt valgte Adversus-opkald (ikke succes) i kø eller under transcription ad gangen (security S1) */
    public const MANUAL_MAX = 50;
    /** Et opkalds fejlede lyd kan hentes igen med knappen højst så ofte (security N2) */
    public const RESET_COOLDOWN = 3600;

    /** @param array<string, mixed> $user */
    public static function list(Request $req, array $user, string $csrf, ?string $flash): Response
    {
        $f = Calls::filter($req->query);
        $res = Calls::search($f);
        $f['side'] = $res['side'];   // klemt til sidste side (QA F-9)
        [$cfg, $cfgError] = self::zendesk();
        if (Ui::modern()) {
            // F005: beskederne vælges efter rolle i UiCalls; uddrag kun for den viste side (højst PAGE_SIZE)
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $res['rows']);
            $snippets = $f['q'] !== '' && $ids !== [] ? Calls::snippets($ids, $f['q']) : [];
            $config = $cfgError === null ? 'ok' : ($cfgError === self::CFG_INVALID ? 'invalid' : 'missing');
            return Response::html(UiCalls::list($user, $csrf, $flash, $f, $res['rows'], $res['total'], Calls::agents(), Calls::status(),
                $config, self::engineReady(), $cfg?->subdomain, $snippets, time(),
                Feature::on() ? ['config' => self::adversusConfig(), 'status' => Calls::adversusStatus(), 'warning' => Calls::adversusWarning(time())] : null));
        }
        $notices = [];
        if ($cfgError !== null) {
            $notices[] = $cfgError;
        }
        if (!self::engineReady()) {
            $notices[] = 'Transcription er ikke sat op endnu (cron/whisper-setup.sh).';
        }
        return Response::html(CallView::list($user, $csrf, $flash, $f, $res['rows'], $res['total'], Calls::agents(), Calls::status(),
            $notices, $cfg?->subdomain));
    }

    /**
     * Listens filtre og side kommer med i query-strengen (F005), så detaljen kan linke tilbage til samme liste.
     *
     * @param array<string, mixed> $user
     * @param array{0: ?string, 1: ?string} $flash  ok- og info-besked fra en handling (F004)
     */
    public static function show(int $id, Request $req, array $user, string $csrf, array $flash = [null, null]): Response
    {
        $call = Calls::find($id);
        $modern = Ui::modern();
        $f = Calls::filter($req->query);
        if ($call === null) {
            $ctx = CallView::query($f, $f['side']);
            return Response::html($modern ? UiPages::error(404, 'Opkaldet findes ikke.', $csrf, $user, $ctx !== '' ? '/?' . $ctx : '/')
                : View::error(404, 'Opkaldet findes ikke.', $csrf, $user), 404);
        }
        [$cfg] = self::zendesk();
        $onDisk = Calls::audioFile(is_string($call['audio_path'] ?? null) ? $call['audio_path'] : null) !== null;
        if ($modern) {
            return Response::html(UiCalls::show($user, $csrf, $call, Calls::transcript($id), $onDisk, $cfg?->subdomain, $f, $flash[0], $flash[1]));
        }
        return Response::html(CallView::show($user, $csrf, $call, Calls::transcript($id), $onDisk, $cfg?->subdomain));
    }

    /** GET /opkald/{id}/lyd med Range (RFC 9110 §14): ét interval, ellers hele filen; 416 ved et umuligt interval. */
    /** @param array<string, mixed> $user */
    public static function audio(int $id, Request $req, array $user): Response
    {
        $call = Calls::find($id);
        $file = $call !== null && $call['audio_status'] === 'hentet'
            ? Calls::audioFile(is_string($call['audio_path'] ?? null) ? $call['audio_path'] : null) : null;
        if ($file === null) {
            return new Response(404, "Lydfilen findes ikke\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $size = (int) filesize($file);
        $type = str_ends_with($file, '.wav') ? 'audio/wav' : 'audio/mpeg';
        $headers = ['Content-Type' => $type, 'Accept-Ranges' => 'bytes', 'Cache-Control' => 'private, no-store'];
        $range = self::range($req->header('range'), $size);
        if ($range === false) {
            return new Response(416, '', $headers + ['Content-Range' => 'bytes */' . $size]);
        }
        // Én linje pr. afspilning/hentning: hele filen eller det første interval (afspilleren spørger mange gange)
        if ($range === null || $range[0] === 0) {
            AccessLog::write((int) $user['id'], 'lyd', ['opkald' => $id]);
        }
        if ($range === null) {
            return Response::file(200, $file, 0, $size, $headers);
        }
        [$start, $end] = $range;
        return Response::file(206, $file, $start, $end - $start + 1, $headers + ['Content-Range' => "bytes $start-$end/$size"]);
    }

    /**
     * @return array{0: int, 1: int}|null|false  [start, slut] inklusive; null = hele filen; false = 416
     */
    public static function range(string $header, int $size): array|null|false
    {
        $h = trim($header);
        if ($h === '' || preg_match('/^bytes=([0-9]{0,18})-([0-9]{0,18})$/', $h, $m) !== 1) {
            return null;   // ingen, flere intervaller eller ukendt enhed: hele filen (tilladt efter RFC'en)
        }
        [, $a, $b] = $m;
        if ($a === '' && $b === '') {
            return null;
        }
        if ($size === 0) {
            return false;
        }
        if ($a === '') {
            $n = (int) $b;
            return $n === 0 ? false : [max(0, $size - $n), $size - 1];
        }
        $start = (int) $a;
        $end = $b === '' ? $size - 1 : min((int) $b, $size - 1);
        if ($start >= $size || $start > $end) {
            return false;
        }
        return [$start, $end];
    }

    /**
     * GET /opkald/{id}/transcript: UTF-8-tekst med hoved-blok, som fil.
     *
     * @param array<string, mixed> $user
     */
    public static function transcript(int $id, array $user): Response
    {
        $call = Calls::find($id);
        $t = $call !== null && $call['transcript_status'] === 'faerdig' ? Calls::transcript($id) : null;
        if ($call === null || $t === null) {
            return new Response(404, "Transcriptet findes ikke\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        [$cfg] = self::zendesk();
        AccessLog::write((int) $user['id'], 'transcript', ['opkald' => $id]);
        $ticket = (int) ($call['ticket_id'] ?? 0);
        $text = Calls::transcriptText($call, $t['segments'], $t['text'], $cfg !== null && $ticket > 0 ? $cfg->ticketUrl($ticket) : null);
        return new Response(200, $text, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . Calls::fileName($call) . '"',
        ]);
    }

    /**
     * GET /opkald/download?<filtre>: de færdige transcripts i filtret som .txt i én zip (højst Calls::ZIP_MAX).
     *
     * @param array<string, mixed> $user
     */
    public static function zip(Request $req, array $user, string $csrf): Response
    {
        $f = Calls::filter($req->query);
        $ids = Calls::zipIds($f);
        if ($ids === []) {
            return Response::html(View::layout('Download', '<section class="card narrow"><h1>Download</h1>'
                . '<p>Der er ingen færdige transcripts i filtret.</p><p><a href="/?' . View::e(CallView::query($f)) . '">Tilbage</a></p></section>',
                $user, $csrf, 'opkald'));
        }
        // Højst én zip ad gangen på hele sitet (security S3): den næste får 429 og en dansk besked
        $lock = Lock::tryAcquire('zip');
        if ($lock === null) {
            $r = Response::html(View::layout('Download', '<section class="card narrow"><h1>Download</h1>'
                . '<p class="msg info">Der laves allerede en zip-fil. Prøv igen om et øjeblik.</p>'
                . '<p><a href="/?' . View::e(CallView::query($f)) . '">Tilbage</a></p></section>', $user, $csrf, 'opkald'), 429);
            $r->headers['Retry-After'] = '30';
            return $r;
        }
        [$cfg] = self::zendesk();
        $dir = Paths::dir('tmp');
        self::cleanTmp($dir);
        $file = $dir . '/transcripts-' . bin2hex(random_bytes(8)) . '.zip';
        $old = umask(077);
        try {
            $zip = new ZipWriter($file);
            $used = [];
            // Transcripts læses ZIP_BATCH ad gangen, så hukommelsen er flad uanset antallet (QA F-4)
            foreach (array_chunk($ids, Calls::ZIP_BATCH) as $chunk) {
                foreach (Calls::zipRows($chunk) as $r) {
                    $name = Calls::fileName($r);
                    if (isset($used[$name])) {
                        $name = substr($name, 0, -4) . '_' . (int) $r['id'] . '.txt';
                    }
                    $used[$name] = true;
                    $ticket = (int) ($r['ticket_id'] ?? 0);
                    $zip->add($name, Calls::transcriptText($r, Calls::segments((string) $r['t_segments']), (string) $r['t_text'],
                        $cfg !== null && $ticket > 0 ? $cfg->ticketUrl($ticket) : null), (int) strtotime((string) $r['started_at'] . ' UTC'));
                }
            }
            $zip->close();
        } catch (Throwable $e) {
            @unlink($file);
            throw $e;
        } finally {
            umask($old);
            $lock->release();
        }
        AccessLog::write((int) $user['id'], 'zip', ['antal' => count($ids), 'filter' => substr(hash('sha256', CallView::query($f)), 0, 16)]);
        return Response::file(200, $file, 0, (int) filesize($file), [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="transcripts_' . gmdate('Y-m-d') . '.zip"',
        ], true);
    }

    /** Zip-filer, som en afbrudt request efterlod. */
    private static function cleanTmp(string $dir): void
    {
        foreach (glob($dir . '/transcripts-*.zip') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - self::ZIP_TMP_MAX_AGE) {
                @unlink($old);
            }
        }
    }

    /**
     * POST /opkald/{id}/transcriber (F004, AK7): sætter et Adversus-opkald, der er "ikke valgt", i kø, eller (H012) et
     * opkald fra begge kilder, som køens regler springer over (for kort, for gammelt). App har tjekket
     * login og CSRF. Én atomisk UPDATE, så knappen er idempotent: et opkald i kø, under arbejde eller færdigt ændres ikke.
     * Svaret er altid en redirect (PRG) til detaljen med listens kontekst.
     *
     * @param array<string, mixed> $user
     * @return array{0: int, 1: string, 2: string} [HTTP-status for fejl (0 = redirect), redirect-sti, ok|koe|faerdig]
     */
    public static function select(int $id, Request $req, array $user): array
    {
        $call = Calls::find($id);
        if ($call === null) {
            return [404, '', ''];
        }
        $adv = Calls::isAdversus($call);
        $now = time();
        $skip = QueueRules::fromConfig()->skipReason($call, $now);
        // H012: Zendesk-opkald har kun knappen, når køens regler springer dem over (eller de allerede er valgt);
        // Adversus-opkald kun med F004 tændt, som før ruten blev åbnet for Zendesk
        if ($adv ? !Feature::on() : ($skip === null && ($call['manual_at'] ?? null) === null)) {
            return [404, '', ''];
        }
        $ctx = $req->str('ctx');
        // Konteksten er listens egen query; den renses gennem filter() igen, så intet andet kan smugles ind i redirecten
        parse_str($ctx, $q);
        $f = Calls::filter($q);
        $back = CallView::query($f, $f['side']);
        $url = '/opkald/' . $id . ($back !== '' ? '?' . $back : '');
        $retry = $adv && $call['audio_status'] === 'fejlet' && $call['transcript_status'] === 'ventende';
        if (!self::selectable($call, $skip)) {
            return [0, $url, $call['transcript_status'] === 'faerdig' ? 'faerdig' : 'koe'];
        }
        if ($retry) {
            // Genforsøg på fejlet lyd: ikke bag loftet (QA N-1), men højst én gang i timen pr. opkald (security N2).
            // Én atomisk UPDATE; lyd-felterne sættes før audio_status, fordi MariaDB evaluerer SET fra venstre.
            $n = DB::exec("UPDATE calls SET audio_attempts = 0, audio_error = NULL, audio_reset_at = ?, audio_status = 'venter', updated_at = ? "
                . "WHERE id = ? AND `source` = 'adversus' AND audio_status = 'fejlet' AND transcript_status = 'ventende' "
                . 'AND (audio_reset_at IS NULL OR audio_reset_at <= ?)',
                [gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now), $id, gmdate('Y-m-d H:i:s', $now - self::RESET_COOLDOWN)]);
            if ($n === 1) {
                AccessLog::write((int) $user['id'], 'transcriber', ['opkald' => $id]);
                return [0, $url, 'igen'];
            }
            $again = Calls::find($id);
            return [0, $url, $again !== null && $again['audio_status'] === 'fejlet' ? 'nylig' : 'koe'];
        }
        // Loft over manuelt valgte opkald i gang (security S1): kun ikke-succes, og døde valg (lyd fejlet efter alle
        // forsøg) tæller ikke (QA N-1). Intet ændres ved loftet. To samtidige klik kan gå én over loftet; det er
        // accepteret (loftet er værn mod masse-valg, ikke et kvotesystem).
        if (($skip !== null || (int) $call['is_success'] === 0) && self::manualInProgress() >= self::MANUAL_MAX) {
            return [0, $url, 'fuld'];
        }
        $at = gmdate('Y-m-d H:i:s', $now);
        if ($skip !== null) {
            // H012: sprunget over af køens regler. Status røres ikke; manual_at gør opkaldet claimbart (QueueRules)
            $n = DB::exec("UPDATE calls SET manual_at = ?, updated_at = ? WHERE id = ? AND manual_at IS NULL AND audio_status = 'hentet' "
                . "AND transcript_status IN ('ventende', 'fejlet')", [$at, $at, $id]);
            $kind = 'valgt';
        } else {
            $n = DB::exec("UPDATE calls SET transcript_status = 'ventende', manual_at = ?, updated_at = ? WHERE id = ? AND `source` = 'adversus' "
                . "AND transcript_status = 'ikke_valgt'", [$at, $at, $id]);
            $kind = 'ok';
        }
        if ($n === 1) {
            AccessLog::write((int) $user['id'], 'transcriber', ['opkald' => $id]);
            return [0, $url, $kind];
        }
        return [0, $url, 'koe'];
    }

    /**
     * Manuelt valgte opkald, der reelt er i gang: valgt med knappen (manual_at) eller Adversus uden succes, og ikke døde
     * efter alle lydforsøg.
     */
    public static function manualInProgress(): int
    {
        return (int) DB::scalar('SELECT COUNT(*) FROM calls WHERE ' . QueueRules::manualSql() . ' '
            . "AND transcript_status IN ('ventende', 'transcriberer') AND NOT (audio_status = 'fejlet' AND audio_attempts >= ?)",
            [Adversus\Sync::MAX_AUDIO_ATTEMPTS]);
    }

    /**
     * Kan knappen bruges på opkaldet? Adversus: "ikke valgt", eller lyden er fejlet, før der blev lavet et transcript
     * (QA F-2). Begge kilder (H012): køens regler springer det over, og lyden er hentet, så det kan transcriberes nu.
     *
     * @param array<string, mixed> $call
     * @param string|false|null $skip QueueRules::skipReason, hvis kalderen allerede har den; false = regn den ud her
     */
    public static function selectable(array $call, string|false|null $skip = false): bool
    {
        if ($skip === false) {
            $skip = QueueRules::fromConfig()->skipReason($call, time());
        }
        if ($skip !== null && $call['audio_status'] === 'hentet') {
            return true;
        }
        return Calls::isAdversus($call) && ($call['transcript_status'] === 'ikke_valgt'
            || ($call['audio_status'] === 'fejlet' && $call['transcript_status'] === 'ventende'));
    }

    /** F004: 'ok' | 'missing' | 'invalid' for [adversus] i config.ini. */
    public static function adversusConfig(): string
    {
        try {
            return AdversusConfig::fromSection(Config::section('adversus')) === null ? 'missing' : 'ok';
        } catch (AdversusError) {
            return 'invalid';
        }
    }

    /** @return array{0: ?ZendeskConfig, 1: ?string} konfigurationen og en besked til siden, hvis den mangler eller er ugyldig */
    public static function zendesk(): array
    {
        try {
            $cfg = ZendeskConfig::fromSection(Config::section('zendesk'));
            return $cfg === null ? [null, self::CFG_MISSING] : [$cfg, null];
        } catch (ZendeskError) {
            return [null, self::CFG_INVALID];
        }
    }

    private static function engineReady(): bool
    {
        try {
            return ProcessEngine::fromConfig()->available();
        } catch (EngineError) {
            return false;
        }
    }
}
