=== Cluvo ===
Contributors: cluvo
Tags: cluvo, verein, formulare, veranstaltungen, news
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bindet Cluvo-Formulare, Veranstaltungen und News über die Cluvo REST-API in WordPress ein.

== Description ==

Dieses Plugin verbindet eine WordPress-Webseite mit einem oder mehreren
Cluvo-Vereinen – ideal auch für Dachverband-Seiten. Über Shortcodes lassen sich
Anmelde-/Antragsformulare, kommende Veranstaltungen und News direkt auf der
Webseite anzeigen.

Pro Verein wird ein eigener API-Key zentral hinterlegt; jeder Key ist
serverseitig fest an seine Organisation gebunden. Formulare werden über einen
plugin-eigenen REST-Proxy abgeschickt, Veranstaltungen und News werden
zwischengespeichert.

= Shortcodes =

Mit account="<ID>" wählst du den Verein. Ist nur ein Verein hinterlegt, kann
das Attribut entfallen.

* `[cluvo_form account="1" slug="mein-formular"]` – bettet ein Formular ein (native Felder,
  inkl. fixer Mitgliedschaften, freiem Beitrag und Zusatzfeldern).
* `[cluvo_events account="1" limit="10" category="" from="2026-01-01" to=""]` – kommende
  Veranstaltungen; Serientermine erscheinen einzeln. `per_page` ist ein Alias für `limit`.
* `[cluvo_news account="1" limit="6" category="" pagination="1"]` – aktuelle News mit Bild
  und Einzelansicht; `pagination="1"` blendet Links zu älteren News ein.

Die Plugin-Oberfläche ist auf Deutsch, Französisch und Italienisch verfügbar (auch fr_CH/it_CH).

= Für Entwickler =

* `CLUVO_API_BASE_URL` (wp-config.php) – andere API-Basis, z.B. für lokale Tests.
* `cluvo_http_request_args` – HTTP-Argumente der API-Aufrufe (z.B. `sslverify` lokal).
* `cluvo_accent_color` – Akzentfarbe der Formulare (Standard: Vereinsfarbe aus Cluvo).
  Per CSS: `.cluvo.cluvo { --cluvo-accent: #0a58ca; }`.
* `cluvo_submit_rate_limit`, `cluvo_submit_rate_window` – Anmeldungen pro IP und Verein
  (Standard 20 pro 10 Minuten, 0 schaltet ab).
* `cluvo_client_ip` – IP-Adresse hinter einem Reverse Proxy (z.B. Cloudflare) bestimmen.

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/cluvo` kopieren und aktivieren.
2. Unter Einstellungen → Cluvo pro Verein den API-Key eintragen. Du findest
   ihn in Cluvo unter Einstellungen → Integration.
3. Shortcodes auf einer Seite oder in einem Beitrag verwenden.

== Changelog ==

= 0.2.0 =
* Formulare: Fixe Mitgliedschaften erscheinen als Auswahlkarten mit Beschreibung und Preis (CHF … pro Jahr/Monat), inkl. Pflichtfeld und Fehleranzeige.
* Formulare: Umschalter Privatperson/Unternehmen, Firmenname und Namen stehen zusammen; bei Unternehmen ist die Person freiwillig.
* Formulare: Freier Beitrag wie auf der Cluvo-Webseite – Intervall, Beträge pro Intervall, individueller Betrag, Standardwerte, Min./Max.
* Formulare: Breiten Drittel/Zweidrittel, Platzhalter, Hilfetexte, Zwischentitel, Telefon- und Wiederholungsfelder.
* Formulare: Bessere Barrierefreiheit (Fehler pro Feld mit aria-invalid/aria-describedby, Fokus auf das erste fehlerhafte Feld, Statusmeldungen für Screenreader, sichtbarer Tastaturfokus) und eindeutige IDs bei mehreren Formularen auf einer Seite.
* Sicherheit: Der Formular-Proxy leitet nur bekannte Felder weiter, hat einen Honeypot und ein Limit pro IP; API-Keys werden im Backend nicht mehr ausgegeben und Weiterleitungen nie mit dem Key verfolgt.
* Robustheit: Ist Cluvo nicht erreichbar, zeigen Formulare, Veranstaltungen und News bis zu einem Tag den letzten Stand; Fehler werden kurz zwischengespeichert, statt jede Seite zu bremsen. Neuer Button «Cache leeren».
* Veranstaltungen: Datum und Zeit im Format der Webseite, mehrtägige und ganztägige Anlässe, Kategorie mit Farbe, `to`-Attribut.
* News: Titelbild, Kategorie, Datum in der Zeitzone der Webseite, optionale Paginierung.
* Backend: «Testen» nutzt den gespeicherten Key, nennt die Ursache von Fehlern und listet die Shortcodes aller aktiven Formulare; Übersicht der Shortcode-Attribute; Link «Einstellungen» in der Plugin-Liste. Administratoren sehen auf der Webseite, warum ein Inhalt nicht geladen werden konnte.
* Übersetzungen Französisch und Italienisch.
* CSS und JavaScript werden nur auf Seiten mit Cluvo-Shortcodes geladen; alle Stile sind mit `.cluvo` präfixiert und über CSS-Variablen anpassbar.
* Updater: Nur Release-Assets des eigenen Repos werden installiert; ein anders benannter Plugin-Ordner bleibt beim Update erhalten.
* Die API-Basis-URL muss nicht mehr eingetragen werden – das Plugin verbindet sich direkt mit app.cluvo.ch. Für Tests lässt sie sich in wp-config.php über CLUVO_API_BASE_URL überschreiben.

= 0.1.0 =
* Erste Version: Formulare, Veranstaltungen und News via Cluvo REST-API.
