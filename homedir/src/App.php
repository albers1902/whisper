<?php
declare(strict_types=1);

namespace Whisper;

use Closure;
use Whisper\Adversus\Feature;
use Whisper\Mcp\Server;
use Whisper\Mcp\Tokens;
use Whisper\OAuth\Authorize;
use Whisper\OAuth\Grants;
use Whisper\OAuth\Issuer;
use Whisper\OAuth\OAuth;

/**
 * Router og controllere for F001 (login, konto, brugeradministration) og ruterne for F002 (opkald; controllerne er i
 * CallPages). handle() tager en Request og en Session og
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
    /** Appens version (MCP serverInfo, F003). Følger den seneste migrations version. */
    public const VERSION = '0.6.0';

    private const BAD_LOGIN = 'Forkert e-mail eller adgangskode.';
    private const LOCKED = 'For mange forsøg — prøv igen om lidt.';
    private const SELF_RESET = 'Skift din adgangskode under Konto.';
    public const ALREADY_REVOKED = 'Nøglen er allerede tilbagekaldt.';
    public const KEYS_REVOKED_SELF = 'Dine MCP-nøgler til Claude Desktop er tilbagekaldt. Opret en ny nøgle her, hvis du stadig skal bruge den.';
    public const KEYS_REVOKED_RESET = 'Brugerens MCP-nøgler til Claude Desktop er tilbagekaldt. Brugeren kan oprette nye under Konto.';
    /** F006 tændt: kun forbundne apps (DECISIONS #48, F003-nøgler virker ikke med flaget). */
    public const APPS_REVOKED_SELF = 'Dine forbundne apps er tilbagekaldt. Forbind Claude igen her, hvis du stadig skal bruge det.';
    public const APPS_REVOKED_RESET = 'Brugerens forbundne apps er tilbagekaldt.';
    public const APP_ALREADY_REVOKED = 'Forbindelsen er allerede tilbagekaldt.';
    public const OAUTH_INVALID_TITLE = 'Forbindelsen kan ikke oprettes';
    public const OAUTH_INVALID_TEXT = 'Anmodningen fra appen er ugyldig, så Whisper sender dig ikke videre. Gå tilbage til Claude, fjern '
        . 'Whisper-connectoren, og tilføj den igen.';
    public const OAUTH_EXPIRED_TITLE = 'Forbindelsen er udløbet';
    public const OAUTH_EXPIRED_TEXT = 'Siden har været åben for længe, anmodningen er allerede brugt, eller en nyere forbindelse er '
        . 'startet i et andet vindue. Gå tilbage til Claude, og vælg Forbind igen.';
    public const OAUTH_LIMITED_TEXT = 'For mange forsøg på kort tid. Vent et minut, og vælg Forbind i Claude igen.';

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
        // HEAD er en GET uden body (PHP/Apache dropper body'en), så link-forhåndsvisning og tjek får samme svar
        $method = $req->method === 'HEAD' ? 'GET' : $req->method;
        $now = ($this->clock)();

        [$route, $id, $action] = self::route($path);
        if ($route === null) {
            return Response::html(View::error(404, 'Siden findes ikke.'), 404);
        }
        $allowed = self::ROUTES[$route];
        if (!in_array($method, $allowed, true)) {
            $allow = in_array('GET', $allowed, true) ? array_merge($allowed, ['HEAD']) : $allowed;
            return new Response(405, View::error(405, 'Metoden er ikke tilladt.'),
                ['Content-Type' => 'text/html; charset=utf-8', 'Allow' => implode(', ', $allow)]);
        }

        $last = $s->get('last_seen');
        if (is_int($last) && $now - $last > self::IDLE_SECONDS) {
            $s->clear();
            $s->regenerate();
        }

        $user = $this->currentUser($s);
        if ($user !== null) {
            $s->set('last_seen', $now);   // kun for indloggede: anonyme GET skriver intet i sessionen
        }
        $csrfOk = $method !== 'POST' || Csrf::valid($s, $req->str(Csrf::FIELD));

        if ($route === 'login') {
            if ($user !== null) {
                return Response::redirect('/');
            }
            if (!$csrfOk) {
                return $this->forbidden($s, null, 'Formularen er udløbet. Gå tilbage, genindlæs siden og prøv igen.');
            }
            return $method === 'POST' ? $this->loginPost($req, $s, $now)
                : Response::html(View::login(Csrf::token($s), null, '', Authorize::loginContext($s, $now)));
        }
        if ($route === 'oauth_authorize') {
            // F006: før login-kravet, så en anonym anmodning kan valideres og gemmes, før brugeren sendes til /login
            return $this->oauthAuthorize($req, $method, $s, $user, $csrfOk, $now);
        }

        if ($user === null) {
            // Også en POST med udløbet session: intet ændres, brugeren logger ind igen
            return Response::redirect('/login');
        }
        if ($route === 'logout') {
            if (!$csrfOk) {
                return $this->forbidden($s, $user, 'Formularen er udløbet. Gå tilbage, genindlæs siden og prøv igen.');
            }
            // F006 (QA F4): "Ikke dig? Log ud" på godkendelsessiden beholder den validerede, ventende anmodning, så
            // den rigtige bruger kan logge ind og fortsætte. Sessionen tømmes og får nyt id som ved et login.
            $pending = OAuth::enabled() ? Authorize::pending($s, $now) : null;
            if ($pending !== null) {
                $s->clear();
                $s->regenerate();
                $s->set(Authorize::SESSION_KEY, $pending);
                return Response::redirect('/login');
            }
            $s->destroy();
            return Response::redirect('/login');
        }
        if ((int) $user['must_change_password'] === 1 && $route !== 'konto') {
            return Response::redirect('/konto');
        }
        if (in_array($route, ['users', 'user_new', 'user_edit', 'user_confirm'], true) && $user['role'] !== 'admin') {
            return $this->forbidden($s, $user, 'Kun administratorer har adgang til brugeradministration.');
        }
        if (!$csrfOk) {
            return $this->forbidden($s, $user, 'Formularen er udløbet. Gå tilbage, genindlæs siden og prøv igen.');
        }

        $csrf = Csrf::token($s);
        return match ($route) {
            'home'      => Flags::enabled(CallPages::FLAG) ? CallPages::list($req, $user, $csrf, $this->flash($s))
                : Response::html(View::home($user, $csrf, $this->flash($s))),
            // F004: detaljen viser kvitteringen fra "Transcribér dette opkald"; slukket røres sessionen ikke (AK9)
            'call_show'  => CallPages::show($id, $req, $user, $csrf,
                Feature::on() ? [$this->flash($s), $this->flash($s, 'flash_info')] : [null, null]),
            'call_select' => $this->callSelect($req, $s, $user, $id, $csrf),
            'call_audio' => CallPages::audio($id, $req, $user),
            'call_txt'   => CallPages::transcript($id, $user),
            'call_zip'   => CallPages::zip($req, $user, $csrf),
            'konto'     => $method === 'POST' ? $this->accountPost($req, $s, $user, $now)
                : Response::html(View::account($user, $csrf, $this->flash($s, 'flash_error'), $this->flash($s), $this->mcp($user, $req, null, null, $s))),
            'users'     => Response::html(View::users($user, $csrf, Users::all(), $this->flash($s))),
            'user_new'  => $method === 'POST' ? $this->userNewPost($req, $s, $user, $now)
                : Response::html(View::userNew($user, $csrf, null, ['email' => '', 'name' => '', 'role' => 'user'])),
            'user_confirm' => $this->userConfirm($s, $user, $id, $action),
            'mcp_create'   => $this->mcpCreate($req, $s, $user, $now),
            'mcp_revoke'   => $this->mcpRevoke($s, $user, $id, $now),
            'app_revoke'   => $this->appRevoke($s, $user, $id, $now),
            default     => $this->userEdit($req, $method, $s, $user, $id, $now),
        };
    }

    /** @var array<string, list<string>> */
    private const ROUTES = [
        'home'         => ['GET'],
        'login'        => ['GET', 'POST'],
        'logout'       => ['POST'],
        'konto'        => ['GET', 'POST'],
        'users'        => ['GET'],
        'user_new'     => ['GET', 'POST'],
        'user_edit'    => ['GET', 'POST'],
        'user_confirm' => ['GET'],
        'call_show'    => ['GET'],
        'call_audio'   => ['GET'],
        'call_txt'     => ['GET'],
        'call_zip'     => ['GET'],
        'call_select'  => ['POST'],
        'mcp_create'   => ['POST'],
        'mcp_revoke'   => ['POST'],
        'oauth_authorize' => ['GET', 'POST'],
        'app_revoke'   => ['POST'],
    ];

    /** @return array{0: ?string, 1: int, 2: string} rute, bruger-id, handling (kun for bekræftelsessiden) */
    private static function route(string $path): array
    {
        $fixed = ['/' => 'home', '/login' => 'login', '/logout' => 'logout', '/konto' => 'konto',
            '/brugere' => 'users', '/brugere/ny' => 'user_new'];
        if (isset($fixed[$path])) {
            return [$fixed[$path], 0, ''];
        }
        if (Flags::enabled(CallPages::FLAG)) {
            // F002: findes kun med flaget tændt (slukket: 404 som enhver ukendt sti, også for anonyme)
            if ($path === '/opkald/download') {
                return ['call_zip', 0, ''];
            }
            // F004: "Transcribér dette opkald" findes kun med F004_adversus (og F005_ui) tændt
            if (Feature::on() && preg_match('#^/opkald/([1-9][0-9]{0,9})/transcriber$#', $path, $m) === 1) {
                return ['call_select', (int) $m[1], ''];
            }
            if (preg_match('#^/opkald/([1-9][0-9]{0,9})(/lyd|/transcript)?$#', $path, $m) === 1) {
                $sub = $m[2] ?? '';
                return [$sub === '/lyd' ? 'call_audio' : ($sub === '/transcript' ? 'call_txt' : 'call_show'), (int) $m[1], ''];
            }
        }
        if (OAuth::enabled()) {
            // F006: godkendelse og tilbagekald af forbundne apps. Nøgle-handlingerne findes ikke med OAuth (DECISIONS #48)
            if ($path === '/oauth/authorize') {
                return ['oauth_authorize', 0, ''];
            }
            if (preg_match('#^/konto/app/([1-9][0-9]{0,9})/tilbagekald$#', $path, $m) === 1) {
                return ['app_revoke', (int) $m[1], ''];
            }
        } elseif (Server::enabled()) {
            // F003: nøgle-handlingerne findes kun med flaget (og F002) tændt
            if ($path === '/konto/mcp-noegle') {
                return ['mcp_create', 0, ''];
            }
            if (preg_match('#^/konto/mcp-noegle/([1-9][0-9]{0,9})/tilbagekald$#', $path, $m) === 1) {
                return ['mcp_revoke', (int) $m[1], ''];
            }
        }
        if (preg_match('#^/brugere/([1-9][0-9]{0,9})$#', $path, $m) === 1) {
            return ['user_edit', (int) $m[1], ''];
        }
        if (preg_match('#^/brugere/([1-9][0-9]{0,9})/(nulstil|deaktiver)$#', $path, $m) === 1) {
            return ['user_confirm', (int) $m[1], $m[2] === 'nulstil' ? 'reset' : 'deactivate'];
        }
        return [null, 0, ''];
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
        LoginThrottle::maybePurge($now);

        // Forsøget registreres som fejl FØR bcrypt (begin), så samtidige requests tæller hinanden med
        $attempt = LoginThrottle::begin($email, $req->ip, $now);
        if ($attempt === null) {
            return Response::html(View::login(Csrf::token($s), self::LOCKED, $email, Authorize::loginContext($s, $now)), 429);
        }

        $user = $email !== '' ? Users::findByEmail($email) : null;
        if ($user === null) {
            // Ukendt e-mail: én bcrypt-beregning med standard-cost som for en rigtig bruger, så svartiden ikke
            // afslører, om e-mailen findes (en hash koster det samme som en verify)
            Password::hash(str_replace("\0", '', $password));   // bcrypt afviser NUL-tegn
            return Response::html(View::login(Csrf::token($s), self::BAD_LOGIN, $email, Authorize::loginContext($s, $now)));
        }
        $hash = (string) $user['password_hash'];
        if (!password_verify($password, $hash) || (int) $user['active'] !== 1) {
            return Response::html(View::login(Csrf::token($s), self::BAD_LOGIN, $email, Authorize::loginContext($s, $now)));
        }
        LoginThrottle::succeed($attempt);

        $id = (int) $user['id'];
        Users::touchLogin($id, $password, $hash, $now);
        $fresh = Users::find($id) ?? $user;
        // F006: kun den validerede, ventende OAuth-anmodning overlever login (ingen next=-parameter: open redirect)
        $pending = OAuth::enabled() ? Authorize::pending($s, $now) : null;
        $s->clear();          // intet andet fra den anonyme session (heller ikke CSRF-tokenet) følger med
        $s->regenerate();     // nyt session-id ved login (session fixation)
        $s->set('user_id', $id);
        $s->set('pwfp', Users::fingerprint($fresh));
        $s->set('last_seen', $now);
        if ($pending !== null) {
            // Ved tvunget skift fortsætter forbindelsen efter skiftet (design F006, BØR)
            $s->set(Authorize::SESSION_KEY, $pending);
        }
        if ((int) $fresh['must_change_password'] === 1) {
            return Response::redirect('/konto');
        }
        return Response::redirect($pending !== null ? '/oauth/authorize' : '/');
    }

    /** @param array<string, mixed> $user */
    private function accountPost(Request $req, Session $s, array $user, int $now): Response
    {
        $current = $req->str('current');
        $new = $req->str('new');
        // Den nuværende adgangskode begrænses som et login (samme e-mail og IP), så en stjålet session ikke kan
        // gætte den uendeligt
        $attempt = LoginThrottle::begin((string) $user['email'], $req->ip, $now);
        if ($attempt === null) {
            return Response::html(View::account($user, Csrf::token($s), self::LOCKED, null, $this->mcp($user, $req, null, null, $s)), 429);
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            return Response::html(View::account($user, Csrf::token($s), 'Den nuværende adgangskode er forkert.', null, $this->mcp($user, $req, null, null, $s)), 422);
        }
        LoginThrottle::succeed($attempt);
        $error = null;
        if (($problem = Password::problem($new)) !== null) {
            $error = $problem;
        } elseif (!hash_equals($new, $req->str('confirm'))) {
            $error = 'De to nye adgangskoder er ikke ens.';
        } elseif (hash_equals($current, $new)) {
            $error = 'Den nye adgangskode skal være forskellig fra den nuværende.';
        }
        if ($error !== null) {
            return Response::html(View::account($user, Csrf::token($s), $error, null, $this->mcp($user, $req, null, null, $s)), 422);
        }

        $wasForced = (int) $user['must_change_password'] === 1;
        $revoked = Users::setPassword((int) $user['id'], $new, false, $now);
        $fresh = Users::find((int) $user['id']) ?? $user;
        // Nyt fingeraftryk: denne session fortsætter, alle andre for brugeren afvises ved næste request
        $s->regenerate();
        $s->set('pwfp', Users::fingerprint($fresh));
        $s->set('flash', 'Adgangskoden er skiftet.' . ($revoked > 0 ? ' ' . (OAuth::enabled() ? self::APPS_REVOKED_SELF : self::KEYS_REVOKED_SELF) : ''));
        if ($wasForced && OAuth::enabled() && Authorize::pending($s, $now) !== null) {
            return Response::redirect('/oauth/authorize');   // F006: den ventende forbindelse fortsætter efter skiftet
        }
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

    /**
     * GET /brugere/{id}/nulstil og /deaktiver: bekræftelsessiden (QA F-4). Egen nulstilling og egen deaktivering
     * afvises allerede her.
     *
     * @param array<string, mixed> $admin
     */
    private function userConfirm(Session $s, array $admin, int $id, string $action): Response
    {
        $target = Users::find($id);
        if ($target === null) {
            return Response::html(View::error(404, 'Brugeren findes ikke.', Csrf::token($s), $admin), 404);
        }
        $self = $id === (int) $admin['id'];
        if ($self) {
            $msg = $action === 'reset' ? self::SELF_RESET : 'Du kan ikke deaktivere dig selv.';
            return Response::html(View::userEdit($admin, Csrf::token($s), $target, $msg, null), 422);
        }
        if ($action === 'deactivate' && (int) $target['active'] !== 1) {
            return Response::redirect('/brugere/' . $id);
        }
        return Response::html(View::confirm($admin, Csrf::token($s), $target, $action));
    }

    /** @param array<string, mixed> $admin */
    private function userEdit(Request $req, string $method, Session $s, array $admin, int $id, int $now): Response
    {
        $target = Users::find($id);
        if ($target === null) {
            return Response::html(View::error(404, 'Brugeren findes ikke.', Csrf::token($s), $admin), 404);
        }
        if ($method !== 'POST') {
            return Response::html(View::userEdit($admin, Csrf::token($s), $target, null, $this->flash($s)));
        }
        $action = $req->str('action');
        $self = $id === (int) $admin['id'];
        // Nulstil og deaktiver kræver bekræftelsessiden; uden den sendes man dertil, og intet ændres
        if (in_array($action, ['reset', 'deactivate'], true) && $req->str('bekraeftet') !== '1' && !$self) {
            return Response::redirect('/brugere/' . $id . ($action === 'reset' ? '/nulstil' : '/deaktiver'));
        }
        try {
            switch ($action) {
                case 'save':
                    Users::update($id, $req->str('name'), $req->str('role'), $now);
                    if ($self && $req->str('role') !== 'admin') {
                        // Selv-nedgradering: /brugere er nu 403, så send til forsiden med en forklaring (QA F-5)
                        $s->set('flash', 'Du er nu Bruger og har ikke længere adgang til Brugere.');
                        return Response::redirect('/');
                    }
                    $msg = 'Ændringerne er gemt.';
                    break;
                case 'reset':
                    if ($self) {
                        throw new UserError(self::SELF_RESET);
                    }
                    $reset = Users::resetPassword($id, $now);
                    return Response::html(View::oneTimePassword($admin, Csrf::token($s), $target, $reset['password'],
                        'Adgangskode nulstillet', $reset['revoked'] > 0 ? (OAuth::enabled() ? self::APPS_REVOKED_RESET : self::KEYS_REVOKED_RESET) : null));
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

    /**
     * Data til MCP-sektionen på /konto, eller null, når F003 er slukket (så vises sektionen ikke).
     *
     * @param array<string, mixed> $user
     * @param array{token: string, name: string}|null $new  den netop oprettede nøgle (kun i POST-svaret)
     * @return array{tokens: list<array<string, mixed>>, origin: string, new: array{token: string, name: string}|null,
     *     error: ?string, name: string, apps: list<array<string, mixed>>|null, resume?: bool}|null
     */
    private function mcp(array $user, Request $req, ?array $new = null, ?string $error = null, ?Session $s = null): ?array
    {
        if (!Server::enabled()) {
            return null;
        }
        if (OAuth::enabled()) {
            // F006: kun "Forbind Claude" og forbundne apps; nøglerne vises ikke (DECISIONS #48)
            return ['tokens' => [], 'origin' => OAuth::site($req), 'new' => null, 'error' => null, 'name' => '',
                'apps' => Grants::active((int) $user['id'], ($this->clock)()),
                'resume' => $s !== null && Authorize::pending($s, ($this->clock)()) !== null];
        }
        return ['tokens' => Tokens::active((int) $user['id']), 'origin' => Server::siteOrigin($req), 'new' => $new, 'error' => $error,
            'name' => $error !== null ? $req->str('name') : '', 'apps' => null];
    }

    /**
     * GET/POST /oauth/authorize (F006). GET med parametre er en ny anmodning fra klienten: valideres (se Authorize) og
     * gemmes i sessionen. GET uden parametre fortsætter den ventende (efter login). POST er Godkend eller Afvis.
     *
     * @param array<string, mixed>|null $user
     */
    private function oauthAuthorize(Request $req, string $method, Session $s, ?array $user, bool $csrfOk, int $now): Response
    {
        $site = OAuth::site($req);
        if ($site === '') {
            return $this->oauthError($s, $user, false, 'invalid_host');
        }
        if ($method === 'GET') {
            if ($req->query !== []) {
                $retry = OAuth::ipLimited($req->ip, 'authorize', $now);
                if ($retry > 0) {
                    $r = Response::html(UiPages::error(429, self::OAUTH_LIMITED_TEXT, Csrf::token($s), $user, '/', self::OAUTH_INVALID_TITLE,
                        'rate_limited'), 429);
                    $r->headers['Retry-After'] = (string) $retry;
                    return $r;
                }
                $v = Authorize::validate($req->query, $site, $now);
                if ($v['kind'] !== 'ok') {
                    $s->remove(Authorize::SESSION_KEY);
                    return $v['kind'] === 'page' ? $this->oauthError($s, $user, false, $v['code']) : self::externalRedirect($v['url']);
                }
                $s->set(Authorize::SESSION_KEY, $v['req']);
            }
            $p = Authorize::pending($s, $now);
            if ($p === null) {
                return $this->oauthError($s, $user, true, 'no_pending_request');
            }
            if ($user === null) {
                return Response::redirect('/login');
            }
            if ((int) $user['must_change_password'] === 1) {
                return Response::redirect('/konto');
            }
            // QA F6b: "Adgangskoden er skiftet." efter et tvunget skift vises her, hvor brugeren er, og forbruges
            $r = Response::html(UiPages::consent($user, Csrf::token($s), $p, Flags::env() === 'dev', $this->flash($s)));
            // Chrome anvender form-action på redirecten efter POST: præcis den registrerede redirect-origin, intet andet
            $r->headers['Content-Security-Policy'] = self::csp($p['redirect_uri']);
            return $r;
        }

        if ($user === null) {
            return Response::redirect('/login');   // sessionen er udløbet: anmodningen er væk (design E7)
        }
        $p = Authorize::pending($s, $now);
        if (!$csrfOk || $p === null || !hash_equals($p['rid'], $req->str('rid'))) {
            return $this->oauthError($s, $user, true, $csrfOk ? 'no_pending_request' : 'csrf');
        }
        $s->remove(Authorize::SESSION_KEY);   // engangs: dobbeltklik og genindsendelse giver "udløbet"
        if ((int) $user['must_change_password'] === 1) {
            return Response::redirect('/konto');
        }
        $uid = (int) $user['id'];
        $decision = $req->str('beslutning');
        if ($decision === 'afvis') {
            AccessLog::write($uid, 'oauth_afvis', ['klient' => $p['client_pk']]);
            return self::externalRedirect(Authorize::back($p, ['error' => 'access_denied'], $site));
        }
        if ($decision !== 'godkend') {
            return $this->oauthError($s, $user, true, 'unknown_decision');
        }
        $grant = Grants::create($p['client_pk'], $uid, $p['resource'], $p['redirect_uri'], $now);
        $code = Issuer::issueCode($grant, $p['redirect_uri'], $p['challenge'], $now);
        AccessLog::write($uid, 'oauth_godkend', ['klient' => $p['client_pk'], 'bevilling' => $grant]);
        return self::externalRedirect(Authorize::back($p, ['code' => $code], $site));
    }

    /** 303 til klientens redirect_uri, med samme form-action som godkendelsessiden. */
    private static function externalRedirect(string $url): Response
    {
        $r = Response::redirect($url);
        $r->headers['Content-Security-Policy'] = self::csp($url);
        $r->headers['Referrer-Policy'] = 'no-referrer';
        return $r;
    }

    /**
     * E1 (ugyldig anmodning: aldrig en redirect, klientens værdier gengives ikke) og E4 (udløbet eller brugt).
     *
     * @param array<string, mixed>|null $user
     */
    private function oauthError(Session $s, ?array $user, bool $expired, string $code): Response
    {
        $r = Response::html(UiPages::error(400, $expired ? self::OAUTH_EXPIRED_TEXT : self::OAUTH_INVALID_TEXT, Csrf::token($s), $user, '/',
            $expired ? self::OAUTH_EXPIRED_TITLE : self::OAUTH_INVALID_TITLE, $code), 400);
        $r->headers['Referrer-Policy'] = 'no-referrer';
        return $r;
    }

    /** POST /konto/app/{id}/tilbagekald (F006): som nøglerne, uden bekræftelse (design D7). @param array<string, mixed> $user */
    private function appRevoke(Session $s, array $user, int $id, int $now): Response
    {
        $name = Grants::revokeOwn((int) $user['id'], $id, $now);
        if ($name === null) {
            $s->set('flash_error', self::APP_ALREADY_REVOKED);
            return Response::redirect('/konto');
        }
        AccessLog::write((int) $user['id'], 'oauth_tilbagekald', ['bevilling' => $id]);
        $shown = OAuth::displayName($name);
        $s->set('flash', 'Adgangen for «' . ($shown !== '' ? $shown : 'En unavngivet app') . '» er tilbagekaldt. Appen kan ikke længere '
            . 'læse dine transcripts i Whisper.');
        return Response::redirect('/konto');
    }

    /** CSP for alle sider; $redirect: godkendelsessiden og redirecten derfra må sende formularen videre til netop den origin. */
    public static function csp(?string $redirect = null): string
    {
        $action = "'self'";
        $origin = $redirect !== null ? OAuth::origin($redirect) : '';
        if (preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?$#', $origin) === 1) {
            $action .= ' ' . $origin;
        }
        return "default-src 'self'; style-src 'self'; img-src 'self'; form-action " . $action . '; '
            . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'";
    }

    /**
     * POST /konto/mcp-noegle: nøglen vises kun i DETTE svar (200, ikke redirect), aldrig i session, flash eller log.
     *
     * @param array<string, mixed> $user
     */
    private function mcpCreate(Request $req, Session $s, array $user, int $now): Response
    {
        try {
            $token = Tokens::create((int) $user['id'], $req->str('name'), $now);
        } catch (UserError $e) {
            return Response::html(View::account($user, Csrf::token($s), null, null, $this->mcp($user, $req, null, $e->getMessage())), 422);
        }
        $new = ['token' => $token, 'name' => Tokens::validName($req->str('name'))];
        return Response::html(View::account($user, Csrf::token($s), null, null, $this->mcp($user, $req, $new)));
    }

    /** @param array<string, mixed> $user */
    private function mcpRevoke(Session $s, array $user, int $id, int $now): Response
    {
        $name = Tokens::revoke((int) $user['id'], $id, $now);
        if ($name === null) {
            // Dobbeltklik, en andens nøgle og et ukendt id får samme svar, så id'er ikke kan afprøves (QA F-4)
            $s->set('flash', self::ALREADY_REVOKED);
            return Response::redirect('/konto');
        }
        $s->set('flash', 'Nøglen «' . $name . '» er tilbagekaldt. Claude Desktop med den nøgle har ikke længere adgang.');
        return Response::redirect('/konto');
    }

    /** @param array<string, mixed>|null $user */
    private function forbidden(Session $s, ?array $user, string $text): Response
    {
        return Response::html(View::error(403, $text, Csrf::token($s), $user), 403);
    }

    private function flash(Session $s, string $key = 'flash'): ?string
    {
        $f = $s->get($key);
        $s->remove($key);
        return is_string($f) ? $f : null;
    }

    /**
     * POST /opkald/{id}/transcriber (F004, UX §4): PRG med en kvittering, der siger, hvad der skete.
     *
     * @param array<string, mixed> $user
     */
    private function callSelect(Request $req, Session $s, array $user, int $id, string $csrf): Response
    {
        [$status, $url, $kind] = CallPages::select($id, $req, $user);
        if ($status !== 0) {
            return Response::html(UiPages::error(404, 'Opkaldet findes ikke.', $csrf, $user), 404);
        }
        $texts = [
            'ok'      => ['flash', 'Opkaldet er sat i kø. Lyden hentes fra Adversus, og transcriptet laves derefter.'],
            'igen'    => ['flash', 'Lyden hentes igen fra Adversus. Transcriptet laves, når lyden er hentet.'],
            'faerdig' => ['flash_info', 'Opkaldet er allerede transcriberet.'],
            'fuld'    => ['flash_info', 'Der er allerede mange opkald i kø. Prøv igen senere.'],
            'nylig'   => ['flash_info', 'Lyden blev prøvet for nylig. Prøv igen om lidt.'],
            'koe'     => ['flash_info', 'Opkaldet er allerede i kø.'],
        ];
        [$key, $text] = $texts[$kind] ?? $texts['koe'];
        $s->set($key, $text);
        return Response::redirect($url);
    }

    /** Sikkerheds-headere på alle svar fra appen. no-store: siderne kan vise engangskoder og persondata. */
    private static function secure(Response $r): Response
    {
        $r->headers += [
            'Content-Security-Policy' => self::csp(),
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Cache-Control'           => 'no-store',
        ];
        return $r;
    }
}
