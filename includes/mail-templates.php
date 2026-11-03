<?php
/**
 * The account e-mails (docs/MAIL.md, docs/ACCOUNT-SECURITY.md §13): the
 * seven approved in the Phase 2 review (e-mails 1–7, D1), ported unchanged
 * in layout and colour from the reviewed designs, their words through t() so
 * an English reader gets them in English.
 *
 *   signup         1   confirm the address of a new account (or, `existing`,
 *                      an existing account's unconfirmed address)
 *   link           2   an address added to an account that signs in with Google
 *   change         3   a changed address, sent to the NEW one
 *   reset          4   choose a new password
 *   password       5   the password was changed (or set: `set`)
 *   email_changed  6a  the address was changed, to the OLD one, with the revert link
 *   google_removed 6b  Google was disconnected
 *   already        7   somebody tried to register with an address that has an account
 *
 * E-mail-safe HTML (tables, inline styles, a pill button that survives every
 * client), light by default with a dark version where the mail app supports
 * prefers-color-scheme, and a plain-text alternative. The colours are the
 * app's own, sampled from theme.css and components.css in both themes; they
 * live here because an e-mail cannot read the app's stylesheet.
 *
 * Rendered when the message is put in the outbox, in the recipient's
 * language, so the outbox stores the finished message (encrypted).
 */
declare(strict_types=1);

require_once __DIR__ . '/i18n.php';

if (!function_exists('mail_template')) {

    /** The light colours (inline, the default). */
    function mail_tpl_colours(): array
    {
        return [
            'ground' => '#F6F1F6', 'card' => '#FCFBFC', 'card2' => '#FBF9FB', 'edge' => '#EBE8EA',
            'title' => '#1D1A1C', 'text' => '#555254', 'muted' => '#696668', 'foot' => '#6A6669',
            'btn1' => '#C8DBD3', 'btn2' => '#D1DDD7', 'btnEdge' => '#A9CDBC', 'btnText' => '#1D1A1C',
            'gold1' => '#E2DBCA', 'gold2' => '#E3DDD1', 'goldEdge' => '#D2C49F', 'goldText' => '#1D1A1C',
            'well' => '#F4F2F4', 'note' => '#F6F3EC', 'noteEdge' => '#E7DFC8', 'attention' => '#7A6731',
        ];
    }

    /** The dark colours, where the mail app follows prefers-color-scheme. */
    function mail_tpl_dark_css(): string
    {
        return '.o-body, .o-ground { background: #1C1620 !important; }'
            . '.o-card { background: #2A2530 !important; background-image: linear-gradient(135deg, #2C2730, #27212B) !important; border-color: #353039 !important; }'
            . '.o-title, .o-word, .o-strong, .o-link { color: #F7F7F5 !important; }'
            . '.o-text { color: #C8C6C9 !important; }'
            . '.o-muted { color: #AAA8AC !important; }'
            . '.o-foot { color: #A4A2A6 !important; }'
            . '.o-btn { background: #384A44 !important; background-image: linear-gradient(180deg, #3D534D, #323D3C) !important; border-color: #3E6A57 !important; }'
            . '.o-btn a { color: #F7F7F5 !important; }'
            . '.o-gold { background: #5B5144 !important; background-image: linear-gradient(180deg, #665C4A, #4E453D) !important; border-color: #7A6A4C !important; }'
            . '.o-gold a { color: #F7F7F5 !important; }'
            . '.o-well { background: #2E2832 !important; border-color: #353039 !important; }'
            . '.o-note { background: #332D2D !important; border-color: #5F523D !important; }'
            . '.o-attn { color: #BFA863 !important; }'
            . '.o-dot { background: #BFA863 !important; }';
    }

    function mail_tpl_e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A sentence in the reader's language with pieces of HTML in it: the
     * Dutch is translated (t()), escaped, and only then are the pieces put in.
     */
    function mail_tpl_html(string $dutch, string ...$pieces): string
    {
        return vsprintf(mail_tpl_e(t($dutch)), $pieces);
    }

    /** A pill action: green (the one way forward) or gold (the cautious one, in a security notice). */
    function mail_tpl_button(string $href, string $label, string $tone = 'green', string $margin = '0 0 16px 0'): string
    {
        $L = mail_tpl_colours();
        [$c1, $c2, $edge, $text, $cls] = $tone === 'gold'
            ? [$L['gold1'], $L['gold2'], $L['goldEdge'], $L['goldText'], 'o-gold']
            : [$L['btn1'], $L['btn2'], $L['btnEdge'], $L['btnText'], 'o-btn'];

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="o-btn-wrap" style="margin:' . $margin . ';border-collapse:separate;"><tr>'
            . '<td class="' . $cls . '" bgcolor="' . $c1 . '" style="border-radius:999px;background:' . $c1 . ';background-image:linear-gradient(180deg,' . $c1 . ',' . $c2 . ');border:1px solid ' . $edge . ';">'
            . '<a href="' . mail_tpl_e($href) . '" style="display:inline-block;padding:13px 24px;font-size:14px;line-height:18px;font-weight:600;color:' . $text . ';text-decoration:none;border-radius:999px;">' . mail_tpl_e($label) . '</a>'
            . '</td></tr></table>';
    }

    /** A Well (radius 20, hairline): the link to copy, or the facts of a change. */
    function mail_tpl_well(string $inner): string
    {
        $L = mail_tpl_colours();

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;border-collapse:separate;"><tr>'
            . '<td class="o-well" style="background:' . $L['well'] . ';border:1px solid ' . $L['edge'] . ';border-radius:20px;padding:12px 16px;">' . $inner . '</td></tr></table>';
    }

    /** A Note in the attention gold: what to do if this was not you. */
    function mail_tpl_note(string $inner): string
    {
        $L = mail_tpl_colours();

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;border-collapse:separate;"><tr>'
            . '<td class="o-note" style="background:' . $L['note'] . ';border:1px solid ' . $L['noteEdge'] . ';border-radius:20px;padding:14px 16px;">' . $inner . '</td></tr></table>';
    }

    function mail_tpl_p(string $html, string $kind = 'text', string $margin = '0 0 16px 0'): string
    {
        $L = mail_tpl_colours();
        [$size, $line, $color, $cls] = match ($kind) {
            'muted' => ['13px', '1.45', $L['muted'], 'o-muted'],
            'small' => ['13px', '1.5', $L['text'], 'o-text'],
            default => ['15px', '1.55', $L['text'], 'o-text'],
        };

        return '<p class="' . $cls . '" style="margin:' . $margin . ';font-size:' . $size . ';line-height:' . $line . ';color:' . $color . ';">' . $html . '</p>';
    }

    function mail_tpl_strong(string $s): string
    {
        return '<strong class="o-strong" style="color:' . mail_tpl_colours()['title'] . ';font-weight:600;">' . mail_tpl_e($s) . '</strong>';
    }

    /** The facts of a change, label and value per row, as Settings' fields show them. @param list<array{0: string, 1: string}> $rows */
    function mail_tpl_facts(array $rows): string
    {
        $L   = mail_tpl_colours();
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
        foreach ($rows as $i => [$label, $value]) {
            $pad  = $i === 0 ? '0 0 6px 0' : '6px 0';
            $out .= '<tr><td class="o-muted" style="padding:' . $pad . ';font-size:13px;line-height:1.4;color:' . $L['muted'] . ';width:38%;vertical-align:top;">' . mail_tpl_e($label) . '</td>'
                . '<td class="o-strong" style="padding:' . $pad . ';font-size:13px;line-height:1.4;color:' . $L['title'] . ';font-weight:600;vertical-align:top;">' . mail_tpl_e($value) . '</td></tr>';
        }

        return $out . '</table>';
    }

    /** "Werkt de knop niet? Open deze link:" and the link in a Well. */
    function mail_tpl_copy_link(string $url): string
    {
        return mail_tpl_p(mail_tpl_e(t('Werkt de knop niet? Open deze link:')), 'muted', '0 0 8px 0')
            . mail_tpl_well('<span class="o-text" style="font-family:ui-monospace,\'SF Mono\',Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:'
                . mail_tpl_colours()['text'] . ';word-break:break-all;">' . mail_tpl_e($url) . '</span>');
    }

    /**
     * The whole message around a card's inner HTML. $tag: a security
     * notice's label, or null for an e-mail that asks for an action.
     * $previewDark forces the dark colours (the review page only).
     */
    function mail_tpl_frame(string $subject, string $preheader, ?string $tag, string $title, string $inner, string $footer, string $logo, bool $previewDark = false): string
    {
        $L     = mail_tpl_colours();
        $dark  = mail_tpl_dark_css();
        $style = $previewDark ? $dark : '@media (prefers-color-scheme: dark) {' . $dark . '}';
        $lang  = i18n_locale();

        $tagHtml = $tag === null ? '' :
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0;"><tr>'
            . '<td style="vertical-align:middle;padding-right:8px;"><span class="o-dot" style="display:inline-block;width:6px;height:6px;border-radius:3px;background:' . $L['attention'] . ';"></span></td>'
            . '<td class="o-attn" style="vertical-align:middle;font-size:13px;line-height:1;font-weight:600;color:' . $L['attention'] . ';">' . mail_tpl_e($tag) . '</td></tr></table>';

        $font = "-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

        return '<!doctype html><html lang="' . mail_tpl_e($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . mail_tpl_e($subject) . '</title>'
            . '<style>'
            . ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
            . 'body{margin:0;padding:0;-webkit-text-size-adjust:100%;}'
            . 'a{color:inherit;}'
            . '@media (max-width:600px){.o-card{padding:24px 20px !important;}.o-outer{padding:20px 12px !important;}.o-btn-wrap{width:100% !important;}.o-btn-wrap td{text-align:center !important;}.o-btn-wrap a{display:block !important;}}'
            . $style
            . '</style></head>'
            . '<body class="o-body" style="margin:0;padding:0;background:' . $L['ground'] . ';font-family:' . $font . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . mail_tpl_e($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="o-ground" bgcolor="' . $L['ground'] . '" style="background:' . $L['ground'] . ';">'
            . '<tr><td align="center" class="o-outer" style="padding:32px 16px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;border-collapse:separate;">'
            . '<tr><td style="padding:0 4px 20px 4px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="vertical-align:middle;padding-right:10px;"><img src="' . mail_tpl_e($logo) . '" width="28" height="28" alt="" style="display:block;border:0;width:28px;height:28px;"></td>'
            . '<td class="o-word" style="vertical-align:middle;font-size:20px;line-height:1;font-weight:700;letter-spacing:-0.02em;color:' . $L['title'] . ';">Ownify</td>'
            . '</tr></table></td></tr>'
            . '<tr><td class="o-card" style="background:' . $L['card'] . ';background-image:linear-gradient(135deg,' . $L['card'] . ',' . $L['card2'] . ');border:1px solid ' . $L['edge'] . ';border-radius:28px;padding:32px 28px;">'
            . $tagHtml
            . '<h1 class="o-title" style="margin:0 0 12px 0;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-0.02em;color:' . $L['title'] . ';">' . mail_tpl_e($title) . '</h1>'
            . $inner
            . '</td></tr>'
            . '<tr><td class="o-foot" style="padding:20px 8px 0 8px;font-size:12px;line-height:1.5;color:' . $L['foot'] . ';">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Renders in another language for a moment — the recipient's — and puts
     * the request's back, whatever happens.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    function mail_tpl_in_locale(string $locale, callable $render): mixed
    {
        $state  = &i18n_state();
        $before = $state['locale'];
        $state['locale'] = i18n_valid($locale) ?? I18N_DEFAULT;

        try {
            return $render();
        } finally {
            $state['locale'] = $before;
        }
    }

    /** A moment as the e-mails say it: "donderdag 22 oktober 2026 om 21:14", Amsterdam time. */
    function mail_tpl_when(DateTimeImmutable $at): string
    {
        $local = $at->setTimezone(new DateTimeZone('Europe/Amsterdam'));

        return tf('%1$s om %2$s', i18n_date($local, 'weekday_day_month_year'), $local->format('H:i'));
    }

    /**
     * One account e-mail, finished: subject, HTML and plain text, in $locale.
     *
     * $f (the facts) — what each kind uses:
     *   username, email, link            1, 2, 3, 4
     *   new_email                         3
     *   old_email, new_masked, link       6a
     *   google_email                      6b
     *   when (DateTimeImmutable), via ('web'|'app')   5, 6a, 6b, 7
     *   existing (1), set (5), google_only (7)        the approved variants
     *
     * @param array<string, mixed> $f
     * @return array{subject: string, html: string, text: string}
     */
    function mail_template(string $kind, array $f, string $locale, bool $previewDark = false, ?string $logo = null): array
    {
        return mail_tpl_in_locale($locale, static fn (): array => mail_tpl_build($kind, $f, $previewDark, $logo));
    }

    /** @param array<string, mixed> $f */
    function mail_tpl_build(string $kind, array $f, bool $previewDark, ?string $logo): array
    {
        require_once __DIR__ . '/mailer.php';

        $s      = mail_settings();
        $base   = rtrim((string) ($s['public_url'] ?? ''), '/');
        $from   = (string) ($s['from'] ?? '');
        $logo ??= $base . '/assets/brand/ownify-icon-192.png';
        $forgot = $base . '/?wachtwoord-vergeten';
        $site   = $base . '/';

        $u      = (string) ($f['username'] ?? '');
        $mail   = (string) ($f['email'] ?? '');
        $link   = (string) ($f['link'] ?? '');
        $when   = isset($f['when']) && $f['when'] instanceof DateTimeImmutable ? mail_tpl_when($f['when']) : '';
        $via    = ($f['via'] ?? 'web') === 'app' ? t('de Ownify-app') : t('de website');
        $host   = (string) (parse_url($base, PHP_URL_HOST) ?: 'ownify.acits.nl');

        $footAction = mail_tpl_e(t('Je krijgt deze e-mail omdat dit adres is opgegeven bij Ownify.')) . '<br>'
            . mail_tpl_e(tf('Dit is een automatisch bericht van %s; antwoorden worden niet gelezen.', $from)) . '<br>Ownify · ' . mail_tpl_e($host);
        $footNotice = mail_tpl_e(t('Je krijgt deze beveiligingsmelding omdat dit adres bij je Ownify-account hoort. Zulke meldingen kun je niet uitzetten.')) . '<br>'
            . mail_tpl_e(tf('Dit is een automatisch bericht van %s; antwoorden worden niet gelezen.', $from)) . '<br>Ownify · ' . mail_tpl_e($host);

        $once24 = t('Deze link werkt 24 uur en één keer.');
        $notice = t('Beveiligingsmelding');
        $notYou = t('Was jij dit niet?');

        switch ($kind) {
            case 'signup':
                $existing  = !empty($f['existing']);
                $subject   = t('Bevestig je e-mailadres voor Ownify');
                $preheader = t('Eén tik, en je kunt je wachtwoord altijd herstellen.');
                $title     = t('Bevestig je e-mailadres');
                $intro     = $existing
                    ? mail_tpl_html('Bevestig dat %1$s van jou is, %2$s. Dan kun je je wachtwoord herstellen als je het vergeet.', mail_tpl_strong($mail), mail_tpl_strong($u))
                    : mail_tpl_html('Welkom bij Ownify, %1$s. Bevestig dat %2$s van jou is. Dan kun je je wachtwoord herstellen als je het vergeet.', mail_tpl_strong($u), mail_tpl_strong($mail));
                $introText = $existing
                    ? tf('Bevestig dat %1$s van jou is, %2$s. Dan kun je je wachtwoord herstellen als je het vergeet.', $mail, $u)
                    : tf('Welkom bij Ownify, %1$s. Bevestig dat %2$s van jou is. Dan kun je je wachtwoord herstellen als je het vergeet.', $u, $mail);
                $action    = t('Bevestig e-mailadres');
                $closing   = $existing
                    ? t('Was jij dit niet? Negeer deze e-mail. Er verandert dan niets.')
                    : t('Heb je geen Ownify-account gemaakt? Dan hoef je niets te doen: zonder bevestiging wordt dit adres nergens als het jouwe gebruikt.');

                $body = mail_tpl_p($intro) . mail_tpl_button($link, $action) . mail_tpl_p(mail_tpl_e($once24), 'muted')
                    . mail_tpl_copy_link($link) . mail_tpl_p(mail_tpl_e($closing), 'small', '0');
                $text = "$title\n\n$introText\n\n$action:\n$link\n\n$once24\n\n$closing";
                $foot = $footAction;
                $tag  = null;
                break;

            case 'link':
                $subject   = t('Koppel dit e-mailadres aan je Ownify-account');
                $preheader = t('Bevestig het adres; daarna kun je ook een wachtwoord instellen.');
                $title     = t('Koppel dit e-mailadres');
                $dutch     = 'Je wilt %1$s koppelen aan je Ownify-account %2$s. Bevestig dat dit adres van jou is. Daarna kun je in Instellingen ook een wachtwoord instellen; inloggen met Google blijft gewoon werken.';
                $action    = t('Koppel e-mailadres');
                $closing   = t('Was jij dit niet? Negeer deze e-mail. Er wordt dan niets gekoppeld.');

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($mail), mail_tpl_strong($u))) . mail_tpl_button($link, $action)
                    . mail_tpl_p(mail_tpl_e($once24), 'muted') . mail_tpl_copy_link($link) . mail_tpl_p(mail_tpl_e($closing), 'small', '0');
                $text = "$title\n\n" . tf($dutch, $mail, $u) . "\n\n$action:\n$link\n\n$once24\n\n$closing";
                $foot = $footAction;
                $tag  = null;
                break;

            case 'change':
                $new       = (string) ($f['new_email'] ?? $mail);
                $subject   = t('Bevestig je nieuwe e-mailadres voor Ownify');
                $preheader = t('Tot je bevestigt, blijft je huidige adres gekoppeld.');
                $title     = t('Bevestig je nieuwe e-mailadres');
                $dutch     = 'Je wilt het e-mailadres van je Ownify-account %1$s wijzigen in %2$s. Tot je bevestigt, blijft je huidige adres gekoppeld en log je daarmee in.';
                $action    = t('Bevestig nieuw adres');
                $closing   = t('Was jij dit niet? Negeer deze e-mail. Er verandert dan niets.');

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($u), mail_tpl_strong($new))) . mail_tpl_button($link, $action)
                    . mail_tpl_p(mail_tpl_e($once24), 'muted') . mail_tpl_copy_link($link) . mail_tpl_p(mail_tpl_e($closing), 'small', '0');
                $text = "$title\n\n" . tf($dutch, $u, $new) . "\n\n$action:\n$link\n\n$once24\n\n$closing";
                $foot = $footAction;
                $tag  = null;
                break;

            case 'reset':
                $subject   = t('Kies een nieuw wachtwoord voor Ownify');
                $preheader = t('Deze link werkt 1 uur. Heb jij dit niet gevraagd? Dan blijft alles zoals het is.');
                $title     = t('Kies een nieuw wachtwoord');
                $dutch     = 'Er is gevraagd om het wachtwoord van je Ownify-account %s opnieuw in te stellen.';
                $action    = t('Kies een nieuw wachtwoord');
                $once      = t('Deze link werkt 1 uur en één keer. Daarna word je overal uitgelogd, behalve op telefoons die alleen gezondheidsgegevens synchroniseren.');
                $closing   = t('Heb jij dit niet gevraagd? Negeer deze e-mail. Je wachtwoord blijft hetzelfde en niemand komt zonder deze link in je account.');

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($u))) . mail_tpl_button($link, $action)
                    . mail_tpl_p(mail_tpl_e($once), 'muted') . mail_tpl_copy_link($link) . mail_tpl_p(mail_tpl_e($closing), 'small', '0');
                $text = "$title\n\n" . tf($dutch, $u) . "\n\n$action:\n$link\n\n$once\n\n$closing";
                $foot = $footAction;
                $tag  = null;
                break;

            case 'password':
                $set       = !empty($f['set']);
                $subject   = $set ? t('Er is een wachtwoord ingesteld voor je Ownify-account') : t('Je Ownify-wachtwoord is gewijzigd');
                $preheader = t('Was jij dit niet? Herstel je wachtwoord meteen.');
                $title     = $set ? t('Je hebt een wachtwoord ingesteld') : t('Je wachtwoord is gewijzigd');
                $dutch     = $set
                    ? 'Er is een wachtwoord ingesteld voor je Ownify-account %s. Je kunt nu ook inloggen met je gebruikersnaam of e-mailadres en dit wachtwoord.'
                    : 'Het wachtwoord van je Ownify-account %s is gewijzigd. Je bent uitgelogd in andere browsers en in de app op andere telefoons. Telefoons die alleen gezondheidsgegevens synchroniseren, blijven gekoppeld.';
                $help      = t('Kies meteen een nieuw wachtwoord. De link daarvoor komt op dit adres.');
                $action    = t('Wachtwoord herstellen');
                $self      = t('Je hoeft niets te doen als jij dit zelf hebt gedaan.');
                $rows      = [[t('Account'), $u], [t('Wanneer'), $when], [t('Via'), $via]];

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($u))) . mail_tpl_well(mail_tpl_facts($rows))
                    . mail_tpl_note(mail_tpl_p(mail_tpl_strong($notYou) . ' ' . mail_tpl_e($help), 'small', '0 0 12px 0') . mail_tpl_button($forgot, $action, 'gold', '0'))
                    . mail_tpl_p(mail_tpl_e($self), 'muted', '0');
                $text = "$notice\n\n$title\n\n" . tf($dutch, $u) . "\n\n" . mail_tpl_rows_text($rows) . "\n\n$notYou $help\n$forgot\n\n$self";
                $foot = $footNotice;
                $tag  = $notice;
                break;

            case 'email_changed':
                $old       = (string) ($f['old_email'] ?? $mail);
                $masked    = (string) ($f['new_masked'] ?? '');
                $subject   = t('Het e-mailadres van je Ownify-account is gewijzigd');
                $preheader = t('Was jij dit niet? Zet je oude adres binnen 72 uur terug.');
                $title     = t('Je e-mailadres is gewijzigd');
                $dutch     = 'Het e-mailadres van je Ownify-account %s is gewijzigd. Dit adres hoort niet meer bij het account en krijgt er geen berichten meer over.';
                $help      = t('Zet je oude adres terug. Je wordt dan overal uitgelogd en kiest een nieuw wachtwoord. Deze link werkt 72 uur en één keer.');
                $action    = t('Zet mijn oude adres terug');
                $self      = t('Je hoeft niets te doen als jij dit zelf hebt gedaan.');
                $rows      = [[t('Account'), $u], [t('Oud adres'), $old], [t('Nieuw adres'), $masked], [t('Wanneer'), $when]];

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($u))) . mail_tpl_well(mail_tpl_facts($rows))
                    . mail_tpl_note(mail_tpl_p(mail_tpl_strong($notYou) . ' ' . mail_tpl_e($help), 'small', '0 0 12px 0') . mail_tpl_button($link, $action, 'gold', '0'))
                    . mail_tpl_p(mail_tpl_e($self), 'muted', '0');
                $text = "$notice\n\n$title\n\n" . tf($dutch, $u) . "\n\n" . mail_tpl_rows_text($rows) . "\n\n$notYou $help\n$link\n\n$self";
                $foot = $footNotice;
                $tag  = $notice;
                break;

            case 'google_removed':
                $google    = (string) ($f['google_email'] ?? '');
                $subject   = t('Google is losgekoppeld van je Ownify-account');
                $preheader = t('Je logt voortaan in met je gebruikersnaam of e-mailadres en je wachtwoord.');
                $title     = t('Google is losgekoppeld');
                $dutch     = 'Het Google-account %1$s is losgekoppeld van je Ownify-account %2$s. Je logt voortaan in met je gebruikersnaam of e-mailadres en je wachtwoord. Je bent uitgelogd in andere browsers en in de app op andere telefoons.';
                $help      = t('Kies meteen een nieuw wachtwoord. De link daarvoor komt op dit adres.');
                $action    = t('Wachtwoord herstellen');
                $where     = t('Ownify uit je Google-account halen kan in Google: Beveiliging › Je verbindingen met apps en services van derden.');
                $rows      = [[t('Account'), $u], [t('Losgekoppeld'), $google], [t('Wanneer'), $when], [t('Via'), $via]];

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($google), mail_tpl_strong($u))) . mail_tpl_well(mail_tpl_facts($rows))
                    . mail_tpl_note(mail_tpl_p(mail_tpl_strong($notYou) . ' ' . mail_tpl_e($help), 'small', '0 0 12px 0') . mail_tpl_button($forgot, $action, 'gold', '0'))
                    . mail_tpl_p(mail_tpl_e($where), 'muted', '0');
                $text = "$notice\n\n$title\n\n" . tf($dutch, $google, $u) . "\n\n" . mail_tpl_rows_text($rows) . "\n\n$notYou $help\n$forgot\n\n$where";
                $foot = $footNotice;
                $tag  = $notice;
                break;

            case 'already':
                $googleOnly = !empty($f['google_only']);
                $subject    = t('Je hebt al een Ownify-account');
                $preheader  = t('Iemand probeerde met dit adres een nieuw account te maken.');
                $title      = t('Je hebt al een Ownify-account');
                $dutch      = 'Er is geprobeerd een nieuw Ownify-account te maken met %s. Dit adres hoort al bij een account, dus er is geen nieuw account gemaakt.';
                $was        = $googleOnly
                    ? t('Was jij dat? Log dan in met Google.')
                    : t('Was jij dat? Log dan in met je gebruikersnaam of e-mailadres. Weet je je wachtwoord niet meer, kies dan een nieuw.');
                $action     = $googleOnly ? t('Doorgaan met Google') : t('Wachtwoord herstellen');
                $href       = $googleOnly ? $site : $forgot;
                $closing    = t('Was jij dit niet? Dan hoef je niets te doen. Je account en je wachtwoord zijn niet gewijzigd.');
                $rows       = [[t('Wanneer'), $when]];

                $body = mail_tpl_p(mail_tpl_html($dutch, mail_tpl_strong($mail))) . mail_tpl_well(mail_tpl_facts($rows))
                    . mail_tpl_p(mail_tpl_e($was), 'text', '0 0 16px 0') . mail_tpl_button($href, $action)
                    . mail_tpl_p(mail_tpl_e($closing), 'muted', '0');
                $text = "$title\n\n" . tf($dutch, $mail) . "\n\n" . mail_tpl_rows_text($rows) . "\n\n$was\n$href\n\n$closing";
                $foot = $footAction;
                $tag  = null;
                break;

            default:
                throw new InvalidArgumentException('Unknown mail template: ' . $kind);
        }

        return [
            'subject' => $subject,
            'html'    => mail_tpl_frame($subject, $preheader, $tag, $title, $body, $foot, $logo, $previewDark),
            'text'    => $text . "\n\n—\n" . str_replace('<br>', "\n", html_entity_decode(strip_tags($foot, '<br>'), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ];
    }

    /** The facts as the plain-text part says them: "Account: sanne". @param list<array{0: string, 1: string}> $rows */
    function mail_tpl_rows_text(array $rows): string
    {
        return implode("\n", array_map(static fn (array $r): string => $r[0] . ': ' . $r[1], $rows));
    }
}
