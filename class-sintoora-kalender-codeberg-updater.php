<?php
/**
 * Updates aus dem öffentlichen Codeberg-Repository.
 *
 * WordPress fragt bei Plugins mit „Update URI: https://codeberg.org/…“ nicht wordpress.org, sondern ruft den
 * Filter „update_plugins_codeberg.org“ auf. Diese Klasse beantwortet ihn: Sie liest die Tags des Repositories
 * (v1.2.3), nimmt die höchste Version und bietet das Tag-Archiv von Codeberg als Paket an. Beim Installieren
 * benennt sie den Ordner im Archiv (Name des Repositories) in den Plugin-Ordner um und prüft, dass die
 * Hauptdatei des Plugins darin liegt.
 *
 * Neue Version veröffentlichen = Tag „vX.Y.Z“ auf Codeberg anlegen. Installiert wird nur, wenn jemand im
 * Backend auf „Aktualisieren“ klickt – oder automatisch, falls für das Plugin automatische Updates aktiviert sind.
 *
 * Sicherheit: nur https zu der fest eingetragenen Repository-Adresse (Weiterleitungen nur innerhalb von
 * codeberg.org, höchstens drei), Versionsnummern streng geprüft, Antwort
 * auf 256 KB begrenzt, keine Angaben über die Website im Abruf. Ergebnis 6 Stunden zwischengespeichert (nach
 * Fehlern 1 Stunde), „Erneut prüfen“ unter Dashboard → Aktualisierungen leert den Zwischenspeicher.
 *
 * Dieselbe Klasse steckt (mit anderem Namen) auch im Plugin „Auhof Schulseiten“; Änderungen in beiden nachziehen.
 *
 * @package SintooraKalender
 */

defined( 'ABSPATH' ) || exit;

/**
 * Updater für ein Plugin aus einem Codeberg-Repository.
 */
final class Sintoora_Kalender_Codeberg_Updater {

	/** Gültige Tags: v1.2.3 oder 1.2.3. */
	const TAG_MUSTER = '/^v?(\d{1,3}\.\d{1,3}\.\d{1,3})$/';

	/** Zwischenspeicher in Sekunden. */
	const DAUER_OK     = 6 * HOUR_IN_SECONDS;
	const DAUER_FEHLER = HOUR_IN_SECONDS;

	/** @var string Plugin-Datei relativ zum Plugin-Ordner, z. B. „sintoora-kalender/sintoora-kalender.php“. */
	private $datei;

	/** @var string Ordner des Plugins, z. B. „sintoora-kalender“. */
	private $ordner;

	/** @var string Repository „besitzer/name“. */
	private $repo;

	/** @var string Anzeigename. */
	private $name;

	/** @var string Version des installierten Plugins. */
	private $version;

	/** @var bool War das Plugin vor dem Update aktiv? */
	private $war_aktiv = false;

	/**
	 * @param string $datei   __FILE__ der Plugin-Hauptdatei.
	 * @param string $repo    Repository „besitzer/name“ auf Codeberg.
	 * @param string $name    Anzeigename.
	 * @param string $version Installierte Version.
	 */
	public function __construct( $datei, $repo, $name, $version ) {
		$this->datei   = plugin_basename( $datei );
		$this->ordner  = dirname( $this->datei );
		$this->repo    = $repo;
		$this->name    = $name;
		$this->version = $version;

		add_filter( 'update_plugins_codeberg.org', array( $this, 'update_pruefen' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_install', array( $this, 'aktiv_merken' ), 1, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'ordner_anpassen' ), 10, 4 );
		add_action( 'load-update-core.php', array( $this, 'erneut_pruefen' ) );
	}

	/** @return string Adresse des Repositories. */
	private function adresse() {
		return 'https://codeberg.org/' . $this->repo;
	}

	/** @return string Name des Zwischenspeichers. */
	private function speicher() {
		return 'codeberg_update_' . sanitize_key( str_replace( '/', '_', $this->repo ) );
	}

	/**
	 * Neueste Version auf Codeberg.
	 *
	 * @return array{version:string,tag:string,paket:string}|null
	 */
	public function neueste() {
		$gespeichert = get_site_transient( $this->speicher() );
		if ( is_array( $gespeichert ) ) {
			return isset( $gespeichert['version'] ) ? $gespeichert : null;
		}

		$antwort = $this->abrufen( 'https://codeberg.org/api/v1/repos/' . $this->repo . '/tags?limit=50' );
		$neueste = null;
		if ( ! is_wp_error( $antwort ) && 200 === (int) wp_remote_retrieve_response_code( $antwort ) ) {
			$tags = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );
			foreach ( is_array( $tags ) ? $tags : array() as $tag ) {
				$name = is_array( $tag ) && isset( $tag['name'] ) && is_string( $tag['name'] ) ? $tag['name'] : '';
				if ( ! preg_match( self::TAG_MUSTER, $name, $treffer ) ) {
					continue;
				}
				if ( null === $neueste || version_compare( $treffer[1], $neueste['version'], '>' ) ) {
					$neueste = array(
						'version' => $treffer[1],
						'tag'     => $name,
						'paket'   => $this->adresse() . '/archive/' . rawurlencode( $name ) . '.zip',
					);
				}
			}
		}
		set_site_transient( $this->speicher(), $neueste ? $neueste : array( 'fehler' => 1 ), $neueste ? self::DAUER_OK : self::DAUER_FEHLER );
		return $neueste;
	}

	/**
	 * Abruf mit kontrollierten Weiterleitungen: Codeberg leitet nach dem Umbenennen oder Übertragen eines
	 * Repositorys von der alten Adresse weiter. Gefolgt wird höchstens dreimal und nur zu Adressen unter
	 * https://codeberg.org/ – jedes andere Ziel gilt als Fehler.
	 *
	 * @param string $url Adresse.
	 * @return array|WP_Error
	 */
	private function abrufen( $url ) {
		for ( $sprung = 0; $sprung <= 3; $sprung++ ) {
			$antwort = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 262144,
					'user-agent'          => 'WordPress-Plugin-Updater',
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
			$code = is_wp_error( $antwort ) ? 0 : (int) wp_remote_retrieve_response_code( $antwort );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return $antwort;
			}
			$ziel = trim( (string) wp_remote_retrieve_header( $antwort, 'location' ) );
			if ( 0 === strpos( $ziel, '/' ) && 0 !== strpos( $ziel, '//' ) ) {
				$ziel = 'https://codeberg.org' . $ziel;
			}
			if ( ! preg_match( '#^https://codeberg\.org/#', $ziel ) ) {
				return new WP_Error( 'codeberg_weiterleitung', 'Weiterleitung außerhalb von Codeberg abgelehnt.' );
			}
			$url = $ziel;
		}
		return new WP_Error( 'codeberg_weiterleitung', 'Zu viele Weiterleitungen.' );
	}

	/**
	 * Antwort für „update_plugins_codeberg.org“. WordPress vergleicht die Versionen selbst.
	 *
	 * @param array|false $update Bisheriges Ergebnis.
	 * @param array       $daten  Kopfdaten des Plugins.
	 * @param string      $datei  Plugin-Datei.
	 * @return array|false
	 */
	public function update_pruefen( $update, $daten, $datei ) {
		if ( $datei !== $this->datei ) {
			return $update;
		}
		$neu = $this->neueste();
		if ( ! $neu ) {
			return $update;
		}
		return array(
			'id'           => $this->adresse(),
			'slug'         => $this->ordner,
			'plugin'       => $this->datei,
			'version'      => $neu['version'],
			'url'          => $this->adresse(),
			'package'      => $neu['paket'],
			'requires'     => isset( $daten['RequiresWP'] ) ? (string) $daten['RequiresWP'] : '',
			'requires_php' => isset( $daten['RequiresPHP'] ) ? (string) $daten['RequiresPHP'] : '',
		);
	}

	/**
	 * Fenster „Details ansehen“ in der Plugin-Liste.
	 *
	 * @param false|object|array $ergebnis Bisheriges Ergebnis.
	 * @param string             $aktion   Art der Anfrage.
	 * @param object             $args     Argumente (slug).
	 * @return false|object|array
	 */
	public function details( $ergebnis, $aktion, $args ) {
		if ( 'plugin_information' !== $aktion || ! is_object( $args ) || ! isset( $args->slug ) || $this->ordner !== $args->slug ) {
			return $ergebnis;
		}
		$neu   = $this->neueste();
		$liste = esc_url( $this->adresse() . '/tags' );
		return (object) array(
			'name'          => $this->name,
			'slug'          => $this->ordner,
			'version'       => $neu ? $neu['version'] : $this->version,
			'author'        => 'Europagymnasium Auhof',
			'homepage'      => $this->adresse(),
			'download_link' => $neu ? $neu['paket'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Schuleigenes Plugin. Quellcode, Versionen und Änderungen auf Codeberg:', 'sintoora-kalender' ) . ' <a href="' . esc_url( $this->adresse() ) . '">' . esc_html( $this->adresse() ) . '</a></p>',
				'changelog'   => '<p><a href="' . $liste . '">' . esc_html__( 'Alle Versionen auf Codeberg', 'sintoora-kalender' ) . '</a></p>',
			),
		);
	}

	/**
	 * Vor dem Installieren merken, ob das Plugin aktiv ist. WordPress deaktiviert es bei Updates im Browser
	 * kurz (Priorität 10) und aktiviert es erst nach Erfolg wieder.
	 *
	 * @param bool|WP_Error $antwort Bisheriges Ergebnis.
	 * @param array         $extra   Angaben zum Vorgang.
	 * @return bool|WP_Error
	 */
	public function aktiv_merken( $antwort, $extra ) {
		if ( is_array( $extra ) && isset( $extra['plugin'] ) && $this->datei === $extra['plugin'] ) {
			$this->war_aktiv = is_plugin_active( $this->datei );
		}
		return $antwort;
	}

	/**
	 * Abbruch: Die alten Dateien sind noch unverändert, das Plugin wird wieder so aktiv wie vorher
	 * (still, ohne Aktivierungs-Hooks – wie WordPress beim Deaktivieren vor dem Update).
	 *
	 * @param string $code    Fehlercode.
	 * @param string $meldung Meldung.
	 * @return WP_Error
	 */
	private function abbrechen( $code, $meldung ) {
		if ( $this->war_aktiv && ! is_plugin_active( $this->datei ) ) {
			activate_plugin( $this->datei, '', false, true );
		}
		return new WP_Error( $code, $meldung );
	}

	/**
	 * Ordner aus dem Codeberg-Archiv in den Plugin-Ordner umbenennen, nur für dieses Plugin.
	 *
	 * @param string|WP_Error $quelle   Entpackter Ordner.
	 * @param string          $entpackt Übergeordneter Ordner.
	 * @param WP_Upgrader     $upgrader Upgrader.
	 * @param array           $extra    Angaben zum Vorgang.
	 * @return string|WP_Error
	 */
	public function ordner_anpassen( $quelle, $entpackt, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $quelle ) || ! is_array( $extra ) || ! isset( $extra['plugin'] ) || $this->datei !== $extra['plugin'] ) {
			return $quelle;
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( trailingslashit( $quelle ) . basename( $this->datei ) ) ) {
			return $this->abbrechen( 'codeberg_paket', __( 'Das heruntergeladene Paket enthält das Plugin nicht. Update abgebrochen.', 'sintoora-kalender' ) );
		}
		$ziel = trailingslashit( $entpackt ) . $this->ordner . '/';
		if ( untrailingslashit( $quelle ) === untrailingslashit( $ziel ) ) {
			return $quelle;
		}
		if ( ! $wp_filesystem->move( $quelle, $ziel, true ) ) {
			return $this->abbrechen( 'codeberg_ordner', __( 'Der Plugin-Ordner konnte nicht umbenannt werden. Update abgebrochen.', 'sintoora-kalender' ) );
		}
		return $ziel;
	}

	/**
	 * „Erneut prüfen“ (Dashboard → Aktualisierungen) fragt Codeberg sofort wieder.
	 */
	public function erneut_pruefen() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leert nur den Zwischenspeicher, wie WordPress selbst.
			delete_site_transient( $this->speicher() );
		}
	}
}
