# Release-Workflow

Updates werden über **GitHub Releases** ausgeliefert. Jede WordPress-Seite mit
dem Plugin fragt die GitHub-API nach dem neuesten Release und zeigt neue
Versionen samt Changelog im Dashboard an (inkl. „Aktualisieren"-Button).

> Voraussetzung: Das Repo `digitalifyCH/cluvo-wp` ist **öffentlich**. Der eingebaute
> Updater (`includes/class-cluvo-updater.php`) lädt das Release-Asset ohne
> Token. Liegt das Repo unter einem anderen Namen, in der Konstante
> `Cluvo_Updater::GITHUB_REPO` anpassen.

## Eine neue Version veröffentlichen

1. **Code ändern.**

2. **Version bumpen** – an beiden Stellen in `cluvo.php` identisch:
   - Header-Kommentar `Version: 0.2.0`
   - `define( 'CLUVO_VERSION', '0.2.0' )`

   (Das Build-Skript bricht ab, wenn die beiden nicht übereinstimmen.)

3. **Changelog ergänzen** in `readme.txt` unter `== Changelog ==` (gesammelte
   Einträge unter `= Unreleased =` in `= X.Y.Z =` umbenennen – das Build-Skript
   sucht die Versionsnummer):
   ```
   = 0.2.0 =
   * Mehrere Vereine pro Seite (Multi-Account).
   * Bugfix: …
   ```
   Diese Einträge werden automatisch zur Release-Beschreibung und erscheinen im
   „Version-Details"-Popup in WordPress.

4. **Commit & Tag pushen** – das löst das automatische Release aus:
   ```
   git commit -am "Release 0.2.0"
   git tag v0.2.0
   git push origin main --tags
   ```

   Die GitHub Action [`release.yml`](.github/workflows/release.yml):
   - baut `cluvo-0.2.0.zip` (Top-Level-Ordner `cluvo/`) + `release-notes.md`,
   - erstellt ein GitHub Release zum Tag `v0.2.0`,
   - hängt das ZIP als Asset an und setzt die Changelog-Notes als Beschreibung.

   Lokal bauen (zum Testen) geht weiterhin mit `php bin/build.php` → `dist/`.

## Übersetzungen

Quellsprache ist Deutsch; `languages/` enthält `cluvo.pot` sowie Französisch und
Italienisch (`fr_FR`, `it_IT` – gelten auch für `fr_CH`/`it_CH`). Nach Textänderungen
(benötigt gettext, z.B. `brew install gettext`):

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

## Was die Nutzer sehen

- WordPress prüft das „latest release" ca. alle 12 h (oder sofort bei „Nach
  Updates suchen"). Ist die Release-Version höher als die installierte,
  erscheint die Update-Benachrichtigung.
- „Details ansehen" zeigt die Release-Notes (Changelog).
- „Jetzt aktualisieren" lädt das ZIP-Asset und installiert es.

## Wichtig

- Das **ZIP-Asset** muss den Top-Level-Ordner `cluvo/` enthalten (macht
  `build.php` automatisch). Der von GitHub generierte Source-Zipball ist *nicht*
  installierbar – darum hängen wir ein eigenes Asset an.
- Versionsnummern nach SemVer (`MAJOR.MINOR.PATCH`); Tag immer mit `v`-Präfix.
- Test-Override des Repos (z.B. Fork/Staging) via Filter:
  ```php
  add_filter( 'cluvo_update_github_repo', fn() => 'meinuser/cluvo-wp-staging' );
  ```

## Keine Secrets nötig

Die Action nutzt nur das automatische `GITHUB_TOKEN` (über `permissions:
contents: write`). Es müssen keine SSH-Keys oder eigenen Secrets hinterlegt
werden.
