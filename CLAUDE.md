# Cluvo WordPress-Plugin (cluvo-wp)

Bettet Formulare, Veranstaltungen und News eines oder mehrerer Cluvo-Vereine per Shortcode in WordPress ein. Spricht
die **Plugin-API** von Cluvo (`https://app.cluvo.ch/api/v1`), Gegenstück im Hauptprojekt `~/Projects/cluvo`
(dort `routes/api.php`, `app/Http/Controllers/Api/`, `app/Actions/ProcessFormSubmission.php`).
Öffentliches Repo `digitalifyCH/cluvo-wp`. PHP ≥ 8.0, WordPress ≥ 6.0, kein Composer, kein Build-Schritt fürs JS/CSS.

## Aufbau

| Datei | Aufgabe |
| --- | --- |
| [cluvo.php](cluvo.php) | Header (`Version`, `Update URI`), Konstanten, lädt die Klassen |
| [Cluvo_Plugin](includes/class-cluvo-plugin.php) | Verdrahtet alles, Textdomain, Assets nur auf Seiten mit Shortcode |
| [Cluvo_Client](includes/class-cluvo-client.php) | HTTP zur API, Konten (Option `cluvo_settings`), Cache, Fehlermeldungen |
| [Cluvo_Settings](includes/class-cluvo-settings.php) | Einstellungen → Cluvo: Vereine/API-Keys, «Testen», Cache, Updates, Shortcode-Übersicht |
| [Cluvo_Shortcodes](includes/class-cluvo-shortcodes.php) | `[cluvo_form]`, `[cluvo_events]`, `[cluvo_news]` |
| [Cluvo_Form_Renderer](includes/class-cluvo-form-renderer.php) | Formular-Schema der API → HTML wie das Formular auf der Cluvo-Webseite |
| [Cluvo_Rest](includes/class-cluvo-rest.php) | Proxy `POST /wp-json/cluvo/v1/accounts/{id}/forms/{slug}/submit` |
| [Cluvo_Updater](includes/class-cluvo-updater.php) | Updates von wp.cluvo.ch |
| [assets/cluvo.js](assets/cluvo.js), [cluvo.css](assets/cluvo.css) | Formularlogik und Stile (alles unter `.cluvo`, CSS-Variablen `--cluvo-*`) |

## Verbindung zu Cluvo

- **Ein API-Key pro Verein** (Multi-Account, Dachverbände), erstellt in Cluvo unter Einstellungen → Integration. Konten
  haben eine stabile ID (`next_id`), im Shortcode `account="<id>"`; bei nur einem Konto darf das Attribut fehlen.
  Gespeicherte Keys werden nie mehr ausgegeben – leeres Feld beim Speichern heisst «Key behalten».
- Endpunkte: `forms`, `forms/{slug}`, `forms/{slug}/submit`, `events`, `news`, `news/{slug}`. Sprache über
  `Accept-Language` aus der WordPress-Locale.
- **Cluvo Basic sperrt die Plugin-API** (403 mit Meldung) – `Cluvo_Client::response_error()` reicht die Meldung durch.
- Lokal gegen ddev: in `wp-config.php` `define( 'CLUVO_API_BASE_URL', 'https://app.cluvo.ddev.site/api/v1' );`, das
  ddev-Zertifikat per Filter `cluvo_http_request_args` (`sslverify => false`) akzeptieren.
- **Cache:** GETs als Transient pro Konto, Key, Locale und Pfad (Einstellung `cache_ttl`, Standard 600 s). Ist Cluvo nicht
  erreichbar (0, 429, 5xx), gilt bis zu einem Tag der letzte gute Stand (`_s`-Transient); 401/403/404 löschen ihn – ein
  deaktiviertes Formular soll nicht wieder auftauchen. «Cache leeren» erhöht `cluvo_cache_generation`.

## Formulare

Das Formular im Plugin muss **dem Web-Formular in Cluvo entsprechen**: Namensblock (Privatperson/Unternehmen,
`contact_type`, Firmenname, Vor-/Nachname), Kontaktangaben, `membership` (fixe Mitgliedschaften als Auswahlkarten) **oder**
`membership_fee` (freier Beitrag mit Beträgen pro Intervall), Zusatzfelder `cf_{id}` inkl. Wiederholungsfelder
(`sub_fields`), Zwischentitel `html_*` (nur Text), Trennlinien `divider_*`, Breiten `full|half|third|two-thirds`.
Die Akzentfarbe kommt aus der Vereinsfarbe (`organization.primary_color`, Filter `cluvo_accent_color`).
Validiert wird massgeblich in Cluvo; Fehler kommen als 422 und werden pro Feld angezeigt.

**Fallen:**
- Neue Feldtypen im Formular-Builder von Cluvo hier nachziehen: Renderer (PHP) **und** `cluvo.js` – und ein neues
  Standardfeld zusätzlich in `Cluvo_Rest::FIELDS`, sonst verwirft der Proxy es stillschweigend (Allowlist).
- Der Proxy ist absichtlich öffentlich (`permission_callback` `__return_true`); Schutz über Honeypot `cluvo_hp`, Limit pro
  IP und Konto (Filter `cluvo_submit_rate_limit`, `cluvo_submit_rate_window`, `cluvo_client_ip` hinter Proxys) und
  `MAX_PAYLOAD_BYTES`. Der API-Key verlässt den Server nie.
- Requests folgen nie Weiterleitungen (`redirection => 0`) – sonst ginge der Bearer-Token an ein fremdes Ziel.

## Veranstaltungen und News

`[cluvo_events limit from to category]` (Serientermine einzeln gezählt, Standard ab heute 12 Monate, `per_page` = Alias),
`[cluvo_news limit category pagination]`. Die Einzelansicht eines Artikels und die Seiten laufen über die Query-Parameter
`cluvo_article` und `cluvo_page` auf derselben WordPress-Seite. Fehlermeldungen sehen Besucher neutral, Administratoren
mit Ursache.

## Updates und Release

Updates kommen von **wp.cluvo.ch** (VPS von Cluvo, Caddy liefert `/var/www/wp-updates/cluvo/` statisch). Der Header
`Update URI: https://wp.cluvo.ch/cluvo/` lässt WordPress den Filter `update_plugins_wp.cluvo.ch` aufrufen und hält
wordpress.org draussen. `Cluvo_Updater` liest `info.json` (6 h Cache), installiert nur ZIPs unter derselben Adresse und
zeigt den Stand unter Einstellungen → Cluvo («Jetzt nach Updates suchen», auch in der Plugin-Liste).

**Release:** Version in `cluvo.php` (Header **und** `CLUVO_VERSION`) und `readme.txt` (`Stable tag`) erhöhen, Changelog
`= X.Y.Z =` in `readme.txt`, auf `main` pushen. Die Action [release.yml](.github/workflows/release.yml) baut mit
[bin/build.php](bin/build.php) (ZIP mit Ordner `cluvo/`, `info.json`, Icons aus `release-assets/`, Download-Seite
`index.html` für `wp.cluvo.ch/cluvo/`) und veröffentlicht nur,
wenn die Version neuer ist als die auf wp.cluvo.ch – plus GitHub-Release. Details: [RELEASING.md](RELEASING.md).

**Fallen:**
- `Update URI` nie ändern, sonst findet keine Installation mehr Updates. Testserver über `CLUVO_UPDATE_URL`
  (wp-config.php) und `CLUVO_UPDATE_BASE=… php bin/build.php` – nie unter `/cluvo/` testen: Was dort liegt, hält die
  Action für veröffentlicht.
- Die GitHub-Releases nicht abschaffen, solange es Installationen ≤ 0.2.0 geben kann – die fragen nur GitHub
  (`releases/latest`) und kommen nur so auf den neuen Updater.
- Eine veröffentlichte Version nie mit anderem Inhalt neu hochladen; immer die Version erhöhen.
- Secret `SSH_KEY` (für `deploy@179.237.96.154`) muss im Repo liegen, sonst bricht der Upload ab.

## Übersetzungen

Quelltexte auf **Deutsch in der Du-Form** (wie Cluvo), Textdomain `cluvo`. `languages/` enthält `cluvo.pot`, `fr_FR`, `it_IT`
(gelten auch für `fr_CH`/`it_CH`, siehe `Cluvo_Plugin::load_textdomain()`). Nach Textänderungen `xgettext` → `msgmerge` →
übersetzen → `msgfmt --check` (Befehle in RELEASING.md); `.mo` wird eingecheckt. Die Action prüft alle `.po`.

## Lokal testen

Syntax: `find . -name '*.php' -print0 | xargs -0 -n1 php -l`. Bauen: `php bin/build.php` → `dist/`.
Eine echte WordPress-Instanz mit Docker (Images `wordpress:6-php8.3-apache`, `mariadb:11`, `wordpress:cli-php8.3`):

```bash
docker network create cwtest
docker run -d --name cwtest-db --network cwtest -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp \
  -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11
docker run -d --name cwtest-wp --network cwtest -e WORDPRESS_DB_HOST=cwtest-db -e WORDPRESS_DB_USER=wp \
  -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp -p 127.0.0.1:8099:80 wordpress:6-php8.3-apache
WP() { docker run --rm --network cwtest --volumes-from cwtest-wp --user 33:33 -e HOME=/tmp \
  -e WORDPRESS_DB_HOST=cwtest-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  wordpress:cli-php8.3 wp "$@"; }
WP core install --url=http://localhost:8099 --title=Test --admin_user=admin --admin_password=admin \
  --admin_email=test@example.ch --skip-email
docker cp dist/cluvo-X.Y.Z.zip cwtest-wp:/var/www/html/wp-content/   # wp-cli sieht nur das gemeinsame Volume
WP plugin install /var/www/html/wp-content/cluvo-X.Y.Z.zip --activate
# Aufräumen: docker rm -f cwtest-wp cwtest-db && docker network rm cwtest
```

Backend unter http://localhost:8099/wp-admin (admin/admin). `WP plugin list --fields=name,version,update,update_version`
zeigt, ob ein Update erkannt wird; `WP plugin update cluvo` installiert es.

## Konventionen

- WordPress Coding Standards: Tabs, `array()`, Yoda-Vergleiche, jede Ausgabe escapen (`esc_html`, `esc_attr`, `esc_url`,
  `wp_kses`), Eingaben sanitizen, Nonces und `current_user_can()` bei jeder Admin-Aktion.
- Präfix `cluvo` für Funktionen, Optionen, Transients (`uninstall.php` räumt `cluvo_*` weg), Hooks und CSS-Klassen.
- Neue Filter/Konstanten in `readme.txt` unter «Für Entwickler» dokumentieren.
