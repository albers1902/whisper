<?php
declare(strict_types=1);

/**
 * Front controller for alle sider undtagen /health (F001). .htaccess sender hertil, når filen ikke findes.
 * Logikken ligger i src/App.php; her er kun det, der kræver en webserver: session, headere og output.
 */

require_once __DIR__ . '/../src/Bootstrap.php';

use Whisper\App;
use Whisper\ArraySessionDriver;
use Whisper\Config;
use Whisper\Flags;
use Whisper\NativeSessionDriver;
use Whisper\Request;
use Whisper\Response;
use Whisper\Session;
use Whisper\View;

if (!Flags::enabled(App::FLAG)) {
    App::notFound()->send();   // ingen session, ingen cookie, når flaget er slukket
    exit;
}

try {
    $request = Request::fromGlobals();
    if (NativeSessionDriver::hasCookie() || rtrim($request->path, '/') === '/login') {
        $driver = NativeSessionDriver::start(Config::baseDir());
        $session = new Session($driver, $driver->load());
        $response = (new App())->handle($request, $session);
        $driver->save($session->all());
    } else {
        // Uden sessionscookie kan ingen være logget ind, og kun /login har brug for et CSRF-token. Så oprettes
        // ingen sessionsfil for bots og anonyme besøg (de får redirect til /login, 404 eller 403).
        $response = (new App())->handle($request, new Session(new ArraySessionDriver()));
    }
} catch (Throwable $e) {
    // Fejlen må ikke nå browseren; serverens fejllog får den
    error_log('whisper: ' . get_class($e) . ': ' . $e->getMessage() . ' i ' . $e->getFile() . ':' . $e->getLine());
    $response = Response::html(View::error(500, 'Prøv igen om lidt.'), 500);
    $response->headers['Cache-Control'] = 'no-store';
}
$response->send();
