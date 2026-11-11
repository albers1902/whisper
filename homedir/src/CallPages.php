<?php
declare(strict_types=1);

namespace Whisper;

use Throwable;
use Whisper\Transcription\EngineError;
use Whisper\Transcription\ProcessEngine;
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
    private const ZIP_TMP_MAX_AGE = 3600;

    /** @param array<string, mixed> $user */
    public static function list(Request $req, array $user, string $csrf, ?string $flash): Response
    {
        $f = Calls::filter($req->query);
        $res = Calls::search($f);
        [$cfg, $cfgError] = self::zendesk();
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

    /** @param array<string, mixed> $user */
    public static function show(int $id, array $user, string $csrf): Response
    {
        $call = Calls::find($id);
        if ($call === null) {
            return Response::html(View::error(404, 'Opkaldet findes ikke.', $csrf, $user), 404);
        }
        [$cfg] = self::zendesk();
        $onDisk = Calls::audioFile(is_string($call['audio_path'] ?? null) ? $call['audio_path'] : null) !== null;
        return Response::html(CallView::show($user, $csrf, $call, Calls::transcript($id), $onDisk, $cfg?->subdomain));
    }

    /** GET /opkald/{id}/lyd med Range (RFC 9110 §14): ét interval, ellers hele filen; 416 ved et umuligt interval. */
    public static function audio(int $id, Request $req): Response
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

    /** GET /opkald/{id}/transcript: UTF-8-tekst med hoved-blok, som fil. */
    public static function transcript(int $id): Response
    {
        $call = Calls::find($id);
        $t = $call !== null && $call['transcript_status'] === 'faerdig' ? Calls::transcript($id) : null;
        if ($call === null || $t === null) {
            return new Response(404, "Transcriptet findes ikke\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        [$cfg] = self::zendesk();
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
        $res = Calls::forZip($f);
        if ($res['rows'] === []) {
            return Response::html(View::layout('Download', '<section class="card narrow"><h1>Download</h1>'
                . '<p>Der er ingen færdige transcripts i filtret.</p><p><a href="/?' . View::e(CallView::query($f)) . '">Tilbage</a></p></section>',
                $user, $csrf));
        }
        [$cfg] = self::zendesk();
        $dir = Paths::dir('tmp');
        self::cleanTmp($dir);
        $file = $dir . '/transcripts-' . bin2hex(random_bytes(8)) . '.zip';
        $old = umask(077);
        try {
            $zip = new ZipWriter($file);
            $used = [];
            foreach ($res['rows'] as $r) {
                $name = Calls::fileName($r);
                if (isset($used[$name])) {
                    $name = substr($name, 0, -4) . '_' . (int) $r['id'] . '.txt';
                }
                $used[$name] = true;
                $ticket = (int) ($r['ticket_id'] ?? 0);
                $zip->add($name, Calls::transcriptText($r, Calls::segments((string) $r['t_segments']), (string) $r['t_text'],
                    $cfg !== null && $ticket > 0 ? $cfg->ticketUrl($ticket) : null), (int) strtotime((string) $r['started_at'] . ' UTC'));
            }
            $zip->close();
        } catch (Throwable $e) {
            @unlink($file);
            throw $e;
        } finally {
            umask($old);
        }
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

    /** @return array{0: ?ZendeskConfig, 1: ?string} konfigurationen og en besked til siden, hvis den mangler eller er ugyldig */
    public static function zendesk(): array
    {
        try {
            $cfg = ZendeskConfig::fromSection(Config::section('zendesk'));
            return $cfg === null ? [null, 'Zendesk er ikke sat op.'] : [$cfg, null];
        } catch (ZendeskError) {
            return [null, 'Zendesk-opsætningen i config.ini er ugyldig.'];
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
