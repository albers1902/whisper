<?php
declare(strict_types=1);

namespace Whisper;

/**
 * Siderne som PHP-strenge (ingen template-filer: de er typede, og PHPStan kan læse dem). Al dynamisk tekst går
 * gennem e(). Fælles ramme med header, menu og log ud, som F002–F004 bygger videre på.
 */
final class View
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed>|null $user */
    public static function layout(string $title, string $body, ?array $user, string $csrf): string
    {
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
            . '<link rel="stylesheet" href="/assets/app.css"></head><body>'
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

    public static function login(string $csrf, ?string $error, string $email): string
    {
        return self::layout('Log ind', '<section class="card narrow"><h1>Log ind</h1>' . self::message($error)
            . '<form method="post" action="/login">' . self::csrfField($csrf)
            . '<label>E-mail<input type="email" name="email" value="' . self::e($email) . '" required autofocus autocomplete="username"></label>'
            . '<label>Adgangskode<input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button type="submit">Log ind</button></form></section>', null, $csrf);
    }

    /** @param array<string, mixed> $user */
    public static function home(array $user, string $csrf, ?string $ok): string
    {
        return self::layout('Opkald', '<h1>Opkald</h1>' . self::message(null, $ok) . '<p>Opkald kommer her (F002)</p>', $user, $csrf);
    }

    /** @param array<string, mixed> $user */
    public static function account(array $user, string $csrf, ?string $error, ?string $ok): string
    {
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
            . '<button type="submit">Skift adgangskode</button></form></section>', $user, $csrf);
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $users
     */
    public static function users(array $user, string $csrf, array $users, ?string $ok): string
    {
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
    public static function oneTimePassword(array $user, string $csrf, array $target, string $password, string $heading): string
    {
        return self::layout($heading, '<section class="card narrow"><h1>' . self::e($heading) . '</h1>'
            . '<p>Engangsadgangskode til <strong>' . self::e((string) $target['email']) . '</strong>:</p>'
            . '<p><input class="otp" id="engangskode" type="text" readonly value="' . self::e($password) . '" aria-label="Engangsadgangskode"></p>'
            . '<p class="msg info">Kopiér den nu. Den vises kun denne ene gang, og brugeren skal skifte den ved første login.</p>'
            . '<p><a href="/brugere/' . (int) $target['id'] . '">Til brugeren</a> · <a href="/brugere">Til listen</a></p></section>',
            $user, $csrf);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    public static function userEdit(array $user, string $csrf, array $target, ?string $error, ?string $ok): string
    {
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
