<?php
/**
 * Updates aus öffentlichen Git-Repositories (GitHub und/oder Codeberg).
 *
 * WordPress fragt bei Plugins mit „Update URI: https://<server>/…“ nicht wordpress.org, sondern ruft den Filter
 * „update_plugins_<server>“ auf. Diese Klasse beantwortet ihn: Sie liest die Tags (v1.2.3) aller eingetragenen
 * Quellen, nimmt die höchste Version und bietet deren Tag-Archiv als Paket an. Beim Installieren benennt sie den
 * Ordner im Archiv in den Plugin-Ordner um und prüft, dass die Hauptdatei des Plugins darin liegt.
 *
 * Quellen werden im Plugin beim Erzeugen der Klasse eingetragen, Reihenfolge = Vorrang bei gleicher Version:
 *   new Sintoora_Kalender_Updater( __FILE__, array( 'github.com' => 'besitzer/name', 'codeberg.org' => 'besitzer/name' ), … );
 * Übergang Codeberg → GitHub: beide Quellen eintragen. Nach dem Umzug nur noch 'github.com' eintragen und im
 * Plugin-Kopf „Update URI: https://github.com/…“ setzen.
 *
 * Neue Version veröffentlichen = Tag „vX.Y.Z“ anlegen. Installiert wird nur, wenn jemand im Backend auf
 * „Aktualisieren“ klickt – oder automatisch, falls für das Plugin automatische Updates aktiviert sind.
 *
 * Sicherheit: nur https zu den fest eingetragenen Repository-Adressen, Weiterleitungen nur innerhalb desselben
 * Servers (höchstens drei; bei GitHub nur innerhalb von api.github.com), Versionsnummern streng geprüft, Antwort
 * auf 256 KB begrenzt, keine Angaben über die Website im Abruf. Fällt eine Quelle aus (z. B. GitHub-Abrufgrenze
 * von 60 Anfragen je Stunde und Server), zählt die andere. Ergebnis 6 Stunden zwischengespeichert (nach einem
 * Fehler bei einer Quelle 1 Stunde), „Erneut prüfen“ unter Dashboard → Aktualisierungen leert den Zwischenspeicher.
 *
 * Dieselbe Klasse steckt (mit anderem Namen) auch im Plugin „Auhof Schulseiten“; Änderungen in beiden nachziehen.
 *
 * @package SintooraKalender
 */

defined( 'ABSPATH' ) || exit;

/**
 * Updater für ein Plugin aus GitHub- und/oder Codeberg-Repositories.
 */
final class Sintoora_Kalender_Updater {

	/** Gültige Tags: v1.2.3 oder 1.2.3. */
	const TAG_MUSTER = '/^v?(\d{1,3}\.\d{1,3}\.\d{1,3})$/';

	/** Gültige Repository-Angabe „besitzer/name“. */
	const REPO_MUSTER = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#';

	/** Zwischenspeicher in Sekunden. */
	const DAUER_OK     = 6 * HOUR_IN_SECONDS;
	const DAUER_FEHLER = HOUR_IN_SECONDS;

	/** @var string Plugin-Datei relativ zum Plugin-Ordner, z. B. „sintoora-kalender/sintoora-kalender.php“. */
	private $datei;

	/** @var string Ordner des Plugins, z. B. „sintoora-kalender“. */
	private $ordner;

	/** @var array<string,string> Quellen: Server => „besitzer/name“, in der Reihenfolge des Vorrangs. */
	private $quellen = array();

	/** @var string Anzeigename. */
	private $name;

	/** @var string Version des installierten Plugins. */
	private $version;

	/** @var bool War das Plugin vor dem Update aktiv? */
	private $war_aktiv = false;

	/**
	 * @param string               $datei   __FILE__ der Plugin-Hauptdatei.
	 * @param array<string,string> $quellen Server ('github.com' oder 'codeberg.org') => Repository „besitzer/name“.
	 * @param string               $name    Anzeigename.
	 * @param string               $version Installierte Version.
	 */
	public function __construct( $datei, $quellen, $name, $version ) {
		$this->datei   = plugin_basename( $datei );
		$this->ordner  = dirname( $this->datei );
		$this->name    = $name;
		$this->version = $version;
		foreach ( (array) $quellen as $server => $repo ) {
			if ( in_array( $server, array( 'github.com', 'codeberg.org' ), true ) && is_string( $repo ) && preg_match( self::REPO_MUSTER, $repo ) ) {
				$this->quellen[ $server ] = $repo;
				add_filter( 'update_plugins_' . $server, array( $this, 'update_pruefen' ), 10, 3 );
			}
		}

		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_install', array( $this, 'aktiv_merken' ), 1, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'ordner_anpassen' ), 10, 4 );
		add_action( 'load-update-core.php', array( $this, 'erneut_pruefen' ) );
	}

	/**
	 * Adressen einer Quelle.
	 *
	 * @param string $server 'github.com' oder 'codeberg.org'.
	 * @param string $repo   „besitzer/name“.
	 * @return array{web:string,tags:string,api:string,erlaubt:string,accept:string}
	 */
	private static function adressen( $server, $repo ) {
		if ( 'github.com' === $server ) {
			return array(
				'web'     => 'https://github.com/' . $repo,
				'tags'    => 'https://github.com/' . $repo . '/tags',
				'api'     => 'https://api.github.com/repos/' . $repo . '/tags?per_page=100',
				'erlaubt' => 'https://api.github.com',
				'accept'  => 'application/vnd.github+json',
			);
		}
		return array(
			'web'     => 'https://codeberg.org/' . $repo,
			'tags'    => 'https://codeberg.org/' . $repo . '/tags',
			'api'     => 'https://codeberg.org/api/v1/repos/' . $repo . '/tags?limit=50',
			'erlaubt' => 'https://codeberg.org',
			'accept'  => 'application/json',
		);
	}

	/**
	 * Tag-Archiv einer Quelle (GitHub leitet zum Herunterladen auf codeload.github.com weiter).
	 *
	 * @param string $server Server.
	 * @param string $repo   „besitzer/name“.
	 * @param string $tag    Tag, z. B. „v1.2.3“.
	 * @return string
	 */
	private static function paket( $server, $repo, $tag ) {
		if ( 'github.com' === $server ) {
			return 'https://github.com/' . $repo . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip';
		}
		return 'https://codeberg.org/' . $repo . '/archive/' . rawurlencode( $tag ) . '.zip';
	}

	/** @return string Name des Zwischenspeichers. */
	private function speicher() {
		return 'sintoora_update_' . sanitize_key( $this->ordner );
	}

	/** @return string Adresse der ersten (bevorzugten) Quelle. */
	private function hauptadresse() {
		foreach ( $this->quellen as $server => $repo ) {
			return self::adressen( $server, $repo )['web'];
		}
		return '';
	}

	/**
	 * Neueste Version über alle Quellen. Bei gleicher Version gewinnt die zuerst eingetragene Quelle.
	 *
	 * @return array{version:string,tag:string,paket:string,server:string,web:string}|null
	 */
	public function neueste() {
		$gespeichert = get_site_transient( $this->speicher() );
		if ( is_array( $gespeichert ) ) {
			return isset( $gespeichert['version'] ) ? $gespeichert : null;
		}

		$neueste = null;
		$fehler  = false;
		foreach ( $this->quellen as $server => $repo ) {
			$tags = $this->tags( $server, $repo );
			if ( null === $tags ) {
				$fehler = true;
				continue;
			}
			foreach ( $tags as $name ) {
				if ( ! preg_match( self::TAG_MUSTER, $name, $treffer ) ) {
					continue;
				}
				if ( null === $neueste || version_compare( $treffer[1], $neueste['version'], '>' ) ) {
					$neueste = array(
						'version' => $treffer[1],
						'tag'     => $name,
						'paket'   => self::paket( $server, $repo, $name ),
						'server'  => $server,
						'web'     => self::adressen( $server, $repo )['web'],
					);
				}
			}
		}
		set_site_transient( $this->speicher(), $neueste ? $neueste : array( 'fehler' => 1 ), ( $neueste && ! $fehler ) ? self::DAUER_OK : self::DAUER_FEHLER );
		return $neueste;
	}

	/**
	 * Tag-Namen einer Quelle.
	 *
	 * @param string $server Server.
	 * @param string $repo   „besitzer/name“.
	 * @return string[]|null Null bei Fehler (Server nicht erreichbar, Abrufgrenze, falsche Antwort).
	 */
	private function tags( $server, $repo ) {
		$a       = self::adressen( $server, $repo );
		$antwort = $this->abrufen( $a['api'], $a['erlaubt'], $a['accept'] );
		if ( is_wp_error( $antwort ) || 200 !== (int) wp_remote_retrieve_response_code( $antwort ) ) {
			return null;
		}
		$liste = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );
		if ( ! is_array( $liste ) || ( $liste && ! isset( $liste[0] ) ) ) {
			return null;
		}
		$namen = array();
		foreach ( $liste as $tag ) {
			if ( is_array( $tag ) && isset( $tag['name'] ) && is_string( $tag['name'] ) ) {
				$namen[] = $tag['name'];
			}
		}
		return $namen;
	}

	/**
	 * Abruf mit kontrollierten Weiterleitungen: Nach dem Umbenennen oder Übertragen eines Repositorys leiten
	 * GitHub und Codeberg von der alten Adresse weiter. Gefolgt wird höchstens dreimal und nur zu Adressen unter
	 * $erlaubt (z. B. https://api.github.com/) – jedes andere Ziel gilt als Fehler.
	 *
	 * @param string $url     Adresse.
	 * @param string $erlaubt Erlaubter Anfang ohne Schrägstrich am Ende, z. B. „https://codeberg.org“.
	 * @param string $accept  Accept-Kopfzeile.
	 * @return array|WP_Error
	 */
	private function abrufen( $url, $erlaubt, $accept ) {
		for ( $sprung = 0; $sprung <= 3; $sprung++ ) {
			$antwort = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 262144,
					'user-agent'          => 'WordPress-Plugin-Updater',
					'headers'             => array( 'Accept' => $accept ),
				)
			);
			$code = is_wp_error( $antwort ) ? 0 : (int) wp_remote_retrieve_response_code( $antwort );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return $antwort;
			}
			$ziel = trim( (string) wp_remote_retrieve_header( $antwort, 'location' ) );
			if ( 0 === strpos( $ziel, '/' ) && 0 !== strpos( $ziel, '//' ) ) {
				$ziel = $erlaubt . $ziel;
			}
			if ( 0 !== strpos( $ziel, $erlaubt . '/' ) ) {
				return new WP_Error( 'update_weiterleitung', 'Weiterleitung auf einen anderen Server abgelehnt.' );
			}
			$url = $ziel;
		}
		return new WP_Error( 'update_weiterleitung', 'Zu viele Weiterleitungen.' );
	}

	/**
	 * Antwort für „update_plugins_<server>“. WordPress vergleicht die Versionen selbst.
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
			'id'           => $neu['web'],
			'slug'         => $this->ordner,
			'plugin'       => $this->datei,
			'version'      => $neu['version'],
			'url'          => $neu['web'],
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
		$neu    = $this->neueste();
		$links  = array();
		$listen = array();
		foreach ( $this->quellen as $server => $repo ) {
			$a        = self::adressen( $server, $repo );
			$links[]  = '<a href="' . esc_url( $a['web'] ) . '">' . esc_html( $a['web'] ) . '</a>';
			$listen[] = '<a href="' . esc_url( $a['tags'] ) . '">' . esc_html( sprintf( /* translators: %s: Server, z. B. github.com. */ __( 'Alle Versionen auf %s', 'sintoora-kalender' ), $server ) ) . '</a>';
		}
		return (object) array(
			'name'          => $this->name,
			'slug'          => $this->ordner,
			'version'       => $neu ? $neu['version'] : $this->version,
			'author'        => 'Europagymnasium Auhof',
			'homepage'      => $neu ? $neu['web'] : $this->hauptadresse(),
			'download_link' => $neu ? $neu['paket'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Schuleigenes Plugin. Quellcode, Versionen und Änderungen:', 'sintoora-kalender' ) . ' ' . implode( ', ', $links ) . '</p>',
				'changelog'   => '<p>' . implode( '<br>', $listen ) . '</p>',
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
	 * Ordner aus dem Archiv (GitHub: „name-1.2.3“, Codeberg: „name“) in den Plugin-Ordner umbenennen,
	 * nur für dieses Plugin.
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
			return $this->abbrechen( 'update_paket', __( 'Das heruntergeladene Paket enthält das Plugin nicht. Update abgebrochen.', 'sintoora-kalender' ) );
		}
		$ziel = trailingslashit( $entpackt ) . $this->ordner . '/';
		if ( untrailingslashit( $quelle ) === untrailingslashit( $ziel ) ) {
			return $quelle;
		}
		if ( ! $wp_filesystem->move( $quelle, $ziel, true ) ) {
			return $this->abbrechen( 'update_ordner', __( 'Der Plugin-Ordner konnte nicht umbenannt werden. Update abgebrochen.', 'sintoora-kalender' ) );
		}
		return $ziel;
	}

	/**
	 * „Erneut prüfen“ (Dashboard → Aktualisierungen) fragt alle Quellen sofort wieder.
	 */
	public function erneut_pruefen() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leert nur den Zwischenspeicher, wie WordPress selbst.
			delete_site_transient( $this->speicher() );
		}
	}
}
