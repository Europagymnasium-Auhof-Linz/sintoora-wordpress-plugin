=== Sintoora Kalender (Europagymnasium Auhof) ===
Tags: kalender, schule, sintoora, termine
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schulkalender aus SINTOORA mit Klassen- und Reifeprüfungsfilter: Klassentermine, Ferien, Feiertage und Matura in einer Ansicht, ohne Lehrerdaten.

== Beschreibung ==

Das Plugin bindet den Schulkalender des Europagymnasiums Auhof aus dem Schulverwaltungssystem SINTOORA ein.

* Drei Sintoora-Kalender in einer Ansicht: Klassentermine (Schularbeiten, Tests, Schulveranstaltungen), allgemeine Termine (Ferien, Feiertage, schulautonome Tage, Elternabende usw.) und die Termine zur Reifeprüfung (Klausuren, Abgaben, Maturaball usw.)
* Filter „Reifeprüfung“ neben „Alle Klassen“: zeigt nur die Termine der Sintoora-Terminart „Reifeprüfung“; Direktlink …/klassentermin/?klasse=reifepruefung
* Klassen-Buttons nach Schulstufen, Termine nach Monaten gruppiert
* „Frühere Termine anzeigen“, Info-Aufklapper, Merken der gewählten Klasse
* Direktlink auf eine Klasse, z. B. …/klassentermin/?klasse=3b
* Termine einer ganzen Schulstufe (z. B. „8.“) erscheinen bei allen Klassen dieser Stufe
* Allgemeine Termine ohne Klassenzuordnung (z. B. Ferien, schulautonome Tage, KV-Stunden) erscheinen bei jeder Klasse
* Tests ohne Klassenzuordnung (Terminart „Leistungsfeststellung und standardisierte Testung“, z. B. „Test aus GS“) werden ausgeblendet
* Reifeprüfungs-Termine ohne Klasse erscheinen nur beim Filter „Reifeprüfung“ und bei „Alle Klassen“, nicht bei den einzelnen Klassen
* Steht ein Termin in mehreren Kalendern (gleiches Datum, gleicher Titel, gleiche Klassen), wird er nur einmal angezeigt
* Ohne Suchfeld, ohne Lehrer-Filter, ohne Lehrerdaten und ohne Teilnehmerlisten
* Schnell: Die Termine kommen sofort aus dem Zwischenspeicher, neu geholt wird im Hintergrund. Die Übertragung ist komprimiert (rund 35 KB statt 1,1 MB).
* Einstellungsseite „Sintoora Kalender“ (eigener Menüpunkt links): Links der Sintoora-Kalender eintragen, ändern, hinzufügen und entfernen, Stand je Kalender ansehen, Termine sofort neu laden

So funktioniert es:

1. Der Browser darf Sintoora nicht direkt abrufen (CORS). Deshalb holt WordPress die Termine selbst vom Server aus – immer nur von den unter „Sintoora Kalender“ (Menüpunkt links) eingetragenen Sintoora-Adressen (erlaubt sind nur Links zu app.sintoora.com).
2. Die Daten werden bereinigt: Es werden nur die Felder titel, raum, klassen, klassenpart und html übernommen, alles andere (z. B. Lehrerdaten) wird verworfen. Teilnehmerlisten (Personen-Symbol mit Schülernamen im Tooltip) werden entfernt. Das html wird mit einer strengen Allowlist gefiltert (keine Skripte, keine Event-Handler, Links nur seitenintern, keine url() in Styles).
3. Die bereinigten Termine werden gespeichert und jedem Besucher sofort ausgeliefert – niemand wartet auf Sintoora. Neu geholt wird im Hintergrund über WP-Cron: alle 15 Minuten und zusätzlich, sobald ein Besucher einen Stand bekommt, der älter als 15 Minuten ist. Dieser Besucher sieht dann noch den vorhandenen Stand, der nächste schon den neuen. Nur wenn es noch gar keinen gespeicherten Stand gibt (direkt nach der Installation), wartet der erste Besucher, bis die Termine geholt sind.
4. Ist Sintoora einmal nicht erreichbar, bleibt der letzte erfolgreiche Stand jedes Kalenders sichtbar. Fällt nur einer der Kalender aus, werden die anderen trotzdem aktualisiert.
5. Die Kalender werden zu einer Liste zusammengeführt; doppelte Termine (siehe oben) werden dabei nur einmal übernommen, und zwar der aus dem Klassenkalender (bzw. aus dem allgemeinen vor dem Reifeprüfungs-Kalender).
6. Die Kalenderseite lädt die Termine von der eigenen Adresse /wp-json/sintoora-kalender/v1/termine. Alle aktuellen Browser bekommen sie gzip-komprimiert (rund 35 KB statt 1,1 MB). Weil die Termine nicht in der Seite selbst stehen, friert WP Fastest Cache keine veralteten Termine ein.

== Installation ==

1. Im WordPress-Dashboard „Plugins → Installieren → Plugin hochladen“ öffnen.
2. Die Datei sintoora-kalender.zip auswählen und „Jetzt installieren“ klicken.
3. „Plugin aktivieren“ klicken. Beim Aktivieren werden die Termine gleich einmal von Sintoora geholt.
   Bei der ZIP-Datei „ohne Links“ zuerst unter „Sintoora Kalender“ (Menüpunkt links) die Links eintragen und speichern (siehe unten).
4. „Seiten“ öffnen und die Seite „Klassentermin“ bearbeiten.
5. Im klassischen Editor auf den Reiter „Text“ wechseln und den gesamten alten Kalender-Code löschen (alles mit div id="sintoora-kalender", style und script).
6. An seine Stelle nur diese eine Zeile einfügen:
   [sintoora_kalender]
7. „Aktualisieren“ klicken.
8. Den Cache von WP Fastest Cache leeren: in der Admin-Leiste oben „Cache löschen“ bzw. unter „WP Fastest Cache → Cache löschen → Alle Cache-Dateien löschen“.
9. Die Seite „Klassentermin“ in einem privaten Browserfenster aufrufen und prüfen.

Hinweis: Den Shortcode bitte nur einmal pro Seite verwenden.

Zwei ZIP-Dateien:

* sintoora-kalender.zip enthält die drei Links des Europagymnasiums als Voreinstellung (sie werden beim ersten Start in die Einstellungen übernommen). Diese Datei enthält damit die Sintoora-Tokens und sollte nicht weitergegeben werden.
* sintoora-kalender-ohne-links.zip ist sonst gleich, aber ohne voreingestellte Links und ohne Tokens. Sie kann weitergegeben werden; nach der Installation trägt man die Links in den Einstellungen ein. Für Updates einer Website, auf der die Links schon in den Einstellungen stehen, ist sie ebenso geeignet.

Update auf eine neue Version:

1. „Plugins → Installieren → Plugin hochladen“, die neue sintoora-kalender.zip auswählen, „Jetzt installieren“ und dann „Aktuelle Version durch hochgeladene ersetzen“ klicken.
2. Den Cache von WP Fastest Cache leeren.

Die gespeicherten Termine und die eingetragenen Kalender-Links bleiben dabei erhalten (sofern sich am Datenformat nichts geändert hat), es muss also niemand warten. Beim Update auf 1.6.0 werden die bisher im Plugin festen drei Links automatisch in die Einstellungen übernommen. Kommt mit dem Update ein Kalender dazu (wie die Reifeprüfung in 1.5.0), zeigt die Seite zuerst die bisherigen Termine; der neue Kalender wird im Hintergrund geholt und ist ab dem nächsten Laden dabei. Die regelmäßige Hintergrund-Aktualisierung trägt sich beim nächsten Abruf der Termine selbst ein.

== Was tun, wenn … ==

= … sich ein Sintoora-Link ändert oder ein Kalender dazukommen soll? =

Alles unter „Sintoora Kalender“ (Menüpunkt links) (nur für Administratoren; in der Plugin-Liste auch über den Link „Einstellungen“):

* Link ändern: beim Kalender den neuen Link einfügen und „Änderungen speichern“. Statt des ganzen Links genügt auch der Token (der Teil nach „t=“).
* Kalender hinzufügen: in der leeren Zeile unten Bezeichnung und Link eintragen und speichern (höchstens 10 Kalender).
* Kalender entfernen: Häkchen „Entfernen“ setzen und speichern.

In Sintoora muss bei der Anzeige das Auslieferungs-Format JSON eingestellt sein. Akzeptiert werden nur Links der Form https://app.sintoora.com/screen/calendar/screen?t=…; ein anderer Link wird mit einer Fehlermeldung abgelehnt, der bisherige bleibt dann unverändert.

Nach dem Speichern werden die Termine sofort neu geholt – Besucher müssen nicht warten. Antwortet Sintoora dabei gerade nicht, bleiben die Termine der unveränderten Kalender sichtbar. Wird nur eine Bezeichnung geändert, wird nichts neu geholt. Die Reihenfolge der Kalender zählt bei doppelten Terminen (der obere gewinnt). Danach den Cache von WP Fastest Cache leeren ist nicht nötig, weil die Termine nicht in der Seite selbst stehen.

Ebenfalls wichtig: Die Sintoora-Anzeigen müssen pro Termin das Feld „html“ mitliefern (darin stehen Datum, Termintext, Klassen und Raum). Liefert eine Anzeige nur titel, raum, klassen und klassenpart, kann der Kalender ihre Termine nicht anzeigen. Das Plugin behält dann den letzten guten Stand dieses Kalenders bzw. meldet einen Fehler (siehe unten, „sintoora_kalender_ohne_html“).

Wichtig: Das Plugin verwirft alle zusätzlichen Datenfelder und entfernt Teilnehmerlisten mit Schülernamen. Was Sintoora aber selbst in den Termintext schreibt (z. B. Kürzel oder Namen im Info-Text), wird angezeigt. Bitte daher in Sintoora darauf achten, was im Termintext erscheinen soll.

= … ein Termin bei allen Klassen erscheint, obwohl er nur eine Klasse betrifft? =

Termine ohne Klassenzuordnung gelten als allgemeine Termine und werden bei jeder Klasse angezeigt – egal aus welchem Kalender sie kommen. Das betrifft auch Einträge, bei denen in Sintoora die Klasse fehlt (z. B. „Kennenlerntage Kl. 1A, 1B“). Lösung: in Sintoora beim Termin die Klasse eintragen; für eine ganze Schulstufe genügt z. B. „5.“. Nach spätestens etwa 20 Minuten ist der Kalender aktuell.

Ausnahme: Tests ohne Klasse werden gar nicht angezeigt, auch nicht bei „Alle Klassen“. Als Test gilt jeder Termin der Sintoora-Terminart „Leistungsfeststellung und standardisierte Testung“ (Tests, Schularbeiten, standardisierte Testungen). „Ohne Klasse“ heißt: In den Klassenfeldern steht weder eine Klasse wie „7a“ noch eine Schulstufe wie „8.“. Sobald in Sintoora die Klasse eingetragen ist, erscheint der Test wieder – bei dieser Klasse. Andere Terminarten ohne Klasse (Ferien, Feiertage, Schulveranstaltungen usw.) sind davon nicht betroffen, auch wenn „Test“ im Titel steht.

Die betroffene Terminart steht in sintoora-kalender.php in der Zeile mit SINTOORA_KALENDER_OHNE_KLASSE_AUSBLENDEN. Sie muss genau so heißen wie im Tooltip des Terminart-Symbols in Sintoora (Groß-/Kleinschreibung egal). Benennt Sintoora die Terminart um, dort den neuen Namen eintragen.

Zweite Ausnahme: Termine der Terminart „Reifeprüfung“ ohne Klasse erscheinen nicht bei den einzelnen Klassen, sondern nur beim Filter „Reifeprüfung“ und bei „Alle Klassen“.

= … der Filter „Reifeprüfung“ fehlt oder leer ist? =

Der Button „Reifeprüfung“ erscheint nur, wenn es mindestens einen Termin der Sintoora-Terminart „Reifeprüfung“ gibt (erkannt am Tooltip des Terminart-Symbols, Groß-/Kleinschreibung egal). Zugeordnet werden diese Termine unabhängig davon, aus welchem Kalender sie kommen. Ist bei einem Reifeprüfungs-Termin in Sintoora eine Klasse eingetragen (z. B. „8a“), erscheint er zusätzlich bei dieser Klasse.

Der Filter hängt an der Terminart, nicht an einem bestimmten Kalender; der Kalender mit den Reifeprüfungs-Terminen muss aber in den Einstellungen eingetragen sein. Benennt Sintoora die Terminart um, in sintoora-kalender.php im JavaScript bei „categories“ den Wert hinter terminart: anpassen. Dort lassen sich nach demselben Muster auch weitere Filter für andere Terminarten ergänzen.

= … ein Termin doppelt erscheint? =

Doppelt eingetragene Termine werden nur dann zusammengefasst, wenn Datum/Uhrzeit, Titel und Klassen genau gleich sind. Steht derselbe Termin in zwei Kalendern mit unterschiedlichem Titel oder unterschiedlicher Klassenangabe (z. B. „Projektwoche Vorarlberg 4BC“ und „Projektwoche Vorarlberg“), erscheint er zweimal. Lösung: in Sintoora einen der beiden Einträge entfernen oder beide gleich benennen.

= … sich die Termine nicht aktualisieren? =

* Die Termine werden im Hintergrund alle 15 Minuten neu geholt, der Browser hält frische Termine zusätzlich bis zu 5 Minuten. Änderungen in Sintoora sind also normalerweise nach spätestens etwa 20 Minuten sichtbar.
* Die Hintergrund-Aktualisierung läuft über WP-Cron. WP-Cron startet nur, wenn WordPress selbst eine Anfrage bearbeitet: Seiten, die WP Fastest Cache aus seinem Cache ausliefert, zählen nicht – der Termin-Abruf des Kalenders und das Dashboard schon. War längere Zeit niemand auf der Website, sieht der erste Besucher daher noch den älteren Stand (sofort, ohne Wartezeit); ab dem nächsten Laden ist der Kalender aktuell.
* Wer das vermeiden möchte: beim Webhoster einen Cronjob einrichten, der alle 15 Minuten die Adresse https://www.europagym.at/wp-cron.php aufruft. Das ist optional und funktioniert zusätzlich zum normalen WP-Cron.
* Ist WP-Cron auf dem Server abgeschaltet (DISABLE_WP_CRON) und läuft auch kein Cronjob, holt der nächste Besucher die Termine nach 90 Sekunden selbst. Der Kalender bleibt also nie dauerhaft alt.
* Sofort neu laden: unter „Sintoora Kalender“ (Menüpunkt links) auf „Termine jetzt neu laden“ klicken. Die Seite zeigt außerdem je Kalender, wie viele Termine gespeichert sind, wann sie zuletzt erfolgreich geholt wurden und den letzten Fehler.
* Kontrolle: Die Adresse https://www.europagym.at/wp-json/sintoora-kalender/v1/termine im Browser öffnen. In den Entwicklerwerkzeugen (F12 → Netzwerk → Antwort-Header) zeigt „X-Sintoora-Kalender-Stand“, wann die Termine zuletzt (zumindest teilweise) erfolgreich von Sintoora geholt wurden (Zeitangabe in UTC, also 1 bzw. 2 Stunden vor der österreichischen Zeit). Steht dort „Cache-Control: no-cache“, war der ausgelieferte Stand älter als 15 Minuten und der Abruf im Hintergrund wurde gerade angestoßen.
* Bleibt dieser Stand alt, hat der letzte Abruf von Sintoora nicht geklappt. Dann zeigt der Kalender den letzten erfolgreichen Stand; nach einem Fehler versucht das Plugin es alle 2 Minuten erneut. Den Grund und den betroffenen Kalender zeigt die Einstellungsseite unter „Letzter Fehler“ (und in den 2 Minuten nach dem Fehler der Antwort-Header „X-Sintoora-Kalender-Fehler“, siehe nächster Abschnitt).
* Falls ein Cache-Plugin oder der Webhoster auch Adressen mit /wp-json/ zwischenspeichert, dort eine Ausnahme für „wp-json/sintoora-kalender“ eintragen.

= … der Kalender langsam lädt? =

Prüfen in den Entwicklerwerkzeugen (F12 → Netzwerk, Seite neu laden, Eintrag „termine“ anklicken):

* „Content-Encoding: gzip“ und rund 35 KB übertragen: Die Komprimierung wirkt. Fehlt sie, hat ein Proxy oder Sicherheits-Plugin die Anfrage verändert oder ein anderes Plugin übernimmt die Ausgabe; die Termine kommen dann unkomprimiert (rund 1,1 MB), funktionieren aber genauso.
* Die Wartezeit („Warten“/„Waiting for server response“) liegt normalerweise unter einer Sekunde, weil die Termine aus dem Zwischenspeicher kommen. Länger dauert es nur direkt nach der Installation, solange es noch keinen gespeicherten Stand gibt.

= … eine Fehlermeldung erscheint? =

Der Kalender zeigt dann „Die Termine konnten gerade nicht geladen werden …“ und darunter einen technischen Hinweis:

* „HTTP 503“: Der Webserver hat keinen der Sintoora-Kalender erreicht und hat noch keinen gespeicherten Stand (z. B. direkt nach der Installation), oder es ist noch gar kein Kalender eingetragen. Unter „Sintoora Kalender“ (Menüpunkt links) die Links prüfen (sie müssen im Browser JSON-Daten liefern) und „Termine jetzt neu laden“ klicken.
* „HTTP 404“: Das Plugin ist nicht aktiv, oder WordPress kennt die Adresse noch nicht. Plugin aktivieren; sonst „Einstellungen → Permalinks“ öffnen und ohne Änderung „Änderungen speichern“ klicken.
* „HTTP 401“ oder „HTTP 403“: Ein Sicherheits-Plugin sperrt die REST-Schnittstelle für Besucher. Dort die Route „sintoora-kalender/v1“ (bzw. „/wp-json/sintoora-kalender/“) freigeben.
* „Zeitüberschreitung“ oder „Failed to fetch“: Die Website selbst hat zu langsam bzw. gar nicht geantwortet. Später erneut versuchen.

Den genauen Grund zeigt die Adresse https://www.europagym.at/wp-json/sintoora-kalender/v1/termine im Antwort-Header „X-Sintoora-Kalender-Fehler“ (F12 → Netzwerk → Antwort-Header; nur in den 2 Minuten nach einem fehlgeschlagenen Abruf). Er nennt den betroffenen Kalender und den Fehlercode, z. B. „allgemein=sintoora_kalender_status“. Ist nur ein Kalender gestört, zeigt die Seite trotzdem alle Termine an – für den gestörten Kalender den letzten guten Stand (bzw. gar keine Termine aus diesem Kalender, falls es noch keinen gibt).

* sintoora_kalender_verbindung: Sintoora ist vom Webserver aus nicht erreichbar (Netzwerk, Zeitüberschreitung nach 6 Sekunden, SSL-Problem).
* sintoora_kalender_status: Sintoora antwortet mit einem Fehler (z. B. Link ungültig oder abgelaufen) oder leitet auf eine andere Adresse um. Weiterleitungen folgt das Plugin bewusst nicht – dann den neuen Link in den Einstellungen eintragen.
* sintoora_kalender_json / sintoora_kalender_format: Die Antwort ist kein passendes JSON. In Sintoora prüfen, ob bei der Anzeige das Auslieferungs-Format JSON eingestellt ist.
* sintoora_kalender_ohne_html: Sintoora liefert die Termine ohne das Feld „html“. In Sintoora bei der Anzeige die Termin-Darstellung (HTML) wieder mitliefern lassen.
* sintoora_kalender_groesse: Die Antwort ist leer oder größer als 5 MB.

Bleibt der Kalender dauerhaft bei „Termine werden geladen …“ stehen, wird das JavaScript nicht ausgeführt: Prüfen, ob der Shortcode genau [sintoora_kalender] lautet, und testweise in WP Fastest Cache das Kombinieren/Minimieren von JavaScript ausschalten.

„Keine Termine … ab heute.“ ist keine Fehlermeldung: Für diese Auswahl gibt es einfach keine kommenden Termine.

== Datenschutz und Sicherheit ==

* Abgerufen werden nur die in den Einstellungen eingetragenen Sintoora-Adressen. Eintragen können sie nur Administratoren, und nur Links der Form https://app.sintoora.com/screen/calendar/screen?t=<Token> werden angenommen (kein anderer Server, Pfad oder Parameter). Die REST-Route nimmt keine Parameter an und kann nicht als Weiterleitung zu anderen Adressen missbraucht werden.
* Die Links (mit den Sintoora-Tokens) stehen in der WordPress-Datenbank, nicht in der REST-Antwort und nicht in der Kalenderseite. Die Einstellungsseite und „Termine jetzt neu laden“ sind nur für Administratoren und mit WordPress-Nonce geschützt.
* Übernommen werden nur titel, raum, klassen, klassenpart und html; alle anderen Felder werden schon vor dem Speichern verworfen.
* Ausgeblendete Tests ohne Klasse werden schon auf dem Server weggelassen und gar nicht an den Browser gesendet.
* Teilnehmerlisten werden entfernt: Von den Symbolen eines Termins bleibt nur das Symbol der Terminart (z. B. „Allgemein (Homepage)“). Das Personen-Symbol, dessen Tooltip die Namen der Schülerinnen und Schüler enthält, wird samt Namen verworfen.
* Das html wird auf dem Server mit wp_kses und einer strengen Allowlist gefiltert und im Browser ein zweites Mal geprüft.
* Es werden keine Cookies gesetzt, keine Besucherdaten gespeichert und nichts protokolliert.
* Das Plugin bekommt keine automatischen Updates von wordpress.org (Update URI: false), damit es nie durch ein fremdes Plugin gleichen Namens ersetzt werden kann.

== Technische Details ==

* Shortcode: [sintoora_kalender]
* REST-Route: GET /wp-json/sintoora-kalender/v1/termine, öffentlich, ohne Parameter, Antwort {"appointments":[…]}, Header „Cache-Control: public, max-age=300“ (Stand jünger als 15 Minuten) bzw. „no-cache“ (älterer Stand, Abruf im Hintergrund angestoßen), „Vary: Accept-Encoding“, „X-Sintoora-Kalender-Stand“ (Zeitpunkt des letzten erfolgreichen Abrufs, UTC) und nach Fehlern „X-Sintoora-Kalender-Fehler“. Gibt es weder frische Daten noch einen letzten Stand, antwortet sie mit HTTP 503.
* Komprimierung: Schickt der Browser „Accept-Encoding: gzip“, sendet das Plugin die Antwort über den Filter rest_pre_serve_request selbst gzip-komprimiert (nur diese Route, nur GET mit Status 200, nicht bei den WordPress-Parametern _jsonp, _envelope, _embed und _pretty und nicht, wenn PHP oder ein anderer Ausgabepuffer schon komprimiert). In allen anderen Fällen gibt WordPress die Antwort wie gewohnt aus.
* Hintergrund-Aktualisierung (WP-Cron): Ereignis „sintoora_kalender_regelmaessig“ alle 15 Minuten (eigenes Intervall „sintoora_kalender_15_minuten“) und einmaliges Ereignis „sintoora_kalender_sofort“, wenn ein Besucher einen älteren Stand bekommt. Ein Hintergrund-Abruf entfällt, solange der Stand vollständig und jünger als 10 Minuten ist, während eines laufenden Abrufs und während der Pause nach einem Fehler. Läuft er nach 90 Sekunden noch nicht, holt der nächste Besucher selbst.
* Gespeicherte Werte: Transient „sintoora_kalender_termine“ (15 Minuten), Option „sintoora_kalender_letzter_stand“ (nicht automatisch geladen; jeder Kalender getrennt, damit bei einer Störung nur der betroffene auf den letzten Stand zurückfällt), kurzlebige Transients „sintoora_kalender_sperre“ (60 Sekunden: auch bei vielen gleichzeitigen Besuchern höchstens eine Abrufrunde pro Minute), „sintoora_kalender_pause“ (2 Minuten nach einem Fehler, enthält Kalender und Fehlercode) und „sintoora_kalender_anstoss“ (Zeitpunkt, zu dem der Hintergrund-Abruf angestoßen wurde).
* Abruf: wp_remote_get mit SSL-Prüfung, 6 Sekunden Zeitlimit pro Kalender (alle drei zusammen bleiben unter den 20 Sekunden, die der Browser wartet), höchstens 5 MB, ohne Weiterleitungen. Die Kalender werden nacheinander geholt.
* Cache-Schlüssel: gebildet aus dem Datenformat und den Adressen der gespeicherten Kalender. Kommt ein Kalender dazu, bleibt der gespeicherte Stand gültig, gilt aber als unvollständig und wird im Hintergrund ergänzt.
* Einstellungen: Option „sintoora_kalender_quellen“ (Liste mit internem Namen, Bezeichnung und Link; der interne Name bleibt beim Umbenennen gleich) und Option „sintoora_kalender_status“ (je Kalender letzter Erfolg, Anzahl, letzter Fehler; nicht automatisch geladen). Mit vielen Kalendern dauert ein Abruf entsprechend länger (6 Sekunden Zeitlimit je Kalender); Besucher merken davon dank Hintergrund-Abruf nichts.
* CSS und JavaScript werden nur auf Seiten mit dem Shortcode geladen.
* Deaktivieren entfernt die geplanten Hintergrund-Abrufe (die Einstellungen bleiben). Beim Löschen des Plugins unter „Plugins“ werden zusätzlich alle gespeicherten Werte und die Einstellungen entfernt.

== Changelog ==

= 1.8.0 =
* Updates: GitHub (Europagymnasium-Auhof-Linz/sintoora-wordpress-plugin) und Codeberg werden gefragt, die höhere Version gewinnt – Übergang für den Umzug nach GitHub.

= 1.7.2 =
* Updates: neue Adresse des Repositorys (Organisation europagymnasium-auhof-linz); Weiterleitungen von Codeberg werden innerhalb von codeberg.org gefolgt, damit ein späterer Umzug Updates nicht mehr unterbricht.

= 1.7.1 =
* Einstellungen als eigener Menüpunkt „Sintoora Kalender“ in der linken Leiste (statt unter Einstellungen). Die alte Adresse leitet weiter.

= 1.7.0 =
* Updates aus Codeberg: WordPress bietet neue Versionen aus dem öffentlichen Repository europagymnasium-auhof-linz/sintoora-wordpress-plugin an (neuer Tag vX.Y.Z).
* Voreinstellung der Kalender-Links ist leer; die Links stehen nur in den Einstellungen.

= 1.6.1 =
* Hinweis im Kalender, wenn die Termine älter als 7 Tage sind (Sintoora länger nicht erreichbar).

= 1.6.0 =
* Neue Einstellungsseite „Einstellungen → Sintoora Kalender“: Kalender-Links eintragen, ändern, hinzufügen und entfernen (auch nur der Token genügt), mit strenger Prüfung auf Sintoora-Links. Nach dem Speichern werden die Termine sofort neu geholt.
* Stand je Kalender (Anzahl, letzter Erfolg, letzter Fehler) und Button „Termine jetzt neu laden“.
* Die bisher festen drei Links werden beim Update automatisch übernommen. Zusätzlich gibt es die ZIP-Datei „ohne Links“ zum Weitergeben.
* Behoben: Fiel ein Kalender aus, von dem es noch keinen gespeicherten Stand gab, bekamen Besucher während der 2-Minuten-Pause einen Fehler (503) statt der Termine der übrigen Kalender.

= 1.5.0 =
* Dritter Sintoora-Kalender mit den Terminen zur Reifeprüfung.
* Neuer Filter-Button „Reifeprüfung“ neben „Alle Klassen“ (auch als Direktlink ?klasse=reifepruefung und gemerkt wie eine Klasse). Reifeprüfungs-Termine ohne Klasse erscheinen nur dort und bei „Alle Klassen“, nicht bei jeder einzelnen Klasse.
* Zeitlimit pro Kalender 6 Sekunden (alle drei zusammen bleiben unter der Wartezeit des Browsers).
* Update ohne Wartezeit: Der gespeicherte Stand aus 1.4.0 bleibt gültig; der neue Kalender wird im Hintergrund ergänzt.

= 1.4.0 =
* Tests ohne Klassenzuordnung (Terminart „Leistungsfeststellung und standardisierte Testung“, z. B. „Test aus GS“) werden ausgeblendet – auch bei „Alle Klassen“. Andere Termine ohne Klasse erscheinen weiterhin bei jeder Klasse.
* Wirkt sofort nach dem Update, ohne neuen Abruf von Sintoora.

= 1.3.0 =
* Schneller: Besucher bekommen die Termine immer sofort aus dem Zwischenspeicher. Neu geholt wird im Hintergrund über WP-Cron – alle 15 Minuten und sobald ein Besucher einen älteren Stand bekommt. Läuft der Hintergrund-Abruf nicht, holt nach 90 Sekunden der nächste Besucher selbst.
* Die Termine werden gzip-komprimiert übertragen (rund 35 KB statt 1,1 MB).
* Ein älterer Stand wird mit „Cache-Control: no-cache“ ausgeliefert, damit das nächste Laden den neuen Stand zeigt.
* Der gespeicherte Stand aus Version 1.2.0 bleibt beim Update gültig.
* Beim Deaktivieren werden die geplanten Hintergrund-Abrufe entfernt.

= 1.2.0 =
* Zweiter Sintoora-Kalender mit den allgemeinen Terminen (Ferien, Feiertage, schulautonome Tage usw.) wird mit dem Klassenkalender zusammengeführt.
* Termine, die in beiden Kalendern gleich eingetragen sind, erscheinen nur einmal.
* Fällt einer der beiden Kalender aus, wird nur dieser durch seinen letzten guten Stand ersetzt; der andere wird weiter aktualisiert.
* Zeitlimit pro Kalender 8 Sekunden (beide zusammen bleiben unter der Wartezeit des Browsers).
* Der Zwischenspeicher wird beim Update automatisch erneuert.

= 1.1.0 =
* Allgemeine Termine ohne Klassenzuordnung erscheinen jetzt auch bei jeder einzelnen Klasse (nicht nur bei „Alle Klassen“).
* Datenschutz: Teilnehmerlisten mit Schülernamen (Tooltip des Personen-Symbols) werden serverseitig entfernt.
* Der Zwischenspeicher wird beim Update automatisch erneuert.

= 1.0.0 =
* Erste Version.
