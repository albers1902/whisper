<?php
declare(strict_types=1);

namespace Whisper;

use Whisper\Mcp\Tokens;
use Whisper\OAuth\OAuth;

/**
 * Login, konto (inkl. F003's MCP-sektion), brugere og fejlsider i det nye UI (F005, UX §3.5–3.8). View kalder hertil
 * som det første, når F005_ui er tændt; samme parametre, samme formularer (felter, CSRF, POST-mål) som før.
 */
final class UiPages
{
    /** @param ?string $context  F006: en ventende forbindelse (design F006 §1); null = siden som før */
    public static function login(string $csrf, ?string $error, string $email, ?string $context = null): string
    {
        return Ui::layout('Log ind', '<section class="card narrow login">'
            . '<p class="login-brand"><strong>Whisper</strong><span>Opkald og transcripts fra kundeservice</span></p>'
            . '<h1>Log ind</h1>' . ($context !== null ? Ui::msg('info', '<p>' . Ui::e($context) . '</p>') : '') . Ui::message($error)
            . '<form method="post" action="/login">' . View::csrfField($csrf)
            . '<label>E-mail<input type="email" name="email" value="' . Ui::e($email) . '" required autofocus autocomplete="username"></label>'
            . '<label>Adgangskode<input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button type="submit" class="full">Log ind</button></form>'
            . '<p class="hint">Glemt adgangskode? Kontakt en administrator.</p>'
            . ($context !== null ? '<p class="hint">Vil du ikke forbinde? Luk bare vinduet.</p>' : '') . '</section>', null, $csrf);
    }

    /**
     * Godkendelsessiden (design F006 §2, visuelt brief: .consent, dl.meta.single, code.host). Klientnavnet er
     * ikke-betroet (D8): renset, i «», mærket "oplyses af appen selv", aldrig i title eller H1. Værten vises i ASCII.
     *
     * @param array<string, mixed> $user
     * @param array{client_name: string, redirect_uri: string, rid: string} $req
     */
    public static function consent(array $user, string $csrf, array $req, bool $dev, ?string $ok = null): string
    {
        $e = Ui::e(...);
        $name = OAuth::displayName($req['client_name']);
        // Uden navn: almindelig vægt, ingen «» (visuelt brief): det er ikke et navn fra appen
        $named = $name !== '' ? '<strong>«' . $e($name) . '»</strong>' : 'En unavngivet app';
        $host = OAuth::host($req['redirect_uri']);
        $warn = $dev && !OAuth::isClaudeHost($host)
            ? Ui::msg('warn', '<p>Appen sender dig til ' . $e($host) . '. Det er kun tilladt på udviklingsserveren.</p>') : '';
        return Ui::layout('Giv adgang til Whisper', '<div class="card narrow consent"><h1>Giv adgang til Whisper</h1>' . Ui::message(null, $ok)
            . '<p>' . $named . ' vil have læseadgang til dine opkald og transcripts i Whisper.</p>'
            . '<dl class="meta single">'
            . '<div><dt>App</dt><dd>' . $named . '<span class="hint">Navnet oplyses af appen selv.</span></dd></div>'
            . '<div><dt>Sender dig til</dt><dd><code class="host">' . $e($host) . '</code></dd></div>'
            . '<div><dt>Logget ind som</dt><dd>' . $e((string) ($user['name'] ?? '')) . ' · ' . $e((string) ($user['email'] ?? '')) . '</dd></div>'
            . '</dl>'
            . '<h2>Appen kan</h2><ul><li>søge i dine opkald og transcripts</li>'
            . '<li>læse transcripts og opkaldsoplysninger (dato, kundens nummer, agent og ticket)</li></ul>'
            . '<p>Appen kan ikke ændre, slette eller oprette noget i Whisper.</p>'
            . '<p class="hint">Godkend kun, hvis du selv har bedt Claude om at forbinde til Whisper lige nu.</p>'
            . $warn
            . '<form method="post" action="/oauth/authorize" class="actions" data-once>' . View::csrfField($csrf)
            . '<input type="hidden" name="rid" value="' . $e($req['rid']) . '">'
            . '<button type="submit" name="beslutning" value="godkend">Godkend</button>'
            . '<button type="submit" name="beslutning" value="afvis" class="secondary">Afvis</button></form>'
            . '<p class="hint">Adgangen gælder, indtil du tilbagekalder den under Konto → Forbundne apps.</p>'
            // div, ikke p: en form må ikke stå i et p-element
            . '<div class="hint">Ikke dig? <form method="post" action="/logout" class="inline">' . View::csrfField($csrf)
            . '<button type="submit" class="link">Log ud</button></form></div>'
            . '<p class="hint">' . $e(self::MCP_WARNING) . '</p>'
            . '</div>', $user, $csrf);
    }

    /** Prompt-injection (DECISIONS #22), ordlyd uden "nøgle" (F006, design §3). */
    public const MCP_WARNING = 'Kombinér ikke Whisper med værktøjer, der kan sende mails eller ændre data, i samme Claude-samtale.';

    /** @param array<string, mixed> $user */
    public static function home(array $user, string $csrf, ?string $ok): string
    {
        return Ui::layout('Opkald', '<h1>Opkald</h1>' . Ui::message(null, $ok) . '<p>Opkald kommer her (F002)</p>', $user, $csrf, 'opkald');
    }

    /**
     * @param array<string, mixed> $user
     * @param array{tokens: list<array<string, mixed>>, origin: string, new: array{token: string, name: string}|null,
     *     error: ?string, name: string, apps: list<array<string, mixed>>|null, resume?: bool}|null $mcp
     */
    public static function account(array $user, string $csrf, ?string $error, ?string $ok, ?array $mcp): string
    {
        $forced = (int) ($user['must_change_password'] ?? 0) === 1
            ? Ui::msg('info', '<p>Du skal vælge en ny adgangskode, før du kan bruge Whisper.</p>'
                . (($mcp['resume'] ?? false) ? '<p>Når du har valgt en ny adgangskode, fortsætter du med at forbinde Claude.</p>' : '')) : '';
        $section = '';
        if ($mcp !== null) {
            $section = $mcp['apps'] !== null ? self::connect($csrf, $mcp['origin'], $mcp['apps']) : self::mcp($csrf, $mcp);
        }
        return Ui::layout('Konto', '<div class="card medium"><h1>Konto</h1>' . $forced . Ui::message($error, $ok)
            . '<dl class="meta"><div><dt>Navn</dt><dd>' . Ui::e((string) $user['name']) . '</dd></div>'
            . '<div><dt>E-mail</dt><dd>' . Ui::e((string) $user['email']) . '</dd></div>'
            . '<div><dt>Rolle</dt><dd>' . self::roleName((string) ($user['role'] ?? '')) . '</dd></div></dl>'
            . '<section aria-labelledby="pw-h"><h2 id="pw-h">Adgangskode</h2><form method="post" action="/konto">' . View::csrfField($csrf)
            . '<label>Nuværende adgangskode<input type="password" name="current" required autocomplete="current-password"></label>'
            . '<label>Ny adgangskode (mindst ' . Password::MIN_LENGTH . ' tegn)<input type="password" name="new" required minlength="'
            . Password::MIN_LENGTH . '" autocomplete="new-password"></label>'
            . '<label>Gentag ny adgangskode<input type="password" name="confirm" required minlength="' . Password::MIN_LENGTH
            . '" autocomplete="new-password"></label>'
            . '<button type="submit">Skift adgangskode</button></form></section>'
            . $section . '</div>', $user, $csrf, 'konto');
    }

    /**
     * "Forbind Claude" (F006, design §3 uden Avanceret: DECISIONS #48). Menunavnene er Claude's egne
     * (claude.com/docs/connectors/custom/add-unlisted, 2026-11-12).
     *
     * @param list<array<string, mixed>> $apps
     */
    private static function connect(string $csrf, string $origin, array $apps): string
    {
        $e = Ui::e(...);
        $now = time();
        $url = $origin !== ''
            ? '<div class="secret"><input id="connector-url" type="text" readonly value="' . $e($origin . '/mcp') . '" aria-label="Whispers connector-URL">'
                . Ui::copyButton('connector-url', 'Kopiér URL') . '</div>'
            : Ui::msg('info', '<p>Whispers adresse er ikke sat op. Kontakt en administrator.</p>');
        $rows = '';
        foreach ($apps as $a) {
            $name = OAuth::displayName((string) $a['client_name']);
            $shown = $name !== '' ? $name : 'En unavngivet app';
            $created = View::dateTime($a['created_at'] ?? null);
            $used = $a['last_used_at'] ?? null;
            $usedText = 'Aldrig';
            if (is_string($used) && $used !== '' && ($ts = strtotime($used)) !== false) {
                $usedText = '<span title="' . $e(View::dateTime($used)) . '">' . $e(ucfirst(Ui::ago($ts, $now))) . '</span>';
            }
            $rows .= '<tr><td data-label="App" class="ellipsis" title="' . $e($shown) . '">' . $e($shown) . '</td>'
                . '<td data-label="Forbundet" class="num">' . $e($created) . '</td>'
                . '<td data-label="Sidst brugt">' . $usedText . '</td>'
                . '<td><form method="post" action="/konto/app/' . (int) $a['id'] . '/tilbagekald" class="inline">' . View::csrfField($csrf)
                . '<button type="submit" class="button danger-secondary small" aria-label="'
                . $e('Tilbagekald adgangen for «' . $shown . '», forbundet ' . $created) . '">Tilbagekald</button></form></td></tr>';
        }
        $list = $rows === '' ? '<p class="muted">Ingen apps er forbundet endnu. Når du godkender Claude, står forbindelsen her.</p>'
            : '<div class="table-wrap"><table class="keys"><caption class="sr-only">Forbundne apps</caption><thead><tr><th scope="col">App</th>'
                . '<th scope="col">Forbundet</th><th scope="col">Sidst brugt</th><th scope="col"><span class="sr-only">Handling</span></th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        return '<section class="mcp" aria-labelledby="claude-h"><h2 id="claude-h">Forbind Claude</h2>'
            . '<p>Forbind Claude til Whisper, så Claude kan søge i og læse dine transcripts. Claude får kun læseadgang.</p>'
            . '<h3>Tilføj Whisper i Claude</h3><ol class="steps">'
            . '<li>Åbn Claude (claude.ai eller Claude Desktop), og gå til Customize → Connectors.</li>'
            . '<li>Vælg Add → "Add custom connector". Skriv Whisper som navn, og indsæt URL\'en:' . $url . '</li>'
            . '<li>Vælg Continue, derefter Add og Connect. Whisper åbner i et vindue: log ind, og vælg Godkend.</li>'
            . '<li>Spørg fx: "Find opkald fra i går, hvor kunden nævner flytning."</li></ol>'
            . '<p class="hint">Du skal ikke bruge en nøgle, Node.js eller genstarte Claude.</p>'
            . '<p class="hint">Bruger I Claude Team eller Enterprise, er det en ejer af organisationen, der tilføjer connectoren under '
            . 'Organization settings → Connectors. Derefter vælger du selv Connect.</p>'
            . Ui::msg('warn', '<p>' . $e(self::MCP_WARNING) . '</p>')
            . '<h3>Forbundne apps (' . count($apps) . ')</h3>' . $list . '</section>';
    }

    /**
     * Sektionen "Claude Desktop (MCP)" efter UX §3.6.
     *
     * @param array{tokens: list<array<string, mixed>>, origin: string, new: array{token: string, name: string}|null,
     *     error: ?string, name: string, apps: list<array<string, mixed>>|null, resume?: bool} $mcp
     */
    private static function mcp(string $csrf, array $mcp): string
    {
        $e = Ui::e(...);
        $origin = $mcp['origin'] !== '' ? $mcp['origin'] : 'https://<din-whisper-adresse>';
        $count = count($mcp['tokens']);
        $now = time();
        $rows = '';
        foreach ($mcp['tokens'] as $t) {
            $name = (string) $t['name'];
            $used = $t['last_used_at'] ?? null;
            $usedText = 'Aldrig';
            if (is_string($used) && $used !== '' && ($ts = strtotime($used)) !== false) {
                $usedText = '<span title="' . $e(View::dateTime($used)) . '">' . $e(ucfirst(Ui::ago($ts, $now))) . '</span>';
            }
            $rows .= '<tr><td data-label="Navn" class="ellipsis" title="' . $e($name) . '">' . $e($name) . '</td>'
                . '<td data-label="Nøgle"><code>' . $e((string) $t['prefix']) . '…</code></td>'
                . '<td data-label="Oprettet" class="num">' . $e(View::dateTime($t['created_at'] ?? null)) . '</td>'
                . '<td data-label="Sidst brugt">' . $usedText . '</td>'
                . '<td><form method="post" action="/konto/mcp-noegle/' . (int) $t['id'] . '/tilbagekald" class="inline">' . View::csrfField($csrf)
                . '<button type="submit" class="button danger-secondary small" aria-label="' . $e('Tilbagekald nøglen «' . $name . '»') . '">Tilbagekald</button>'
                . '</form></td></tr>';
        }
        $list = $rows === '' ? '<p class="muted">Du har ingen nøgler endnu.</p>'
            : '<div class="table-wrap"><table class="keys"><caption class="sr-only">Dine nøgler</caption><thead><tr><th scope="col">Navn</th>'
                . '<th scope="col">Nøgle</th><th scope="col">Oprettet</th><th scope="col">Sidst brugt</th><th scope="col"><span class="sr-only">Handling</span></th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        $new = '';
        if ($mcp['new'] !== null) {
            $new = Ui::msg('ok', '<p><strong>Nøglen «' . $e($mcp['new']['name']) . '» er oprettet</strong></p>'
                . '<p>Kopiér nøglen nu. Den vises kun denne ene gang.</p>'
                . '<div class="secret"><input id="mcp-noegle" type="text" readonly value="' . $e($mcp['new']['token']) . '" aria-label="Din nye MCP-nøgle">'
                . Ui::copyButton('mcp-noegle', 'Kopiér nøgle') . '</div>'
                . '<h3>Sådan forbinder du Claude Desktop</h3><ol class="steps">'
                . '<li>Installér Node.js (LTS) fra <a href="https://nodejs.org/">nodejs.org</a>, hvis du ikke har det.</li>'
                . '<li>Åbn Claude Desktop → Indstillinger → Udvikler → Rediger konfiguration.<br>'
                . 'Windows: <code>%APPDATA%\\Claude\\claude_desktop_config.json</code><br>'
                . 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code></li>'
                . '<li>Indsæt blokken herunder. Har filen allerede "mcpServers", så tilføj kun "whisper"-delen.'
                . '<div class="code-wrap"><pre class="code"><code id="mcp-config" tabindex="0" aria-label="Konfiguration til Claude Desktop">'
                . $e(View::mcpConfig($origin, $mcp['new']['token'])) . '</code></pre>'
                . Ui::copyButton('mcp-config', 'Kopiér konfiguration', 'small') . '</div></li>'
                . '<li>Genstart Claude Desktop helt (luk den også i proceslinjen).</li>'
                . '<li>Spørg fx: "Find opkald fra i går, hvor kunden nævner flytning."</li></ol>'
                . '<p>Får du en login-side, en browserfane eller en OAuth-fejl, når Claude Desktop forbinder, er nøglen forkert eller '
                . 'tilbagekaldt. Opret en ny nøgle, og skift den i filen.</p>');
        }

        if ($count >= Tokens::MAX_ACTIVE) {
            $form = '<p class="muted">' . $e(Tokens::FULL) . '</p>';
        } else {
            $invalid = $mcp['error'] !== null && in_array($mcp['error'], [Tokens::NAME_EMPTY, Tokens::NAME_LONG], true);
            $form = '<h3>Opret ny nøgle</h3>'
                . ($mcp['error'] !== null && $mcp['error'] !== Tokens::FULL
                    ? '<p class="msg error field-msg" role="alert" id="mcp-navn-fejl">' . Ui::icon('circle-alert', 18) . $e($mcp['error']) . '</p>' : '')
                . '<form method="post" action="/konto/mcp-noegle" class="inline-form">' . View::csrfField($csrf)
                . '<label>Navn på nøglen<input type="text" name="name" maxlength="80" value="' . $e($mcp['name']) . '"'
                . ($invalid ? ' aria-invalid="true" aria-describedby="mcp-navn-fejl mcp-navn-hjaelp"' : ' aria-describedby="mcp-navn-hjaelp"') . '>'
                . '<span class="hint" id="mcp-navn-hjaelp">Fx navnet på computeren, så du kan kende nøglen igen.</span></label>'
                . '<button type="submit">' . Ui::icon('key-round') . 'Opret nøgle</button></form>'
                . '<p class="hint">' . $e(View::MCP_RELOAD) . '</p>';
        }
        return '<section class="mcp" aria-labelledby="mcp-overskrift"><h2 id="mcp-overskrift">Claude Desktop (MCP)</h2>'
            . $new
            . '<p>Forbind Claude Desktop til Whisper, så Claude kan søge i og læse transcripts.</p>'
            . '<p>Claude får kun læseadgang. Nøglen er personlig – del den ikke.</p>'
            . Ui::msg('warn', '<p>' . $e(View::MCP_WARNING) . '</p>')
            . '<h3>Dine nøgler (' . $count . ' af ' . Tokens::MAX_ACTIVE . ')</h3>' . $list . $form
            . '<p class="hint">Har din Claude-konto "Request headers" under custom connectors, kan du i stedet tilføje <code>'
            . $e($origin) . '/mcp</code> med headeren <code>authorization</code> = <code>Bearer &lt;nøgle&gt;</code>.</p></section>';
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $users
     */
    public static function users(array $user, string $csrf, array $users, ?string $ok): string
    {
        $rows = '';
        foreach ($users as $u) {
            $active = (int) $u['active'] === 1;
            $rows .= '<tr' . ($active ? '' : ' class="inactive"') . '>'
                . '<td data-label="Navn"><a class="row-link" href="/brugere/' . (int) $u['id'] . '">' . Ui::e((string) $u['name']) . '</a></td>'
                . '<td data-label="E-mail" class="ellipsis" title="' . Ui::e((string) $u['email']) . '">' . Ui::e((string) $u['email']) . '</td>'
                . '<td data-label="Rolle">' . self::roleName((string) $u['role']) . '</td>'
                . '<td data-label="Status">' . self::activeBadge($active) . '</td>'
                . '<td data-label="Sidste login" class="num">' . Ui::e(View::dateTime($u['last_login_at'] ?? null)) . '</td></tr>';
        }
        return Ui::layout('Brugere', '<div class="page-head"><h1>Brugere</h1><a class="button" href="/brugere/ny">Opret bruger</a></div>'
            . Ui::message(null, $ok)
            . '<div class="table-wrap"><table class="users"><caption class="sr-only">Brugere</caption><thead><tr><th scope="col">Navn</th>'
            . '<th scope="col">E-mail</th><th scope="col">Rolle</th><th scope="col">Status</th><th scope="col">Sidste login</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>', $user, $csrf, 'brugere');
    }

    /**
     * @param array<string, mixed> $user
     * @param array{email: string, name: string, role: string} $values
     */
    public static function userNew(array $user, string $csrf, ?string $error, array $values): string
    {
        return Ui::layout('Opret bruger', '<section class="card narrow"><h1>Opret bruger</h1>' . Ui::message($error)
            . '<form method="post" action="/brugere/ny">' . View::csrfField($csrf)
            . '<label>E-mail<input type="email" name="email" value="' . Ui::e($values['email']) . '" required maxlength="190"></label>'
            . '<label>Navn<input type="text" name="name" value="' . Ui::e($values['name']) . '" required maxlength="120"></label>'
            . self::roleSelect($values['role'])
            . '<div class="actions"><button type="submit">Opret bruger</button><a class="button secondary" href="/brugere">Annuller</a></div>'
            . '</form></section>', $user, $csrf, 'brugere');
    }

    /**
     * Engangskoden vises kun i dette svar (no-store).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function oneTimePassword(array $user, string $csrf, array $target, string $password, string $heading,
        ?string $note = null): string
    {
        return Ui::layout($heading, '<section class="card narrow"><h1>' . Ui::e($heading) . '</h1>'
            . '<p>Engangsadgangskode til <strong>' . Ui::e((string) $target['email']) . '</strong>:</p>'
            . '<div class="secret"><input class="otp" id="engangskode" type="text" readonly value="' . Ui::e($password) . '" aria-label="Engangsadgangskode">'
            . Ui::copyButton('engangskode', 'Kopiér') . '</div>'
            . Ui::msg('info', '<p>Kopiér den nu. Den vises kun denne ene gang, og brugeren skal skifte den ved første login.</p>'
                . ($note !== null ? '<p>' . Ui::e($note) . '</p>' : ''))
            . '<div class="actions"><a class="button" href="/brugere/' . (int) $target['id'] . '">Færdig – til brugeren</a>'
            . '<a class="button secondary" href="/brugere">Til listen</a></div></section>', $user, $csrf, 'brugere');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function userEdit(array $user, string $csrf, array $target, ?string $error, ?string $ok): string
    {
        $id = (int) $target['id'];
        $active = (int) $target['active'] === 1;
        $self = $id === (int) ($user['id'] ?? 0);
        $access = [];
        if ($self) {
            $access[] = '<p>Din egen adgangskode skifter du under <a href="/konto">Konto</a>.</p>';
        } else {
            $access[] = '<a class="button secondary" href="/brugere/' . $id . '/nulstil">Nulstil adgangskode …</a>';
            $access[] = $active
                ? '<a class="button danger-secondary" href="/brugere/' . $id . '/deaktiver">Deaktiver bruger …</a>'
                : '<form method="post" action="/brugere/' . $id . '" class="inline">' . View::csrfField($csrf)
                    . '<button type="submit" class="secondary" name="action" value="activate">Genaktiver bruger</button></form>';
        }
        return Ui::layout('Rediger bruger', '<p class="back"><a href="/brugere">' . Ui::icon('chevron-left') . 'Til listen</a></p>'
            . '<div class="card medium"><h1>' . Ui::e((string) $target['name']) . '</h1>'
            . '<p class="sub">' . Ui::e((string) $target['email']) . ' · ' . self::activeBadge($active) . '</p>'
            . Ui::message($error, $ok)
            . '<section aria-labelledby="info-h"><h2 id="info-h">Oplysninger</h2><form method="post" action="/brugere/' . $id . '">' . View::csrfField($csrf)
            . '<label>Navn<input type="text" name="name" value="' . Ui::e((string) $target['name']) . '" required maxlength="120"></label>'
            . self::roleSelect((string) $target['role'])
            . '<button type="submit" name="action" value="save">Gem ændringer</button></form></section>'
            . '<section aria-labelledby="adgang-h"><h2 id="adgang-h">Adgang</h2><div class="actions">' . implode('', $access) . '</div>'
            . '<p class="muted">Sidste login: ' . Ui::e(View::dateTime($target['last_login_at'] ?? null)) . '</p></section></div>',
            $user, $csrf, 'brugere');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function confirm(array $user, string $csrf, array $target, string $action): string
    {
        $id = (int) $target['id'];
        $name = Ui::e((string) $target['name']);
        [$title, $text, $button] = $action === 'reset'
            ? ['Nulstil adgangskode', "Nulstil adgangskoden for <strong>$name</strong>? Den nuværende adgangskode holder op med at "
                . "virke med det samme, $name logges ud, og du får en engangskode, som kun vises én gang.", 'Ja, nulstil adgangskoden']
            : ['Deaktiver bruger', "Deaktiver <strong>$name</strong>? $name logges ud med det samme og kan ikke logge ind, "
                . 'før en administrator genaktiverer brugeren.' . (OAuth::enabled() ? ' Brugerens forbundne apps holder op med at virke.' : ''),
                'Ja, deaktiver brugeren'];
        return Ui::layout($title, '<section class="card narrow"><h1>' . Ui::e($title) . '</h1><p>' . $text . '</p>'
            . '<form method="post" action="/brugere/' . $id . '" class="actions">' . View::csrfField($csrf)
            . '<input type="hidden" name="action" value="' . Ui::e($action) . '">'
            . '<input type="hidden" name="bekraeftet" value="1">'
            . '<button type="submit" class="danger">' . Ui::e($button) . '</button><a class="button secondary" href="/brugere/' . $id . '">Annuller</a>'
            . '</form></section>', $user, $csrf, 'brugere');
    }

    /**
     * @param array<string, mixed>|null $user
     * @param string $home  linket "Til opkald" (fx med listens filtre)
     */
    public static function error(int $status, string $text, ?string $csrf = null, ?array $user = null, string $home = '/',
        ?string $title = null, ?string $details = null): string
    {
        $titles = [400 => 'Ugyldig forespørgsel', 403 => 'Ingen adgang', 404 => 'Ikke fundet', 405 => 'Metoden er ikke tilladt',
            500 => 'Der skete en fejl'];
        $title ??= $titles[$status] ?? 'Fejl';
        $link = $user !== null ? '<a class="button secondary" href="' . Ui::e($home) . '">Til opkald</a>'
            : '<a class="button secondary" href="/login">Til login</a>';
        // F006 (D4): en fast teknisk kode i en <details>, aldrig klientens egne værdier
        $more = $details !== null ? '<details><summary>Teknisk besked</summary><p><code>' . Ui::e($details) . '</code></p></details>' : '';
        return Ui::layout($title, '<section class="card narrow"><h1>' . Ui::e($title) . '</h1><p>' . Ui::e($text) . '</p>' . $more
            . '<div class="actions">' . $link . '</div></section>', $user, $csrf ?? '');
    }

    private static function activeBadge(bool $active): string
    {
        return $active ? '<span class="badge s-faerdig">Aktiv</span>' : '<span class="badge s-ventende">Deaktiveret</span>';
    }

    private static function roleName(string $role): string
    {
        return $role === 'admin' ? 'Administrator' : 'Bruger';
    }

    private static function roleSelect(string $current): string
    {
        $opts = '';
        foreach (Users::ROLES as $r) {
            $opts .= '<option value="' . $r . '"' . ($r === $current ? ' selected' : '') . '>' . self::roleName($r) . '</option>';
        }
        return '<label>Rolle<select name="role">' . $opts . '</select></label>';
    }
}
