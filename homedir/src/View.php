<?php
declare(strict_types=1);

namespace Whisper;

use Whisper\Mcp\Tokens;

/**
 * Siderne som PHP-strenge (ingen template-filer: de er typede, og PHPStan kan læse dem). Al dynamisk tekst går
 * gennem e(). Fælles ramme med header, menu og log ud, som F002–F004 bygger videre på.
 * Med F005_ui tændt sender hver side videre til det nye UI (Ui, UiPages) som det første; resten er det gamle UI.
 */
final class View
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed>|null $user
     * @param string $section  kun det nye UI (F005) bruger den: opkald|brugere|konto|''
     */
    public static function layout(string $title, string $body, ?array $user, string $csrf, string $section = ''): string
    {
        if (Ui::modern()) {
            return Ui::layout($title, $body, $user, $csrf, $section);
        }
        $nav = '';
        if ($user !== null) {
            $items = ['<a href="/">Opkald</a>'];
            if (($user['role'] ?? '') === 'admin') {
                $items[] = '<a href="/brugere">Brugere</a>';
            }
            $items[] = '<a href="/konto">Konto</a>';
            $items[] = '<form method="post" action="/logout" class="inline">' . self::csrfField($csrf)
                . '<button type="submit" class="link">Log ud</button></form>';
            $nav = '<nav>' . implode(' ', $items) . '<span class="who">' . self::e((string) ($user['name'] ?? '')) . '</span></nav>';
        }
        return '<!doctype html><html lang="da"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . ' · Whisper</title>'
            . '<link rel="stylesheet" href="/assets/legacy.css"></head><body>'
            . '<header class="top"><a class="brand" href="/">Whisper</a>' . $nav . '</header>'
            . '<main>' . $body . '</main></body></html>';
    }

    public static function csrfField(string $csrf): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . self::e($csrf) . '">';
    }

    public static function message(?string $error, ?string $ok = null): string
    {
        $out = '';
        if ($error !== null && $error !== '') {
            $out .= '<p class="msg error" role="alert">' . self::e($error) . '</p>';
        }
        if ($ok !== null && $ok !== '') {
            $out .= '<p class="msg ok" role="status">' . self::e($ok) . '</p>';
        }
        return $out;
    }

    /** @param ?string $context  F006: teksten om en ventende forbindelse (kun det nye UI; OAuth kræver F005) */
    public static function login(string $csrf, ?string $error, string $email, ?string $context = null): string
    {
        if (Ui::modern()) {
            return UiPages::login($csrf, $error, $email, $context);
        }
        return self::layout('Log ind', '<section class="card narrow"><h1>Log ind</h1>' . self::message($error)
            . '<form method="post" action="/login">' . self::csrfField($csrf)
            . '<label>E-mail<input type="email" name="email" value="' . self::e($email) . '" required autofocus autocomplete="username"></label>'
            . '<label>Adgangskode<input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button type="submit">Log ind</button></form></section>', null, $csrf);
    }

    /** @param array<string, mixed> $user */
    public static function home(array $user, string $csrf, ?string $ok): string
    {
        if (Ui::modern()) {
            return UiPages::home($user, $csrf, $ok);
        }
        return self::layout('Opkald', '<h1>Opkald</h1>' . self::message(null, $ok) . '<p>Opkald kommer her (F002)</p>', $user, $csrf);
    }

    /**
     * @param array<string, mixed> $user
     * @param array{tokens: list<array<string, mixed>>, origin: string, new: array{token: string, name: string}|null,
     *     error: ?string, name: string, apps: list<array<string, mixed>>|null, resume?: bool}|null $mcp  F003; null = skjult
     */
    public static function account(array $user, string $csrf, ?string $error, ?string $ok, ?array $mcp = null): string
    {
        if (Ui::modern()) {
            return UiPages::account($user, $csrf, $error, $ok, $mcp);
        }
        $intro = (int) ($user['must_change_password'] ?? 0) === 1
            ? '<p class="msg info">Du skal vælge en ny adgangskode, før du kan bruge Whisper.</p>' : '';
        return self::layout('Konto', '<section class="card narrow"><h1>Konto</h1>'
            . '<p>' . self::e((string) $user['name']) . ' · ' . self::e((string) $user['email']) . '</p>'
            . $intro . self::message($error, $ok)
            . '<h2>Skift adgangskode</h2><form method="post" action="/konto">' . self::csrfField($csrf)
            . '<label>Nuværende adgangskode<input type="password" name="current" required autocomplete="current-password"></label>'
            . '<label>Ny adgangskode (mindst ' . Password::MIN_LENGTH . ' tegn)<input type="password" name="new" required minlength="'
            . Password::MIN_LENGTH . '" autocomplete="new-password"></label>'
            . '<label>Gentag ny adgangskode<input type="password" name="confirm" required minlength="' . Password::MIN_LENGTH
            . '" autocomplete="new-password"></label>'
            . '<button type="submit">Skift adgangskode</button></form></section>'
            . ($mcp !== null ? self::mcpSection($csrf, $mcp) : ''), $user, $csrf);
    }

    /** Prompt-injection (DECISIONS #22): transcripts er kundetale, og Claude kan have andre værktøjer i samme samtale. */
    public const MCP_WARNING = 'Kombinér ikke Whisper-nøglen med værktøjer, der kan sende mails eller ændre data, i samme Claude-samtale.';
    /** Svaret på "Opret nøgle" er bevidst ikke en redirect (nøglen vises kun der), så en genindlæsning sender igen. */
    public const MCP_RELOAD = 'Genindlæs ikke siden, når nøglen er oprettet. Det opretter endnu en nøgle.';

    /** Version af mcp-remote i konfigurationsblokken: låst, og ældre end et par uger (F003 v0 §4c). */
    public const MCP_REMOTE = 'mcp-remote@0.15.2';

    /**
     * Konfigurationsblokken til claude_desktop_config.json. Nøglen ligger i env og headeren skrives uden mellemrum,
     * fordi Claude Desktop på Windows ikke escaper mellemrum i args (mcp-remote's README).
     */
    public static function mcpConfig(string $origin, string $token): string
    {
        $cfg = ['mcpServers' => ['whisper' => [
            'command' => 'npx',
            'args'    => ['-y', self::MCP_REMOTE, $origin . '/mcp', '--transport', 'http-only', '--header', 'Authorization:${WHISPER_AUTH}'],
            'env'     => ['WHISPER_AUTH' => 'Bearer ' . $token],
        ]]];
        return (string) json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sektionen "Claude Desktop (MCP)" efter design F005 §3.6. Kopiér-knapper og app.js kommer i F005: nøglen står i
     * et readonly-felt og konfigurationen i en <pre>, som brugeren selv markerer. Intet inline script eller style (CSP).
     *
     * @param array{tokens: list<array<string, mixed>>, origin: string, new: array{token: string, name: string}|null,
     *     error: ?string, name: string, apps: list<array<string, mixed>>|null, resume?: bool} $mcp
     */
    private static function mcpSection(string $csrf, array $mcp): string
    {
        $origin = $mcp['origin'] !== '' ? $mcp['origin'] : 'https://<din-whisper-adresse>';
        $count = count($mcp['tokens']);
        $rows = '';
        foreach ($mcp['tokens'] as $t) {
            $name = (string) $t['name'];
            $used = $t['last_used_at'] ?? null;
            $rows .= '<tr><td>' . self::e($name) . '</td><td><code>' . self::e((string) $t['prefix']) . '…</code></td>'
                . '<td>' . self::e(self::dateTime($t['created_at'] ?? null)) . '</td>'
                . '<td>' . (is_string($used) && $used !== '' ? self::e(self::dateTime($used)) : 'Aldrig') . '</td>'
                . '<td><form method="post" action="/konto/mcp-noegle/' . (int) $t['id'] . '/tilbagekald" class="inline">' . self::csrfField($csrf)
                . '<button type="submit" class="danger" aria-label="' . self::e('Tilbagekald nøglen «' . $name . '»') . '">Tilbagekald</button>'
                . '</form></td></tr>';
        }
        $list = $rows === '' ? '<p class="muted">Du har ingen nøgler endnu.</p>'
            : '<table><thead><tr><th>Navn</th><th>Nøgle</th><th>Oprettet</th><th>Sidst brugt</th><th><span class="sr-only">Handling</span></th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>';

        $new = '';
        if ($mcp['new'] !== null) {
            $new = '<div class="msg ok" role="status"><p><strong>✓ Nøglen «' . self::e($mcp['new']['name']) . '» er oprettet</strong></p>'
                . '<p>Kopiér nøglen nu. Den vises kun denne ene gang.</p>'
                . '<p><input class="otp" id="mcp-noegle" type="text" readonly value="' . self::e($mcp['new']['token']) . '" aria-label="Din nye MCP-nøgle"></p>'
                . '<h3>Sådan forbinder du Claude Desktop</h3><ol>'
                . '<li>Installér Node.js (LTS) fra <a href="https://nodejs.org/">nodejs.org</a>, hvis du ikke har det.</li>'
                . '<li>Åbn Claude Desktop → Indstillinger → Udvikler → Rediger konfiguration.<br>'
                . 'Windows: <code>%APPDATA%\\Claude\\claude_desktop_config.json</code><br>'
                . 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code></li>'
                . '<li>Indsæt blokken herunder. Har filen allerede "mcpServers", så tilføj kun "whisper"-delen.'
                . '<pre class="config"><code tabindex="0" aria-label="Konfiguration til Claude Desktop">'
                . self::e(self::mcpConfig($origin, $mcp['new']['token'])) . '</code></pre></li>'
                . '<li>Genstart Claude Desktop helt (luk den også i proceslinjen).</li>'
                . '<li>Spørg fx: "Find opkald fra i går, hvor kunden nævner flytning."</li></ol>'
                . '<p>Får du en login-side, en browserfane eller en OAuth-fejl, når Claude Desktop forbinder, er nøglen forkert eller '
                . 'tilbagekaldt. Opret en ny nøgle, og skift den i filen.</p></div>';
        }

        $form = '';
        if ($count >= Tokens::MAX_ACTIVE) {
            $form = '<p class="muted">' . self::e(Tokens::FULL) . '</p>';
        } else {
            $invalid = $mcp['error'] !== null && in_array($mcp['error'], [Tokens::NAME_EMPTY, Tokens::NAME_LONG], true);
            $form = '<h3>Opret ny nøgle</h3>'
                . ($mcp['error'] !== null && $mcp['error'] !== Tokens::FULL
                    ? '<p class="msg error" role="alert" id="mcp-navn-fejl">' . self::e($mcp['error']) . '</p>' : '')
                . '<form method="post" action="/konto/mcp-noegle">' . self::csrfField($csrf)
                . '<label>Navn på nøglen<input type="text" name="name" maxlength="80" value="' . self::e($mcp['name']) . '"'
                . ($invalid ? ' aria-invalid="true" aria-describedby="mcp-navn-fejl mcp-navn-hjaelp"' : ' aria-describedby="mcp-navn-hjaelp"') . '>'
                . '<span class="hint" id="mcp-navn-hjaelp">Fx navnet på computeren, så du kan kende nøglen igen.</span></label>'
                . '<button type="submit">Opret nøgle</button></form>'
                . '<p class="hint">' . self::e(self::MCP_RELOAD) . '</p>';
        }
        return '<section class="card narrow mcp" aria-labelledby="mcp-overskrift"><h2 id="mcp-overskrift">Claude Desktop (MCP)</h2>'
            . $new
            . '<p>Forbind Claude Desktop til Whisper, så Claude kan søge i og læse transcripts.</p>'
            . '<p>Claude får kun læseadgang. Nøglen er personlig – del den ikke.</p>'
            . '<p class="msg info">' . self::e(self::MCP_WARNING) . '</p>'
            . '<h3>Dine nøgler (' . $count . ' af ' . Tokens::MAX_ACTIVE . ')</h3>' . $list . $form
            . '<p class="muted">Har din Claude-konto "Request headers" under custom connectors, kan du i stedet tilføje <code>'
            . self::e($origin) . '/mcp</code> med headeren <code>authorization</code> = <code>Bearer &lt;nøgle&gt;</code>.</p></section>';
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $users
     */
    public static function users(array $user, string $csrf, array $users, ?string $ok): string
    {
        if (Ui::modern()) {
            return UiPages::users($user, $csrf, $users, $ok);
        }
        $rows = '';
        foreach ($users as $u) {
            $rows .= '<tr><td>' . self::e((string) $u['name']) . '</td><td>' . self::e((string) $u['email']) . '</td>'
                . '<td>' . self::roleName((string) $u['role']) . '</td>'
                . '<td>' . ((int) $u['active'] === 1 ? 'Aktiv' : '<span class="muted">Deaktiveret</span>') . '</td>'
                . '<td>' . self::e(self::dateTime($u['last_login_at'] ?? null)) . '</td>'
                . '<td><a href="/brugere/' . (int) $u['id'] . '">Rediger</a></td></tr>';
        }
        return self::layout('Brugere', '<div class="row"><h1>Brugere</h1><a class="button" href="/brugere/ny">Opret bruger</a></div>'
            . self::message(null, $ok)
            . '<table><thead><tr><th>Navn</th><th>E-mail</th><th>Rolle</th><th>Status</th><th>Sidste login</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>', $user, $csrf);
    }

    /**
     * @param array<string, mixed> $user
     * @param array{email: string, name: string, role: string} $values
     */
    public static function userNew(array $user, string $csrf, ?string $error, array $values): string
    {
        if (Ui::modern()) {
            return UiPages::userNew($user, $csrf, $error, $values);
        }
        return self::layout('Opret bruger', '<section class="card narrow"><h1>Opret bruger</h1>' . self::message($error)
            . '<form method="post" action="/brugere/ny">' . self::csrfField($csrf)
            . '<label>E-mail<input type="email" name="email" value="' . self::e($values['email']) . '" required maxlength="190"></label>'
            . '<label>Navn<input type="text" name="name" value="' . self::e($values['name']) . '" required maxlength="120"></label>'
            . self::roleSelect($values['role'])
            . '<button type="submit">Opret bruger</button> <a href="/brugere">Annuller</a></form></section>', $user, $csrf);
    }

    /**
     * Engangskoden vises kun i dette svar (som også er no-store). Den gemmes ingen steder i klartekst.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function oneTimePassword(array $user, string $csrf, array $target, string $password, string $heading,
        ?string $note = null): string
    {
        if (Ui::modern()) {
            return UiPages::oneTimePassword($user, $csrf, $target, $password, $heading, $note);
        }
        return self::layout($heading, '<section class="card narrow"><h1>' . self::e($heading) . '</h1>'
            . '<p>Engangsadgangskode til <strong>' . self::e((string) $target['email']) . '</strong>:</p>'
            . '<p><input class="otp" id="engangskode" type="text" readonly value="' . self::e($password) . '" aria-label="Engangsadgangskode"></p>'
            . '<p class="msg info">Kopiér den nu. Den vises kun denne ene gang, og brugeren skal skifte den ved første login.</p>'
            . ($note !== null ? '<p class="msg info">' . self::e($note) . '</p>' : '')
            . '<p><a href="/brugere/' . (int) $target['id'] . '">Til brugeren</a> · <a href="/brugere">Til listen</a></p></section>',
            $user, $csrf);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function userEdit(array $user, string $csrf, array $target, ?string $error, ?string $ok): string
    {
        if (Ui::modern()) {
            return UiPages::userEdit($user, $csrf, $target, $error, $ok);
        }
        $id = (int) $target['id'];
        $active = (int) $target['active'] === 1;
        $self = (int) $target['id'] === (int) ($user['id'] ?? 0);
        // Nulstil og Deaktiver går via en bekræftelsesside (uden JS), så et fejlklik ikke ændrer noget
        $access = [];
        if ($self) {
            $access[] = '<p>Din egen adgangskode skifter du under <a href="/konto">Konto</a>.</p>';
        } else {
            $access[] = '<a class="button" href="/brugere/' . $id . '/nulstil">Nulstil adgangskode …</a>';
            $access[] = $active
                ? '<a class="button danger" href="/brugere/' . $id . '/deaktiver">Deaktiver bruger …</a>'
                : '<form method="post" action="/brugere/' . $id . '" class="inline">' . self::csrfField($csrf)
                    . '<button type="submit" name="action" value="activate">Genaktiver bruger</button></form>';
        }
        return self::layout('Rediger bruger', '<section class="card narrow"><h1>' . self::e((string) $target['name']) . '</h1>'
            . '<p>' . self::e((string) $target['email']) . ' · ' . ($active ? 'Aktiv' : 'Deaktiveret') . '</p>'
            . self::message($error, $ok)
            . '<form method="post" action="/brugere/' . $id . '">' . self::csrfField($csrf)
            . '<label>Navn<input type="text" name="name" value="' . self::e((string) $target['name']) . '" required maxlength="120"></label>'
            . self::roleSelect((string) $target['role'])
            . '<button type="submit" name="action" value="save">Gem</button></form>'
            . '<p class="muted">Sidste login: ' . self::e(self::dateTime($target['last_login_at'] ?? null)) . '</p>'
            . '<h2>Adgang</h2><div class="actions">' . implode(' ', $access) . '</div>'
            . '<p><a href="/brugere">Til listen</a></p></section>', $user, $csrf);
    }

    /**
     * Bekræftelse før en handling, der ikke kan fortrydes (nulstil) eller logger brugeren ud (deaktiver).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function confirm(array $user, string $csrf, array $target, string $action): string
    {
        if (Ui::modern()) {
            return UiPages::confirm($user, $csrf, $target, $action);
        }
        $id = (int) $target['id'];
        $name = self::e((string) $target['name']);
        [$title, $text, $button] = $action === 'reset'
            ? ['Nulstil adgangskode', "Nulstil adgangskoden for <strong>$name</strong>? Den nuværende adgangskode holder op med at "
                . "virke med det samme, $name logges ud, og du får en engangskode, som kun vises én gang.", 'Ja, nulstil adgangskoden']
            : ['Deaktiver bruger', "Deaktiver <strong>$name</strong>? $name logges ud med det samme og kan ikke logge ind, "
                . 'før en administrator genaktiverer brugeren.', 'Ja, deaktiver brugeren'];
        return self::layout($title, '<section class="card narrow"><h1>' . self::e($title) . '</h1><p>' . $text . '</p>'
            . '<form method="post" action="/brugere/' . $id . '" class="actions">' . self::csrfField($csrf)
            . '<input type="hidden" name="action" value="' . self::e($action) . '">'
            . '<input type="hidden" name="bekraeftet" value="1">'
            . '<button type="submit" class="danger">' . self::e($button) . '</button> <a href="/brugere/' . $id . '">Annuller</a>'
            . '</form></section>', $user, $csrf);
    }

    /** Databasens DATETIME som dd.mm.åååå tt:mm; tom værdi som "—". */
    public static function dateTime(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '—';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        return $d === false ? '—' : $d->format('d.m.Y H:i');
    }

    /** @param array<string, mixed>|null $user */
    public static function error(int $status, string $text, ?string $csrf = null, ?array $user = null): string
    {
        if (Ui::modern()) {
            return UiPages::error($status, $text, $csrf, $user);
        }
        $titles = [400 => 'Ugyldig forespørgsel', 403 => 'Ingen adgang', 404 => 'Ikke fundet', 405 => 'Metoden er ikke tilladt',
            500 => 'Der skete en fejl'];
        $title = $titles[$status] ?? 'Fejl';
        return self::layout($title, '<section class="card narrow"><h1>' . self::e($title) . '</h1><p>' . self::e($text)
            . '</p><p><a href="/">Til forsiden</a></p></section>', $user, $csrf ?? '');
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
