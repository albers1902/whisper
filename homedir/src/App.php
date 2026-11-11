<?php
declare(strict_types=1);

namespace Whisper;

use Closure;

/**
 * Router og controllere for F001 (login, konto, brugeradministration). handle() tager en Request og en Session og
 * returnerer en Response, så alt kan testes uden webserver; public_html/index.php er den tynde web-indgang.
 *
 * Rækkefølgen for hver request: flag → rute → inaktivitets-timeout → bruger fra databasen (rolle og aktiv-status
 * ved HVER request, så en deaktivering eller nedgradering virker med det samme) → login krævet → tvunget skift af
 * adgangskode → admin-rolle → CSRF ved POST → controller.
 */
final class App
{
    public const FLAG = 'F001_brugere';
    public const IDLE_SECONDS = 8 * 3600;

    private const BAD_LOGIN = 'Forkert e-mail eller adgangskode.';
    private const LOCKED = 'For mange forsøg — prøv igen om lidt.';

    /** @var Closure(): int */
    private Closure $clock;

    /** @param (Closure(): int)|null $clock tests styrer tiden (throttling, timeout) */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function handle(Request $req, Session $s): Response
    {
        if (!Flags::enabled(self::FLAG)) {
            return self::notFound();
        }
        return self::secure($this->dispatch($req, $s));
    }

    /** Flaget slukket: som om ruterne ikke fandtes (AK11). Ingen session startes. */
    public static function notFound(): Response
    {
        return self::secure(new Response(404, "Ikke fundet\n", ['Content-Type' => 'text/plain; charset=utf-8']));
    }

    private function dispatch(Request $req, Session $s): Response
    {
        $path = $req->path === '/' ? '/' : rtrim($req->path, '/');
        $method = $req->method;
        $now = ($this->clock)();

        [$route, $id] = self::route($path);
        if ($route === null) {
            return Response::html(View::error(404, 'Siden findes ikke.'), 404);
        }
        $allowed = self::ROUTES[$route];
        if (!in_array($method, $allowed, true)) {
            return new Response(405, View::error(405, 'Metoden er ikke tilladt.'),
                ['Content-Type' => 'text/html; charset=utf-8', 'Allow' => implode(', ', $allowed)]);
        }

        $last = $s->get('last_seen');
        if (is_int($last) && $now - $last > self::IDLE_SECONDS) {
            $s->clear();
            $s->regenerate();
        }
        $s->set('last_seen', $now);

        $user = $this->currentUser($s);
        $csrfOk = $method !== 'POST' || Csrf::valid($s, $req->str(Csrf::FIELD));

        if ($route === 'login') {
            if ($user !== null) {
                return Response::redirect('/');
            }
            if (!$csrfOk) {
                return $this->forbidden($s, null, 'Formularen er udløbet. Gå tilbage, genindlæs siden og prøv igen.');
            }
            return $method === 'POST' ? $this->loginPost($req, $s, $now) : Response::html(View::login(Csrf::token($s), null, ''));
        }

        if ($user === null) {
            // Også en POST med udløbet session: intet ændres, brugeren logger ind igen
            return Response::redirect('/login');
        }
        if ($route === 'logout') {
            if (!$csrfOk) {
                return $this->forbidden($s, $user, 'Formularen er udløbet. Gå tilbage, genindlæs siden og prøv igen.');
            }
            $s->destroy();
            return Response::redirect('/login');
        }
        if ((int) $user['must_change_password'] === 1 && $route !== 'konto') {
            return Response::redirect('/konto');
        }
        if (in_array($route, ['users', 'user_new', 'user_edit'], true) && $user['role'] !== 'admin') {
            return $this->forbidden($s, $user, 'Kun administratorer har adgang til brugeradministration.');
        }
        if (!$csrfOk) {
            return $this->forbidden($s, $user, 'Formularen er udløbet. Gå tilbage, genindlæs siden og prøv igen.');
        }

        $csrf = Csrf::token($s);
        return match ($route) {
            'home'      => Response::html(View::home($user, $csrf, $this->flash($s))),
            'konto'     => $method === 'POST' ? $this->accountPost($req, $s, $user, $now)
                : Response::html(View::account($user, $csrf, null, $this->flash($s))),
            'users'     => Response::html(View::users($user, $csrf, Users::all(), $this->flash($s))),
            'user_new'  => $method === 'POST' ? $this->userNewPost($req, $s, $user, $now)
                : Response::html(View::userNew($user, $csrf, null, ['email' => '', 'name' => '', 'role' => 'user'])),
            default     => $this->userEdit($req, $s, $user, $id, $now),
        };
    }

    /** @var array<string, list<string>> */
    private const ROUTES = [
        'home'      => ['GET'],
        'login'     => ['GET', 'POST'],
        'logout'    => ['POST'],
        'konto'     => ['GET', 'POST'],
        'users'     => ['GET'],
        'user_new'  => ['GET', 'POST'],
        'user_edit' => ['GET', 'POST'],
    ];

    /** @return array{0: ?string, 1: int} */
    private static function route(string $path): array
    {
        $fixed = ['/' => 'home', '/login' => 'login', '/logout' => 'logout', '/konto' => 'konto',
            '/brugere' => 'users', '/brugere/ny' => 'user_new'];
        if (isset($fixed[$path])) {
            return [$fixed[$path], 0];
        }
        if (preg_match('#^/brugere/([1-9][0-9]{0,9})$#', $path, $m) === 1) {
            return ['user_edit', (int) $m[1]];
        }
        return [null, 0];
    }

    /**
     * Brugeren fra databasen ved hver request. En deaktiveret eller slettet bruger, eller en ændret adgangskode
     * (fingeraftryk), tømmer sessionen.
     *
     * @return array<string, mixed>|null
     */
    private function currentUser(Session $s): ?array
    {
        $uid = $s->get('user_id');
        if (!is_int($uid)) {
            return null;
        }
        $user = Users::find($uid);
        $fp = $s->get('pwfp');
        if ($user === null || (int) $user['active'] !== 1 || !is_string($fp) || !hash_equals(Users::fingerprint($user), $fp)) {
            $s->clear();
            $s->regenerate();
            return null;
        }
        return $user;
    }

    private function loginPost(Request $req, Session $s, int $now): Response
    {
        $email = Users::normalizeEmail($req->str('email'));
        $password = $req->str('password');
        LoginThrottle::purge($now);

        if (LoginThrottle::isLocked($email, $req->ip, $now)) {
            return Response::html(View::login(Csrf::token($s), self::LOCKED, $email), 429);
        }

        $user = $email !== '' ? Users::findByEmail($email) : null;
        if ($user === null) {
            // Ukendt e-mail: én bcrypt-beregning med standard-cost som for en rigtig bruger, så svartiden ikke
            // afslører, om e-mailen findes (en hash koster det samme som en verify)
            Password::hash(str_replace("\0", '', $password));   // bcrypt afviser NUL-tegn

            LoginThrottle::record($email, $req->ip, false, $now);
            return Response::html(View::login(Csrf::token($s), self::BAD_LOGIN, $email));
        }
        $hash = (string) $user['password_hash'];
        $ok = password_verify($password, $hash) && (int) $user['active'] === 1;
        LoginThrottle::record($email, $req->ip, $ok, $now);
        if (!$ok) {
            return Response::html(View::login(Csrf::token($s), self::BAD_LOGIN, $email));
        }

        $id = (int) $user['id'];
        Users::touchLogin($id, $password, $hash, $now);
        $fresh = Users::find($id) ?? $user;
        $s->clear();          // intet fra den anonyme session (heller ikke CSRF-tokenet) følger med
        $s->regenerate();     // nyt session-id ved login (session fixation)
        $s->set('user_id', $id);
        $s->set('pwfp', Users::fingerprint($fresh));
        $s->set('last_seen', $now);
        return Response::redirect((int) $fresh['must_change_password'] === 1 ? '/konto' : '/');
    }

    /** @param array<string, mixed> $user */
    private function accountPost(Request $req, Session $s, array $user, int $now): Response
    {
        $current = $req->str('current');
        $new = $req->str('new');
        $error = null;
        if (!password_verify($current, (string) $user['password_hash'])) {
            $error = 'Den nuværende adgangskode er forkert.';
        } elseif (($problem = Password::problem($new)) !== null) {
            $error = $problem;
        } elseif (!hash_equals($new, $req->str('confirm'))) {
            $error = 'De to nye adgangskoder er ikke ens.';
        } elseif (hash_equals($current, $new)) {
            $error = 'Den nye adgangskode skal være forskellig fra den nuværende.';
        }
        if ($error !== null) {
            return Response::html(View::account($user, Csrf::token($s), $error, null), 422);
        }

        $wasForced = (int) $user['must_change_password'] === 1;
        Users::setPassword((int) $user['id'], $new, false, $now);
        $fresh = Users::find((int) $user['id']) ?? $user;
        // Nyt fingeraftryk: denne session fortsætter, alle andre for brugeren afvises ved næste request
        $s->regenerate();
        $s->set('pwfp', Users::fingerprint($fresh));
        $s->set('flash', 'Adgangskoden er skiftet.');
        return Response::redirect($wasForced ? '/' : '/konto');
    }

    /** @param array<string, mixed> $admin */
    private function userNewPost(Request $req, Session $s, array $admin, int $now): Response
    {
        $values = ['email' => $req->str('email'), 'name' => $req->str('name'), 'role' => $req->str('role')];
        $password = Password::generate();
        try {
            $id = Users::create($values['email'], $values['name'], $values['role'], $password, true, $now);
        } catch (UserError $e) {
            if (!in_array($values['role'], Users::ROLES, true)) {
                $values['role'] = 'user';
            }
            return Response::html(View::userNew($admin, Csrf::token($s), $e->getMessage(), $values), 422);
        }
        $target = Users::find($id) ?? ['id' => $id, 'email' => $values['email']];
        return Response::html(View::oneTimePassword($admin, Csrf::token($s), $target, $password, 'Bruger oprettet'));
    }

    /** @param array<string, mixed> $admin */
    private function userEdit(Request $req, Session $s, array $admin, int $id, int $now): Response
    {
        $target = Users::find($id);
        if ($target === null) {
            return Response::html(View::error(404, 'Brugeren findes ikke.', Csrf::token($s), $admin), 404);
        }
        if ($req->method !== 'POST') {
            return Response::html(View::userEdit($admin, Csrf::token($s), $target, null, $this->flash($s)));
        }
        $action = $req->str('action');
        try {
            switch ($action) {
                case 'save':
                    Users::update($id, $req->str('name'), $req->str('role'), $now);
                    $msg = 'Ændringerne er gemt.';
                    break;
                case 'reset':
                    $password = Users::resetPassword($id, $now);
                    return Response::html(View::oneTimePassword($admin, Csrf::token($s), $target, $password, 'Adgangskode nulstillet'));
                case 'deactivate':
                    Users::setActive($id, false, (int) $admin['id'], $now);
                    $msg = 'Brugeren er deaktiveret.';
                    break;
                case 'activate':
                    Users::setActive($id, true, (int) $admin['id'], $now);
                    $msg = 'Brugeren er genaktiveret.';
                    break;
                default:
                    return Response::html(View::error(400, 'Ukendt handling.', Csrf::token($s), $admin), 400);
            }
        } catch (UserError $e) {
            $fresh = Users::find($id) ?? $target;
            return Response::html(View::userEdit($admin, Csrf::token($s), $fresh, $e->getMessage(), null), 422);
        }
        $s->set('flash', $msg);
        return Response::redirect('/brugere/' . $id);
    }

    /** @param array<string, mixed>|null $user */
    private function forbidden(Session $s, ?array $user, string $text): Response
    {
        return Response::html(View::error(403, $text, Csrf::token($s), $user), 403);
    }

    private function flash(Session $s): ?string
    {
        $f = $s->get('flash');
        $s->remove('flash');
        return is_string($f) ? $f : null;
    }

    /** Sikkerheds-headere på alle svar fra appen. no-store: siderne kan vise engangskoder og persondata. */
    private static function secure(Response $r): Response
    {
        $r->headers += [
            'Content-Security-Policy' => "default-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; "
                . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Cache-Control'           => 'no-store',
        ];
        return $r;
    }
}
