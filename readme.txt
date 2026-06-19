=== Cluvo ===
Contributors: cluvo
Tags: cluvo, verein, formulare, veranstaltungen, news
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
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

* `[cluvo_form account="1" slug="mein-formular"]` – bettet ein Formular ein (native Felder).
* `[cluvo_events account="1" limit="10" category="" from="2026-01-01"]` – kommende Veranstaltungen.
* `[cluvo_news account="1" limit="6" category=""]` – aktuelle News (mit Einzelansicht).

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/cluvo` kopieren und aktivieren.
2. Unter Einstellungen → Cluvo die API-Basis-URL und den API-Key eintragen.
   Beide findest du in Cluvo unter Einstellungen → Integration.
3. Shortcodes auf einer Seite oder in einem Beitrag verwenden.

== Changelog ==

= 0.1.0 =
* Erste Version: Formulare, Veranstaltungen und News via Cluvo REST-API.
