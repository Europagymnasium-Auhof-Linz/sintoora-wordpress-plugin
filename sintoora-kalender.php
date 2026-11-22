<?php
/**
 * Plugin Name:       Sintoora Kalender (Europagymnasium Auhof)
 * Plugin URI:        https://www.europagym.at/
 * Description:       Zeigt den Schulkalender aus SINTOORA mit Klassenfilter an – ohne Suchfeld, ohne Lehrer-Filter, ohne Lehrerdaten und ohne Teilnehmerlisten (Schülernamen). Führt drei Sintoora-Kalender zusammen: Klassentermine, allgemeine Termine wie Ferien, Feiertage und schulautonome Tage sowie die Termine zur Reifeprüfung (eigener Filter „Reifeprüfung“). Allgemeine Termine ohne Klassenzuordnung erscheinen bei jeder Klasse, Tests ohne Klassenzuordnung werden ausgeblendet. Die Links der Sintoora-Kalender werden im Menüpunkt „Sintoora Kalender“ (linke Leiste) eingetragen. Einbinden mit dem Shortcode [sintoora_kalender]. Die Termine werden von WordPress serverseitig geladen, bereinigt, zwischengespeichert und im Hintergrund aktualisiert; die Antwort wird gzip-komprimiert.
 * Version:           1.7.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Europagymnasium Auhof
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://codeberg.org/europagym-auhof/sintoora-wordpress-plugin
 *
 * Ablauf:
 * 1. Die Kalenderseite enthält nur [sintoora_kalender]. Der Shortcode gibt das leere
 *    Kalender-Gerüst aus, CSS und JavaScript kommen über wp_add_inline_style/-script.
 * 2. Das JavaScript lädt die Termine von der eigenen REST-Route
 *    /wp-json/sintoora-kalender/v1/termine (gleiche Domain → kein CORS-Problem).
 * 3. Die REST-Route liefert die Termine sofort aus dem Zwischenspeicher. Neu geholt
 *    wird im Hintergrund (WP-Cron): regelmäßig alle 15 Minuten und zusätzlich, sobald
 *    ein Besucher einen Stand bekommt, der älter als 15 Minuten ist. Der Server holt
 *    die Daten von den Sintoora-Adressen aus den Einstellungen (nur Links der Form
 *    https://app.sintoora.com/screen/calendar/screen?t=…), bereinigt sie und speichert sie. Nur wenn es noch gar keinen
 *    Stand gibt (oder der Hintergrund-Abruf nicht läuft), holt der Besucher selbst.
 * 4. Vor der Ausgabe werden die Kalender zu einer Liste zusammengeführt. Steht ein
 *    Termin in mehreren (gleiches Datum, gleicher Titel, gleiche Klassen), erscheint er
 *    nur einmal. Tests (Terminart „Leistungsfeststellung und standardisierte Testung“)
 *    ohne Klasse werden dabei weggelassen.
 * 5. Im Browser gibt es neben den Klassen den Filter „Reifeprüfung“ (Terminart
 *    „Reifeprüfung“). Diese Termine erscheinen nur dort und bei „Alle Klassen“.
 * 6. Unterstützt der Browser gzip, wird die Antwort komprimiert gesendet
 *    (rund 1,1 MB → etwa 35 KB).
 *
 * @package SintooraKalender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * =====================================================================
 * Einstellungen
 * =====================================================================
 */

/** Version des Plugins. */
const SINTOORA_KALENDER_VERSION = '1.7.1';

// Updates aus Codeberg (auch im Hintergrund per WP-Cron, daher nicht nur im Backend).
require_once __DIR__ . '/class-sintoora-kalender-codeberg-updater.php';
new Sintoora_Kalender_Codeberg_Updater( __FILE__, 'europagym-auhof/sintoora-wordpress-plugin', 'Sintoora Kalender (Europagymnasium Auhof)', SINTOORA_KALENDER_VERSION );

/** Ab diesem Alter (Sekunden) steht beim Kalender ein Hinweis, dass die Termine veraltet sein können. */
const SINTOORA_KALENDER_VERALTET_AB = 7 * DAY_IN_SECONDS;

/**
 * Stand der Bereinigung und der gespeicherten Form (Teil des Cache-Schlüssels).
 * Nur erhöhen, wenn sich daran etwas ändert – dann werden alte Daten nicht mehr
 * verwendet. Bleibt er gleich, gilt der gespeicherte Stand nach einem Update weiter.
 */
const SINTOORA_KALENDER_DATENFORMAT = '1.2.0';

/**
 * Die Links der Sintoora-Anzeigen (Art der Anzeige mit Auslieferungs-Format JSON) stehen
 * in den Einstellungen (Menüpunkt „Sintoora Kalender“, Option
 * SINTOORA_KALENDER_OPTION_QUELLEN). Erlaubt sind nur Links der Form
 * https://app.sintoora.com/screen/calendar/screen?t=<Token> – Anfragen von außen können
 * keine andere Adresse angeben. Die Reihenfolge zählt: Steht ein Termin in mehreren
 * Kalendern, wird der aus dem ersten behalten.
 *
 * Voreinstellung: wird einmalig in die Einstellungen übernommen, solange dort noch nichts
 * gespeichert ist (z. B. beim Update von Version 1.5.0). Danach gelten nur noch die
 * Einstellungen. Seit 1.7.0 ist die Liste leer: Die Links enthalten Token (wie Passwörter)
 * und stehen nur in den Einstellungen der Website, nie im öffentlichen Repository.
 */
const SINTOORA_KALENDER_STANDARD_QUELLEN = array(); // Im Repository bewusst leer: Links mit Token nur in den Einstellungen.

/** Optionen für die Kalender-Links und den Abruf-Status je Kalender. */
const SINTOORA_KALENDER_OPTION_QUELLEN = 'sintoora_kalender_quellen';
const SINTOORA_KALENDER_OPTION_STATUS  = 'sintoora_kalender_status';
const SINTOORA_KALENDER_MAX_QUELLEN    = 10;

/** Namen der gespeicherten Werte (Transients bzw. Option). */
const SINTOORA_KALENDER_CACHE         = 'sintoora_kalender_termine';
const SINTOORA_KALENDER_LETZTER_STAND = 'sintoora_kalender_letzter_stand';
const SINTOORA_KALENDER_SPERRE        = 'sintoora_kalender_sperre';
const SINTOORA_KALENDER_PAUSE         = 'sintoora_kalender_pause';
const SINTOORA_KALENDER_ANSTOSS       = 'sintoora_kalender_anstoss';

/** Namen der WP-Cron-Ereignisse und des 15-Minuten-Intervalls. */
const SINTOORA_KALENDER_CRON_REGELMAESSIG = 'sintoora_kalender_regelmaessig';
const SINTOORA_KALENDER_CRON_SOFORT       = 'sintoora_kalender_sofort';
const SINTOORA_KALENDER_CRON_INTERVALL    = 'sintoora_kalender_15_minuten';

/** Zeiten in Sekunden. */
const SINTOORA_KALENDER_CACHE_DAUER       = 900;  // 15 Minuten gelten Daten als frisch.
const SINTOORA_KALENDER_SPERRE_DAUER      = 60;   // Höchstens ein Abruf pro Minute (auch nach Abstürzen).
const SINTOORA_KALENDER_PAUSE_DAUER       = 120;  // Nach einem Fehler 2 Minuten nicht erneut versuchen.
const SINTOORA_KALENDER_MINDESTABSTAND    = 600;  // Hintergrund-Abruf entfällt, solange der Stand jünger als 10 Minuten ist.
const SINTOORA_KALENDER_HINTERGRUND_FRIST = 90;   // Läuft der Hintergrund-Abruf so lange nicht, holt der nächste Besucher selbst.
const SINTOORA_KALENDER_ANSTOSS_DAUER     = 3600; // Ein offener Anstoß wird spätestens nach 1 Stunde vergessen.
const SINTOORA_KALENDER_TIMEOUT           = 6;    // Pro Kalender; alle drei zusammen bleiben unter den 20 s des Browsers.

/** Grenzen gegen übergroße oder kaputte Antworten. */
const SINTOORA_KALENDER_MAX_BYTES   = 5242880; // 5 MB.
const SINTOORA_KALENDER_MAX_TERMINE = 5000;
const SINTOORA_KALENDER_MAX_HTML    = 100000;  // Zeichen pro Termin.
const SINTOORA_KALENDER_MAX_TEXT    = 1000;    // Zeichen pro Textfeld.

/** Diese Felder werden übernommen – alles andere (z. B. Lehrerdaten) wird verworfen. */
const SINTOORA_KALENDER_TEXTFELDER = array( 'titel', 'raum', 'klassen', 'klassenpart' );

/**
 * Terminarten (Name wie im Tooltip des Terminart-Symbols in Sintoora), deren Termine
 * ausgeblendet werden, wenn sie keiner Klasse zugeordnet sind – z. B. ein „Test aus GS“
 * ohne Klasse. Alle anderen Termine ohne Klasse (Ferien, Feiertage, Schulveranstaltungen
 * usw.) erscheinen weiterhin bei jeder Klasse.
 */
const SINTOORA_KALENDER_OHNE_KLASSE_AUSBLENDEN = array( 'Leistungsfeststellung und standardisierte Testung' );

/*
 * =====================================================================
 * Bereinigung
 * =====================================================================
 */

/**
 * Erlaubte HTML-Elemente und -Attribute für das Feld "html".
 *
 * Zusätzlich zur Allowlist werden die Attributwerte über value_callback streng
 * geprüft (z. B. href nur "#…", keine url() in SVG-Attributen).
 *
 * @return array
 */
function sintoora_kalender_erlaubtes_html() {
	$style  = array( 'value_callback' => 'sintoora_kalender_ist_sicherer_style' );
	$klasse = array( 'value_callback' => 'sintoora_kalender_ist_klassenliste' );
	$wert   = array( 'value_callback' => 'sintoora_kalender_ist_einfacher_wert' );

	return array(
		'span'   => array(
			'style' => $style,
			'class' => $klasse,
		),
		'div'    => array(
			'style' => $style,
			'class' => $klasse,
			'id'    => array( 'value_callback' => 'sintoora_kalender_ist_id' ),
		),
		'a'      => array(
			'href'        => array( 'value_callback' => 'sintoora_kalender_ist_anker' ),
			'class'       => $klasse,
			'data-toggle' => array( 'values' => array( 'collapse' ) ),
		),
		'svg'    => array(
			'fill'         => $wert,
			'viewbox'      => $wert,
			'stroke-width' => $wert,
			'stroke'       => $wert,
			'width'        => $wert,
			'height'       => $wert,
			'xmlns'        => array( 'values' => array( 'http://www.w3.org/2000/svg' ) ),
		),
		'path'   => array(
			'd'               => array( 'value_callback' => 'sintoora_kalender_ist_pfad' ),
			'stroke-linecap'  => $wert,
			'stroke-linejoin' => $wert,
			'fill'            => $wert,
			'stroke'          => $wert,
		),
		'title'  => array(),
		'b'      => array(),
		'strong' => array(),
		'em'     => array(),
		'i'      => array(),
		'br'     => array(),
		'p'      => array(),
	);
}

/**
 * Erlaubte CSS-Eigenschaften in style-Attributen (Filter "safe_style_css").
 * Wird nur während der Bereinigung aktiviert, damit der Rest der Website unberührt bleibt.
 *
 * @return string[]
 */
function sintoora_kalender_css_eigenschaften() {
	return array( 'color', 'font-size', 'font-weight', 'margin-left', 'margin-right', 'text-indent', 'line-height' );
}

/**
 * Prüft, ob ein Attributwert ein String ist und auf das Muster passt.
 *
 * @param mixed  $wert   Attributwert (von wp_kses bereits normalisiert).
 * @param string $muster Regulärer Ausdruck.
 * @return bool
 */
function sintoora_kalender_passt( $wert, $muster ) {
	return is_string( $wert ) && 1 === preg_match( $muster, $wert );
}

/**
 * Zweite Prüfung des style-Attributs NACH safecss_filter_attr: nur Buchstaben, Ziffern
 * und # . , % : ; - Leerzeichen. Damit sind Klammern (url(), expression(), var() …),
 * Backslashes, Zeichenreferenzen, Anführungszeichen und spitze Klammern ausgeschlossen.
 *
 * @param mixed $wert Attributwert.
 * @return bool
 */
function sintoora_kalender_ist_sicherer_style( $wert ) {
	return sintoora_kalender_passt( $wert, '/^[A-Za-z0-9#.,%:; \-]{1,300}$/' );
}

/**
 * Klassenliste wie "collapse ml-2 pl-2 border-left".
 *
 * @param mixed $wert Attributwert.
 * @return bool
 */
function sintoora_kalender_ist_klassenliste( $wert ) {
	return sintoora_kalender_passt( $wert, '/^[A-Za-z0-9_ \-]{0,200}$/' );
}

/**
 * Element-ID wie "info-199138". Nur mit Präfix "info-", damit keine IDs des Themes
 * (z. B. "content", "primary") doppelt vorkommen können.
 *
 * @param mixed $wert Attributwert.
 * @return bool
 */
function sintoora_kalender_ist_id( $wert ) {
	return sintoora_kalender_passt( $wert, '/^info-[A-Za-z0-9_\-]{1,58}$/' );
}

/**
 * Link nur innerhalb der Seite: "#info-199138". Kein http:, javascript:, data: usw.
 *
 * @param mixed $wert Attributwert.
 * @return bool
 */
function sintoora_kalender_ist_anker( $wert ) {
	return sintoora_kalender_passt( $wert, '/^#info-[A-Za-z0-9_\-]{1,58}$/' );
}

/**
 * Einfache SVG-Werte wie "#00cccc", "currentColor", "0 0 24 24", "1.5", "round".
 * Keine Klammern und keine Doppelpunkte → kein url(…), kein javascript:.
 *
 * @param mixed $wert Attributwert.
 * @return bool
 */
function sintoora_kalender_ist_einfacher_wert( $wert ) {
	return sintoora_kalender_passt( $wert, '/^[A-Za-z0-9#.,% \-]{1,100}$/' );
}

/**
 * SVG-Pfaddaten (Buchstaben-Befehle, Zahlen, Leerzeichen, Komma, Punkt, Vorzeichen).
 *
 * @param mixed $wert Attributwert.
 * @return bool
 */
function sintoora_kalender_ist_pfad( $wert ) {
	return sintoora_kalender_passt( $wert, '/^[A-Za-z0-9.,+\s\-]{1,5000}$/' );
}

/**
 * Bereinigt ein Textfeld. null bleibt null, alles andere wird zu reinem Text.
 *
 * @param mixed $wert Wert aus Sintoora.
 * @return string|null
 */
function sintoora_kalender_text( $wert ) {
	if ( null === $wert || ! is_scalar( $wert ) ) {
		return null;
	}
	$text = sanitize_text_field( (string) $wert );
	if ( strlen( $text ) > SINTOORA_KALENDER_MAX_TEXT ) {
		$text = mb_substr( $text, 0, SINTOORA_KALENDER_MAX_TEXT, 'UTF-8' );
	}
	return $text;
}

/**
 * Entfernt Teilnehmerlisten (Schülernamen) aus dem HTML eines Termins.
 *
 * Sintoora zeigt bei manchen Terminen nach dem Termintext ein Personen-Symbol, dessen
 * Tooltip (<title>) die Namen der teilnehmenden Schülerinnen und Schüler enthält.
 * Behalten wird deshalb nur das erste SVG eines Termins (das Symbol der Terminart,
 * z. B. „Allgemein (Homepage)“) und nur, wenn davor noch kein Text steht. Alle anderen
 * SVGs werden samt Inhalt entfernt, ebenso <title>-Elemente außerhalb eines SVG.
 *
 * @param string $html HTML aus Sintoora.
 * @return string
 */
function sintoora_kalender_ohne_teilnehmer( $html ) {
	$teile = preg_split( '@(<svg\b[^>]*>|</svg\s*>)@i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $teile ) {
		return '';
	}

	$ergebnis = '';
	$tiefe    = 0;     // Verschachtelungstiefe der SVGs.
	$anzahl   = 0;     // Bisherige SVGs auf oberster Ebene.
	$behalten = false; // Wird das aktuelle SVG behalten?

	foreach ( $teile as $nr => $teil ) {
		if ( 0 === $nr % 2 ) {
			// HTML zwischen den SVG-Tags.
			if ( 0 === $tiefe ) {
				$ergebnis .= preg_replace( '@<title\b[^>]*>.*?(?:</title\s*>|$)@is', '', $teil );
			} elseif ( $behalten && 1 === $tiefe ) {
				$ergebnis .= $teil;
			}
			continue;
		}

		if ( '</' === substr( $teil, 0, 2 ) ) {
			// Schließendes </svg>; ein überzähliges wird verworfen.
			if ( $tiefe > 0 ) {
				if ( $behalten && 1 === $tiefe ) {
					$ergebnis .= $teil;
				}
				--$tiefe;
			}
			continue;
		}

		// Öffnendes <svg …> bzw. leeres <svg … />.
		$leer = '/>' === substr( $teil, -2 );
		if ( 0 === $tiefe ) {
			++$anzahl;
			$behalten = 1 === $anzahl && '' === wp_strip_all_tags( $ergebnis );
			if ( $behalten ) {
				$ergebnis .= $teil;
			}
		}
		if ( ! $leer ) {
			++$tiefe;
		}
	}

	return $ergebnis;
}

/**
 * Bereinigt das HTML eines Termins mit wp_kses und der eigenen Allowlist.
 * Muss innerhalb von sintoora_kalender_bereinigen() aufgerufen werden (CSS-Filter aktiv).
 *
 * @param mixed $html    HTML aus Sintoora.
 * @param array $erlaubt Allowlist aus sintoora_kalender_erlaubtes_html().
 * @return string
 */
function sintoora_kalender_html( $html, $erlaubt ) {
	if ( ! is_string( $html ) || '' === $html || strlen( $html ) > SINTOORA_KALENDER_MAX_HTML ) {
		return '';
	}

	// Inhalte von Skript-, Style- und ähnlichen Blöcken ganz entfernen, damit sie nicht als
	// Text stehen bleiben (die eigentliche Sicherheit liefert danach wp_kses).
	$ohne_bloecke = preg_replace( '@<(script|style|iframe|object|embed|noscript|template|textarea|select|xmp|noembed|noframes)\b[^>]*>.*?</\1\s*>@is', '', $html );
	if ( null === $ohne_bloecke ) {
		return '';
	}

	$sauber = wp_kses( sintoora_kalender_ohne_teilnehmer( $ohne_bloecke ), $erlaubt, array( 'http', 'https' ) );

	// wp_kses lässt HTML-Kommentare (geleert) stehen – sie werden nicht gebraucht.
	$sauber = preg_replace( '/<!--.*?-->/s', '', $sauber );

	return is_string( $sauber ) ? $sauber : '';
}

/**
 * Wandelt die Sintoora-Antwort in eine sichere Terminliste um.
 *
 * Es werden ausschließlich die Felder titel, raum, klassen, klassenpart und html
 * übernommen. Alle anderen Felder werden verworfen.
 *
 * @param mixed $daten Dekodierte JSON-Antwort.
 * @return array|WP_Error Liste der Termine oder Fehler.
 */
function sintoora_kalender_bereinigen( $daten ) {
	if ( ! is_array( $daten ) || ! isset( $daten['appointments'] ) || ! is_array( $daten['appointments'] ) ) {
		return new WP_Error( 'sintoora_kalender_format', 'Die Antwort von Sintoora hat ein unerwartetes Format.' );
	}

	$erlaubt  = sintoora_kalender_erlaubtes_html();
	$termine  = array();
	$mit_html = 0;

	add_filter( 'safe_style_css', 'sintoora_kalender_css_eigenschaften', PHP_INT_MAX );
	try {
		foreach ( array_slice( $daten['appointments'], 0, SINTOORA_KALENDER_MAX_TERMINE ) as $termin ) {
			if ( ! is_array( $termin ) ) {
				continue;
			}
			$sauber = array();
			foreach ( SINTOORA_KALENDER_TEXTFELDER as $feld ) {
				$sauber[ $feld ] = sintoora_kalender_text( isset( $termin[ $feld ] ) ? $termin[ $feld ] : null );
			}
			$sauber['html'] = sintoora_kalender_html( isset( $termin['html'] ) ? $termin['html'] : '', $erlaubt );
			if ( '' !== $sauber['html'] ) {
				++$mit_html;
			}
			$termine[] = $sauber;
		}
	} finally {
		remove_filter( 'safe_style_css', 'sintoora_kalender_css_eigenschaften', PHP_INT_MAX );
	}

	// Ohne html gibt es weder Datum noch Termintext – dann lieber den letzten Stand behalten.
	if ( $termine && 0 === $mit_html ) {
		return new WP_Error( 'sintoora_kalender_ohne_html', 'Sintoora liefert keine Termintexte (Feld "html" fehlt).' );
	}

	return $termine;
}

/*
 * =====================================================================
 * Abruf und Zwischenspeicher
 * =====================================================================
 */

/**
 * Holt die Termine von einer der fest eingetragenen Sintoora-Adressen und bereinigt sie.
 *
 * @param string $url Geprüfte Adresse aus sintoora_kalender_quellen().
 * @return array|WP_Error
 */
function sintoora_kalender_abrufen( $url ) {
	$antwort = wp_remote_get(
		$url,
		array(
			'timeout'             => SINTOORA_KALENDER_TIMEOUT,
			'redirection'         => 0, // Nur genau diese Adresse, keinen Weiterleitungen folgen.
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => SINTOORA_KALENDER_MAX_BYTES,
			'headers'             => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $antwort ) ) {
		return new WP_Error( 'sintoora_kalender_verbindung', 'Sintoora ist nicht erreichbar.' );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $antwort ) ) {
		return new WP_Error( 'sintoora_kalender_status', 'Sintoora hat mit einem Fehler geantwortet.' );
	}

	$inhalt = wp_remote_retrieve_body( $antwort );
	if ( ! is_string( $inhalt ) || '' === $inhalt || strlen( $inhalt ) >= SINTOORA_KALENDER_MAX_BYTES ) {
		return new WP_Error( 'sintoora_kalender_groesse', 'Die Antwort von Sintoora ist leer oder zu groß.' );
	}

	$daten = json_decode( $inhalt, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'sintoora_kalender_json', 'Die Antwort von Sintoora ist kein gültiges JSON.' );
	}

	return sintoora_kalender_bereinigen( $daten );
}

/**
 * Schlüssel für den Zwischenspeicher, gebildet aus dem Datenformat und den Adressen der
 * gespeicherten Kalender. Ändert sich eine Adresse oder das Datenformat, werden alte
 * Daten automatisch nicht mehr verwendet. Kommt ein Kalender neu hinzu, bleiben die
 * gespeicherten Daten der anderen gültig (sie gelten dann nur als unvollständig).
 *
 * @param string[]|null $namen   Namen der gespeicherten Kalender; null = alle eingetragenen.
 * @param array|null    $quellen Name => Adresse; null = aktuelle Einstellungen.
 * @return string
 */
function sintoora_kalender_schluessel( $namen = null, $quellen = null ) {
	$quellen = null === $quellen ? sintoora_kalender_quellen() : $quellen;
	$quellen = null === $namen ? $quellen : array_intersect_key( $quellen, array_flip( $namen ) );
	return md5( SINTOORA_KALENDER_DATENFORMAT . '|' . http_build_query( $quellen ) );
}

/**
 * Prüft gespeicherte Daten auf die erwartete Form:
 * array( 'schluessel', 'zeit', 'vollstaendig', 'quellen' => array( Name => Terminliste ) ).
 *
 * @param mixed $daten Gespeicherter Wert.
 * @return bool
 */
function sintoora_kalender_ist_gueltig( $daten ) {
	if ( ! is_array( $daten )
		|| ! isset( $daten['schluessel'], $daten['zeit'], $daten['quellen'] )
		|| ! is_array( $daten['quellen'] )
		|| ! $daten['quellen'] ) {
		return false;
	}
	$konfig = sintoora_kalender_quellen();
	foreach ( $daten['quellen'] as $name => $termine ) {
		if ( ! is_string( $name ) || ! array_key_exists( $name, $konfig ) || ! is_array( $termine ) ) {
			return false;
		}
	}
	return sintoora_kalender_schluessel( array_keys( $daten['quellen'] ) ) === $daten['schluessel'];
}

/**
 * Vergleichswert, um denselben Termin in mehreren Kalendern zu erkennen: Datumsangabe,
 * Titel und Klassen (ohne Raum und Info-Text, die sich unterscheiden dürfen).
 * null, wenn der Termin keinen Titel oder keine Datumsangabe hat – er wird dann nie
 * als doppelt angesehen.
 *
 * @param mixed $termin Bereinigter Termin.
 * @return string|null
 */
function sintoora_kalender_vergleichswert( $termin ) {
	if ( ! is_array( $termin ) || empty( $termin['titel'] ) || ! isset( $termin['html'] ) || ! is_string( $termin['html'] )
		|| ! preg_match( '@<span style="color:#666[^"]*">([^<]*)</span>@', $termin['html'], $datum ) ) {
		return null;
	}

	$klassen = array();
	foreach ( array( 'klassen', 'klassenpart' ) as $feld ) {
		$teile = preg_split( '/,|\s+und\s+/i', isset( $termin[ $feld ] ) ? (string) $termin[ $feld ] : '' );
		foreach ( (array) $teile as $teil ) {
			$teil = strtolower( trim( $teil ) );
			if ( '' !== $teil ) {
				$klassen[ $teil ] = true;
			}
		}
	}
	ksort( $klassen );

	$text = strtolower( preg_replace( '/\s+/u', ' ', trim( $datum[1] ) . '|' . trim( (string) $termin['titel'] ) ) );
	return $text . '|' . implode( ',', array_keys( $klassen ) );
}

/**
 * Ist der Termin einer Klasse oder Schulstufe zugeordnet? Gleiche Regel wie im
 * JavaScript (classTokens): In klassen oder klassenpart steht mindestens eine Klasse
 * wie „1a“ oder eine Schulstufe wie „8.“ (getrennt durch Komma oder „und“).
 *
 * @param mixed $termin Bereinigter Termin.
 * @return bool
 */
function sintoora_kalender_hat_klasse( $termin ) {
	foreach ( array( 'klassen', 'klassenpart' ) as $feld ) {
		$wert = ( is_array( $termin ) && isset( $termin[ $feld ] ) && is_scalar( $termin[ $feld ] ) ) ? (string) $termin[ $feld ] : '';
		foreach ( (array) preg_split( '/,|\s+und\s+/i', $wert ) as $teil ) {
			if ( 1 === preg_match( '/^\d(?:\.(?:\s|$)|[a-z])/', strtolower( trim( $teil ) ) ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Name der Terminart aus dem Tooltip des Terminart-Symbols (kleingeschrieben, Leerzeichen
 * vereinheitlicht), z. B. „leistungsfeststellung und standardisierte testung“.
 * Leerer Text, wenn der Termin kein solches Symbol hat.
 *
 * @param mixed $termin Bereinigter Termin.
 * @return string
 */
function sintoora_kalender_terminart( $termin ) {
	if ( ! is_array( $termin ) || ! isset( $termin['html'] ) || ! is_string( $termin['html'] )
		|| ! preg_match( '@<svg\b[^>]*>\s*<title>([^<]*)</title>@i', $termin['html'], $treffer ) ) {
		return '';
	}
	return sintoora_kalender_name_vergleichbar( html_entity_decode( $treffer[1], ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Macht einen Namen vergleichbar: kleingeschrieben, Leerzeichen vereinheitlicht.
 *
 * @param string $name Name.
 * @return string
 */
function sintoora_kalender_name_vergleichbar( $name ) {
	return strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ) ) );
}

/**
 * Wird der Termin angezeigt? Nein bei Terminen ohne Klasse, deren Terminart in
 * SINTOORA_KALENDER_OHNE_KLASSE_AUSBLENDEN steht (z. B. Tests ohne Klasse).
 *
 * @param mixed $termin Bereinigter Termin.
 * @return bool
 */
function sintoora_kalender_sichtbar( $termin ) {
	if ( sintoora_kalender_hat_klasse( $termin ) ) {
		return true;
	}
	$art = sintoora_kalender_terminart( $termin );
	if ( '' === $art ) {
		return true;
	}
	foreach ( SINTOORA_KALENDER_OHNE_KLASSE_AUSBLENDEN as $ausblenden ) {
		if ( sintoora_kalender_name_vergleichbar( $ausblenden ) === $art ) {
			return false;
		}
	}
	return true;
}

/**
 * Führt die Terminlisten der Kalender in der Reihenfolge der Einstellungen
 * zusammen. Ein Termin, der schon in einem früheren Kalender steht, wird weggelassen,
 * ebenso Termine, die nicht angezeigt werden (sintoora_kalender_sichtbar()). Sonst
 * bleibt innerhalb eines Kalenders alles unverändert.
 *
 * Das Ausblenden passiert erst hier bei der Ausgabe, nicht beim Speichern: Eine
 * Änderung wirkt so sofort, ohne dass die Termine neu geholt werden müssen.
 *
 * @param array $quellen Name => bereinigte Terminliste.
 * @return array
 */
function sintoora_kalender_zusammenfuehren( $quellen ) {
	$termine = array();
	$bekannt = array();
	foreach ( array_keys( sintoora_kalender_quellen() ) as $name ) {
		if ( empty( $quellen[ $name ] ) || ! is_array( $quellen[ $name ] ) ) {
			continue;
		}
		$neu = array();
		foreach ( $quellen[ $name ] as $termin ) {
			if ( ! sintoora_kalender_sichtbar( $termin ) ) {
				continue;
			}
			$wert = sintoora_kalender_vergleichswert( $termin );
			if ( null !== $wert && isset( $bekannt[ $wert ] ) ) {
				continue;
			}
			$termine[] = $termin;
			if ( null !== $wert ) {
				$neu[ $wert ] = true;
			}
		}
		$bekannt += $neu;
	}
	return $termine;
}

/**
 * Ist ein gespeicherter Stand vollständig (alle eingetragenen Kalender ohne Fehler
 * geholt) und jünger als $hoechstalter Sekunden?
 * Ein Zeitstempel in der Zukunft (z. B. nach einer Uhr-Korrektur) gilt nicht als frisch.
 *
 * @param array|null $daten        Gültige gespeicherte Daten oder null.
 * @param int        $hoechstalter Höchstalter in Sekunden.
 * @return bool
 */
function sintoora_kalender_ist_frisch( $daten, $hoechstalter = SINTOORA_KALENDER_CACHE_DAUER ) {
	if ( ! is_array( $daten ) || empty( $daten['vollstaendig'] ) || ! isset( $daten['zeit'] )
		|| ! is_array( $daten['quellen'] ?? null ) || array_diff_key( sintoora_kalender_quellen(), $daten['quellen'] ) ) {
		return false;
	}
	$alter = time() - (int) $daten['zeit'];
	return $alter >= 0 && $alter < $hoechstalter;
}

/**
 * Macht aus gespeicherten Daten die Ausgabe: Zeitpunkt, „frisch?“ und zusammengeführte Termine.
 *
 * @param array|null $daten Gültige gespeicherte Daten oder null.
 * @return array|null Array mit 'zeit', 'frisch' und 'appointments' oder null.
 */
function sintoora_kalender_ausgabe( $daten ) {
	if ( null === $daten ) {
		return null;
	}
	return array(
		'zeit'         => (int) $daten['zeit'],
		'frisch'       => sintoora_kalender_ist_frisch( $daten ),
		'appointments' => sintoora_kalender_zusammenfuehren( $daten['quellen'] ),
	);
}

/**
 * Der „letzte Stand“ (Reserve ohne Ablaufzeit), falls gültig.
 *
 * @return array|null
 */
function sintoora_kalender_letzter_stand() {
	$letzter = get_option( SINTOORA_KALENDER_LETZTER_STAND );
	return sintoora_kalender_ist_gueltig( $letzter ) ? $letzter : null;
}

/**
 * Läuft gerade ein Abruf (Sperre) oder gab es eben einen Fehler (Pause)?
 *
 * @return bool
 */
function sintoora_kalender_ruht() {
	return false !== get_transient( SINTOORA_KALENDER_SPERRE ) || false !== get_transient( SINTOORA_KALENDER_PAUSE );
}

/**
 * Liefert die Termine für einen Besucher – möglichst ohne Wartezeit:
 *
 * 1. Frischer Stand im Cache (bzw. „letzter Stand“ jünger als 15 Minuten) → sofort.
 * 2. Älterer Stand → sofort den vorhandenen Stand liefern und den Abruf im Hintergrund
 *    anstoßen (WP-Cron). Der nächste Besucher bekommt dann den neuen Stand.
 * 3. Holt nur dann selbst (Besucher wartet), wenn es noch gar keinen Stand gibt oder
 *    der angestoßene Hintergrund-Abruf nach 90 s noch nicht gelaufen ist (z. B. weil
 *    WP-Cron auf dem Server abgeschaltet ist).
 *
 * Während eines laufenden Abrufs (Sperre) und nach einem Fehler (Pause) wird der
 * vorhandene Stand geliefert, ohne erneut abzurufen. Details: sintoora_kalender_holen().
 *
 * @param bool $erzwingen true = sofort selbst abrufen, Cache, Sperre und Pause übergehen
 *                        (nur beim Aktivieren).
 * @return array|null Array mit 'zeit', 'frisch' und 'appointments' oder null.
 */
function sintoora_kalender_termine( $erzwingen = false ) {
	if ( ! sintoora_kalender_quellen() ) {
		return null; // Noch keine Kalender eingetragen (Einstellungen).
	}
	if ( ! $erzwingen ) {
		$cache = get_transient( SINTOORA_KALENDER_CACHE );
		if ( sintoora_kalender_ist_gueltig( $cache ) && sintoora_kalender_ist_frisch( $cache ) ) {
			return sintoora_kalender_ausgabe( $cache );
		}
	}

	$letzter = sintoora_kalender_letzter_stand();
	if ( $erzwingen ) {
		return sintoora_kalender_holen( $letzter );
	}

	// Falls die regelmäßige Aktualisierung (noch) nicht geplant ist, z. B. nach einem Update.
	sintoora_kalender_planen();

	// Ist der „letzte Stand“ noch frisch, wird er direkt verwendet. Das schützt auch dann,
	// wenn ein Host den großen Transient nicht speichern kann (z. B. Objektcache mit
	// 1-MB-Grenze) – sonst würde jeder Aufruf neu abrufen.
	if ( sintoora_kalender_ist_frisch( $letzter ) || sintoora_kalender_ruht() ) {
		return sintoora_kalender_ausgabe( $letzter );
	}

	if ( $letzter ) {
		$anstoss = get_transient( SINTOORA_KALENDER_ANSTOSS );
		if ( false === $anstoss ) {
			sintoora_kalender_anstossen();
			return sintoora_kalender_ausgabe( $letzter );
		}
		$wartet = time() - (int) $anstoss;
		if ( $wartet >= 0 && $wartet < SINTOORA_KALENDER_HINTERGRUND_FRIST ) {
			return sintoora_kalender_ausgabe( $letzter );
		}
	}

	return sintoora_kalender_holen( $letzter );
}

/**
 * Ruft alle Kalender von Sintoora ab, speichert das Ergebnis und gibt es zurück.
 *
 * Fällt einer der Kalender aus, wird er durch seinen letzten guten Stand
 * ersetzt (bzw. weggelassen, falls es noch keinen gibt); die anderen werden trotzdem
 * aktualisiert. Ein solcher Teil-Stand gilt nicht als frisch: Nach der 2-Minuten-Pause
 * wird erneut abgerufen.
 *
 * Schutz gegen viele gleichzeitige Abrufe (Cache-Stampede):
 * - Die Sperre wird gesetzt und danach NICHT gelöscht, sondern läuft nach 60 s ab. So
 *   bleibt es bei höchstens einem Abruf pro Minute – auch wenn das Speichern scheitert
 *   oder PHP während des Abrufs abbricht (z. B. Speichergrenze).
 * - Nach einem Fehler wird 2 Minuten lang nicht erneut abgerufen.
 * Die Sperre ist ein Transient und damit „best effort“; das genügt hier, weil im
 * ungünstigsten Fall nur wenige Abrufe innerhalb weniger Millisekunden starten.
 *
 * @param array|null $letzter Gültiger letzter Stand oder null.
 * @return array|null Array mit 'zeit', 'frisch' und 'appointments' oder null.
 */
function sintoora_kalender_holen( $letzter ) {
	$konfig = sintoora_kalender_quellen();
	if ( ! $konfig ) {
		return null; // Noch keine Kalender eingetragen.
	}
	set_transient( SINTOORA_KALENDER_SPERRE, time(), SINTOORA_KALENDER_SPERRE_DAUER );

	$quellen = $letzter ? array_intersect_key( $letzter['quellen'], $konfig ) : array();
	$fehler  = array();
	$status  = get_option( SINTOORA_KALENDER_OPTION_STATUS );
	$status  = is_array( $status ) ? array_intersect_key( $status, $konfig ) : array();
	foreach ( $konfig as $name => $url ) {
		$termine = sintoora_kalender_abrufen( $url );
		$eintrag = isset( $status[ $name ] ) && is_array( $status[ $name ] ) ? $status[ $name ] : array();
		if ( is_wp_error( $termine ) ) {
			// Der Fehlercode (fester Text aus diesem Plugin) dient zugleich als Diagnose-Hinweis.
			$fehler[ $name ]        = $termine->get_error_code();
			$eintrag['fehler']      = $fehler[ $name ];
			$eintrag['fehler_zeit'] = time();
		} else {
			$quellen[ $name ] = $termine;
			$eintrag          = array(
				'ok'     => time(),
				'anzahl' => count( $termine ),
			);
		}
		$status[ $name ] = $eintrag;
	}
	update_option( SINTOORA_KALENDER_OPTION_STATUS, $status, false );

	// Ein offener Anstoß ist damit erledigt (bei Fehlern sorgt die Pause für den Abstand).
	delete_transient( SINTOORA_KALENDER_ANSTOSS );

	if ( count( $fehler ) === count( $konfig ) ) {
		// Nichts Neues: den letzten Stand unverändert behalten.
		set_transient( SINTOORA_KALENDER_PAUSE, $fehler, SINTOORA_KALENDER_PAUSE_DAUER );
		return sintoora_kalender_ausgabe( $letzter );
	}

	// Der Schlüssel muss zu den gespeicherten Kalendern passen: Fehlt einer (Fehler und noch
	// kein früherer Stand), zählt er nicht mit – sonst wäre der Teil-Stand ungültig.
	$daten = array(
		'schluessel'   => sintoora_kalender_schluessel( array_keys( $quellen ) ),
		'zeit'         => time(),
		'vollstaendig' => ! $fehler,
		'quellen'      => $quellen,
	);
	if ( $fehler ) {
		set_transient( SINTOORA_KALENDER_PAUSE, $fehler, SINTOORA_KALENDER_PAUSE_DAUER );
	} else {
		set_transient( SINTOORA_KALENDER_CACHE, $daten, SINTOORA_KALENDER_CACHE_DAUER );
		delete_transient( SINTOORA_KALENDER_PAUSE );
	}
	update_option( SINTOORA_KALENDER_LETZTER_STAND, $daten, false );

	return sintoora_kalender_ausgabe( $daten );
}

/*
 * =====================================================================
 * Hintergrund-Aktualisierung (WP-Cron)
 * =====================================================================
 *
 * WP-Cron läuft, wenn WordPress eine Anfrage bearbeitet (z. B. den Termin-Abruf des
 * Kalenders), und startet die fälligen Aufgaben in einer eigenen Anfrage an
 * wp-cron.php. Der Besucher wartet darauf nicht.
 */

/**
 * Stößt den Abruf im Hintergrund an: einmaliges Cron-Ereignis „jetzt“ planen und
 * WP-Cron starten. Ist WP-Cron abgeschaltet (DISABLE_WP_CRON, dann läuft wp-cron.php
 * meist über einen Cronjob des Servers) oder auf ALTERNATE_WP_CRON umgestellt (würde
 * die REST-Antwort umleiten), wird nur geplant.
 */
function sintoora_kalender_anstossen() {
	set_transient( SINTOORA_KALENDER_ANSTOSS, time(), SINTOORA_KALENDER_ANSTOSS_DAUER );
	if ( false === wp_next_scheduled( SINTOORA_KALENDER_CRON_SOFORT ) ) {
		wp_schedule_single_event( time(), SINTOORA_KALENDER_CRON_SOFORT );
	}
	if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && ! ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) ) {
		spawn_cron();
	}
}

/**
 * Abruf im Hintergrund (für beide Cron-Ereignisse). Entfällt, solange der Stand
 * vollständig und jünger als 10 Minuten ist, während eines laufenden Abrufs und
 * während der Pause nach einem Fehler.
 */
function sintoora_kalender_hintergrund() {
	$letzter = sintoora_kalender_letzter_stand();
	if ( sintoora_kalender_ist_frisch( $letzter, SINTOORA_KALENDER_MINDESTABSTAND ) || sintoora_kalender_ruht() ) {
		delete_transient( SINTOORA_KALENDER_ANSTOSS );
		return;
	}
	sintoora_kalender_holen( $letzter );
}
add_action( SINTOORA_KALENDER_CRON_REGELMAESSIG, 'sintoora_kalender_hintergrund' );
add_action( SINTOORA_KALENDER_CRON_SOFORT, 'sintoora_kalender_hintergrund' );

/**
 * Plant die regelmäßige Aktualisierung alle 15 Minuten (falls noch nicht geplant).
 */
function sintoora_kalender_planen() {
	if ( false === wp_next_scheduled( SINTOORA_KALENDER_CRON_REGELMAESSIG ) ) {
		wp_schedule_event( time() + SINTOORA_KALENDER_CACHE_DAUER, SINTOORA_KALENDER_CRON_INTERVALL, SINTOORA_KALENDER_CRON_REGELMAESSIG );
	}
}

/**
 * Meldet das Intervall „alle 15 Minuten“ bei WP-Cron an.
 *
 * @param mixed $intervalle Vorhandene Intervalle.
 * @return array
 */
function sintoora_kalender_cron_intervalle( $intervalle ) {
	if ( ! is_array( $intervalle ) ) {
		$intervalle = array();
	}
	$intervalle[ SINTOORA_KALENDER_CRON_INTERVALL ] = array(
		'interval' => 15 * MINUTE_IN_SECONDS, // = SINTOORA_KALENDER_CACHE_DAUER.
		'display'  => 'Alle 15 Minuten (Sintoora Kalender)',
	);
	return $intervalle;
}
add_filter( 'cron_schedules', 'sintoora_kalender_cron_intervalle' );

/*
 * =====================================================================
 * REST-Route: GET /wp-json/sintoora-kalender/v1/termine
 * =====================================================================
 */

/**
 * Registriert die öffentliche, parameterlose REST-Route.
 */
function sintoora_kalender_rest_registrieren() {
	register_rest_route(
		'sintoora-kalender/v1',
		'/termine',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'sintoora_kalender_rest_antwort',
			'permission_callback' => '__return_true',
			'args'                => array(),
		)
	);
}
add_action( 'rest_api_init', 'sintoora_kalender_rest_registrieren' );

/**
 * Antwort der REST-Route. Anfrage-Parameter werden bewusst nicht ausgewertet.
 *
 * @return WP_REST_Response
 */
function sintoora_kalender_rest_antwort() {
	$daten = sintoora_kalender_termine();

	if ( null === $daten ) {
		$antwort = new WP_REST_Response(
			array(
				'code'    => sintoora_kalender_quellen() ? 'sintoora_kalender_nicht_verfuegbar' : 'sintoora_kalender_nicht_eingerichtet',
				'message' => sintoora_kalender_quellen() ? 'Die Termine sind gerade nicht verfügbar. Bitte später noch einmal versuchen.' : 'Der Kalender ist noch nicht eingerichtet.',
				'data'    => array( 'status' => 503 ),
			),
			503
		);
		$antwort->header( 'Cache-Control', 'no-store' );
		$antwort->header( 'Retry-After', (string) SINTOORA_KALENDER_PAUSE_DAUER );
		sintoora_kalender_fehler_header( $antwort );
		return $antwort;
	}

	// „stand“ (Zeitpunkt des Abrufs) und „veraltet“ (älter als 7 Tage) für den Hinweis im Kalender.
	$antwort = new WP_REST_Response(
		array(
			'appointments' => array_values( $daten['appointments'] ),
			'stand'        => wp_date( 'j.n.Y, G:i', (int) $daten['zeit'] ),
			'veraltet'     => time() - (int) $daten['zeit'] > SINTOORA_KALENDER_VERALTET_AB,
		),
		200
	);
	// Frischer Stand: Browser darf 5 Minuten wiederverwenden. Älterer Stand (Abruf läuft im
	// Hintergrund): nicht wiederverwenden, damit das nächste Laden den neuen Stand zeigt.
	$antwort->header( 'Cache-Control', $daten['frisch'] ? 'public, max-age=300' : 'no-cache' );
	$antwort->header( 'Vary', 'Accept-Encoding' ); // Antwort kann gzip-komprimiert sein.
	$antwort->header( 'X-Sintoora-Kalender-Stand', gmdate( 'Y-m-d\TH:i:s\Z', (int) $daten['zeit'] ) );
	sintoora_kalender_fehler_header( $antwort );
	return $antwort;
}

/**
 * Hängt nach einem fehlgeschlagenen Abruf (während der 2-Minuten-Pause) Kalender und
 * Fehlercode als Header an, z. B.
 * "X-Sintoora-Kalender-Fehler: allgemein=sintoora_kalender_verbindung".
 * Es werden nur die Kalender-Namen und festen Codes dieses Plugins ausgegeben, keine
 * Fehlermeldungen von außen.
 *
 * @param WP_REST_Response $antwort Antwort.
 */
function sintoora_kalender_fehler_header( $antwort ) {
	$fehler = get_transient( SINTOORA_KALENDER_PAUSE );
	$konfig = sintoora_kalender_quellen();
	$teile  = array();
	if ( is_array( $fehler ) ) {
		foreach ( $fehler as $name => $code ) {
			if ( is_string( $name ) && array_key_exists( $name, $konfig ) && is_string( $code ) && 1 === preg_match( '/^sintoora_kalender_[a-z_]{1,40}$/', $code ) ) {
				$teile[] = $name . '=' . $code;
			}
		}
	}
	if ( $teile ) {
		$antwort->header( 'X-Sintoora-Kalender-Fehler', implode( ', ', $teile ) );
	}
}

/*
 * =====================================================================
 * gzip-Komprimierung der REST-Antwort
 * =====================================================================
 */

/**
 * Akzeptiert der Browser gzip? (Accept-Encoding, "gzip;q=0" heißt ausdrücklich nein.)
 *
 * @param mixed $kopf Wert des Headers Accept-Encoding.
 * @return bool
 */
function sintoora_kalender_akzeptiert_gzip( $kopf ) {
	if ( ! is_string( $kopf ) ) {
		return false;
	}
	foreach ( explode( ',', strtolower( $kopf ) ) as $eintrag ) {
		$teile = explode( ';', $eintrag, 2 );
		if ( 'gzip' !== trim( $teile[0] ) ) {
			continue;
		}
		return ! ( isset( $teile[1] ) && 1 === preg_match( '/^\s*q\s*=\s*0(?:\.0{0,3})?\s*$/', $teile[1] ) );
	}
	return false;
}

/**
 * Darf die Antwort hier gzip-komprimiert gesendet werden?
 *
 * Nur für die Termine-Route, bei erfolgreichen GET-Anfragen ohne die Sonderparameter
 * von WordPress (_jsonp, _envelope, _embed, _pretty), wenn der Browser gzip akzeptiert
 * und die Ausgabe nicht schon anderweitig komprimiert wird (PHP-Einstellung
 * zlib.output_compression, Ausgabepuffer ob_gzhandler oder Content-Encoding-Header).
 *
 * @param mixed $antwort Antwort (WP_HTTP_Response).
 * @param mixed $anfrage Anfrage (WP_REST_Request).
 * @param mixed $server  REST-Server (WP_REST_Server).
 * @return bool
 */
function sintoora_kalender_gzip_passend( $antwort, $anfrage, $server ) {
	if ( ! ( $antwort instanceof WP_HTTP_Response ) || ! ( $anfrage instanceof WP_REST_Request ) || ! ( $server instanceof WP_REST_Server )
		|| '/sintoora-kalender/v1/termine' !== strtolower( untrailingslashit( (string) $anfrage->get_route() ) )
		|| 'GET' !== $anfrage->get_method()
		|| 200 !== $antwort->get_status()
		|| ! function_exists( 'gzencode' )
		|| headers_sent()
		|| ! sintoora_kalender_akzeptiert_gzip( $anfrage->get_header( 'accept_encoding' ) ) ) {
		return false;
	}

	$abfrage = $anfrage->get_query_params();
	foreach ( array( '_jsonp', '_envelope', '_embed', '_pretty' ) as $parameter ) {
		if ( isset( $abfrage[ $parameter ] ) ) {
			return false;
		}
	}

	$zlib = strtolower( trim( (string) ini_get( 'zlib.output_compression' ) ) );
	if ( '' !== $zlib && '0' !== $zlib && 'off' !== $zlib ) {
		return false;
	}
	foreach ( ob_list_handlers() as $puffer ) {
		if ( false !== stripos( $puffer, 'gzhandler' ) || false !== stripos( $puffer, 'zlib' ) ) {
			return false;
		}
	}
	foreach ( headers_list() as $kopf ) {
		if ( 0 === stripos( $kopf, 'content-encoding:' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Sendet die Termine gzip-komprimiert (aus rund 1,1 MB werden etwa 35 KB), wenn
 * sintoora_kalender_gzip_passend() zustimmt. Sonst gibt WordPress die Antwort wie
 * gewohnt unkomprimiert aus. Inhalt und übrige Header sind in beiden Fällen gleich.
 *
 * Filter "rest_pre_serve_request": true bedeutet „Antwort wurde hier schon gesendet“.
 *
 * @param bool  $gesendet true, wenn die Antwort schon gesendet wurde.
 * @param mixed $antwort  Antwort (WP_HTTP_Response).
 * @param mixed $anfrage  Anfrage (WP_REST_Request).
 * @param mixed $server   REST-Server (WP_REST_Server).
 * @return bool
 */
function sintoora_kalender_gzip_senden( $gesendet, $antwort, $anfrage, $server ) {
	if ( $gesendet || ! sintoora_kalender_gzip_passend( $antwort, $anfrage, $server ) ) {
		return $gesendet;
	}

	// Dieselben Daten, die WordPress in WP_REST_Server::serve_request() ausgeben würde.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter von WordPress selbst, wie in serve_request().
	$daten = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $antwort, false ), $server, $anfrage );
	$json  = null === $daten ? false : wp_json_encode( $daten );
	$gzip  = is_string( $json ) ? gzencode( $json, 6 ) : false;
	if ( ! is_string( $gzip ) ) {
		return $gesendet;
	}

	header( 'Content-Encoding: gzip' );
	header( 'Content-Length: ' . strlen( $gzip ) );
	echo $gzip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gzip-Daten des JSON; dessen Inhalt wurde beim Abruf mit wp_kses bereinigt.
	return true;
}
add_filter( 'rest_pre_serve_request', 'sintoora_kalender_gzip_senden', 20, 4 );

/*
 * =====================================================================
 * Shortcode [sintoora_kalender] und Darstellung
 * =====================================================================
 */

/**
 * Registriert CSS und JavaScript (ohne eigene Dateien, nur Inline-Code).
 */
function sintoora_kalender_assets_registrieren() {
	if ( wp_script_is( 'sintoora-kalender', 'registered' ) ) {
		return;
	}

	$url = wp_json_encode(
		esc_url_raw( rest_url( 'sintoora-kalender/v1/termine' ) ),
		JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
	);

	wp_register_style( 'sintoora-kalender', false, array(), SINTOORA_KALENDER_VERSION );
	wp_add_inline_style( 'sintoora-kalender', sintoora_kalender_css() );

	wp_register_script( 'sintoora-kalender', false, array(), SINTOORA_KALENDER_VERSION, true );
	wp_add_inline_script(
		'sintoora-kalender',
		str_replace( '__SINTOORA_KALENDER_URL__', is_string( $url ) ? $url : '""', sintoora_kalender_js() )
	);
}

/**
 * Lädt CSS/JS nur auf Seiten, deren Inhalt den Shortcode enthält (CSS dann im <head>).
 */
function sintoora_kalender_assets_laden() {
	if ( ! is_singular() ) {
		return;
	}
	$beitrag = get_post();
	if ( $beitrag instanceof WP_Post && has_shortcode( $beitrag->post_content, 'sintoora_kalender' ) ) {
		sintoora_kalender_assets_registrieren();
		wp_enqueue_style( 'sintoora-kalender' );
		wp_enqueue_script( 'sintoora-kalender' );
	}
}
add_action( 'wp_enqueue_scripts', 'sintoora_kalender_assets_laden' );

/**
 * Shortcode-Ausgabe: das Gerüst des Kalenders. Bitte nur einmal pro Seite verwenden.
 *
 * @return string
 */
function sintoora_kalender_shortcode() {
	if ( is_feed() ) {
		return '';
	}

	// Falls der Shortcode außerhalb des Seiteninhalts steht (z. B. Widget): Assets jetzt laden.
	sintoora_kalender_assets_registrieren();
	wp_enqueue_style( 'sintoora-kalender' );
	wp_enqueue_script( 'sintoora-kalender' );

	return '<div id="sintoora-kalender" class="sk">'
		. '<p class="sk-veraltet" hidden></p>'
		. '<div class="sk-bar">'
		. '<div class="sk-classes" role="group" aria-label="' . esc_attr( 'Klasse auswählen' ) . '"></div>'
		. '<label class="sk-past"><input type="checkbox" class="sk-past-input"> ' . esc_html( 'Frühere Termine anzeigen' ) . '</label>'
		. '</div>'
		. '<p class="sk-status" aria-live="polite">' . esc_html( 'Termine werden geladen …' ) . '</p>'
		. '<div class="sk-list"></div>'
		. '<p class="sk-note">' . esc_html( 'Irrtümer und Änderungen vorbehalten.' ) . '</p>'
		. '</div>';
}
add_shortcode( 'sintoora_kalender', 'sintoora_kalender_shortcode' );

/**
 * CSS des Kalenders (unverändert aus schulkalender.html).
 *
 * @return string
 */
function sintoora_kalender_css() {
	return <<<'SKCSS'
  .sk { --sk-accent: #b5212a; --sk-line: #e3e3e3; --sk-muted: #666;
        font-size: 1rem; line-height: 1.45; max-width: 1100px; }
  .sk *, .sk *::before, .sk *::after { box-sizing: border-box; }
  .sk-bar { padding: .5rem 0 .75rem; border-bottom: 2px solid var(--sk-line); }
  .sk-classes { display: flex; flex-direction: column; gap: .35rem; }
  .sk-row { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
  .sk-row-label { min-width: 4.5rem; font-size: .85rem; color: var(--sk-muted); }
  .sk-btn { font: inherit; font-size: .9rem; cursor: pointer; border: 1px solid #ccc;
            background: #fff; color: #222; border-radius: 999px; padding: .2rem .7rem;
            line-height: 1.4; }
  .sk-btn:hover { border-color: var(--sk-accent); color: var(--sk-accent); }
  .sk-btn:focus-visible { outline: 2px solid var(--sk-accent); outline-offset: 2px; }
  .sk-btn[aria-pressed="true"] { background: var(--sk-accent); border-color: var(--sk-accent); color: #fff; }
  .sk-past { display: inline-flex; gap: .4rem; align-items: center; margin-top: .6rem;
             font-size: .9rem; cursor: pointer; }
  .sk-status { margin: .75rem 0; color: var(--sk-muted); font-size: .9rem; }
  .sk-status.sk-error { color: var(--sk-accent); font-weight: 600; }
  .sk-month { margin: 1.5rem 0 .25rem; font-size: 1.15rem; color: var(--sk-accent);
              border-bottom: 1px solid var(--sk-line); padding-bottom: .2rem; }
  .sk-items { list-style: none; margin: 0; padding: 0; }
  .sk-item { padding: .45rem 0; border-bottom: 1px solid var(--sk-line); }
  .sk-item.sk-is-past { opacity: .6; }
  .sk-item svg { vertical-align: -4px; margin-right: 4px; }
  .sk-item .collapse { display: none; }
  .sk-item .collapse.show { display: block; }
  .sk-item a { color: var(--sk-accent); }
  .sk-note { margin-top: 1rem; font-size: .8rem; color: var(--sk-muted); }
  .sk-veraltet { margin: 0 0 1rem; padding: .6rem .8rem; border-left: 4px solid var(--sk-accent); background: #fff8e1; color: #222; font-size: .95rem; }
  .sk [hidden] { display: none !important; }
  @media (max-width: 600px) {
    .sk-row-label { min-width: 100%; }
  }
SKCSS;
}

/**
 * JavaScript des Kalenders (aus schulkalender.html). Geändert sind CONFIG.url (zeigt auf
 * die REST-Route), showUnassignedInClassFilter: true – allgemeine Termine ohne
 * Klassenzuordnung erscheinen damit auch bei jeder einzelnen Klasse – und die eigenen
 * Filter für Terminarten (CONFIG.categories, z. B. „Reifeprüfung“).
 *
 * @return string
 */
function sintoora_kalender_js() {
	return <<<'SKJS'
(function () {
  'use strict';

  // ===== Einstellungen =====
  var CONFIG = {
    // Adresse der Termine: REST-Route dieses Plugins (WordPress holt die Daten serverseitig von Sintoora)
    url: __SINTOORA_KALENDER_URL__,
    // Termine ganz ohne Klasse auch bei einem Klassenfilter anzeigen?
    showUnassignedInClassFilter: true,
    // Terminarten mit eigenem Filter-Button (erkannt am Tooltip des Terminart-Symbols).
    // Ihre Termine ohne Klasse erscheinen nur bei diesem Filter und bei „Alle Klassen“.
    categories: [
      { key: 'reifepruefung', label: 'Reifeprüfung', terminart: 'Reifeprüfung', status: ' zur Reifeprüfung' }
    ],
    // Vergangene Termine beim Öffnen ausblenden?
    hidePastByDefault: true,
    // Nur für die Vorschau: Daten direkt einbetten statt laden
    data: null
  };
  var EXT = window.SK_CONFIG || {};
  Object.keys(EXT).forEach(function (k) { CONFIG[k] = EXT[k]; });

  var root = document.getElementById('sintoora-kalender');
  if (!root) return;
  var elClasses = root.querySelector('.sk-classes');
  var elStatus = root.querySelector('.sk-status');
  var elList = root.querySelector('.sk-list');
  var elPast = root.querySelector('.sk-past-input');

  var MONTHS = ['Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
                'August', 'September', 'Oktober', 'November', 'Dezember'];

  // ---- Speicher (kann in manchen Browsern fehlen) ----
  function store(key, val) {
    try { if (val === undefined) return localStorage.getItem(key); localStorage.setItem(key, val); }
    catch (e) { return null; }
  }

  // ---- Sicherheitsfilter für das HTML aus Sintoora ----
  var ALLOWED = {
    span: ['style', 'class'], div: ['style', 'class', 'id'], a: ['href', 'class', 'data-toggle'],
    svg: ['fill', 'viewbox', 'stroke-width', 'stroke', 'width', 'height', 'xmlns'],
    path: ['d', 'stroke-linecap', 'stroke-linejoin', 'fill', 'stroke'],
    title: [], b: [], strong: [], em: [], i: [], br: [], p: []
  };
  var DROP = { script: 1, style: 1, iframe: 1, object: 1, embed: 1, link: 1, meta: 1, form: 1, input: 1, img: 1 };

  function cleanNode(node) {
    var children = Array.prototype.slice.call(node.childNodes);
    children.forEach(function (child) {
      if (child.nodeType === 3) return;                  // Text
      if (child.nodeType !== 1) { child.remove(); return; }
      var tag = child.localName.toLowerCase();
      if (DROP[tag]) { child.remove(); return; }
      if (!ALLOWED.hasOwnProperty(tag)) {                // unbekannt: nur Inhalt behalten
        cleanNode(child);
        while (child.firstChild) node.insertBefore(child.firstChild, child);
        child.remove();
        return;
      }
      Array.prototype.slice.call(child.attributes).forEach(function (attr) {
        var name = attr.name.toLowerCase(), value = attr.value;
        var ok = ALLOWED[tag].indexOf(name) !== -1;
        if (ok && name === 'href') ok = /^#[\w-]+$/.test(value);
        if (ok && name === 'style') ok = !/url\s*\(|expression|javascript:/i.test(value);
        if (!ok) child.removeAttribute(attr.name);
      });
      cleanNode(child);
    });
  }

  function sanitize(html) {
    var tpl = document.createElement('template');
    tpl.innerHTML = String(html || '');
    cleanNode(tpl.content);
    return tpl.content;
  }

  // ---- Datum aus dem Datumsfeld lesen ("Di. 17.11. 3. UE", "Mo. 28.09. - Mi. 30.09.", "Fr. 08.01.2027 …") ----
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function parseDates(text, fallbackYear) {
    var re = /(\d{1,2})\.(\d{1,2})\.(\d{4})?/g, m, out = [];
    while ((m = re.exec(text))) {
      var d = +m[1], mo = +m[2], y = m[3] ? +m[3] : fallbackYear;
      if (d >= 1 && d <= 31 && mo >= 1 && mo <= 12) out.push(y + '-' + pad(mo) + '-' + pad(d));
    }
    if (!out.length) return null;
    var start = out[0], end = out[out.length - 1];
    if (end < start) end = start;
    return { start: start, end: end };
  }

  // ---- Klassen und Schulstufen aus "1a,1b" bzw. "8." / "6l und 6m" ----
  function classTokens(str) {
    var res = { classes: [], grades: [] };
    String(str || '').split(',').forEach(function (part) {
      part.split(/\s+und\s+/i).forEach(function (t) {
        t = t.trim().toLowerCase();
        if (!t) return;
        var g = t.match(/^(\d)\.(\s|$)/);                  // "8." = ganze Schulstufe
        if (g) { res.grades.push(g[1]); return; }
        var c = t.match(/^\d[a-z][a-z0-9-]*/);
        if (c) res.classes.push(c[0]);
      });
    });
    return res;
  }

  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  // ---- Terminarten mit eigenem Filter ----
  function norm(s) { return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase(); }
  function categoryByKey(key) {
    var list = CONFIG.categories || [];
    for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
    return null;
  }
  function categoriesOf(holder) {
    var t = holder.querySelector('svg title');
    var art = t ? norm(t.textContent) : '';
    return art ? (CONFIG.categories || []).filter(function (c) { return norm(c.terminart) === art; })
      .map(function (c) { return c.key; }) : [];
  }

  // ---- Daten aufbereiten ----
  function prepare(data) {
    var list = (data && data.appointments) || [];
    var year = new Date().getFullYear();
    var allClasses = {}, allCats = {};
    var items = [];
    list.forEach(function (a, idx) {
      var frag = sanitize(a.html);
      var holder = document.createElement('div');
      holder.appendChild(frag);
      var dateEl = holder.querySelector('span[style*="#666"]');
      var dates = parseDates(dateEl ? dateEl.textContent : holder.textContent, year);
      var t1 = classTokens(a.klassen), t2 = classTokens(a.klassenpart);
      var classes = t1.classes.concat(t2.classes), grades = t1.grades.concat(t2.grades);
      var cats = categoriesOf(holder);
      classes.forEach(function (c) { allClasses[c] = 1; });
      cats.forEach(function (c) { allCats[c] = 1; });
      items.push({
        idx: idx, node: holder, start: dates ? dates.start : null, end: dates ? dates.end : null,
        classes: classes, grades: grades, cats: cats,
        unassigned: !classes.length && !grades.length && !cats.length
      });
    });
    items.sort(function (x, y) {
      if (x.start === y.start) return x.idx - y.idx;
      if (!x.start) return 1; if (!y.start) return -1;
      return x.start < y.start ? -1 : 1;
    });
    return { items: items, classes: Object.keys(allClasses), cats: Object.keys(allCats) };
  }

  // ---- Darstellung ----
  var state = { klasse: 'alle', showPast: false };
  var model = null;

  function sortClasses(list) {
    return list.sort(function (a, b) {
      return a.charAt(0) === b.charAt(0) ? (a < b ? -1 : a > b ? 1 : 0) : a.charAt(0) - b.charAt(0);
    });
  }

  function button(label, value) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'sk-btn'; b.textContent = label; b.dataset.klasse = value;
    return b;
  }

  function renderButtons(classes, cats) {
    elClasses.textContent = '';
    var first = document.createElement('div'); first.className = 'sk-row';
    first.appendChild(button('Alle Klassen', 'alle'));
    (CONFIG.categories || []).forEach(function (c) {
      if (cats.indexOf(c.key) !== -1) first.appendChild(button(c.label, c.key));
    });
    elClasses.appendChild(first);
    var byGrade = {};
    sortClasses(classes).forEach(function (c) { (byGrade[c.charAt(0)] = byGrade[c.charAt(0)] || []).push(c); });
    Object.keys(byGrade).sort().forEach(function (g) {
      var row = document.createElement('div'); row.className = 'sk-row';
      var label = document.createElement('span'); label.className = 'sk-row-label';
      label.textContent = g + '. Klassen'; row.appendChild(label);
      byGrade[g].forEach(function (c) { row.appendChild(button(c, c)); });
      elClasses.appendChild(row);
    });
  }

  function renderList(items) {
    elList.textContent = '';
    var currentKey = null, ul = null;
    items.forEach(function (it) {
      var key = it.start ? it.start.slice(0, 7) : 'ohne';
      if (key !== currentKey) {
        currentKey = key;
        var h = document.createElement('h3'); h.className = 'sk-month';
        h.textContent = it.start ? MONTHS[+key.slice(5, 7) - 1] + ' ' + key.slice(0, 4) : 'Ohne Datum';
        ul = document.createElement('ul'); ul.className = 'sk-items';
        var sec = document.createElement('section'); sec.className = 'sk-section';
        sec.appendChild(h); sec.appendChild(ul); elList.appendChild(sec);
      }
      var li = document.createElement('li'); li.className = 'sk-item';
      while (it.node.firstChild) li.appendChild(it.node.firstChild);
      it.li = li; ul.appendChild(li);
    });
  }

  function matches(it, today) {
    if (!state.showPast && it.end && it.end < today) return false;
    if (state.klasse === 'alle') return true;
    if (categoryByKey(state.klasse)) return it.cats.indexOf(state.klasse) !== -1;
    if (it.classes.indexOf(state.klasse) !== -1) return true;
    if (it.grades.indexOf(state.klasse.charAt(0)) !== -1) return true;
    return CONFIG.showUnassignedInClassFilter && it.unassigned;
  }

  function apply() {
    var today = todayISO(), shown = 0;
    model.items.forEach(function (it) {
      var ok = matches(it, today);
      it.li.hidden = !ok;
      it.li.classList.toggle('sk-is-past', !!(it.end && it.end < today));
      if (ok) shown++;
    });
    Array.prototype.forEach.call(elList.querySelectorAll('.sk-section'), function (sec) {
      sec.hidden = !sec.querySelector('.sk-item:not([hidden])');
    });
    Array.prototype.forEach.call(elClasses.querySelectorAll('.sk-btn'), function (b) {
      b.setAttribute('aria-pressed', b.dataset.klasse === state.klasse ? 'true' : 'false');
    });
    elPast.checked = state.showPast;
    var cat = categoryByKey(state.klasse);
    var was = state.klasse === 'alle' ? '' : cat ? cat.status : ' für die ' + state.klasse;
    elStatus.classList.remove('sk-error');
    elStatus.textContent = shown
      ? shown + (shown === 1 ? ' Termin' : ' Termine') + was + (state.showPast ? '' : ' ab heute')
      : 'Keine Termine' + was + (state.showPast ? '' : ' ab heute') + '.';
  }

  function setKlasse(k) {
    state.klasse = k;
    store('sk-klasse', k);
    try {
      var u = new URL(window.location.href);
      if (k === 'alle') u.searchParams.delete('klasse'); else u.searchParams.set('klasse', k);
      history.replaceState(null, '', u.toString());
    } catch (e) { /* egal */ }
    apply();
  }

  // ---- Bedienung ----
  elClasses.addEventListener('click', function (e) {
    var b = e.target.closest('.sk-btn');
    if (b) setKlasse(b.dataset.klasse);
  });
  elPast.addEventListener('change', function () { state.showPast = elPast.checked; apply(); });
  elList.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-toggle="collapse"]');
    if (!a) return;
    e.preventDefault();
    var id = (a.getAttribute('href') || '').slice(1);
    var target = id && elList.querySelector('[id="' + id + '"]');
    if (target) {
      target.classList.toggle('show');
      a.setAttribute('aria-expanded', target.classList.contains('show') ? 'true' : 'false');
    }
  });

  function start(data) {
    // Hinweis, wenn der gespeicherte Stand älter als 7 Tage ist (Sintoora seit Längerem nicht erreichbar).
    var elAlt = root.querySelector('.sk-veraltet');
    if (elAlt && data && data.veraltet && data.stand) {
      elAlt.textContent = 'Hinweis: Die Termine wurden zuletzt am ' + String(data.stand) + ' Uhr aktualisiert. Neuere Änderungen fehlen eventuell.';
      elAlt.hidden = false;
    }
    model = prepare(data);
    renderButtons(model.classes, model.cats);
    renderList(model.items);
    var wanted = null;
    try { wanted = new URL(window.location.href).searchParams.get('klasse'); } catch (e) { /* egal */ }
    wanted = (wanted || store('sk-klasse') || 'alle').toLowerCase();
    state.klasse = (wanted === 'alle' || model.classes.indexOf(wanted) !== -1 || model.cats.indexOf(wanted) !== -1) ? wanted : 'alle';
    state.showPast = !CONFIG.hidePastByDefault;
    apply();
  }

  function fail(err) {
    elStatus.classList.add('sk-error');
    elStatus.textContent = 'Die Termine konnten gerade nicht geladen werden. Bitte später noch einmal versuchen.';
    var detail = document.createElement('small');
    detail.style.cssText = 'display:block;font-weight:400;color:#888;margin-top:.25rem';
    detail.textContent = 'Technischer Hinweis: ' + (err && err.name === 'AbortError'
      ? 'keine Antwort von Sintoora (Zeitüberschreitung)' : String(err && err.message || err));
    elStatus.appendChild(detail);
    if (window.console) console.error('Sintoora-Kalender:', err);
  }

  function safeStart(data) {
    try { start(data); } catch (e) { fail(e); }
  }

  if (CONFIG.data) { safeStart(CONFIG.data); return; }
  elStatus.textContent = 'Verbindung zu Sintoora wird aufgebaut …';
  var ctrl = window.AbortController ? new AbortController() : null;
  var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 20000) : null;
  fetch(CONFIG.url, { headers: { Accept: 'application/json' }, credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (data) { clearTimeout(timer); safeStart(data); })
    .catch(function (e) { clearTimeout(timer); fail(e); });
})();
SKJS;
}

/*
 * =====================================================================
 * Kalender-Links und Einstellungsseite (Menüpunkt „Sintoora Kalender“)
 * =====================================================================
 */

/**
 * Prüft einen Sintoora-Link (oder nur dessen Token) und gibt ihn in einheitlicher Form
 * zurück. Erlaubt ist genau https://app.sintoora.com/screen/calendar/screen?t=<UUID> –
 * kein anderer Server, kein anderer Pfad, keine weiteren Parameter.
 *
 * @param mixed $eingabe Eingegebener Link oder Token.
 * @return string|null Link oder null, wenn ungültig.
 */
function sintoora_kalender_link_pruefen( $eingabe ) {
	if ( ! is_string( $eingabe ) ) {
		return null;
	}
	$eingabe = trim( $eingabe );
	$uuid    = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
	if ( strlen( $eingabe ) > 300
		|| ( 1 !== preg_match( '/^(' . $uuid . ')$/', $eingabe, $treffer )
			&& 1 !== preg_match( '~^https://app\.sintoora\.com/screen/calendar/screen\?t=(' . $uuid . ')$~i', $eingabe, $treffer ) ) ) {
		return null;
	}
	return 'https://app.sintoora.com/screen/calendar/screen?t=' . strtolower( $treffer[1] );
}

/**
 * Prüft eine gespeicherte Liste von Kalendern: nur gültige interne Namen und Links,
 * keine doppelten Namen oder Links, höchstens SINTOORA_KALENDER_MAX_QUELLEN Einträge.
 *
 * @param mixed $wert Gespeicherter Wert.
 * @return array Liste von array( 'name', 'bezeichnung', 'url' ).
 */
function sintoora_kalender_zeilen_pruefen( $wert ) {
	$liste = array();
	foreach ( is_array( $wert ) ? $wert : array() as $zeile ) {
		if ( count( $liste ) >= SINTOORA_KALENDER_MAX_QUELLEN ) {
			break;
		}
		if ( ! is_array( $zeile ) || ! isset( $zeile['name'], $zeile['url'] ) || ! is_string( $zeile['name'] )
			|| 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $zeile['name'] ) || isset( $liste[ $zeile['name'] ] ) ) {
			continue;
		}
		$url = sintoora_kalender_link_pruefen( $zeile['url'] );
		if ( null === $url || in_array( $url, array_column( $liste, 'url' ), true ) ) {
			continue;
		}
		$bezeichnung             = isset( $zeile['bezeichnung'] ) && is_string( $zeile['bezeichnung'] ) ? sanitize_text_field( $zeile['bezeichnung'] ) : '';
		$liste[ $zeile['name'] ] = array(
			'name'        => $zeile['name'],
			'bezeichnung' => '' !== $bezeichnung ? $bezeichnung : $zeile['name'],
			'url'         => $url,
		);
	}
	return array_values( $liste );
}

/**
 * Die eingetragenen Kalender (geprüft). Ist noch nichts gespeichert, gilt die
 * Voreinstellung; sie wird dabei einmalig als Einstellung angelegt.
 *
 * @param bool $anlegen false = die Voreinstellung nicht speichern (beim Prüfen der Eingabe).
 * @return array Liste von array( 'name', 'bezeichnung', 'url' ).
 */
function sintoora_kalender_einstellungen( $anlegen = true ) {
	$wert = get_option( SINTOORA_KALENDER_OPTION_QUELLEN, null );
	if ( null === $wert ) {
		$wert = SINTOORA_KALENDER_STANDARD_QUELLEN;
		if ( $anlegen && $wert ) {
			add_option( SINTOORA_KALENDER_OPTION_QUELLEN, $wert );
		}
	}
	return sintoora_kalender_zeilen_pruefen( $wert );
}

/**
 * Die eingetragenen Kalender als Name => Link, in der eingetragenen Reihenfolge.
 *
 * @return array
 */
function sintoora_kalender_quellen() {
	return array_column( sintoora_kalender_einstellungen(), 'url', 'name' );
}

/**
 * Interner Name für einen neuen Kalender (bleibt auch beim Umbenennen gleich).
 *
 * @param string   $bezeichnung Bezeichnung.
 * @param string[] $vergeben    Bereits vergebene Namen.
 * @return string
 */
function sintoora_kalender_neuer_name( $bezeichnung, $vergeben ) {
	$basis = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( remove_accents( (string) $bezeichnung ) ) ), '_' );
	$basis = '' !== $basis ? substr( $basis, 0, 30 ) : 'kalender';
	$name  = $basis;
	$nr    = 2;
	while ( in_array( $name, $vergeben, true ) ) {
		$name = $basis . '_' . $nr;
		++$nr;
	}
	return $name;
}

/**
 * Prüft die Eingabe der Einstellungsseite (sanitize_callback der Option).
 *
 * Bestehende Kalender behalten ihren internen Namen (Formularfeld „name“ wird nur
 * akzeptiert, wenn es diesen Kalender schon gibt). Ein ungültiger Link wird nicht
 * übernommen: Bei einem bestehenden Kalender bleibt der bisherige Link, ein neuer
 * Kalender wird nicht angelegt. Entfernt wird nur mit dem Häkchen „Entfernen“.
 *
 * @param mixed $eingabe Formulardaten.
 * @return array Neue Liste der Kalender.
 */
function sintoora_kalender_einstellungen_bereinigen( $eingabe ) {
	$alt = array_column( sintoora_kalender_einstellungen( false ), null, 'name' );
	if ( ! is_array( $eingabe ) ) {
		return array_values( $alt );
	}

	$neu    = array();
	$fehler = array();
	foreach ( $eingabe as $zeile ) {
		if ( ! is_array( $zeile ) ) {
			continue;
		}
		$name        = isset( $zeile['name'] ) && is_string( $zeile['name'] ) && isset( $alt[ $zeile['name'] ] ) && ! isset( $neu[ $zeile['name'] ] ) ? $zeile['name'] : '';
		$bezeichnung = isset( $zeile['bezeichnung'] ) && is_string( $zeile['bezeichnung'] ) ? mb_substr( sanitize_text_field( $zeile['bezeichnung'] ), 0, 60, 'UTF-8' ) : '';
		$roh         = isset( $zeile['url'] ) && is_string( $zeile['url'] ) ? trim( $zeile['url'] ) : '';
		if ( '' !== $name && ! empty( $zeile['entfernen'] ) ) {
			continue; // Ausdrücklich entfernt.
		}
		if ( '' === $name && '' === $roh ) {
			continue; // Leere Zeile für einen neuen Kalender.
		}
		// Für die Fehlermeldung (WordPress gibt Meldungen der Einstellungen ohne Escaping aus).
		$anzeige = esc_html( '' !== $bezeichnung ? $bezeichnung : ( '' !== $name ? $alt[ $name ]['bezeichnung'] : 'neuer Kalender' ) );
		$url     = sintoora_kalender_link_pruefen( $roh );
		if ( null !== $url && in_array( $url, array_column( $neu, 'url' ), true ) ) {
			$fehler[] = sprintf( 'Der Link für „%s“ ist schon bei einem anderen Kalender eingetragen und wurde nicht übernommen.', $anzeige );
			$url      = false;
		} elseif ( null === $url ) {
			$fehler[] = sprintf( 'Der Link für „%s“ ist kein gültiger Sintoora-Link (https://app.sintoora.com/screen/calendar/screen?t=…) und wurde nicht übernommen.', $anzeige );
		}
		if ( ! $url ) {
			if ( '' !== $name && ! in_array( $alt[ $name ]['url'], array_column( $neu, 'url' ), true ) ) {
				$neu[ $name ] = $alt[ $name ]; // Bisherigen Link behalten.
			}
			continue;
		}
		if ( count( $neu ) >= SINTOORA_KALENDER_MAX_QUELLEN ) {
			$fehler[] = sprintf( 'Es sind höchstens %d Kalender möglich.', SINTOORA_KALENDER_MAX_QUELLEN );
			break;
		}
		if ( '' === $name ) {
			$name = sintoora_kalender_neuer_name( '' !== $bezeichnung ? $bezeichnung : 'kalender', array_merge( array_keys( $alt ), array_keys( $neu ) ) );
		}
		$neu[ $name ] = array(
			'name'        => $name,
			'bezeichnung' => '' !== $bezeichnung ? $bezeichnung : ( isset( $alt[ $name ] ) ? $alt[ $name ]['bezeichnung'] : $name ),
			'url'         => $url,
		);
	}

	foreach ( $fehler as $nr => $text ) {
		add_settings_error( SINTOORA_KALENDER_OPTION_QUELLEN, 'sintoora_kalender_' . $nr, $text );
	}
	return array_values( $neu );
}

/**
 * Nach dem Speichern geänderter Einstellungen: Termine gleich neu holen, damit kein
 * Besucher warten muss. Die gespeicherten Termine unveränderter Kalender bleiben dabei
 * als Reserve erhalten (falls Sintoora gerade nicht antwortet).
 *
 * @param mixed $alt Bisheriger Wert der Option.
 */
function sintoora_kalender_einstellungen_geaendert( $alt ) {
	$alt_quellen = array_column( sintoora_kalender_zeilen_pruefen( $alt ), 'url', 'name' );
	$neu_quellen = sintoora_kalender_quellen();
	if ( $alt_quellen === $neu_quellen ) {
		return; // Nur Bezeichnungen geändert – die Termine bleiben gültig.
	}
	$gespeichert = get_option( SINTOORA_KALENDER_LETZTER_STAND );
	$reserve     = array();
	if ( is_array( $gespeichert ) && isset( $gespeichert['schluessel'], $gespeichert['zeit'], $gespeichert['quellen'] ) && is_array( $gespeichert['quellen'] )
		&& sintoora_kalender_schluessel( array_keys( $gespeichert['quellen'] ), $alt_quellen ) === $gespeichert['schluessel'] ) {
		foreach ( $gespeichert['quellen'] as $name => $termine ) {
			if ( is_array( $termine ) && isset( $alt_quellen[ $name ], $neu_quellen[ $name ] ) && $alt_quellen[ $name ] === $neu_quellen[ $name ] ) {
				$reserve[ $name ] = $termine;
			}
		}
	}

	delete_transient( SINTOORA_KALENDER_CACHE );
	delete_transient( SINTOORA_KALENDER_SPERRE );
	delete_transient( SINTOORA_KALENDER_PAUSE );
	delete_transient( SINTOORA_KALENDER_ANSTOSS );
	if ( ! $neu_quellen || ! $reserve ) {
		delete_option( SINTOORA_KALENDER_LETZTER_STAND );
		if ( ! $neu_quellen ) {
			return;
		}
	}
	$letzter = null;
	if ( $reserve ) {
		// Als „letzter Stand“ speichern, damit er auch dann gilt, wenn gleich kein Kalender antwortet.
		$letzter = array(
			'schluessel'   => sintoora_kalender_schluessel( array_keys( $reserve ) ),
			'zeit'         => (int) $gespeichert['zeit'],
			'vollstaendig' => false,
			'quellen'      => $reserve,
		);
		update_option( SINTOORA_KALENDER_LETZTER_STAND, $letzter, false );
	}
	sintoora_kalender_holen( $letzter );
}
add_action( 'update_option_' . SINTOORA_KALENDER_OPTION_QUELLEN, 'sintoora_kalender_einstellungen_geaendert' );

/**
 * Registriert die Option (nur für die Einstellungsseite, nicht in der REST-API).
 */
function sintoora_kalender_einstellungen_registrieren() {
	register_setting(
		'sintoora_kalender',
		SINTOORA_KALENDER_OPTION_QUELLEN,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sintoora_kalender_einstellungen_bereinigen',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'sintoora_kalender_einstellungen_registrieren' );

/**
 * Adresse der Einstellungsseite.
 *
 * @return string
 */
function sintoora_kalender_seiten_url() {
	return admin_url( 'admin.php?page=sintoora-kalender' );
}

/**
 * Eigener Menüpunkt „Sintoora Kalender“ in der linken Leiste (nur für Administratoren, weil dort die Links
 * mit den Token stehen). Bis 1.7.0 lag er unter „Einstellungen“.
 */
function sintoora_kalender_menue() {
	add_menu_page( 'Sintoora Kalender', 'Sintoora Kalender', 'manage_options', 'sintoora-kalender', 'sintoora_kalender_seite', 'dashicons-calendar-alt', 23 );
}
add_action( 'admin_menu', 'sintoora_kalender_menue' );

/**
 * Alte Adresse „Einstellungen → Sintoora Kalender“ (Lesezeichen) auf die neue weiterleiten. WordPress kennt die
 * Seite dort nicht mehr und würde den Zugriff verweigern; vorher greift diese Weiterleitung.
 */
function sintoora_kalender_alte_adresse() {
	global $pagenow;
	if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'sintoora-kalender' === sanitize_key( wp_unslash( $_GET['page'] ) ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Weiterleitung.
		wp_safe_redirect( sintoora_kalender_seiten_url() );
		exit;
	}
}
add_action( 'admin_page_access_denied', 'sintoora_kalender_alte_adresse' );

/**
 * Link „Einstellungen“ in der Plugin-Liste.
 *
 * @param array $links Vorhandene Links.
 * @return array
 */
function sintoora_kalender_aktionslinks( $links ) {
	array_unshift( $links, '<a href="' . esc_url( sintoora_kalender_seiten_url() ) . '">Einstellungen</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sintoora_kalender_aktionslinks' );

/**
 * Hinweis im Dashboard, solange noch kein Kalender eingetragen ist.
 */
function sintoora_kalender_hinweis() {
	if ( ! current_user_can( 'manage_options' ) || sintoora_kalender_quellen() ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
		esc_html( 'Sintoora Kalender: Es sind noch keine Kalender-Links eingetragen.' ),
		esc_url( sintoora_kalender_seiten_url() ),
		esc_html( 'Jetzt eintragen' )
	);
}
add_action( 'admin_notices', 'sintoora_kalender_hinweis' );

/**
 * Text zu einem Fehlercode für die Statusanzeige.
 *
 * @param string $code Fehlercode.
 * @return string
 */
function sintoora_kalender_fehlertext( $code ) {
	$texte = array(
		'sintoora_kalender_verbindung' => 'Sintoora nicht erreichbar',
		'sintoora_kalender_status'     => 'Sintoora meldet einen Fehler (Link ungültig oder abgelaufen?)',
		'sintoora_kalender_groesse'    => 'Antwort leer oder zu groß',
		'sintoora_kalender_json'       => 'Antwort ist kein JSON (Auslieferungs-Format JSON eingestellt?)',
		'sintoora_kalender_format'     => 'Antwort hat ein unerwartetes Format',
		'sintoora_kalender_ohne_html'  => 'Termine ohne Feld „html“',
	);
	return isset( $texte[ $code ] ) ? $texte[ $code ] : 'Fehler';
}

/**
 * Die Einstellungsseite: Kalender-Links, Stand der Termine und „Jetzt neu laden“.
 */
function sintoora_kalender_seite() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$liste   = sintoora_kalender_einstellungen();
	$status  = get_option( SINTOORA_KALENDER_OPTION_STATUS );
	$status  = is_array( $status ) ? $status : array();
	$letzter = sintoora_kalender_letzter_stand();
	$feld    = SINTOORA_KALENDER_OPTION_QUELLEN;
	$meldung = get_transient( 'sintoora_kalender_meldung_' . get_current_user_id() );
	$zeit    = function ( $wert ) {
		return $wert ? wp_date( 'd.m.Y, H:i', (int) $wert ) : '–';
	};

	echo '<div class="wrap"><h1>Sintoora Kalender</h1>';
	settings_errors(); // Auf eigenen Menüseiten zeigt WordPress „Einstellungen gespeichert“ und Fehler nicht von selbst.
	if ( is_array( $meldung ) && isset( $meldung[0], $meldung[1] ) && in_array( $meldung[0], array( 'success', 'warning', 'error' ), true ) ) {
		delete_transient( 'sintoora_kalender_meldung_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $meldung[0] ), esc_html( $meldung[1] ) );
	}

	echo '<p>' . esc_html( 'Hier stehen die Links der Sintoora-Anzeigen, deren Termine im Kalender erscheinen (Shortcode [sintoora_kalender]). In Sintoora muss bei der Anzeige das Auslieferungs-Format JSON eingestellt sein. Statt des ganzen Links genügt auch der Token (der Teil nach „t=“).' ) . '</p>';
	echo '<p>' . esc_html( 'Die Reihenfolge zählt: Steht ein Termin in mehreren Kalendern, bleibt der aus dem oberen. Nach dem Speichern werden die Termine sofort neu geholt.' ) . '</p>';

	echo '<form method="post" action="options.php">';
	settings_fields( 'sintoora_kalender' );
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:22%">Bezeichnung</th><th>Link der Sintoora-Anzeige</th><th style="width:9%">Entfernen</th></tr></thead><tbody>';
	$zeilen = $liste;
	if ( count( $zeilen ) < SINTOORA_KALENDER_MAX_QUELLEN ) {
		$zeilen[] = array(
			'name'        => '',
			'bezeichnung' => '',
			'url'         => '',
		);
	}
	foreach ( $zeilen as $nr => $zeile ) {
		$basis = $feld . '[' . $nr . ']';
		$neu   = '' === $zeile['name'];
		echo '<tr>';
		printf(
			'<td><input type="text" class="regular-text" style="width:100%%" maxlength="60" name="%s" value="%s" placeholder="%s" aria-label="%s"></td>',
			esc_attr( $basis . '[bezeichnung]' ),
			esc_attr( $zeile['bezeichnung'] ),
			esc_attr( $neu ? 'Neuer Kalender (optional)' : '' ),
			esc_attr( 'Bezeichnung' )
		);
		printf(
			'<td><input type="text" class="large-text code" name="%s" value="%s" placeholder="%s" aria-label="%s" autocomplete="off" spellcheck="false"><input type="hidden" name="%s" value="%s"></td>',
			esc_attr( $basis . '[url]' ),
			esc_attr( $zeile['url'] ),
			esc_attr( 'https://app.sintoora.com/screen/calendar/screen?t=…' ),
			esc_attr( 'Link der Sintoora-Anzeige' ),
			esc_attr( $basis . '[name]' ),
			esc_attr( $zeile['name'] )
		);
		echo $neu ? '<td></td>' : '<td><label><input type="checkbox" name="' . esc_attr( $basis . '[entfernen]' ) . '" value="1"> ' . esc_html( 'Entfernen' ) . '</label></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	submit_button( 'Änderungen speichern' );
	echo '</form>';

	echo '<h2>' . esc_html( 'Stand der Termine' ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Kalender</th><th>Termine</th><th>Zuletzt erfolgreich geholt</th><th>Letzter Fehler</th></tr></thead><tbody>';
	foreach ( $liste as $zeile ) {
		$eintrag = isset( $status[ $zeile['name'] ] ) && is_array( $status[ $zeile['name'] ] ) ? $status[ $zeile['name'] ] : array();
		$anzahl  = $letzter && isset( $letzter['quellen'][ $zeile['name'] ] ) ? count( $letzter['quellen'][ $zeile['name'] ] ) : null;
		// Ein erfolgreicher Abruf löscht den Fehler; steht einer da, war der letzte Versuch erfolglos.
		$fehler = ! empty( $eintrag['fehler'] ) ? sintoora_kalender_fehlertext( (string) $eintrag['fehler'] ) . ' (' . $zeit( $eintrag['fehler_zeit'] ?? 0 ) . ')' : '–';
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $zeile['bezeichnung'] ),
			esc_html( null === $anzahl ? '–' : (string) $anzahl ),
			esc_html( $zeit( $eintrag['ok'] ?? 0 ) ),
			esc_html( $fehler )
		);
	}
	echo '</tbody></table>';
	$naechster = wp_next_scheduled( SINTOORA_KALENDER_CRON_REGELMAESSIG );
	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				'Gespeicherter Stand: %s%s. Nächste regelmäßige Aktualisierung: %s.',
				$letzter ? $zeit( $letzter['zeit'] ) : 'noch keiner',
				$letzter && ! sintoora_kalender_ist_frisch( $letzter ) ? ' (älter als 15 Minuten oder unvollständig – wird beim nächsten Seitenaufruf im Hintergrund erneuert)' : '',
				$naechster ? $zeit( $naechster ) : 'beim nächsten Abruf der Termine'
			)
		)
	);
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="sintoora_kalender_neu_laden">';
	wp_nonce_field( 'sintoora_kalender_neu_laden' );
	submit_button( 'Termine jetzt neu laden', 'secondary', 'submit', false );
	echo '</form></div>';
}

/**
 * „Termine jetzt neu laden“ (admin-post.php, nur Administratoren, mit Nonce).
 */
function sintoora_kalender_neu_laden() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Keine Berechtigung.' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sintoora_kalender_neu_laden' );

	delete_transient( SINTOORA_KALENDER_CACHE );
	delete_transient( SINTOORA_KALENDER_PAUSE );
	delete_transient( SINTOORA_KALENDER_ANSTOSS );
	$daten  = sintoora_kalender_termine( true );
	$fehler = get_transient( SINTOORA_KALENDER_PAUSE );
	if ( null === $daten ) {
		$meldung = array( 'error', sintoora_kalender_quellen() ? 'Die Termine konnten nicht von Sintoora geholt werden. Details stehen unten bei „Letzter Fehler“.' : 'Es sind noch keine Kalender eingetragen.' );
	} elseif ( $fehler ) {
		$meldung = array( 'warning', sprintf( '%d Termine geholt, aber nicht alle Kalender haben geantwortet. Details stehen unten bei „Letzter Fehler“.', count( $daten['appointments'] ) ) );
	} else {
		$meldung = array( 'success', sprintf( '%d Termine neu von Sintoora geholt.', count( $daten['appointments'] ) ) );
	}
	set_transient( 'sintoora_kalender_meldung_' . get_current_user_id(), $meldung, 120 );
	wp_safe_redirect( sintoora_kalender_seiten_url() );
	exit;
}
add_action( 'admin_post_sintoora_kalender_neu_laden', 'sintoora_kalender_neu_laden' );

/*
 * =====================================================================
 * Aktivierung und Deinstallation
 * =====================================================================
 */

/**
 * Beim Aktivieren: Deinstallations-Routine anmelden, Zwischenspeicher leeren, gleich
 * einmal füllen (damit es sofort einen „letzten Stand“ gibt) und die regelmäßige
 * Aktualisierung planen.
 */
function sintoora_kalender_aktivieren() {
	register_uninstall_hook( __FILE__, 'sintoora_kalender_deinstallieren' );
	delete_transient( SINTOORA_KALENDER_CACHE );
	delete_transient( SINTOORA_KALENDER_SPERRE );
	delete_transient( SINTOORA_KALENDER_PAUSE );
	delete_transient( SINTOORA_KALENDER_ANSTOSS );
	sintoora_kalender_termine( true );
	sintoora_kalender_planen();
}
register_activation_hook( __FILE__, 'sintoora_kalender_aktivieren' );

/**
 * Beim Deaktivieren: geplante Hintergrund-Abrufe entfernen.
 */
function sintoora_kalender_deaktivieren() {
	wp_clear_scheduled_hook( SINTOORA_KALENDER_CRON_REGELMAESSIG );
	wp_clear_scheduled_hook( SINTOORA_KALENDER_CRON_SOFORT );
	delete_transient( SINTOORA_KALENDER_ANSTOSS );
}
register_deactivation_hook( __FILE__, 'sintoora_kalender_deaktivieren' );

/**
 * Beim Löschen des Plugins: alle gespeicherten Daten, Einstellungen und geplanten Abrufe entfernen.
 */
function sintoora_kalender_deinstallieren() {
	sintoora_kalender_deaktivieren();
	delete_option( SINTOORA_KALENDER_OPTION_QUELLEN );
	delete_option( SINTOORA_KALENDER_OPTION_STATUS );
	delete_transient( SINTOORA_KALENDER_CACHE );
	delete_transient( SINTOORA_KALENDER_SPERRE );
	delete_transient( SINTOORA_KALENDER_PAUSE );
	delete_option( SINTOORA_KALENDER_LETZTER_STAND );
}
