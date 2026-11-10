# CD.RestrictMenu

![CD.RestrictMenu – die Backend-Navigation von WordPress für jeden Benutzer einzeln festlegen](assets/banner.svg)

WordPress-Must-Use-Plugin. Ermöglicht es einem festgelegten Superuser, die Backend-Navigation anzupassen, sodass Sie die beste Benutzererfahrung in der Bedienung erhalten und nicht durch unnütze Inhalte gestört werden.

Der Superuser wird im Plugincode festgelegt. Nur er kann für jeden anderen Benutzer einzeln bestimmen, welche Einträge der Backend-Navigation und der Admin-Leiste sichtbar sind. Für den Superuser selbst gelten keine Einschränkungen.

## Funktionen
- **Link in der Benutzerliste:** Unter *Benutzer* erscheint beim Überfahren eines Benutzernamens der Link **Menü einschränken** (goldfarben). Nur der Superuser sieht ihn, und nicht bei sich selbst.
- **Zugriffsschutz:** Die Einstellungsseite wird nur für den Superuser registriert. Beim Öffnen und bei jedem Speichern wird erneut geprüft, ob der Aufrufer der Superuser ist (zusätzlich Nonce).
- **Baum der Backend-Navigation:** gleiche Reihenfolge wie im WordPress-Menü, mit Haupt- und Unterpunkten, Icons und je einer Checkbox. Haken = sichtbar. Ein neuer Benutzer hat alle Haken; beim Öffnen wird der gespeicherte Stand eingelesen.
- **Admin-Leiste:** zweiter Baum für die obere Leiste (Toolbar) mit eigenen Checkboxen.
- **Sperre per Direktaufruf:** Ausgeblendete Seiten sind nicht nur unsichtbar, sondern auch über die URL gesperrt (Meldung „Kein Zugriff“). Ist das Dashboard ausgeblendet, wird auf die erste sichtbare Seite weitergeleitet.
- **Trotzdem freischalten:** Einträge, die die Rolle eines Benutzers nicht sehen darf, sind markiert und lassen sich über einen zusätzlichen Haken erlauben. Der Benutzer erhält dafür die dahinter genannte Berechtigung. Berechtigungen, die Administrator-Rechten gleichkommen, sind mit „⚠ weitreichend“ markiert und müssen beim Speichern eigens bestätigt werden.
- **Copy2User:** überträgt das gespeicherte Rechteprofil auf einen anderen Benutzer derselben Benutzergruppe (ohne Superuser).
- **Drucken / PDF:** druckt beide Kästen in voller Länge zu Dokumentationszwecken; im Druckdialog „Als PDF speichern“ wählen. Die Kastenüberschrift wird auf jeder Seite wiederholt, Gruppen werden nicht mitten im Text getrennt.

## Installation

<details>
<summary><strong>Besonderheiten eines Must-Use-Plugins</strong></summary>

Ein Must-Use-Plugin („mu-plugin“) unterscheidet sich von einem normalen Plugin:
- Es liegt im Ordner `wp-content/mu-plugins/`, nicht in `wp-content/plugins/`.
- Es ist **immer aktiv**. Es gibt keinen Aktivieren-/Deaktivieren-Link; im Backend lässt es sich weder abschalten noch löschen. Genau deshalb eignet es sich für diese Aufgabe: Ein eingeschränkter Benutzer kann es nicht einfach deaktivieren.
- Es lässt sich **nicht über das Backend installieren** (kein ZIP-Upload), sondern nur per FTP/SFTP oder über den Dateimanager des Hosters.
- WordPress lädt nur PHP-Dateien, die **direkt** im Ordner `mu-plugins` liegen. Dateien in Unterordnern werden nicht geladen.
- Es wird **vor** allen normalen Plugins geladen.
- Es gibt **keine Update-Hinweise und keine automatischen Updates**. Ein Update bedeutet: Datei ersetzen.
- Aktivierungs- und Deinstallationsroutinen gibt es nicht.
- In einer Multisite gilt es für alle Websites des Netzwerks.
- Sichtbar ist es unter *Plugins* im Reiter **Must-Use** (dort nur zur Information).

</details>

### Schritte
1. **Superuser eintragen:** In die `wp-config.php` (oberhalb von „That's all, stop editing“) den Benutzernamen (Login) eines Administrators schreiben:
   ```php
   define( 'CDMV_SUPERUSER', 'benutzername' );
   ```
   So steht der Login nicht im Plugin-Quelltext und bleibt bei Updates erhalten. In der Plugin-Datei ist der Wert leer; ohne diesen Eintrag gibt es keinen Superuser. Optional zusätzlich die Benutzer-ID festlegen:
   ```php
   define( 'CDMV_SUPERUSER_ID', 1 );
   ```
   Ohne diese Zeile merkt sich das Plugin die ID beim ersten Aufruf des Superusers selbst.
2. **Ordner prüfen:** Per FTP/SFTP oder Dateimanager nach `wp-content/` wechseln. Fehlt der Ordner `mu-plugins`, ihn anlegen.
3. **Datei hochladen:** `cd-RestrictMenu.php` direkt nach `wp-content/mu-plugins/` kopieren (nicht in einen Unterordner). `README.md`, `MEMORY.md` und der Ordner `assets` gehören nicht auf den Server.
4. **Prüfen:** Im Backend unter *Plugins → Must-Use* muss **CD.RestrictMenu** stehen. Als Superuser unter *Benutzer* einen Namen überfahren – der Link **Menü einschränken** erscheint.

### Update
Datei `cd-RestrictMenu.php` auf dem Server durch die neue Fassung ersetzen. Gespeicherte Einstellungen bleiben erhalten. Der Superuser steht in der `wp-config.php` und ist vom Update nicht betroffen.
### Entfernen
Ein Must-Use-Plugin hat keine Deinstallationsroutine: WordPress bemerkt nicht, wenn die Datei gelöscht wird. Die Daten müssen deshalb **vor** dem Löschen der Datei entfernt werden. Dafür gibt es zwei Wege.

<details>
<summary><strong>Weg 1 – Link im Backend (als Superuser)</strong></summary>

1. *Plugins → Must-Use* öffnen. Unter **CD.RestrictMenu** auf **Plugin-Daten entfernen** klicken und bestätigen.
2. Datei `cd-RestrictMenu.php` aus `wp-content/mu-plugins/` löschen.

Nach dem Klick legt das Plugin im verwendeten Browser 24 Stunden lang keine neuen Daten an, damit bis zum Löschen der Datei nichts nachwächst. Wer in dieser Zeit erneut „Menü einschränken“ öffnet, nutzt das Plugin wieder normal.

</details>

<details>
<summary><strong>Weg 2 – Schalter in der <code>wp-config.php</code> (ohne Backend-Zugang)</strong></summary>

1. In die `wp-config.php` eintragen:
   ```php
   define( 'CDMV_CLEANUP', true );
   ```
2. Eine beliebige Seite der Website aufrufen. Das Plugin entfernt dabei alle Daten und ruht, solange der Schalter gesetzt ist.
3. Datei `cd-RestrictMenu.php` aus `wp-content/mu-plugins/` löschen, danach die Zeile wieder aus der `wp-config.php` entfernen.

</details>

In beiden Fällen sind alle Einschränkungen und Freischaltungen sofort aufgehoben. Ist die Datei bereits gelöscht, siehe FAQ.

### Voraussetzungen
WordPress, PHP ≥ 7.4. Der Superuser muss Administrator sein (Berechtigung `manage_options`).

## Hinweise
- **Speichern vor Copy2User:** Übertragen wird der zuletzt gespeicherte Stand. Das Profil des Empfängers wird vollständig überschrieben.
- **Freischalten gilt umfassend:** Eine freigeschaltete Berechtigung gilt überall, wo WordPress oder ein Plugin sie abfragt, nicht nur für den einen Menüpunkt. Bei `manage_options` sind das weitreichende Admin-Rechte. Bei Inhaltstypen und Taxonomien kommen deren Bearbeitungsrechte dazu.
- **Frontend-Einträge der Admin-Leiste** (z. B. „Seite bearbeiten“) erscheinen im Baum erst, nachdem der Superuser die Website einmal angemeldet im Frontend besucht hat.
- **Bearbeiten-Ansichten** (bestehenden Beitrag oder Seite öffnen) werden nur gesperrt, wenn der ganze Bereich ausgeblendet ist.
- **Neue Plugins:** Später hinzukommende Menüpunkte sind zunächst für alle sichtbar, bis sie abgewählt werden.
- **Aussperren:** Der Superuser kann sich nicht selbst einschränken. Ist der eingetragene Benutzername falsch oder leer, gibt es keinen Superuser und der Link erscheint bei niemandem – bestehende Einschränkungen gelten dann weiter.
- **Superuser-Konto neu angelegt oder gewechselt:** Das Plugin bindet den Superuser an Benutzername **und** Benutzer-ID. Wird das Konto gelöscht und mit demselben Namen neu angelegt, ist es nicht mehr Superuser. Dann `CDMV_SUPERUSER_ID` in der `wp-config.php` auf die neue ID setzen oder die Option `cdmv_superuser_pin` löschen. Ein Wechsel auf einen anderen Benutzernamen wird automatisch übernommen.
- **Grenzen der Sperre:** Gesperrt werden normale Backend-Seiten. Über REST-Schnittstelle, `admin-ajax.php` und Oberflächen, die Inhalte ohne Neuladen nachladen, bleibt erreichbar, was die Rolle des Benutzers erlaubt. Das Plugin räumt die Bedienung auf; es ersetzt keine Rechtevergabe über Rollen. Wer Plugins installieren oder Dateien ändern darf, kann es aushebeln.

## FAQ

<details>
<summary><strong>Die Plugin-Datei ist schon gelöscht – wie entferne ich die Reste aus der Datenbank von Hand?</strong></summary>

Das Plugin legt genau drei Dinge ab:

| Wo | Schlüssel | Inhalt |
|---|---|---|
| Tabelle `usermeta` | `cdmv_hidden` | Rechteprofil je Benutzer (ausgeblendete Einträge, Freischaltungen) |
| Tabelle `options` | `cdmv_bar_nodes` | bekannte Einträge der Admin-Leiste |
| Tabelle `options` | `cdmv_superuser_pin` | gemerkte Benutzer-ID des Superusers |

**Per phpMyAdmin** (Tabellenpräfix `wp_` ggf. anpassen, vorher Datenbank sichern):
```sql
DELETE FROM wp_usermeta WHERE meta_key = 'cdmv_hidden';
DELETE FROM wp_options WHERE option_name IN ('cdmv_bar_nodes', 'cdmv_superuser_pin');
```

**Per WP-CLI:**
```bash
wp option delete cdmv_bar_nodes cdmv_superuser_pin
wp db query "DELETE FROM $(wp db prefix)usermeta WHERE meta_key = 'cdmv_hidden'"
```

Schaden richten die Reste nicht an: Ohne die Plugin-Datei liest sie niemand, Einschränkungen und Freischaltungen sind wirkungslos. In einer Multisite liegen die beiden Optionen je Website vor.

</details>

<details>
<summary><strong>Ich habe „Plugin-Daten entfernen“ geklickt, will das Plugin aber doch weiter nutzen. Was nun?</strong></summary>

Einfach unter *Benutzer* wieder **Menü einschränken** öffnen. Das Plugin arbeitet dann normal weiter; die entfernten Profile sind allerdings weg und müssen neu angelegt werden.

</details>

<details>
<summary><strong>Warum erscheint der Link „Plugin-Daten entfernen“ nicht?</strong></summary>

Er wird nur dem Superuser angezeigt, und nur im Reiter *Must-Use* der Plugin-Liste.

</details>

## Struktur
```
cd-RestrictMenu.php   das gesamte Plugin (eine Datei, inkl. CSS und JS)
assets/banner.svg       Banner für diese README (nicht Teil des Plugins)
README.md               diese Datei
MEMORY.md               Projektkontext und Entscheidungen
```
