<?php
/**
 * Test des Updaters mit zwei Quellen (GitHub und Codeberg) für Auhof Schulseiten und Sintoora Kalender,
 * ohne Verbindung nach außen.
 *   wp eval-file tests/plugin-update-test.php
 * GitHub und Codeberg werden über „pre_http_request“ (Priorität 20, nach dem Staging Guard) nachgebildet. Für die
 * Installations-Tests werden Archive wie von GitHub (Ordner „repo-1.2.3“) und Codeberg (Ordner „repo“) gebaut.
 * Danach müssen die ursprünglichen Plugin-Ordner BEIDER Plugins wieder eingespielt werden (macht das Aufrufskript).
 */
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$GLOBALS['ct_ok'] = 0; $GLOBALS['ct_f'] = 0;
function ct( $n, $b, $i = '' ) { if ( $b ) { $GLOBALS['ct_ok']++; WP_CLI::log( "  OK     $n" ); } else { $GLOBALS['ct_f']++; WP_CLI::log( "  FEHLER $n $i" ); } }

$GLOBALS['ct_antwort']  = array(); // URL-Anfang => array( code, body[, headers] ); bei Downloads body = Datei.
$GLOBALS['ct_anfragen'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	foreach ( $GLOBALS['ct_antwort'] as $anfang => $a ) {
		if ( 0 === strpos( $url, $anfang ) ) {
			$GLOBALS['ct_anfragen'][] = array( $url, $args );
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				copy( $a[1], $args['filename'] );
				$a[1] = '';
			}
			return array( 'headers' => isset( $a[2] ) ? $a[2] : array(), 'body' => $a[1], 'response' => array( 'code' => $a[0], 'message' => 'x' ), 'cookies' => array(), 'filename' => isset( $args['filename'] ) ? $args['filename'] : null );
		}
	}
	return $pre;
}, 20, 3 );

/** Archiv: Ordner im ZIP = $zip_ordner, Version im Kopf auf $neu gesetzt. */
function ct_archiv( $plugin_ordner, $zip_ordner, $hauptdatei, $neu, $ohne_hauptdatei = false ) {
	$zip  = new ZipArchive();
	$ziel = '/tmp/ct-' . $zip_ordner . '-' . $neu . ( $ohne_hauptdatei ? '-kaputt' : '' ) . '.zip';
	@unlink( $ziel );
	$zip->open( $ziel, ZipArchive::CREATE );
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_ordner, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$rel = substr( $f->getPathname(), strlen( $plugin_ordner ) + 1 );
		if ( $rel === $hauptdatei ) {
			if ( $ohne_hauptdatei ) { continue; }
			$inhalt = preg_replace( '/^( \* Version:\s+)\S+/m', '${1}' . $neu, file_get_contents( $f->getPathname() ) );
			$zip->addFromString( $zip_ordner . '/' . $rel, $inhalt );
		} else {
			$zip->addFile( $f->getPathname(), $zip_ordner . '/' . $rel );
		}
	}
	$zip->close();
	return $ziel;
}
function ct_tags( $namen ) { return wp_json_encode( array_map( function ( $n ) { return array( 'name' => $n ); }, $namen ) ); }
function ct_plus( $v ) { return preg_replace_callback( '/(\d+)$/', function ( $m ) { return (string) ( $m[1] + 1 ); }, $v ); }

$faelle = array(
	array( 'auhof-schulseiten/auhof-schulseiten.php', 'Europagymnasium-Auhof-Linz/auhof-wordpress-plugin', 'europagymnasium-auhof-linz/auhof-wordpress-plugin', 'auhof_update_' ),
	array( 'sintoora-kalender/sintoora-kalender.php', 'Europagymnasium-Auhof-Linz/sintoora-wordpress-plugin', 'europagymnasium-auhof-linz/sintoora-wordpress-plugin', 'sintoora_update_' ),
);
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

foreach ( $faelle as list( $datei, $gh, $cb, $praefix ) ) {
	$gh_api   = 'https://api.github.com/repos/' . $gh . '/tags';
	$cb_api   = 'https://codeberg.org/api/v1/repos/' . $cb . '/tags';
	$gh_zip   = 'https://github.com/' . $gh . '/archive/refs/tags/';
	$cb_zip   = 'https://codeberg.org/' . $cb . '/archive/';
	$daten    = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	$version  = $daten['Version'];
	$speicher = $praefix . sanitize_key( dirname( $datei ) );
	$filter   = 'update_plugins_codeberg.org';
	$frage    = function () use ( $daten, $datei, $filter, $speicher ) {
		delete_site_transient( $speicher );
		$GLOBALS['ct_anfragen'] = array();
		return apply_filters( $filter, false, $daten, $datei, array() );
	};
	$dauer = function () use ( $speicher ) {
		return (int) get_option( '_site_transient_timeout_' . $speicher ) - time();
	};
	WP_CLI::log( "== $datei ($version)" );
	ct( 'Update URI noch auf Codeberg (Übergang)', 'https://codeberg.org/' . $cb === $daten['UpdateURI'], $daten['UpdateURI'] );
	ct( 'Auch der GitHub-Filter ist eingehängt', has_filter( 'update_plugins_github.com' ) && has_filter( 'update_plugins_codeberg.org' ) );

	// 1) Versionswahl über beide Quellen.
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, '[]' ), $cb_api => array( 200, ct_tags( array( 'v1.0.0', 'v1.9.0', 'v1.10.0', 'v2.0.0-beta', 'v9.9.9;rm', '../v3.0.0', 'test', 'v1.10.0 ', 'v1.8.0' ) ) ) );
	$r = $frage();
	ct( 'Nur Codeberg hat Tags: höchste gültige 1.10.0 von Codeberg', is_array( $r ) && '1.10.0' === $r['version'] && $cb_zip . 'v1.10.0.zip' === $r['package'], wp_json_encode( $r ) );
	ct( 'Beide Quellen gefragt (2 Anfragen)', 2 === count( $GLOBALS['ct_anfragen'] ) );
	$gh_a = null;
	foreach ( $GLOBALS['ct_anfragen'] as $x ) { if ( 0 === strpos( $x[0], $gh_api ) ) { $gh_a = $x; } }
	ct( 'GitHub-Abruf: api.github.com, per_page=100, Accept vnd.github+json', $gh_a && false !== strpos( $gh_a[0], 'per_page=100' ) && 'application/vnd.github+json' === $gh_a[1]['headers']['Accept'] );
	ct( 'GitHub-Abruf: keine Weiterleitung, Größe begrenzt, keine Website-Adresse', $gh_a && 0 === $gh_a[1]['redirection'] && 262144 === $gh_a[1]['limit_response_size'] && false === strpos( $gh_a[1]['user-agent'], home_url() ) && false === strpos( $gh_a[0], wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	ct( 'Alles erreichbar: 6 Stunden zwischengespeichert', abs( $dauer() - 6 * HOUR_IN_SECONDS ) < 60, (string) $dauer() );
	$GLOBALS['ct_anfragen'] = array();
	apply_filters( $filter, false, $daten, $datei, array() );
	ct( 'Zweiter Aufruf aus dem Zwischenspeicher', 0 === count( $GLOBALS['ct_anfragen'] ) );
	ct( 'Fremdes Plugin unverändert', false === apply_filters( $filter, false, $daten, 'anderes/plugin.php', array() ) );

	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v2.0.0', 'v1.0.0' ) ) ), $cb_api => array( 200, ct_tags( array( 'v1.10.0' ) ) ) );
	$r = $frage();
	ct( 'GitHub höher: 2.0.0 mit GitHub-Archiv', is_array( $r ) && '2.0.0' === $r['version'] && $gh_zip . 'v2.0.0.zip' === $r['package'] && 'https://github.com/' . $gh === $r['url'], wp_json_encode( $r ) );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v1.0.0' ) ) ), $cb_api => array( 200, ct_tags( array( 'v3.0.0' ) ) ) );
	$r = $frage();
	ct( 'Codeberg höher: 3.0.0 mit Codeberg-Archiv', is_array( $r ) && '3.0.0' === $r['version'] && $cb_zip . 'v3.0.0.zip' === $r['package'], wp_json_encode( $r ) );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v4.0.0' ) ) ), $cb_api => array( 200, ct_tags( array( 'v4.0.0' ) ) ) );
	$r = $frage();
	ct( 'Gleichstand: GitHub hat Vorrang', is_array( $r ) && $gh_zip . 'v4.0.0.zip' === $r['package'] );

	// 2) Ausfälle einer oder beider Quellen.
	foreach ( array( 'Abrufgrenze 403' => array( 403, '{"message":"API rate limit exceeded"}' ), 'Abrufgrenze 429' => array( 429, '' ), 'Serverfehler 500' => array( 500, '[]' ), 'kein JSON' => array( 200, '<html>' ), 'Objekt statt Liste' => array( 200, '{"name":"v5.0.0"}' ) ) as $fall => $antwort ) {
		$GLOBALS['ct_antwort'] = array( $gh_api => $antwort, $cb_api => array( 200, ct_tags( array( 'v1.10.0' ) ) ) );
		$r = $frage();
		ct( "GitHub $fall: Codeberg zählt, kurz zwischengespeichert", is_array( $r ) && '1.10.0' === $r['version'] && abs( $dauer() - HOUR_IN_SECONDS ) < 60, wp_json_encode( $r ) . ' ' . $dauer() );
		$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v1.10.0' ) ) ), $cb_api => $antwort );
		$r = $frage();
		ct( "Codeberg $fall: GitHub zählt", is_array( $r ) && $gh_zip . 'v1.10.0.zip' === $r['package'] );
		$GLOBALS['ct_antwort'] = array( $gh_api => $antwort, $cb_api => $antwort );
		ct( "Beide $fall: kein Update", false === $frage() && is_array( get_site_transient( $speicher ) ) && ! isset( get_site_transient( $speicher )['version'] ) );
	}

	// 3) Weiterleitungen: nur innerhalb desselben Servers (GitHub: api.github.com), höchstens drei.
	$neu_gh = 'https://api.github.com/repositories/123456/tags?per_page=100';
	$sieben = ct_tags( array( 'v7.7.7' ) );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 301, '', array( 'location' => $neu_gh ) ), $neu_gh => array( 200, $sieben ), $cb_api => array( 200, '[]' ) );
	$r = $frage();
	ct( 'GitHub: Weiterleitung innerhalb api.github.com wird gefolgt', is_array( $r ) && '7.7.7' === $r['version'] && $gh_zip . 'v7.7.7.zip' === $r['package'], wp_json_encode( $r ) );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 301, '', array( 'location' => '/repositories/123456/tags?per_page=100' ) ), $neu_gh => array( 200, $sieben ), $cb_api => array( 200, '[]' ) );
	$r = $frage();
	ct( 'GitHub: relative Weiterleitung auf api.github.com aufgelöst', is_array( $r ) && '7.7.7' === $r['version'] );
	foreach ( array( 'github.com statt API' => 'https://github.com/x/y/tags', 'fremder Server' => 'https://boese.example/tags', 'ähnlicher Name' => 'https://api.github.com.boese.example/x', 'http statt https' => 'http://api.github.com/repos/x/y/tags', 'Protokoll-relativ' => '//boese.example/x' ) as $fall => $ziel ) {
		$GLOBALS['ct_antwort'] = array( $gh_api => array( 302, '', array( 'location' => $ziel ) ), 'https://github.com/x' => array( 200, $sieben ), 'https://boese.example' => array( 200, $sieben ), 'https://api.github.com.boese.example' => array( 200, $sieben ), 'http://api.github.com' => array( 200, $sieben ), $cb_api => array( 200, '[]' ) );
		$r = $frage();
		$gh_n = count( array_filter( $GLOBALS['ct_anfragen'], function ( $x ) use ( $cb_api ) { return 0 !== strpos( $x[0], $cb_api ); } ) );
		ct( "GitHub: Weiterleitung abgelehnt ($fall)", false === $r && 1 === $gh_n, $gh_n . ' Anfragen' );
	}
	$neu_cb = 'https://codeberg.org/api/v1/repos/umgezogen-org/' . basename( $cb ) . '/tags?limit=50';
	$GLOBALS['ct_antwort'] = array( $cb_api => array( 307, '', array( 'location' => $neu_cb ) ), $neu_cb => array( 200, $sieben ), $gh_api => array( 200, '[]' ) );
	$r = $frage();
	ct( 'Codeberg: Weiterleitung innerhalb Codeberg wird gefolgt', is_array( $r ) && '7.7.7' === $r['version'] && $cb_zip . 'v7.7.7.zip' === $r['package'] );
	$GLOBALS['ct_antwort'] = array( $cb_api => array( 302, '', array( 'location' => 'https://codeberg.org.boese.example/x' ) ), 'https://codeberg.org.boese.example' => array( 200, $sieben ), $gh_api => array( 200, '[]' ) );
	ct( 'Codeberg: Weiterleitung auf fremden Server abgelehnt', false === $frage() );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 307, '', array( 'location' => $gh_api . '?per_page=100' ) ), $cb_api => array( 200, '[]' ) );
	$r = $frage();
	$gh_n = count( array_filter( $GLOBALS['ct_anfragen'], function ( $x ) use ( $cb_api ) { return 0 !== strpos( $x[0], $cb_api ); } ) );
	ct( 'GitHub: endlose Weiterleitung nach 4 Anfragen abgebrochen', false === $r && 4 === $gh_n, $gh_n . ' Anfragen' );

	// 4) WordPress' eigene Update-Prüfung und „Details ansehen“.
	$neu = ct_plus( $version );
	delete_site_transient( $speicher );
	delete_site_transient( 'update_plugins' );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v' . $neu ) ) ), $cb_api => array( 200, ct_tags( array( 'v' . $version ) ) ) );
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	ct( "WordPress bietet $neu von GitHub an", isset( $t->response[ $datei ] ) && $neu === $t->response[ $datei ]->new_version && 0 === strpos( $t->response[ $datei ]->package, $gh_zip ), isset( $t->response[ $datei ] ) ? wp_json_encode( $t->response[ $datei ] ) : 'kein Eintrag' );
	$info = plugins_api( 'plugin_information', array( 'slug' => dirname( $datei ) ) );
	ct( 'Details ansehen: Version, Links zu GitHub und Codeberg', ! is_wp_error( $info ) && $neu === $info->version && false !== strpos( $info->sections['description'], 'https://github.com/' . $gh ) && false !== strpos( $info->sections['description'], 'https://codeberg.org/' . $cb ) && false !== strpos( $info->sections['changelog'], 'https://github.com/' . $gh . '/tags' ) );

	// 5) Kaputtes Paket (ohne Hauptdatei) wird abgelehnt, Plugin bleibt unverändert und aktiv.
	$ordner = WP_PLUGIN_DIR . '/' . dirname( $datei );
	$vorher = md5_file( WP_PLUGIN_DIR . '/' . $datei );
	$kaputt = ct_archiv( $ordner, basename( $gh ) . '-' . $neu, basename( $datei ), $neu, true );
	$GLOBALS['ct_antwort'][ $gh_zip ] = array( 200, $kaputt );
	ct( 'Vorher aktiv', is_plugin_active( $datei ) );
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->upgrade( $datei ); // Browser-Weg: WordPress deaktiviert vorher.
	ct( 'Paket ohne Plugin abgelehnt', ! $erg || is_wp_error( $erg ), wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Plugin danach unverändert vorhanden', is_file( WP_PLUGIN_DIR . '/' . $datei ) && md5_file( WP_PLUGIN_DIR . '/' . $datei ) === $vorher );
	ct( 'Nach dem Abbruch wieder aktiv', is_plugin_active( $datei ) );

	// 6) Update aus einem Archiv wie von GitHub (Ordner „repo-1.2.3“), Hintergrund-Weg.
	$aktiv = is_plugin_active( $datei );
	$paket = ct_archiv( $ordner, basename( $gh ) . '-' . $neu, basename( $datei ), $neu );
	$GLOBALS['ct_antwort'][ $gh_zip ] = array( 200, $paket );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	add_filter( 'wp_doing_cron', '__return_true' ); // wie ein automatisches Update im Hintergrund: Plugin bleibt aktiv.
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->upgrade( $datei );
	remove_filter( 'wp_doing_cron', '__return_true' );
	wp_clean_plugins_cache();
	$nach = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	ct( "GitHub-Archiv: Version $neu im richtigen Ordner", true === $erg && $neu === $nach['Version'], wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Kein Ordner „repo-Version“ übrig', ! file_exists( WP_PLUGIN_DIR . '/' . basename( $gh ) . '-' . $neu ) );
	ct( 'Aktiv-Status unverändert', $aktiv === is_plugin_active( $datei ) );
	$letzte = end( $GLOBALS['ct_anfragen'] );
	ct( 'Download vom Tag-Archiv auf GitHub', 0 === strpos( $letzte[0], $gh_zip . 'v' . $neu . '.zip' ), $letzte[0] );

	// 7) Browser-Weg „Jetzt aktualisieren“ mit einem Archiv von Codeberg (Ordner „repo“).
	$neu2  = ct_plus( $neu );
	$paket2 = ct_archiv( $ordner, basename( $cb ), basename( $datei ), $neu2 );
	delete_site_transient( $speicher );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v' . $neu ) ) ), $cb_api => array( 200, ct_tags( array( 'v' . $neu2 ) ) ), $cb_zip => array( 200, $paket2 ) );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->bulk_upgrade( array( $datei ) );
	wp_clean_plugins_cache();
	$nach = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	ct( "Codeberg-Archiv, Browser-Weg: Version $neu2 installiert", is_array( $erg ) && ! empty( $erg[ $datei ] ) && ! is_wp_error( $erg[ $datei ] ) && $neu2 === $nach['Version'], wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Browser-Weg: danach aktiv (wie vor dem Update)', is_plugin_active( $datei ) );
	ct( 'Kein Ordner „repo“ übrig', ! file_exists( WP_PLUGIN_DIR . '/' . basename( $cb ) ) );

	delete_site_transient( $speicher );
	@unlink( $kaputt ); @unlink( $paket ); @unlink( $paket2 );
}
delete_site_transient( 'update_plugins' );
WP_CLI::log( 'Ergebnis: ' . $GLOBALS['ct_ok'] . ' bestanden, ' . $GLOBALS['ct_f'] . ' fehlgeschlagen' );
