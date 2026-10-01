# Release-Workflow

Updates kommen von Cluvos eigenem Update-Server **wp.cluvo.ch** (VPS bei Infomaniak, Caddy liefert
`/var/www/wp-updates/cluvo/` statisch aus). Jede WordPress-Seite mit dem Plugin fragt dort
`https://wp.cluvo.ch/cluvo/info.json` und zeigt neue Versionen samt Changelog im Dashboard an –
inkl. «Jetzt aktualisieren» und automatischer Updates.

## Eine neue Version veröffentlichen

1. **Code ändern.**
2. **Version erhöhen**, an allen Stellen gleich:
   - `cluvo.php`: Header `Version: 0.3.1` **und** `define( 'CLUVO_VERSION', '0.3.1' )`
   - `readme.txt`: `Stable tag: 0.3.1`
3. **Changelog** in `readme.txt` unter `== Changelog ==` ergänzen:
   ```
   = 0.3.1 =
   * Was sich geändert hat.
   ```
4. **Auf `main` pushen.** Fertig.

Die Action [`release.yml`](.github/workflows/release.yml) läuft bei jedem Push auf `main`:

- prüft PHP-Syntax und Übersetzungen,
- baut mit `bin/build.php` das ZIP (Top-Level-Ordner `cluvo/`), `info.json` und die Icons,
- vergleicht die Version mit der auf wp.cluvo.ch:
  - **gleich** → nichts zu tun (Hinweis im Log: Version erhöhen),
  - **älter** → Fehler,
  - **neuer** → lädt ZIP und Icons hoch, dann `info.json` (zuletzt und atomar), kopiert das ZIP nach
    `cluvo-latest.zip`, prüft die Veröffentlichung (Version und SHA-256) und legt ein GitHub-Release
    `vX.Y.Z` mit dem ZIP an.

Das Build-Skript bricht ab, wenn Header, `CLUVO_VERSION` und `Stable tag` nicht übereinstimmen, wenn der
Changelog-Eintrag zur Version fehlt oder wenn `Update URI` nicht auf `https://wp.cluvo.ch/cluvo/` zeigt.

Lokal bauen: `php bin/build.php` → `dist/`.

## Was auf wp.cluvo.ch liegt

| Datei | Zweck |
| --- | --- |
| `cluvo/info.json` | Version, Download, Anforderungen, Beschreibung, Installation, Changelog, Icons |
| `cluvo/cluvo-X.Y.Z.zip` | jede veröffentlichte Version (bleibt liegen, wird nie überschrieben) |
| `cluvo/cluvo-latest.zip` | immer die neueste – Download-Link in Cluvo (Einstellungen → Integration) |
| `cluvo/icon-128x128.png`, `icon-256x256.png` | Icon in den Update-Übersichten (Quelle: `release-assets/`) |
| `cluvo/index.html` | Download-Seite für Menschen (`wp.cluvo.ch` leitet dorthin), erzeugt aus `info.json` – ändert sich mit jeder Version |

## Was die Nutzer sehen

- WordPress fragt alle 12 Stunden nach Updates; das Plugin merkt sich die Antwort 6 Stunden.
- Einstellungen → Cluvo zeigt installierte und neueste Version, ob automatische Updates an sind, und hat
  «Jetzt nach Updates suchen» (dasselbe als Link in der Plugin-Liste).
- «Details ansehen» zeigt Beschreibung, Installation und Changelog.
- Installiert wird nur ein Paket, das unter derselben Adresse wie `info.json` liegt.

## Einrichtung (einmalig)

- **Secret `SSH_KEY`** in diesem Repo (Settings → Secrets → Actions): derselbe private Schlüssel wie im
  Cluvo-Repo (`deploy@179.237.96.154`).
- Auf dem Server: Verzeichnis `/var/www/wp-updates/cluvo` (Besitzer `deploy`) und der Block `wp.cluvo.ch`
  im Caddyfile – beides bringt `deploy/server/provision.sh` im Cluvo-Repo mit. DNS: `*.cluvo.ch` zeigt
  bereits auf den Server, «wp» ist als Vereins-Slug gesperrt.

## Umstieg von GitHub Releases (bis 0.2.0)

Version 0.1.0 holt ihre Updates noch über die GitHub-API (`releases/latest`). Deshalb legt die Action
weiterhin zu jeder Version ein GitHub-Release mit dem ZIP an: Alte Installationen bekommen so die erste
Version mit dem neuen Updater und fragen danach nur noch wp.cluvo.ch.

## Header «Update URI»

`Update URI: https://wp.cluvo.ch/cluvo/` im Plugin-Header bewirkt zweierlei: WordPress ruft für das Plugin
den Filter `update_plugins_wp.cluvo.ch` auf (dort antwortet `Cluvo_Updater`), und wordpress.org kann nie ein
gleichnamiges Plugin als Update anbieten. Den Wert nicht ändern – sonst findet keine Installation mehr Updates.

Zum Testen gegen einen anderen Server in `wp-config.php`:

```php
define( 'CLUVO_UPDATE_URL', 'https://wp.cluvo.ch/cluvo-test/info.json' );
```

und mit `CLUVO_UPDATE_BASE=https://wp.cluvo.ch/cluvo-test php bin/build.php` bauen.

## Übersetzungen

Quellsprache ist Deutsch; `languages/` enthält `cluvo.pot` sowie Französisch und Italienisch (`fr_FR`, `it_IT`
– gelten auch für `fr_CH`/`it_CH`). Nach Textänderungen (benötigt gettext, z.B. `brew install gettext`):

```
xgettext --language=PHP --from-code=UTF-8 --add-comments=translators: --sort-by-file \
  --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
  --keyword=esc_attr__ --keyword=esc_attr_e --keyword=_n:1,2 --keyword=_x:1,2c \
  --package-name=Cluvo -o languages/cluvo.pot cluvo.php includes/*.php
msgmerge --update --backup=none languages/cluvo-fr_FR.po languages/cluvo.pot
msgmerge --update --backup=none languages/cluvo-it_IT.po languages/cluvo.pot
# neue/fuzzy Einträge übersetzen, dann:
msgfmt --check -o languages/cluvo-fr_FR.mo languages/cluvo-fr_FR.po
msgfmt --check -o languages/cluvo-it_IT.mo languages/cluvo-it_IT.po
```

Die `.mo`-Dateien werden mit eingecheckt – das Build-Skript packt `languages/` ins ZIP.
