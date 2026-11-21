# Sintoora Kalender (Europagymnasium Auhof)

WordPress-Plugin: zeigt den Schulkalender aus SINTOORA mit Klassenfilter an – ohne Lehrerdaten und Teilnehmerlisten.

- **Lizenz:** GPL-2.0-or-later (siehe `LICENSE`)
- **Voraussetzungen:** WordPress 6.0+, PHP 7.4+
- **Versionen:** Jede Version ist ein Commit mit Tag (`v1.6.0`, `v1.6.1`).

## Wichtig: keine Kalender-Links im Repository

Die Links der Sintoora-Kalender enthalten Token, die wie Passwörter zu behandeln sind. In diesem Repository ist die Voreinstellung `SINTOORA_KALENDER_STANDARD_QUELLEN` deshalb **leer**.

- **Bestehende Website:** Die Links stehen bereits in den Einstellungen, ein Update aus diesem Repository ändert daran nichts.
- **Neue Installation:** Nach dem Aktivieren unter Einstellungen → Sintoora Kalender die Links eintragen (Form `https://app.sintoora.com/screen/calendar/screen?t=…`).

**Token nie committen**, auch nicht in Issues oder im Wiki.

## Installieren

Für WordPress braucht es eine ZIP-Datei mit dem Ordner `sintoora-kalender/`:

```
git archive --format=zip --prefix=sintoora-kalender/ -o sintoora-kalender-1.6.1.zip v1.6.1
```

`README.md`, `ENTWICKLUNG.md` und die Git-Dateien sind vom Archiv ausgenommen.

---

# Plugin „Sintoora Kalender (Europagymnasium Auhof)“

**Aktuelle Version:** 1.6.1. Die Datei `sintoora-kalender.php` enthält alles: PHP, CSS und JavaScript (als Heredoc).

## Aufgabe
Der Schulkalender aus **SINTOORA** erscheint auf der Website mit Klassenfilter, aber **ohne Suchfeld, ohne Lehrerfilter, ohne Lehrerdaten und ohne Teilnehmerlisten**. Drei Sintoora-Kalender werden zusammengeführt:
- Klassentermine;
- allgemeine Termine (Ferien, Feiertage, schulautonome Tage);
- Reifeprüfung (eigener Filter).

Eingebunden wird er mit `[sintoora_kalender]` (Seite „Klassenkalender“, `/klassenkalender/`).

## Ablauf
1. Der Shortcode gibt nur das Kalender-Gerüst aus; CSS und JS kommen inline.
2. Das Skript lädt die Termine von der eigenen REST-Route `/wp-json/sintoora-kalender/v1/termine` (gleiche Domain).
3. **Serverseitiger Abruf:** WordPress holt die öffentlichen Sintoora-„Screen“-Seiten (`https://app.sintoora.com/screen/calendar/screen?t=<Token>`). Es bereinigt das HTML: nur erlaubte Tags, Stile und Attribute, Teilnehmer werden entfernt. Das Ergebnis wird zwischengespeichert:
   - Transient `sintoora_kalender_termine`, 15 Minuten frisch;
   - Reserve „letzter Stand“ in der Option `sintoora_kalender_letzter_stand`, nicht automatisch geladen.
4. **Aktualisierung im Hintergrund** per WP-Cron (`sintoora_kalender_regelmaessig` alle 15 Minuten, `sintoora_kalender_sofort` bei Bedarf). Eine Sperre erlaubt höchstens einen Abruf pro Minute; nach einem Fehler gibt es 2 Minuten Pause.
5. **Zusammenführen:** Doppelte Termine werden entfernt; Tests ohne Klasse werden ausgeblendet (`SINTOORA_KALENDER_OHNE_KLASSE_AUSBLENDEN`).
6. **gzip-Ausgabe** der REST-Antwort über `rest_pre_serve_request` (rund 1,1 MB → 35 KB).

## Einstellungen und Daten
- **Kalenderadressen:** unter Einstellungen → Sintoora Kalender (Option `sintoora_kalender_quellen`). Die Voreinstellung `SINTOORA_KALENDER_STANDARD_QUELLEN` ist im Repository leer.
- **Token wie Passwörter behandeln:** Die Token in den Adressen geben Zugriff auf die vollständigen Sintoora-Ansichten (mit Lehrerfilter). Nicht in öffentliche Repositories oder Anleitungen kopieren. Bei Verdacht bei Sintoora neu erzeugen lassen.
- **Grenzen:** max. 5 MB je Abruf, 5000 Termine, 6 s Zeitlimit je Kalender.

## Abhängigkeiten zu anderen Teilen
- Das Plugin **„Auhof Schulseiten“** nutzt für die Terminvorschau der Startseite (`[auhof_termine]`) die Funktionen:
  - `sintoora_kalender_termine()`
  - `sintoora_kalender_terminart()`
  - `sintoora_kalender_hat_klasse()`

  **Diese Funktionsnamen und Rückgabeformate nicht ändern**, sonst zeigt die Startseite nur noch den Link zum Kalender. Das Format ist ein Array mit den Schlüsseln `zeit`, `frisch` und `appointments`; jeder Termin hat `titel`, `raum`, `klassen`, `klassenpart` und `html`. Die Terminart steht im `<title>` des SVG-Symbols.
- Das Child-Theme setzt vor den Kalender eine unsichtbare Zwischenüberschrift (Filter `do_shortcode_tag`) und gestaltet die Zielgrößen (`.sk …` in `style.css`).

## Risiken / beim Überarbeiten beachten
- **Keine offizielle Schnittstelle:** Ändert Sintoora den Aufbau der Screen-Seiten, liefert der Abruf nichts mehr. Der Kalender zeigt dann den letzten Stand. Prüfen: `wp option get sintoora_kalender_status` auf der Testseite bzw. die REST-Antwort. Langfristig bei Sintoora nach einem ICS- oder JSON-Export fragen.
- **gzip-Ausgabe** über `rest_pre_serve_request`: nach größeren WordPress-Updates prüfen, ob `/wp-json/sintoora-kalender/v1/termine` noch korrekt (gzip und ungzip) antwortet.
- **Testinstallation:** Sind dort externe Verbindungen gesperrt, erscheint immer der letzte gespeicherte Stand.
- **Datum:** Sintoora schreibt Daten ohne Jahr, wenn es das laufende Jahr ist. Das Jahr wird im Browser bzw. in der Terminvorschau aus dem Abrufzeitpunkt ergänzt.

## Ändern und testen
Ablauf, Tests und Paketbau stehen in `ENTWICKLUNG.md`. Zusätzlich prüfen:
- Klassenkalender bei 390 und 1280 px;
- Filter „Reifeprüfung“ und einzelne Klassen;
- „Frühere Termine anzeigen“;
- Startseite → Terminvorschau.

## Hinweis bei veraltetem Stand (ab 1.6.1)
- Ist der gespeicherte Stand älter als 7 Tage (`SINTOORA_KALENDER_VERALTET_AB`), liefert die REST-Antwort `veraltet: true` und `stand` (Datum/Uhrzeit). Das Skript zeigt dann über der Klassenleiste den Hinweis `.sk-veraltet`: „Hinweis: Die Termine wurden zuletzt am … Uhr aktualisiert. Neuere Änderungen fehlen eventuell.“
- Im Normalfall (regelmäßiger Abruf alle 15 Minuten) erscheint nichts. Der Hinweis zeigt an, dass Sintoora länger nicht erreichbar war oder ein Kalender-Link nicht mehr gilt; Details stehen unter Einstellungen → Sintoora Kalender.
- Das Datenformat (`SINTOORA_KALENDER_DATENFORMAT`) ist unverändert, gespeicherte Termine gelten nach dem Update weiter.
