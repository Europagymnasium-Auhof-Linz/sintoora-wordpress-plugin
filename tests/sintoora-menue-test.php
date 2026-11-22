<?php
/**
 * Test Menüpunkt „Sintoora Kalender“ (ab 1.7.1).
 *   wp --exec='define("WP_ADMIN",true);' eval-file tests/sintoora-menue-test.php
 */
require_once ABSPATH . 'wp-admin/includes/admin.php';
$ok = 0; $f = 0;
$t = function ( $n, $b, $i = '' ) use ( &$ok, &$f ) { if ( $b ) { $ok++; WP_CLI::log( "  OK     $n" ); } else { $f++; WP_CLI::log( "  FEHLER $n $i" ); } };

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
wp_set_current_user( $admin );
require ABSPATH . 'wp-admin/menu.php';
$oben  = array_values( array_filter( $GLOBALS['menu'], function ( $m ) { return isset( $m[2] ) && 'sintoora-kalender' === $m[2]; } ) );
$unter = array_filter( isset( $GLOBALS['submenu']['options-general.php'] ) ? $GLOBALS['submenu']['options-general.php'] : array(), function ( $m ) { return 'sintoora-kalender' === $m[2]; } );
$t( 'Administrator: eigener Menüpunkt links', 1 === count( $oben ) && 'Sintoora Kalender' === $oben[0][0] && 'dashicons-calendar-alt' === $oben[0][6] );
$t( 'Administrator: nicht mehr unter Einstellungen', ! $unter );
$pos = array_search( $oben[0], $GLOBALS['menu'], true );
$t( 'Position direkt nach Raumplan (23)', 23 == $pos || ( $pos > 22 && $pos < 24 ), (string) $pos );
$t( 'Menüpunkt nur mit Recht manage_options', 'manage_options' === $oben[0][1] );
$red = get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) );
$t( 'Redakteur hat dieses Recht nicht (sieht den Menüpunkt nicht)', ! $red || ! user_can( $red[0], 'manage_options' ) );

$t( 'Adresse der Seite', admin_url( 'admin.php?page=sintoora-kalender' ) === sintoora_kalender_seiten_url() );
$links = sintoora_kalender_aktionslinks( array() );
$t( 'Link „Einstellungen“ in der Plugin-Liste zeigt auf neue Adresse', false !== strpos( $links[0], 'admin.php?page=sintoora-kalender' ) );

// Alte Adresse leitet weiter.
$GLOBALS['pagenow'] = 'options-general.php';
$_GET['page'] = 'sintoora-kalender';
$ziel = null;
add_filter( 'wp_redirect', function ( $l ) use ( &$ziel ) { $ziel = $l; throw new Exception( 'umgeleitet' ); }, 1 );
try { sintoora_kalender_alte_adresse(); } catch ( Exception $e ) {}
$t( 'Alte Adresse → neue Adresse', $ziel === sintoora_kalender_seiten_url(), (string) $ziel );
$ziel = null; $_GET['page'] = 'andere-seite';
try { sintoora_kalender_alte_adresse(); } catch ( Exception $e ) {}
$t( 'Andere Einstellungsseiten unberührt', null === $ziel );
$ziel = null; $_GET['page'] = 'sintoora-kalender';
wp_set_current_user( 0 );
try { sintoora_kalender_alte_adresse(); } catch ( Exception $e ) {}
$t( 'Ohne Recht keine Weiterleitung', null === $ziel );
unset( $_GET['page'] );
wp_set_current_user( $admin );

// Seite und Meldungen.
add_settings_error( SINTOORA_KALENDER_OPTION_QUELLEN, 'testfehler', 'TESTMELDUNG-XYZ' );
ob_start(); sintoora_kalender_seite(); $html = ob_get_clean();
$t( 'Seite wird angezeigt (Überschrift, Formular an options.php)', false !== strpos( $html, '<h1>Sintoora Kalender</h1>' ) && false !== strpos( $html, 'action="options.php"' ) );
$t( 'Meldungen nach dem Speichern erscheinen', false !== strpos( $html, 'TESTMELDUNG-XYZ' ) );
WP_CLI::log( "Ergebnis: $ok bestanden, $f fehlgeschlagen" );
