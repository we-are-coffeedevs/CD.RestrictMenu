# MEMORY.md – CD.RestrictMenu

> Projektgedächtnis für Claude. Bei jeder relevanten Änderung/Entscheidung aktualisieren (Pflicht).
> Stand: 2026-11-10

## Arbeitsregeln
- Commit und Push macht der Nutzer selbst. Nicht committen, nicht pushen, nicht danach fragen; nur auf geänderte Dateien hinweisen.
- MEMORY.md muss Projektkontext + Verständnis vollständig enthalten (Nutzung an anderem PC ohne Verlust).
- Versionierung: Bei jeder Änderung die Version erhöhen (SemVer, Patch +1; Minor/Major nur auf Ansage). Einzige Stelle: Header `Version:` in `cd-RestrictMenu.php`.
- Sprache: Deutsch (UI, Doku, Kommunikation).
- Diese Regeln hat der Nutzer am 2026-11-10 für dieses Projekt ausdrücklich bestätigt.

## Projekt
- Name im Backend (Plugin-Header): **CD.RestrictMenu**. Seitentitel heißt ebenfalls **CD.RestrictMenu** („CD.RestrictMenu: Name“), der Link in der Benutzerliste heißt **Menü einschränken** (Vorgabe des Nutzers, 2026-11-10).
- Beschreibung (Vorgabe des Nutzers, wörtlich): „Ermöglicht es einem festgelegten Superuser die Backend-Navigation anzupassen, sodass Sie die beste Benutzererfahrung in der Bedienung erhalten und nicht durch unnütze Inhalte gestört werden.“
- Typ: WordPress-**Must-Use-Plugin**, bewusst **eine einzige Datei** `cd-RestrictMenu.php` (CSS und JS inline), Ziel `wp-content/mu-plugins/`.
- Lokaler Ordner: `F:\claude-local\we-are-coffeedevs--wp-mu_RestrictBackendNavigation\` (Repo-Root; bis 2026-11-10 `wp_menu_sichtbarkeit`, vom Nutzer verschoben).
- Remote: https://github.com/we-are-coffeedevs/wp-mu_RestrictBackendNavigation.git
- `.repo-lock.json` = Sperrmarker aus dem Repo-Setup; unverändert lassen.
- Präfix: `cdmv` / `CDMV_` (Klasse `CDMV_Menu_Visibility`). Der Präfix stammt vom ursprünglichen Namen und wurde bei der Umbenennung nicht geändert (sonst gingen gespeicherte Daten verloren).
- Einsatz: Kundenwebsite mit WooCommerce, Elementor und VamTAM-Theme. Website-Name und Admin-Login stehen bewusst nicht in dieser Datei (Audit-Befund 5).

## Anforderungen (Vorgaben des Nutzers)
1. Im Code wird ein bestehender Benutzer als Superuser festgelegt. Nur er bestimmt für andere Benutzer, welche Navigationselemente im Backend angezeigt werden.
2. Zugang über einen Link unter dem Benutzernamen in der Benutzerliste (Hover). Nur der Superuser bekommt den Link, nicht bei sich selbst. Beim Öffnen wird erneut geprüft, ob der Aufrufer der Superuser ist.
3. Seite zeigt ein Abbild des Backend-Navigationsbaums: gleiche Reihenfolge, Eltern- und Kindelemente einzeln, mit Icons, je eine Checkbox, unten Speichern.
4. Beim Öffnen wird der aktuelle Stand eingelesen. Neuer Benutzer = alle Haken; Haken weg = Element nicht mehr sichtbar.
5. Entscheidungen auf Rückfrage: ausblenden **und** Direktaufruf sperren; Superuser per **Benutzername**; **auch Admin-Leiste** steuerbar.
6. Später ergänzt: „trotzdem freischalten“, goldener Link, Kästen je halbe Breite, Titel-Korrekturen der Admin-Leiste, Drucken/PDF, Copy2User, Umbenennung.

## Architektur (Stand v1.0.3)
Alles in `cd-RestrictMenu.php`, statische Klasse `CDMV_Menu_Visibility`.

### Konstanten und Daten
- `CDMV_SUPERUSER`: Login-Name des Superusers. In der Plugin-Datei **leer** (= kein Superuser); der echte Wert gehört in die `wp-config.php`. Vergleich strikt gegen `user_login`.
- `CDMV_SUPERUSER_ID` (optional, Standard 0): feste Benutzer-ID. Bei 0 merkt sich das Plugin die ID beim ersten gültigen Aufruf in der Option `cdmv_superuser_pin` (`login` + `id`); ändert sich der Login in der Konstante, wird neu gemerkt.
- `is_superuser()`: Login passt **und** Konto hat `manage_options` (ohne Freischaltungen geprüft) **und** ID passt zur Konstante bzw. zur gemerkten ID. `target_user()`, `row_action()` und `grant_caps()` schließen weiterhin allein nach Login aus (vorsichtige Richtung: ein Konto mit dem Superuser-Namen wird nie eingeschränkt oder freigeschaltet).
- User-Meta `cdmv_hidden` (Array): `menu` (Hashes ausgeblendeter Menüpunkte), `bar` (IDs ausgeblendeter Leisten-Einträge), `grant` (Hashes freigeschalteter Punkte, für die Anzeige), `caps` (daraus abgeleitete Berechtigungen). Leer ⇒ Meta wird gelöscht.
- Option `cdmv_bar_nodes` (autoload aus): alle Leisten-Einträge, die der Superuser je gesehen hat (`t` Titel, `p` Eltern-ID, `g` Gruppe).
- **Gespeichert wird, was ausgeblendet ist**, nicht was sichtbar ist ⇒ neue Benutzer und neu installierte Plugins sind automatisch sichtbar.
- Schlüssel der Menüpunkte: `md5('m|'.slug)` bzw. `md5('s|'.eltern.'|'.slug)`. Grund: Slugs enthalten `&amp;`/Sonderzeichen, die im Formular nicht stabil zurückkommen. `norm()` dekodiert Entities und entfernt den wechselnden `return=`-Parameter (Customizer), sonst wäre der Hash bei jedem Aufruf anders.

### Hooks
| Hook | Methode | Aufgabe |
|---|---|---|
| `admin_menu` (10) | `register_page` | Seite nur für den Superuser registrieren (`add_users_page`, Capability `read`); `load-`Hook für Speichern; per `admin_head` aus dem Menü entfernt (bleibt erreichbar) |
| `admin_menu` (PHP_INT_MAX) | `apply_menu` | Für alle außer Superuser: Einträge aus `$menu`/`$submenu` entfernen, danach `block_request()` |
| `wp_before_admin_bar_render` | `admin_bar` | Superuser: Einträge in `cdmv_bar_nodes` merken; andere: ausgeblendete per `remove_node()` entfernen |
| `user_row_actions` | `row_action` | Link „Menü einschränken“ (Farbe `#996800`) |
| `user_has_cap` | `grant_caps` | Freigeschaltete Berechtigungen dynamisch ergänzen |

### Ausblenden und Sperren (`apply_menu`, `block_request`)
- Läuft vor WPs eigener Menü-Nachbearbeitung, daher räumt WP doppelte Trennlinien selbst auf und verlinkt Elternpunkte auf den ersten verbleibenden Unterpunkt.
- Elternpunkt ausgeblendet ⇒ alle Unterpunkte ausgeblendet. Alle Unterpunkte ausgeblendet ⇒ Elternpunkt verschwindet ebenfalls (sonst führte er auf eine gesperrte Seite).
- Sperre: Alle Menüpunkte (vor dem Entfernen) sind „Kandidaten“. Für die aktuelle Anfrage wird der **genaueste** passende Kandidat gesucht (`score()`: Datei + Anzahl übereinstimmender Query-Parameter). Gesperrt wird nur, wenn alle besten Treffer ausgeblendet sind – ein sichtbarer Treffer gewinnt.
  - Core-Datei (existiert in `wp-admin/`): Datei muss `$pagenow` entsprechen. `edit.php`/`post-new.php` ohne `post_type` passen nur auf Beiträge. Hat die Anfrage `page=`, passen Core-Kandidaten ohne `page` nicht (sonst gewänne „Alle Produkte“ gegen eine Plugin-Unterseite).
  - Sonst Plugin-Seite: Slug wird als `page=<slug>` geparst (deckt z. B. `wc-admin&path=/analytics/overview` ab).
  - `post.php` und `term.php` werden dem Bereich (`edit.php?post_type=…`, `upload.php`, `edit-tags.php?taxonomy=…`) zugeordnet und nur gesperrt, wenn der **gesamte** Bereich ausgeblendet ist (sonst könnte man nach „Erstellen“ nicht weiterbearbeiten).
- Zwei Lesarten je Anfrage (`current_requests()`): nur `$_GET` sowie `$_GET` + `$_POST` (WordPress liest `post_type`/`taxonomy` aus `$_REQUEST`); `page` stammt immer nur aus `$_GET`. Gesperrt wird, sobald **eine** Lesart gesperrt ist (`is_blocked()`). Per Skript getestet (8 Fälle, u. a. POST mit `post_type=page` im Body).
- Gesperrt: `wp_die` 403 „Kein Zugriff“. Ausnahme Dashboard (`index.php`): Weiterleitung auf die erste sichtbare, erlaubte Seite.
- Greift nur bei normalen Backend-Seitenaufrufen (dort feuert `admin_menu`), nicht bei `admin-ajax.php`, `admin-post.php`, REST. Netzwerk-Admin (Multisite) wird nicht behandelt.

### Freischalten („trotzdem freischalten“)
- `role_can()` prüft die Berechtigung ohne Freischaltungen (Flag `$bypass`), damit die Seite zeigt, was die Rolle allein darf.
- `caps_for()`: Berechtigung des Menüpunkts; bei `edit.php`/`post-new.php` zusätzlich alle Rechte des Inhaltstyps (ohne Meta-Caps `edit_post`, `read_post`, `delete_post`), bei `edit-tags.php` die der Taxonomie – sonst sähe der Benutzer nur die Liste.
- Vergabe dynamisch über `user_has_cap`, nichts wird in Rolle oder Benutzer geschrieben. Plugin entfernt ⇒ Freischaltung weg.
- Keine Sperrliste: Der Superuser darf jede Berechtigung freischalten. Berechtigungen aus `CRITICAL_CAPS` (Einstellungen, Plugins/Themes/Dateien, Benutzerverwaltung, Netzwerk) werden aber mit „⚠ weitreichend“ markiert (`data-danger` am Haken); sind solche beim Speichern neu angehakt, fragt ein `confirm()` nach (nur clientseitig, reine Bedienhilfe). Einzige Ausnahme: `do_not_allow` (WordPress' Verbotsmarke) wird nie vergeben – ausgeschlossen in `caps_for()`, `grant_caps()`, `handle_save()`, und die Seite zeigt dort keinen Haken.

### Sicherheitsaudit (2026-11-10, Code-Durchsicht, kein Test in WordPress)
- Behoben in v1.0.1: (1) Superuser nur über Login → jetzt Login + ID + `manage_options`; (3) Sperre per POST umgehbar; (4) `do_not_allow` freischaltbar; (5) Admin-Login im Quelltext und in der Doku.
- **Bewusst nicht behoben (Entscheidung des Nutzers):** (2) User-Meta `cdmv_hidden` ist ungeschützt. Wer dieses Meta-Feld beschreiben kann (z. B. über eine fremde Schnittstelle, die beliebige Benutzer-Meta schreibt), kann Einschränkungen löschen oder sich über `caps` Berechtigungen eintragen. Vorgeschlagen war eine Signatur mit den WordPress-Salts; nicht umgesetzt.
- Unauffällig: Seite nur für Superuser registriert, Prüfung bei Öffnen und Speichern, Nonces an Zielbenutzer gebunden, Ausgaben maskiert, kein SQL, keine eigenen AJAX-/REST-Endpunkte, Speichern übernimmt nur Schlüssel aus dem aktuellen Menü.
- Grundsätzliche Grenzen: Sperre wirkt nicht auf REST, `admin-ajax.php`, `admin-post.php` und clientseitig nachladende Oberflächen; wer Code ausführen darf (Plugins installieren, Theme-Editor, Dateizugriff), kann das Plugin aushebeln.
- Der frühere Standardwert der Konstante (echter Admin-Login) und der Website-Name stehen noch in der Git-Historie (Commits vom 2026-11-10); Repo ist privat.

### Admin-Leiste
- Einträge werden vor dem Rendern erfasst (`get_nodes()` liefert danach nichts mehr). Backend und Frontend haben unterschiedliche Einträge ⇒ Sammlung über alle Aufrufe des Superusers.
- Gruppen (`group`) werden im Baum übersprungen, Kinder hängen am nächsten echten Elternteil. `menu-toggle` (mobiler Menü-Schalter) ist nie abschaltbar.
- Titel: Screenreader-Spans werden beim Erfassen entfernt (sonst „77 Aktualisierungen“). Feste neutrale Titel zur Anzeigezeit für `wp-logo`, `updates`, `comments`, `my-account`, `user-info`; die beiden letzten mit dem Namen des **bearbeiteten** Benutzers statt des Superusers.

### Einstellungsseite (`render`, `handle_save`)
- URL: `users.php?page=cdmv-menu-visibility&user_id=N`. `target_user()` prüft Superuser, Existenz des Benutzers und dass er nicht der Superuser ist.
- Speichern im `load-`Hook (dort ist `$menu` aufgebaut), Nonce `cdmv_save_<ID>`. Einträge momentan nicht geladener Plugins behalten ihren gespeicherten Status.
- Icons: Dashicons serverseitig; alles andere (SVG, Icon-Fonts wie WooCommerce, CSS-Icons) kopiert ein Skript nach `window.load` aus der echten Navigation (`getComputedStyle` von `.wp-menu-image` bzw. `::before`), Ersatz: Zahnrad.
- Leere Menütitel (nur HTML) werden durch den Slug ersetzt.
- Checkbox-Logik (JS): Elternteil schaltet Kinder; Kind an ⇒ Elternteil an; im Menübaum letztes Kind aus ⇒ Elternteil aus. Freischalt-Haken (`.cdmv-grant`) sind davon ausgenommen.
- Layout: zwei Kästen nebeneinander, je halbe Breite (`flex: 1 1 0`, `min-width: 360px`).
- **Copy2User:** Button mit Tooltip, Popover mit Auswahl aller Benutzer derselben Rolle(n) ohne Superuser und ohne den Benutzer selbst. Eigenes Formular (`cdmv_action=copy`, Nonce `cdmv_copy_<ID>`), Server prüft Rolle/Superuser erneut. Kopiert das gespeicherte Meta 1:1, überschreibt das Ziel.
- **Drucken / PDF:** `window.print()` mit Print-CSS. Kästen als CSS-Tabelle (`display: table`, Überschrift `table-header-group`) ⇒ Überschrift wiederholt sich je Druckseite; Hauptpunkte `break-inside: avoid`; zweiter Kasten auf neuer Seite; WP-Rahmen und Bedienelemente (`.cdmv-noprint`) ausgeblendet; Zeitstempel „Stand:“ nur im Druck.

### Plugin-Daten entfernen (ab v1.0.3)
- Anlass: Must-Use-Plugins haben keine Deinstallationsroutine; Daten müssen vor dem Löschen der Datei weg.
- `cleanup()`: `delete_metadata( 'user', 0, 'cdmv_hidden', '', true )` + `delete_option` für `cdmv_bar_nodes` und `cdmv_superuser_pin`.
- **Link** „Plugin-Daten entfernen“ (rot) in der Must-Use-Liste unter dem Plugin-Namen: Filter `plugin_action_links` mit `$context === 'mustuse'` und Dateiname `basename(__FILE__)`, nur Superuser, `confirm()`. Ausführung über `admin-post.php?action=cdmv_cleanup` (Nonce `cdmv_cleanup`, Superuser-Prüfung), danach Hinweis auf der Plugin-Seite (`cdmv_cleaned=1`).
- **Speicherpause:** Ohne Gegenmaßnahme legte der nächste Seitenaufruf des Superusers Pin und Leisten-Einträge sofort wieder an. Deshalb setzt der Link das Cookie `cdmv_paused` (24 h, httponly): solange es da ist, schreibt das Plugin weder Pin noch Leisten-Einträge. Es unterdrückt nur das Schreiben; die Superuser-Prüfung bleibt gleich (ohne Pin gilt Login + Administrator, wie vor dem ersten Merken). Öffnen der Einstellungsseite beendet die Pause (`handle_save()` läuft im `load-`Hook auch bei GET). Bewusst Cookie statt Datenbank-Flag, weil ein Flag selbst ein Überbleibsel wäre.
- **Schalter** `define( 'CDMV_CLEANUP', true );` in der `wp-config.php`: `init()` ruft `cleanup()` und registriert keine Hooks – Plugin ruht, solange der Schalter gesetzt ist (läuft bei jedem Seitenaufruf, daher nur vorübergehend setzen).
- README: Abschnitt „Entfernen“ beschreibt beide Wege, FAQ die Handlöschung per SQL/WP-CLI.

## Status
- Aktuelle Version: **1.0.3** (1.0.0 vom Nutzer nach der Umbenennung zurückgesetzt; Entwicklungsstände davor liefen bis 1.3.0).
- PHP-Syntax geprüft (PHP 8.3 lint, Laragon: `C:\laragon\bin\php\php-8.3.14-Win32-vs16-x64\php.exe -n -l`).
- Der Nutzer hat das Plugin auf der Zielseite im Einsatz (Screenshots der Einstellungsseite). Nicht von Claude in WordPress getestet; ungeprüft insbesondere: Druckbild (wiederholte Überschrift), Copy2User, Sperre per Direktaufruf bei Plugin-Seiten, Freischaltung der Gutscheine.

## Offene Punkte / Ideen
- Gutscheine beim Shop-Manager: falls nach Freischaltung nicht sichtbar, sind Gutscheine evtl. in WooCommerce deaktiviert.
- Bereits doppelt erfasste Leisten-Titel fremder Plugins bleiben, bis die Option `cdmv_bar_nodes` gelöscht wird (baut sich neu auf).
- Netzwerk-Admin (Multisite) nicht abgedeckt.
- „Plugin-Daten entfernen“ löscht bei Multisite die beiden Optionen nur für die aktuelle Website (Benutzer-Meta gilt netzwerkweit).

## Änderungslog
- 2026-11-10: v1.0.3: Link „Plugin-Daten entfernen“ in der Must-Use-Liste, Schalter `CDMV_CLEANUP`, Speicherpause per Cookie; README um „Entfernen“ (zwei Wege) und FAQ erweitert. Alter leerer Ordner `wp_menu_sichtbarkeit` vom Nutzer gelöscht.
- 2026-11-10: v1.0.2: Offene Punkte mit dem Nutzer geklärt. Link heißt „Menü einschränken“, Seitentitel „CD.RestrictMenu: Name“; Warnmarkierung und Sicherheitsabfrage für weitreichende Freischaltungen (`CRITICAL_CAPS`); Arbeitsregeln bestätigt; Nutzer will die Git-Historie bereinigen (Schritte übergeben, Force-Push macht er selbst).
- 2026-11-10: v1.0.1: Sicherheitsaudit, Befunde 1, 3, 4, 5 behoben (Superuser an ID und Administrator-Recht gebunden, neue Konstante `CDMV_SUPERUSER_ID`, Option `cdmv_superuser_pin`; Sperre wertet auch POST aus; `do_not_allow` ausgeschlossen; `CDMV_SUPERUSER` in der Datei leer, Login und Website-Name aus der Doku entfernt). Befund 2 auf Nutzerwunsch nicht umgesetzt.
- 2026-11-10: Plugin-Datei auf Nutzerwunsch umbenannt: `menu-sichtbarkeit.php` → `cd-RestrictMenu.php`. Beim Hochladen muss die alte Datei aus `mu-plugins` gelöscht werden, sonst laden beide und die doppelte Klasse `CDMV_Menu_Visibility` erzeugt einen Fatal Error. Banner: coffeedevs-Logo (aus `favicon.svg` des Nutzers) bei „by coffeedevs“ eingebettet.
- 2026-11-10: README-Banner `assets/banner.svg` (1400×640, handgeschriebenes SVG: links Name, Nutzen und vier Vorteile, rechts die als Vektor nachgebaute Einstellungsseite mit Plugins/Benutzer/Werkzeuge; Farben Dunkelgrau `#1d2327` und Gold `#f0b849`; keine eingebetteten Schriften oder Bilder). Oben in der README eingebunden. Nur Doku, keine Versionsänderung.
- 2026-11-10: Projekt vom Nutzer in den Repo-Ordner `we-are-coffeedevs--wp-mu_RestrictBackendNavigation` verschoben; Pfad und Remote hier nachgetragen.
- 2026-11-10: README.md und MEMORY.md angelegt.
- 2026-11-10: Umbenennung im Plugin-Header in **CD.RestrictMenu**, neue Beschreibung, Version auf 1.0.0 zurückgesetzt.
- 2026-11-10: (vormals 1.3.0) Copy2User.
- 2026-11-10: (vormals 1.2.0) Button „Drucken / PDF“ mit Print-CSS.
- 2026-11-10: (vormals 1.1.2) Titel der Admin-Leiste korrigiert (doppelte Zahl, Name des bearbeiteten Benutzers).
- 2026-11-10: (vormals 1.1.1) Kästen je halbe Breite.
- 2026-11-10: (vormals 1.1.0) „trotzdem freischalten“, goldener Link, leere Menütitel durch Slug ersetzt.
- 2026-11-10: Erstfassung: Superuser, Link in der Benutzerliste, Menübaum, Admin-Leiste, Ausblenden und Sperren.
