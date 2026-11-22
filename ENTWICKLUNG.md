# Entwicklung der schuleigenen Plugins

Die Website des Europagymnasiums Auhof nutzt zwei eigene WordPress-Plugins, jedes in einem eigenen Repository:

- **Auhof Schulseiten**: Personen, Organigramm, Raumplan mit PDF, Pinnwand, Diashow, Terminvorschau.
- **Sintoora Kalender**: Schulkalender aus SINTOORA mit Klassenfilter.

Diese Anleitung gilt für beide Plugins. Sie richtet sich an Menschen und an KI-Assistenten, die die Plugins überarbeiten. Aufbau und Besonderheiten des einzelnen Plugins stehen in dessen `README.md`.

## 1. Grundregeln

1. **Nie direkt auf der echten Website entwickeln.** Gearbeitet und getestet wird auf einer eigenen Testinstallation. Die echte Website bekommt nur getestete Versionen.
2. **Vor jedem Überschreiben auf der Testinstallation sichern** (Dateien und Datenbank), z. B. `wp db export` und ein Archiv des Plugin-Ordners.
3. **Keine Passwörter, Token oder persönlichen Daten** in Code, Repository, Issues oder Anleitungen. Die Links der Sintoora-Kalender enthalten Token und gehören nur in die Einstellungen der jeweiligen Website.
4. **Barrierefreiheit (WCAG 2.2 AA)** ist Pflicht. Keine Overlay- oder „Barrierefreiheits-Widgets“.
5. **Datenschutz:** keine externen Abrufe aus dem Browser (Schriften, Skripte, Tracking). Personendaten nur, wenn die Schule sie veröffentlicht.

## 2. Technische Vorgaben

| Punkt | Vorgabe |
|---|---|
| WordPress | mindestens 6.3 (Sintoora: 6.0), getestet bis 7.1 |
| PHP | mindestens 7.4, getestet bis 8.4; keine Sprachmittel neuer als 7.4 (kein `match`, `enum`, `readonly`, `str_contains` …) |
| Abhängigkeiten | keine fremden Bibliotheken, kein Composer, kein Build-Schritt; nur WordPress-Funktionen |
| Theme | Die Website nutzt Kadence mit einem eigenen Child-Theme. Die Plugins dürfen vom Theme nicht abhängen |
| Sprache | Oberfläche, Kommentare und Funktionsnamen Deutsch; Präfix `auhof_` bzw. `sintoora_kalender_` |
| Text-Domain | `auhof-schulseiten` bzw. `sintoora-kalender` |
| Updates | `Update URI: https://codeberg.org/…` im Plugin-Kopf: Updates kommen aus dem Codeberg-Repository, nie von wordpress.org (Schutz vor gleichnamigen Fremd-Plugins). Die Updater-Klasse steckt in beiden Plugins, Änderungen in beiden nachziehen |

### Sicherheitsregeln für jeden Code
- **Ausgabe immer maskieren:** `esc_html`, `esc_attr`, `esc_url`, `wp_kses`. Daten für JavaScript mit `wp_json_encode( …, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT )`.
- **Eingaben bereinigen und begrenzen** (`sanitize_text_field`, `absint`, Längen, Positivlisten). Arrays prüfen (`is_array`, `is_string`).
- **Formulare:** Einstellungs-API (`register_setting` mit `sanitize_callback`) oder Nonce plus `current_user_can()`.
- **Rechte:** im Auhof-Plugin immer `auhof_schulseiten_recht()` (Standard `edit_others_pages`, also Redakteur und Administrator). Nicht `edit_pages`, das haben auf dieser Website auch Autoren.
- **Datenbank** nur über WordPress-Funktionen oder `$wpdb->prepare()`.
- **Keine REST- oder AJAX-Endpunkte**, wenn es ohne geht. Wenn nötig: `permission_callback`, Nonce, Rechte.
- PHP-Dateien beginnen mit `defined( 'ABSPATH' ) || exit;`.

## 3. So wird eine Änderung umgesetzt

1. **Lesen:** diese Datei, dann `README.md` und `CHANGELOG.md` des Plugins, dann den Code.
2. **Versionsnummer anheben**, neue Funktion `1.7.1` → `1.8.0`, Korrektur → `1.7.2`, an drei Stellen:
   - Plugin-Kopf `Version:`;
   - Konstante (`AUHOF_SCHULSEITEN_VERSION` bzw. `SINTOORA_KALENDER_VERSION`). Sie steuert auch das Neuladen von CSS/JS im Browser;
   - `readme.txt`: `Stable tag` und Changelog.
3. **`CHANGELOG.md`** ergänzen: Datum, was, warum, wie geprüft.
4. **Auf der Testinstallation prüfen:**
   - Funktion bei 390 und 1280 px Breite.
   - Bedienung nur mit der Tastatur.
   - Automatischer Barrierefreiheitstest (z. B. axe) ohne Befund.
   - Kein waagrechter Überlauf am Handy.
5. **Code prüfen**, z. B. mit:
   ```bash
   phpcs --standard=WordPress-Extra --extensions=php --report=summary <plugin-ordner>
   phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- --extensions=php <plugin-ordner>
   semgrep scan --metrics=off --config p/php --config p/owasp-top-ten --config p/xss --config p/javascript --config p/secrets <plugin-ordner>
   ```
   Beim Auhof-Plugin zusätzlich die Skripte in `tests/` (Aufruf steht jeweils oben in der Datei).
6. **Commit und Tag** (`v1.7.2`), dann `git push origin main v1.7.2` (Tag ausdrücklich mitschicken). **Der Tag ist die Veröffentlichung:** Danach bieten alle Websites das Update an. Für eine Erstinstallation entsteht das Plugin-Paket so:
   ```bash
   git archive --format=zip --prefix=auhof-schulseiten/ -o auhof-schulseiten-1.7.2.zip v1.7.2
   ```
   Hochladen im Backend unter Plugins → Installieren → Plugin hochladen; das ersetzt die alte Version. Kein ZIP mit Windows-„Komprimieren“ bauen: Es erzeugt Pfade mit `\`, die WordPress nicht versteht.

## 4. Bekannte Stolpersteine
- **CSS-Gewichtung:** Das Theme setzt allgemeine Linkregeln mit hoher Gewichtung. In Plugins hebt `:not(#_)` die Gewichtung, ohne `!important`.
- **`wpautop`:** Shortcodes immer allein in einer Zeile bzw. im Shortcode-Block; HTML aus Shortcodes wird nicht nachbearbeitet.
- **Bilder:** Das Auhof-Plugin entfernt Kamera- und GPS-Daten aus verkleinerten Fassungen und speichert neue Zwischengrößen als WebP (`includes/bilder.php`).
- **WP-Cron:** Hintergrundaufgaben (Sintoora-Abruf, Diashow-Fassungen, Bildbereinigung) laufen nur, wenn die Seite besucht wird oder ein echter Cronjob `wp-cron.php` aufruft.
- **Testinstallationen ohne Internetzugang** (oder mit gesperrten externen Verbindungen) zeigen im Sintoora-Kalender immer den zuletzt gespeicherten Stand.
