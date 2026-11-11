<?php
declare(strict_types=1);

/**
 * F002: sætter opkald, hvis transcription er "fejlet", tilbage til "ventende" med nulstillede forsøg (QA F-3), fx efter
 * at motoren er sat op igen. Kun CLI, køres af ejeren som sitets bruger:
 *
 *   php ~/cron/whisper-requeue.php              alle fejlede
 *   php ~/cron/whisper-requeue.php --id=<id>    ét opkald (id fra adressen /opkald/<id>)
 *
 * Udskriver antallet. Rører ikke lyd, transcripts eller opkald i andre statusser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
require_once __DIR__ . '/../src/Bootstrap.php';

$id = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--id=([1-9][0-9]{0,9})$/', $a, $m) === 1) {
        $id = (int) $m[1];
    } else {
        fwrite(STDERR, "whisper-requeue.php: ukendt argument (brug --id=<id> eller intet)\n");
        exit(2);
    }
}
$sql = "UPDATE calls SET transcript_status = 'ventende', transcript_attempts = 0, transcript_error = NULL, "
    . "transcript_retry_at = NULL, claimed_at = NULL WHERE transcript_status = 'fejlet'";
$n = $id === null ? \Whisper\DB::exec($sql) : \Whisper\DB::exec($sql . ' AND id = ?', [$id]);
echo $n, " opkald sat tilbage til ventende\n";
exit(0);
