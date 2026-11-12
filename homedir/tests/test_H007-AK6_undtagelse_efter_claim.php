<?php
declare(strict_types=1);

// H007-AK6 (DECISIONS #34, H006-LOW-4): en tvungen undtagelse efter claim og uden for motoren giver en detalje (klasse
// og linje) i slottets sidste-fejl-fil (0600), runBatch returnerer 0, så kørslen stopper til næste cron-minut, og
// opkaldene er ventende med uændrede forsøg. Før rettelsen returnerede runBatch antallet, og løkken prøvede straks igen.

require_once __DIR__ . '/h007_lib.php';

use Whisper\DB;
use Whisper\Transcription\BatchEngine;
use Whisper\Transcription\Slots;
use Whisper\Transcription\Transcriber;

/** Kaster ved andet kald af batchSize(): runBatch har da claimet det første opkald, og motoren er ikke startet */
final class H007ThrowingEngine implements BatchEngine
{
    public int $sizeCalls = 0;
    public int $engineCalls = 0;

    public function available(): bool
    {
        return true;
    }

    public function label(): string
    {
        return '';
    }

    public function batchSize(): int
    {
        if (++$this->sizeCalls === 2) {
            throw new RuntimeException('hemmelig-sti /home/x/var/recordings');
        }
        return 10;
    }

    public function transcribe(string $audioFile, int $durationSec): array
    {
        $this->engineCalls++;
        return [];
    }

    public function transcribeBatch(array $files, int $limitSec, Closure $onResult): ?float
    {
        $this->engineCalls++;
        return null;
    }
}

$var = f002_setup();
$now = F002_NOW;
$calls = h006_three_calls();
$errFile = Slots::errorFile(2);
$held = [];
$log = [];
$engine = new H007ThrowingEngine();
$t = new Transcriber($engine, static fn(): int => $now, static function (string $l) use (&$log): void {
    $log[] = $l;
}, $errFile, static function (array $ids) use (&$held): void {
    $held[] = $ids;
});

$thrown = false;
$n = -1;
try {
    $n = $t->runBatch();
} catch (Throwable) {
    $thrown = true;
}
H007Check::that('AK6', !$thrown && $n === 0, 'runBatch kaster ikke videre og returnerer 0');
H007Check::that('AK6', $engine->engineCalls === 0 && $held !== [] && ($held[0] ?? []) === [$calls[0]['id']],
    'undtagelsen kom efter claim af det første opkald og før motoren');
foreach ($calls as $c) {
    $s = h006_state($c['id']);
    H007Check::that('AK6', $s['status'] === 'ventende' && $s['attempts'] === 0, 'opkald ' . $c['id'] . ' er ventende med 0 forsøg');
}
H007Check::that('AK6', end($held) === [], 'de claimede id\'er er meldt tomme bagefter (låsefilen tømmes)');

H007Check::that('AK6', is_file($errFile) && $errFile === $var . '/whisper/sidste-fejl-2.log', 'detaljen står i slottets fil');
$detail = is_file($errFile) ? (string) file_get_contents($errFile) : '';
H007Check::that('AK6', preg_match('/batch afbrudt: RuntimeException i test_H007-AK6_undtagelse_efter_claim\.php:[0-9]+$/', trim($detail)) === 1,
    'klasse, fil og linje: ' . trim($detail));
H007Check::that('AK6', !str_contains($detail, 'hemmelig'), 'undtagelsens besked (kan indeholde data) skrives ikke');
H007Check::that('AK6', DIRECTORY_SEPARATOR !== '/' || (fileperms($errFile) & 0777) === 0600, 'filen har 0600');
H007Check::that('AK6', $log === ['transcribe: batch afbrudt: Intern fejl ved transcription'], 'loggen har kun den faste linje');

// Kørslens løkke (som cron/transcribe.php): stopper efter afbrydelsen i stedet for at prøve straks igen
$engine = new H007ThrowingEngine();
$t = new Transcriber($engine, static fn(): int => $now, static fn(string $l) => null, $errFile);
$rounds = 0;
while ($rounds < 5) {
    $rounds++;
    $engine->sizeCalls = 0;
    if ($t->runBatch() === 0) {
        break;
    }
}
H007Check::that('AK6', $rounds === 1, 'løkken stopper efter første afbrudte batch');
H007Check::that('AK6', (int) DB::scalar("SELECT SUM(transcript_attempts) FROM calls") === 0, 'ingen forsøg brugt');

H007Check::done();
