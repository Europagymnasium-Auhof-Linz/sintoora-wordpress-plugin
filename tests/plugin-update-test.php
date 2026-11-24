<?php
/**
 * Test des Updaters (nur GitHub, ab Auhof 1.11.0 / Sintoora 1.9.0) für Auhof Schulseiten und Sintoora Kalender,
 * ohne Verbindung nach außen.
 *   wp eval-file tests/plugin-update-test.php
 * GitHub (und zur Gegenprobe Codeberg) wird über „pre_http_request“ (Priorität 20, nach dem Staging Guard)
 * nachgebildet. Für die Installations-Tests werden Archive wie von GitHub gebaut (Ordner „repo-1.2.3“).
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
	array( 'auhof-schulseiten/auhof-schulseiten.php', 'Europagymnasium-Auhof-Linz/auhof-wordpress-plugin', 'europagymnasium-auhof-linz/auhof-wordpress-plugin', 'auhof_update_github_' ),
	array( 'sintoora-kalender/sintoora-kalender.php', 'Europagymnasium-Auhof-Linz/sintoora-wordpress-plugin', 'europagymnasium-auhof-linz/sintoora-wordpress-plugin', 'sintoora_update_github_' ),
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
	$filter   = 'update_plugins_github.com';
	$frage    = function () use ( $daten, $datei, $filter, $speicher ) {
		delete_site_transient( $speicher );
		$GLOBALS['ct_anfragen'] = array();
		return apply_filters( $filter, false, $daten, $datei, array() );
	};
	$dauer = function () use ( $speicher ) {
		return (int) get_option( '_site_transient_timeout_' . $speicher ) - time();
	};
	$nur_github = function () {
		foreach ( $GLOBALS['ct_anfragen'] as $x ) {
			if ( 0 !== strpos( $x[0], 'https://api.github.com/' ) && 0 !== strpos( $x[0], 'https://github.com/' ) ) { return false; }
		}
		return true;
	};
	WP_CLI::log( "== $datei ($version)" );
	ct( 'Update URI zeigt auf GitHub', 'https://github.com/' . $gh === $daten['UpdateURI'], $daten['UpdateURI'] );
	ct( 'Kein Codeberg-Filter mehr eingehängt', false === has_filter( 'update_plugins_codeberg.org' ) );
	ct( 'Klasse nimmt Codeberg nicht an (auch wenn eingetragen)', ( function () use ( $datei ) {
		$klasse = 0 === strpos( $datei, 'auhof' ) ? 'Auhof_Updater' : 'Sintoora_Kalender_Updater';
		$vorher = has_filter( 'update_plugins_codeberg.org' );
		new $klasse( WP_PLUGIN_DIR . '/test-x/test-x.php', array( 'codeberg.org' => 'a/b' ), 'Test', '1.0.0' );
		return false === $vorher && false === has_filter( 'update_plugins_codeberg.org' );
	} )() );

	// 1) Versionswahl, nur GitHub; Codeberg wird nicht gefragt, auch wenn dort mehr liegt.
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v1.0.0', 'v1.9.0', 'v1.10.0', 'v2.0.0-beta', 'v9.9.9;rm', '../v3.0.0', 'test', 'v1.10.0 ', 'v1.8.0' ) ) ), $cb_api => array( 200, ct_tags( array( 'v99.0.0' ) ) ) );
	$r = $frage();
	ct( 'Höchste gültige Version 1.10.0 (beta, Schadtext, Pfade ignoriert)', is_array( $r ) && '1.10.0' === $r['version'], wp_json_encode( $r ) );
	ct( 'Paket = Tag-Archiv auf GitHub', is_array( $r ) && $gh_zip . 'v1.10.0.zip' === $r['package'] && 'https://github.com/' . $gh === $r['url'] );
	ct( 'Nur GitHub gefragt (1 Anfrage, Codeberg-v99 ignoriert)', 1 === count( $GLOBALS['ct_anfragen'] ) && $nur_github(), count( $GLOBALS['ct_anfragen'] ) . ' Anfragen' );
	$a = $GLOBALS['ct_anfragen'][0];
	ct( 'Abruf: api.github.com, per_page=100, Accept vnd.github+json', 0 === strpos( $a[0], $gh_api . '?per_page=100' ) && 'application/vnd.github+json' === $a[1]['headers']['Accept'] );
	ct( 'Abruf: keine Weiterleitung, Größe begrenzt, keine Website-Adresse', 0 === $a[1]['redirection'] && 262144 === $a[1]['limit_response_size'] && false === strpos( $a[1]['user-agent'], home_url() ) );
	ct( '6 Stunden zwischengespeichert', abs( $dauer() - 6 * HOUR_IN_SECONDS ) < 60, (string) $dauer() );
	$GLOBALS['ct_anfragen'] = array();
	apply_filters( $filter, false, $daten, $datei, array() );
	ct( 'Zweiter Aufruf aus dem Zwischenspeicher', 0 === count( $GLOBALS['ct_anfragen'] ) );
	ct( 'Fremdes Plugin unverändert', false === apply_filters( $filter, false, $daten, 'anderes/plugin.php', array() ) );
	set_site_transient( $praefix === 'auhof_update_github_' ? 'auhof_update_' . dirname( $datei ) : 'sintoora_update_' . dirname( $datei ), array( 'version' => '99.0.0', 'tag' => 'v99.0.0', 'paket' => $cb_zip . 'v99.0.0.zip', 'server' => 'codeberg.org', 'web' => 'https://codeberg.org/' . $cb ), HOUR_IN_SECONDS );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v1.10.0' ) ) ) );
	$r = $frage();
	ct( 'Alter Zwischenspeicher der Übergangsversion (Codeberg) wird nicht verwendet', is_array( $r ) && '1.10.0' === $r['version'] && 0 === strpos( $r['package'], $gh_zip ) );

	// 2) Fehlerfälle: kein Update, 1 Stunde zwischengespeichert, kein Ausweichen auf Codeberg.
	foreach ( array( 'Abrufgrenze 403' => array( 403, '{"message":"API rate limit exceeded"}' ), 'Abrufgrenze 429' => array( 429, '' ), 'Serverfehler 500' => array( 500, '[]' ), 'kein JSON' => array( 200, '<html>' ), 'Objekt statt Liste' => array( 200, '{"name":"v5.0.0"}' ), 'leere Liste' => array( 200, '[]' ) ) as $fall => $antwort ) {
		$GLOBALS['ct_antwort'] = array( $gh_api => $antwort, $cb_api => array( 200, ct_tags( array( 'v99.0.0' ) ) ) );
		$r = $frage();
		ct( "Fehlerfall $fall → kein Update, nur GitHub gefragt, 1 h gespeichert", false === $r && $nur_github() && abs( $dauer() - HOUR_IN_SECONDS ) < 60, wp_json_encode( $r ) . ' ' . $dauer() );
	}

	// 3) Weiterleitungen: nur innerhalb api.github.com, höchstens drei.
	$neu_gh = 'https://api.github.com/repositories/123456/tags?per_page=100';
	$sieben = ct_tags( array( 'v7.7.7' ) );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 301, '', array( 'location' => $neu_gh ) ), $neu_gh => array( 200, $sieben ) );
	$r = $frage();
	ct( 'Weiterleitung innerhalb api.github.com wird gefolgt', is_array( $r ) && '7.7.7' === $r['version'] && $gh_zip . 'v7.7.7.zip' === $r['package'], wp_json_encode( $r ) );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 301, '', array( 'location' => '/repositories/123456/tags?per_page=100' ) ), $neu_gh => array( 200, $sieben ) );
	$r = $frage();
	ct( 'Relative Weiterleitung auf api.github.com aufgelöst', is_array( $r ) && '7.7.7' === $r['version'] );
	foreach ( array( 'Codeberg' => 'https://codeberg.org/api/v1/repos/x/y/tags', 'github.com statt API' => 'https://github.com/x/y/tags', 'fremder Server' => 'https://boese.example/tags', 'ähnlicher Name' => 'https://api.github.com.boese.example/x', 'http statt https' => 'http://api.github.com/repos/x/y/tags', 'Protokoll-relativ' => '//boese.example/x' ) as $fall => $ziel ) {
		$GLOBALS['ct_antwort'] = array( $gh_api => array( 302, '', array( 'location' => $ziel ) ), 'https://codeberg.org' => array( 200, $sieben ), 'https://github.com/x' => array( 200, $sieben ), 'https://boese.example' => array( 200, $sieben ), 'https://api.github.com.boese.example' => array( 200, $sieben ), 'http://api.github.com' => array( 200, $sieben ) );
		$r = $frage();
		ct( "Weiterleitung abgelehnt ($fall)", false === $r && 1 === count( $GLOBALS['ct_anfragen'] ), count( $GLOBALS['ct_anfragen'] ) . ' Anfragen' );
	}
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 307, '', array( 'location' => $gh_api . '?per_page=100' ) ) );
	$r = $frage();
	ct( 'Endlose Weiterleitung nach 4 Anfragen abgebrochen', false === $r && 4 === count( $GLOBALS['ct_anfragen'] ), count( $GLOBALS['ct_anfragen'] ) . ' Anfragen' );

	// 4) WordPress' eigene Update-Prüfung und „Details ansehen“.
	$neu = ct_plus( $version );
	delete_site_transient( $speicher );
	delete_site_transient( 'update_plugins' );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v' . $version, 'v' . $neu ) ) ), $cb_api => array( 200, ct_tags( array( 'v99.0.0' ) ) ) );
	$GLOBALS['ct_anfragen'] = array();
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	ct( "WordPress bietet $neu von GitHub an", isset( $t->response[ $datei ] ) && $neu === $t->response[ $datei ]->new_version && 0 === strpos( $t->response[ $datei ]->package, $gh_zip ), isset( $t->response[ $datei ] ) ? wp_json_encode( $t->response[ $datei ] ) : 'kein Eintrag' );
	ct( 'Bei der WordPress-Prüfung keine Anfrage an Codeberg', 0 === count( array_filter( $GLOBALS['ct_anfragen'], function ( $x ) { return false !== strpos( $x[0], 'codeberg.org' ); } ) ) );
	$info = plugins_api( 'plugin_information', array( 'slug' => dirname( $datei ) ) );
	ct( 'Details ansehen: Version, nur GitHub verlinkt', ! is_wp_error( $info ) && $neu === $info->version && false !== strpos( $info->sections['description'], 'https://github.com/' . $gh ) && false === strpos( $info->sections['description'] . $info->sections['changelog'], 'codeberg' ) );

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

	// 6) Update aus einem GitHub-Archiv (Ordner „repo-1.2.3“), Hintergrund-Weg.
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
	ct( "Hintergrund-Update: Version $neu im richtigen Ordner", true === $erg && $neu === $nach['Version'], wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Kein Ordner „repo-Version“ übrig', ! file_exists( WP_PLUGIN_DIR . '/' . basename( $gh ) . '-' . $neu ) );
	ct( 'Aktiv-Status unverändert', $aktiv === is_plugin_active( $datei ) );
	$letzte = end( $GLOBALS['ct_anfragen'] );
	ct( 'Download vom Tag-Archiv auf GitHub', 0 === strpos( $letzte[0], $gh_zip . 'v' . $neu . '.zip' ), $letzte[0] );

	// 7) Browser-Weg „Jetzt aktualisieren“ (bulk_upgrade wie Plugin-Liste und Dashboard → Aktualisierungen).
	$neu2   = ct_plus( $neu );
	$paket2 = ct_archiv( $ordner, basename( $gh ) . '-' . $neu2, basename( $datei ), $neu2 );
	delete_site_transient( $speicher );
	$GLOBALS['ct_antwort'] = array( $gh_api => array( 200, ct_tags( array( 'v' . $neu2 ) ) ), $gh_zip => array( 200, $paket2 ) );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->bulk_upgrade( array( $datei ) );
	wp_clean_plugins_cache();
	$nach = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	ct( "Browser-Weg: Version $neu2 installiert", is_array( $erg ) && ! empty( $erg[ $datei ] ) && ! is_wp_error( $erg[ $datei ] ) && $neu2 === $nach['Version'], wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Browser-Weg: danach aktiv (wie vor dem Update)', is_plugin_active( $datei ) );

	delete_site_transient( $speicher );
	@unlink( $kaputt ); @unlink( $paket ); @unlink( $paket2 );
}
delete_site_transient( 'update_plugins' );
WP_CLI::log( 'Ergebnis: ' . $GLOBALS['ct_ok'] . ' bestanden, ' . $GLOBALS['ct_f'] . ' fehlgeschlagen' );
