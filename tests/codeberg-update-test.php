<?php
/**
 * Test des Codeberg-Updaters (Auhof Schulseiten und Sintoora Kalender) ohne Verbindung nach außen.
 *   wp eval-file tests/codeberg-update-test.php
 * Codeberg wird über „pre_http_request“ (Priorität 20, nach dem Staging Guard) nachgebildet. Für den
 * Installations-Test wird ein Archiv wie von Codeberg gebaut (Ordner = Repository-Name, Version +0.0.1).
 * Danach muss der ursprüngliche Plugin-Ordner wieder eingespielt werden (macht das Aufrufskript).
 */
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$GLOBALS['ct_ok'] = 0; $GLOBALS['ct_f'] = 0;
function ct( $n, $b, $i = '' ) { if ( $b ) { $GLOBALS['ct_ok']++; WP_CLI::log( "  OK     $n" ); } else { $GLOBALS['ct_f']++; WP_CLI::log( "  FEHLER $n $i" ); } }

$GLOBALS['ct_antwort'] = array();   // URL-Anfang => array( code, body ) oder Datei für Downloads.
$GLOBALS['ct_anfragen'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	foreach ( $GLOBALS['ct_antwort'] as $anfang => $a ) {
		if ( 0 === strpos( $url, $anfang ) ) {
			$GLOBALS['ct_anfragen'][] = array( $url, $args );
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				copy( $a[1], $args['filename'] );
				$a[1] = '';
			}
			return array( 'headers' => array(), 'body' => $a[1], 'response' => array( 'code' => $a[0], 'message' => 'x' ), 'cookies' => array(), 'filename' => isset( $args['filename'] ) ? $args['filename'] : null );
		}
	}
	return $pre;
}, 20, 3 );

/** Archiv wie Codeberg: Ordner = Repository-Name, Version im Kopf auf $neu gesetzt. */
function ct_archiv( $plugin_ordner, $repo_name, $hauptdatei, $neu, $ohne_hauptdatei = false ) {
	$zip  = new ZipArchive();
	$ziel = '/tmp/ct-' . $repo_name . '-' . $neu . ( $ohne_hauptdatei ? '-kaputt' : '' ) . '.zip';
	@unlink( $ziel );
	$zip->open( $ziel, ZipArchive::CREATE );
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_ordner, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$rel = substr( $f->getPathname(), strlen( $plugin_ordner ) + 1 );
		if ( $rel === $hauptdatei ) {
			if ( $ohne_hauptdatei ) { continue; }
			$inhalt = preg_replace( '/^( \* Version:\s+)\S+/m', '${1}' . $neu, file_get_contents( $f->getPathname() ) );
			$zip->addFromString( $repo_name . '/' . $rel, $inhalt );
		} else {
			$zip->addFile( $f->getPathname(), $repo_name . '/' . $rel );
		}
	}
	$zip->close();
	return $ziel;
}

$faelle = array(
	array( 'auhof-schulseiten/auhof-schulseiten.php', 'europagym-auhof/auhof-wordpress-plugin' ),
	array( 'sintoora-kalender/sintoora-kalender.php', 'europagym-auhof/sintoora-wordpress-plugin' ),
);
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

foreach ( $faelle as list( $datei, $repo ) ) {
	$api      = 'https://codeberg.org/api/v1/repos/' . $repo . '/tags';
	$daten    = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	$version  = $daten['Version'];
	$speicher = 'codeberg_update_' . sanitize_key( str_replace( '/', '_', $repo ) );
	WP_CLI::log( "== $datei ($version)" );
	ct( 'Update URI zeigt auf Codeberg', 'https://codeberg.org/' . $repo === $daten['UpdateURI'], $daten['UpdateURI'] );

	// 1) Auswahl der Version.
	delete_site_transient( $speicher );
	$tags = array( 'v1.0.0', 'v1.9.0', 'v1.10.0', 'v2.0.0-beta', 'v9.9.9;rm', '../v3.0.0', 'test', 'v1.10.0 ', 'v1.8.0' );
	$GLOBALS['ct_antwort'] = array( $api => array( 200, wp_json_encode( array_map( function ( $n ) { return array( 'name' => $n ); }, $tags ) ) ) );
	$GLOBALS['ct_anfragen'] = array();
	$res = apply_filters( 'update_plugins_codeberg.org', false, $daten, $datei, array() );
	ct( 'Höchste gültige Version 1.10.0 (beta, Schadtext, Pfade ignoriert)', is_array( $res ) && '1.10.0' === $res['version'], wp_json_encode( $res ) );
	ct( 'Paket = Tag-Archiv auf Codeberg', is_array( $res ) && 'https://codeberg.org/' . $repo . '/archive/v1.10.0.zip' === $res['package'] );
	$a = $GLOBALS['ct_anfragen'][0][1] ?? array();
	ct( 'Abruf: keine Weiterleitung, Größe begrenzt, keine Website-Adresse', 0 === $a['redirection'] && 262144 === $a['limit_response_size'] && false === strpos( $a['user-agent'], home_url() ) );
	apply_filters( 'update_plugins_codeberg.org', false, $daten, $datei, array() );
	ct( 'Zweiter Aufruf aus dem Zwischenspeicher (keine neue Anfrage)', 1 === count( $GLOBALS['ct_anfragen'] ) );
	$fremd = apply_filters( 'update_plugins_codeberg.org', false, $daten, 'anderes/plugin.php', array() );
	ct( 'Fremdes Plugin unverändert', false === $fremd );

	// 2) Fehlerfälle.
	foreach ( array( 'Serverfehler 500' => array( 500, '[]' ), 'kein JSON' => array( 200, '<html>' ), 'leere Liste' => array( 200, '[]' ), 'falsche Form' => array( 200, '{"name":"v5.0.0"}' ) ) as $fall => $antwort ) {
		delete_site_transient( $speicher );
		$GLOBALS['ct_antwort'] = array( $api => $antwort );
		ct( "Fehlerfall $fall → kein Update", false === apply_filters( 'update_plugins_codeberg.org', false, $daten, $datei, array() ) );
	}
	ct( 'Nach Fehler kurz zwischengespeichert', is_array( get_site_transient( $speicher ) ) && ! isset( get_site_transient( $speicher )['version'] ) );

	// 3) WordPress' eigene Update-Prüfung und „Details ansehen“.
	$neu = preg_replace_callback( '/(\d+)$/', function ( $m ) { return (string) ( $m[1] + 1 ); }, $version );
	delete_site_transient( $speicher );
	delete_site_transient( 'update_plugins' );
	$GLOBALS['ct_antwort'] = array( $api => array( 200, wp_json_encode( array( array( 'name' => 'v' . $version ), array( 'name' => 'v' . $neu ) ) ) ) );
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	ct( "WordPress bietet $neu an", isset( $t->response[ $datei ] ) && $neu === $t->response[ $datei ]->new_version, isset( $t->response[ $datei ] ) ? wp_json_encode( $t->response[ $datei ] ) : 'kein Eintrag' );
	$info = plugins_api( 'plugin_information', array( 'slug' => dirname( $datei ) ) );
	ct( 'Details ansehen: Name, Version, Link zu Codeberg', ! is_wp_error( $info ) && $neu === $info->version && false !== strpos( $info->sections['description'], 'https://codeberg.org/' . $repo ) );

	// 4) Kaputtes Paket (ohne Hauptdatei) wird abgelehnt, Plugin bleibt unverändert.
	$ordner = WP_PLUGIN_DIR . '/' . dirname( $datei );
	$vorher = md5_file( WP_PLUGIN_DIR . '/' . $datei );
	$kaputt = ct_archiv( $ordner, basename( $repo ), basename( $datei ), $neu, true );
	$GLOBALS['ct_antwort'][ 'https://codeberg.org/' . $repo . '/archive/' ] = array( 200, $kaputt );
	ct( 'Vorher aktiv', is_plugin_active( $datei ) );
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->upgrade( $datei ); // Browser-Weg: WordPress deaktiviert vorher.
	ct( 'Paket ohne Plugin abgelehnt', ! $erg || is_wp_error( $erg ), wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Plugin danach unverändert vorhanden', is_file( WP_PLUGIN_DIR . '/' . $datei ) && md5_file( WP_PLUGIN_DIR . '/' . $datei ) === $vorher );
	ct( 'Nach dem Abbruch wieder aktiv', is_plugin_active( $datei ) );

	// 5) Echtes Update aus einem Archiv wie von Codeberg.
	$aktiv = is_plugin_active( $datei );
	$paket = ct_archiv( $ordner, basename( $repo ), basename( $datei ), $neu );
	$GLOBALS['ct_antwort'][ 'https://codeberg.org/' . $repo . '/archive/' ] = array( 200, $paket );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	add_filter( 'wp_doing_cron', '__return_true' ); // wie ein automatisches Update im Hintergrund: Plugin bleibt aktiv.
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->upgrade( $datei );
	remove_filter( 'wp_doing_cron', '__return_true' );
	wp_clean_plugins_cache();
	$nach = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	ct( "Update installiert: Version $neu im richtigen Ordner", true === $erg && $neu === $nach['Version'], wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Kein Ordner mit Repository-Namen übrig', ! file_exists( WP_PLUGIN_DIR . '/' . basename( $repo ) ) );
	ct( 'Aktiv-Status unverändert', $aktiv === is_plugin_active( $datei ) );
	$letzte = end( $GLOBALS['ct_anfragen'] );
	ct( 'Download vom Tag-Archiv auf Codeberg', false !== strpos( $letzte[0], 'https://codeberg.org/' . $repo . '/archive/v' . $neu . '.zip' ), $letzte[0] );

	// 6) Browser-Weg „Jetzt aktualisieren“ (bulk_upgrade wie Plugin-Liste und Dashboard → Aktualisierungen).
	$neu2  = preg_replace_callback( '/(\d+)$/', function ( $m ) { return (string) ( $m[1] + 1 ); }, $neu );
	$paket = ct_archiv( $ordner, basename( $repo ), basename( $datei ), $neu2 );
	delete_site_transient( $speicher );
	$GLOBALS['ct_antwort'] = array(
		$api                                                => array( 200, wp_json_encode( array( array( 'name' => 'v' . $neu2 ) ) ) ),
		'https://codeberg.org/' . $repo . '/archive/'       => array( 200, $paket ),
	);
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$skin = new Automatic_Upgrader_Skin();
	$erg  = ( new Plugin_Upgrader( $skin ) )->bulk_upgrade( array( $datei ) );
	wp_clean_plugins_cache();
	$nach = get_plugin_data( WP_PLUGIN_DIR . '/' . $datei, false, false );
	ct( "Browser-Weg: Version $neu2 installiert", is_array( $erg ) && ! empty( $erg[ $datei ] ) && ! is_wp_error( $erg[ $datei ] ) && $neu2 === $nach['Version'], wp_json_encode( $skin->get_upgrade_messages() ) );
	ct( 'Browser-Weg: danach aktiv (wie vor dem Update)', is_plugin_active( $datei ) );
	@unlink( $paket );

	delete_site_transient( $speicher );
	@unlink( $kaputt ); @unlink( $paket );
}
delete_site_transient( 'update_plugins' );
WP_CLI::log( 'Ergebnis: ' . $GLOBALS['ct_ok'] . ' bestanden, ' . $GLOBALS['ct_f'] . ' fehlgeschlagen' );
