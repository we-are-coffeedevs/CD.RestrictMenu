<?php
/**
 * Plugin Name: CD.RestrictMenu
 * Description: Ermöglicht es einem festgelegten Superuser die Backend-Navigation anzupassen, sodass Sie die beste Benutzererfahrung in der Bedienung erhalten und nicht durch unnütze Inhalte gestört werden.
 * Version:     1.0.3
 * Requires PHP: 7.4
 *
 * Installation: Diese Datei nach wp-content/mu-plugins/ kopieren.
 */

defined( 'ABSPATH' ) || exit;

/**
 * ------------------------------------------------------------------
 * SUPERUSER: Benutzername (Login) des Benutzers, der die Sichtbarkeit
 * für alle anderen festlegen darf. Für ihn selbst gelten keine
 * Einschränkungen. Empfohlen: in der wp-config.php setzen, damit der
 * Login nicht im Plugin-Quelltext steht:
 *
 *     define( 'CDMV_SUPERUSER', 'benutzername' );
 *
 * Leer = es gibt keinen Superuser. Das Konto muss Administrator sein.
 * ------------------------------------------------------------------
 */
if ( ! defined( 'CDMV_SUPERUSER' ) ) {
	define( 'CDMV_SUPERUSER', '' );
}

/**
 * Optional: Benutzer-ID des Superusers. Ist sie gesetzt (> 0), muss sie
 * zusätzlich zum Benutzernamen passen. Bei 0 merkt sich das Plugin die ID
 * beim ersten Aufruf selbst. So wird ein später neu angelegtes Konto mit
 * demselben Benutzernamen nicht zum Superuser.
 */
if ( ! defined( 'CDMV_SUPERUSER_ID' ) ) {
	define( 'CDMV_SUPERUSER_ID', 0 );
}

final class CDMV_Menu_Visibility {

	const META       = 'cdmv_hidden';       // User-Meta: menu (Hashes), bar (Node-IDs), grant (Hashes), caps (Berechtigungen).
	const LINK_COLOR = '#996800';

	/** true = Rechteprüfung ohne die hier vergebenen Freischaltungen (reine Rolle). */
	private static $bypass = false;

	/** Zwischenspeicher der freigeschalteten Berechtigungen je Benutzer-ID. */
	private static $cap_cache = array();
	const BAR_OPTION = 'cdmv_bar_nodes';    // Bekannte Admin-Leisten-Einträge (vom Superuser gesehen).
	const PIN_OPTION = 'cdmv_superuser_pin'; // Gemerkte Benutzer-ID des Superusers (login + id).
	const PAUSE_COOKIE = 'cdmv_paused';     // Nach dem Entfernen der Daten: vorerst nichts Neues speichern.
	const PAGE       = 'cdmv-menu-visibility';
	const NONCE      = 'cdmv_save_';

	/** Einträge der Admin-Leiste, die nie abschaltbar sind (mobiler Menü-Schalter). */
	const BAR_PROTECTED = array( 'menu-toggle' );

	/**
	 * Berechtigungen, die Administrator-Rechten gleichkommen oder den Weg dorthin öffnen
	 * (Einstellungen, Code ausführen, Benutzer verwalten). Sie werden beim Freischalten
	 * markiert und vor dem Speichern eigens bestätigt.
	 */
	const CRITICAL_CAPS = array(
		'manage_options',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'switch_themes',
		'update_core',
		'edit_files',
		'import',
		'unfiltered_html',
		'unfiltered_upload',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'remove_users',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
	);

	public static function init() {
		// Schalter in der wp-config.php: define( 'CDMV_CLEANUP', true );
		// Entfernt alle Plugin-Daten und lässt das Plugin ruhen, solange der Schalter gesetzt ist.
		if ( defined( 'CDMV_CLEANUP' ) && CDMV_CLEANUP ) {
			self::cleanup();
			return;
		}

		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_links' ), 10, 4 );
		add_action( 'admin_post_cdmv_cleanup', array( __CLASS__, 'handle_cleanup' ) );
		add_action( 'admin_notices', array( __CLASS__, 'cleanup_notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'apply_menu' ), PHP_INT_MAX );
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'admin_bar' ), PHP_INT_MAX );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 4 );
	}

	/* ------------------------------------------------------------------
	 * Plugin-Daten entfernen (vor dem Löschen der Plugin-Datei)
	 * ---------------------------------------------------------------- */

	/** Löscht alles, was das Plugin in der Datenbank ablegt. */
	private static function cleanup() {
		delete_metadata( 'user', 0, self::META, '', true ); // Profile aller Benutzer.
		delete_option( self::BAR_OPTION );
		delete_option( self::PIN_OPTION );
		self::$cap_cache = array();
	}

	/**
	 * Nach „Plugin-Daten entfernen“ legt das Plugin in diesem Browser vorerst keine neuen
	 * Daten an (sonst wären sie beim nächsten Seitenaufruf wieder da). Das Cookie unterdrückt
	 * nur das Schreiben; an der Prüfung, wer Superuser ist, ändert es nichts.
	 */
	private static function is_paused() {
		return ! empty( $_COOKIE[ self::PAUSE_COOKIE ] );
	}

	private static function set_pause( $on ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::PAUSE_COOKIE, $on ? '1' : '', $on ? time() + DAY_IN_SECONDS : time() - DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		if ( $on ) {
			$_COOKIE[ self::PAUSE_COOKIE ] = '1';
		} else {
			unset( $_COOKIE[ self::PAUSE_COOKIE ] );
		}
	}

	/** Link „Plugin-Daten entfernen“ in der Liste der Must-Use-Plugins (nur Superuser). */
	public static function plugin_links( $actions, $plugin_file, $plugin_data = null, $context = '' ) {
		if ( 'mustuse' !== $context || basename( __FILE__ ) !== $plugin_file || ! self::is_superuser() ) {
			return $actions;
		}
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=cdmv_cleanup' ), 'cdmv_cleanup' );
		$confirm = 'Alle Daten von CD.RestrictMenu entfernen? Sämtliche Einschränkungen und Freischaltungen aller Benutzer werden sofort aufgehoben. Das lässt sich nicht rückgängig machen.';

		$actions                 = (array) $actions;
		$actions['cdmv_cleanup'] = '<a href="' . esc_url( $url ) . '" style="color:#b32d2e" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">Plugin-Daten entfernen</a>';
		return $actions;
	}

	public static function handle_cleanup() {
		if ( ! self::is_superuser() ) {
			wp_die( 'Du bist nicht berechtigt, diese Aktion auszuführen.', 'Kein Zugriff', array( 'response' => 403 ) );
		}
		check_admin_referer( 'cdmv_cleanup' );

		self::cleanup();
		self::set_pause( true );

		wp_safe_redirect( admin_url( 'plugins.php?plugin_status=mustuse&cdmv_cleaned=1' ) );
		exit;
	}

	public static function cleanup_notice() {
		if ( ! isset( $_GET['cdmv_cleaned'] ) || ! self::is_superuser() ) {
			return;
		}
		echo '<div class="notice notice-success"><p><strong>CD.RestrictMenu:</strong> Alle Plugin-Daten wurden entfernt. Sämtliche Einschränkungen und Freischaltungen sind aufgehoben.</p>'
			. '<p>Lösche jetzt die Datei <code>' . esc_html( basename( __FILE__ ) ) . '</code> aus <code>wp-content/mu-plugins/</code>. In diesem Browser legt das Plugin 24 Stunden lang keine neuen Daten an – es sei denn, du öffnest erneut „Menü einschränken“.</p></div>';
	}

	/* ------------------------------------------------------------------
	 * Freischaltung: Berechtigungen über die Rolle hinaus
	 * ---------------------------------------------------------------- */

	public static function grant_caps( $allcaps, $caps, $args, $user ) {
		if ( self::$bypass || ! ( $user instanceof WP_User ) || ! $user->ID || CDMV_SUPERUSER === $user->user_login ) {
			return $allcaps;
		}
		if ( ! isset( self::$cap_cache[ $user->ID ] ) ) {
			$stored                       = self::get_hidden( $user->ID );
			self::$cap_cache[ $user->ID ] = $stored['caps'];
		}
		foreach ( self::$cap_cache[ $user->ID ] as $cap ) {
			// „do_not_allow“ ist WordPress' Verbotsmarke und darf nie vergeben werden.
			if ( is_string( $cap ) && '' !== $cap && 'do_not_allow' !== $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/** Hat der Benutzer die Berechtigung allein aufgrund seiner Rolle? */
	private static function role_can( $user, $cap ) {
		self::$bypass = true;
		$can          = user_can( $user, $cap );
		self::$bypass = false;
		return $can;
	}

	/**
	 * Berechtigungen, die für einen Menüpunkt nötig sind. Bei Inhaltstypen und Taxonomien
	 * kommen deren Bearbeitungsrechte dazu, damit der Bereich auch benutzbar ist.
	 */
	private static function caps_for( $cap, $slug ) {
		$caps = array( $cap );
		$slug = self::norm( $slug );
		$q    = strpos( $slug, '?' );
		$path = false === $q ? $slug : substr( $slug, 0, $q );
		$args = array();
		if ( false !== $q ) {
			parse_str( substr( $slug, $q + 1 ), $args );
		}

		if ( in_array( $path, array( 'edit.php', 'post-new.php' ), true ) ) {
			$type = get_post_type_object( isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? $args['post_type'] : 'post' );
			if ( $type ) {
				$type_caps = (array) $type->cap;
				unset( $type_caps['edit_post'], $type_caps['read_post'], $type_caps['delete_post'] );
				$caps = array_merge( $caps, array_values( $type_caps ) );
			}
		} elseif ( 'edit-tags.php' === $path && isset( $args['taxonomy'] ) && is_string( $args['taxonomy'] ) ) {
			$tax = get_taxonomy( $args['taxonomy'] );
			if ( $tax ) {
				$caps = array_merge( $caps, array_values( (array) $tax->cap ) );
			}
		}
		$caps = array_filter(
			$caps,
			static function ( $c ) {
				return is_string( $c ) && '' !== $c && 'do_not_allow' !== $c;
			}
		);
		return array_values( array_unique( $caps ) );
	}

	/* ------------------------------------------------------------------
	 * Grundlagen
	 * ---------------------------------------------------------------- */

	/**
	 * Superuser ist nur, wer den festgelegten Benutzernamen trägt, Administrator ist
	 * und dessen Benutzer-ID zur festgelegten bzw. beim ersten Aufruf gemerkten ID passt.
	 */
	private static function is_superuser() {
		static $checked = array();

		$user = wp_get_current_user();
		if ( ! $user->exists() || '' === CDMV_SUPERUSER || CDMV_SUPERUSER !== $user->user_login ) {
			return false;
		}
		if ( isset( $checked[ $user->ID ] ) ) {
			return $checked[ $user->ID ];
		}

		$ok = self::role_can( $user, 'manage_options' );
		if ( $ok ) {
			if ( (int) CDMV_SUPERUSER_ID > 0 ) {
				$ok = (int) CDMV_SUPERUSER_ID === (int) $user->ID;
			} else {
				$pin = get_option( self::PIN_OPTION );
				if ( ! is_array( $pin ) || ! isset( $pin['login'], $pin['id'] ) || CDMV_SUPERUSER !== $pin['login'] ) {
					if ( ! self::is_paused() ) {
						update_option(
							self::PIN_OPTION,
							array(
								'login' => CDMV_SUPERUSER,
								'id'    => (int) $user->ID,
							),
							false
						);
					}
				} else {
					$ok = (int) $pin['id'] === (int) $user->ID;
				}
			}
		}
		$checked[ $user->ID ] = $ok;
		return $ok;
	}

	private static function get_hidden( $user_id ) {
		$data = get_user_meta( $user_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		return array(
			'menu' => isset( $data['menu'] ) && is_array( $data['menu'] ) ? $data['menu'] : array(),
			'bar'   => isset( $data['bar'] ) && is_array( $data['bar'] ) ? $data['bar'] : array(),
			'grant' => isset( $data['grant'] ) && is_array( $data['grant'] ) ? $data['grant'] : array(),
			'caps'  => isset( $data['caps'] ) && is_array( $data['caps'] ) ? $data['caps'] : array(),
		);
	}

	private static function parent_file() {
		return current_user_can( 'edit_users' ) ? 'users.php' : 'profile.php';
	}

	private static function page_url( $user_id ) {
		return add_query_arg(
			array(
				'page'    => self::PAGE,
				'user_id' => (int) $user_id,
			),
			admin_url( self::parent_file() )
		);
	}

	/** Slug vereinheitlichen (Entities, wechselnder return-Parameter des Customizers). */
	private static function norm( $slug ) {
		$slug = html_entity_decode( (string) $slug, ENT_QUOTES, 'UTF-8' );
		if ( false !== strpos( $slug, 'return=' ) ) {
			$slug = preg_replace( '/([?&])return=[^&]*&?/', '$1', $slug );
			$slug = rtrim( $slug, '?&' );
		}
		return $slug;
	}

	private static function key_top( $slug ) {
		return md5( 'm|' . self::norm( $slug ) );
	}

	private static function key_sub( $parent, $slug ) {
		return md5( 's|' . self::norm( $parent ) . '|' . self::norm( $slug ) );
	}

	private static function is_separator( $item ) {
		return isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' );
	}

	private static function is_core_file( $path ) {
		return '.php' === substr( $path, -4 )
			&& false === strpos( $path, '/' )
			&& file_exists( ABSPATH . 'wp-admin/' . $path );
	}

	private static function clean_title( $title ) {
		$title = (string) $title;
		// Zähler-Blasen (Updates, Kommentare …) entfernen.
		$clean = trim( wp_strip_all_tags( preg_replace( '/<span.*<\/span>/s', '', $title ) ) );
		if ( '' === $clean ) {
			$clean = trim( wp_strip_all_tags( $title ) );
		}
		return $clean;
	}

	/* ------------------------------------------------------------------
	 * Link in der Benutzerliste
	 * ---------------------------------------------------------------- */

	public static function row_action( $actions, $user ) {
		if ( self::is_superuser() && CDMV_SUPERUSER !== $user->user_login ) {
			$actions['cdmv'] = '<a href="' . esc_url( self::page_url( $user->ID ) ) . '" style="color:' . self::LINK_COLOR . '">Menü einschränken</a>';
		}
		return $actions;
	}

	/* ------------------------------------------------------------------
	 * Durchsetzung: Navigation ausblenden + Direktaufruf sperren
	 * ---------------------------------------------------------------- */

	public static function apply_menu() {
		global $menu, $submenu;

		if ( self::is_superuser() || ! is_array( $menu ) ) {
			return;
		}
		$stored = self::get_hidden( get_current_user_id() );
		if ( ! $stored['menu'] ) {
			return;
		}
		$hidden     = array_flip( $stored['menu'] );
		$candidates = array();

		foreach ( $menu as $i => $item ) {
			if ( empty( $item[2] ) || self::is_separator( $item ) ) {
				continue;
			}
			$slug       = $item[2];
			$top_hidden = isset( $hidden[ self::key_top( $slug ) ] );
			$subs       = isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ? $submenu[ $slug ] : array();
			$same_slug  = false;
			$left       = 0;

			foreach ( $subs as $j => $sub ) {
				$sub_hidden = $top_hidden || isset( $hidden[ self::key_sub( $slug, $sub[2] ) ] );
				if ( self::norm( $sub[2] ) === self::norm( $slug ) ) {
					$same_slug = true;
				}
				$candidates[] = array(
					'slug'   => $sub[2],
					'parent' => $slug,
					'group'  => $slug,
					'hidden' => $sub_hidden,
					'cap'    => $sub[1],
				);
				if ( $sub_hidden ) {
					unset( $submenu[ $slug ][ $j ] );
				} else {
					$left++;
				}
			}

			// Sind alle Unterpunkte weg, verschwindet auch der Elternpunkt.
			if ( $subs && ! $left ) {
				$top_hidden = true;
			}
			if ( ! $same_slug ) {
				$candidates[] = array(
					'slug'   => $slug,
					'parent' => '',
					'group'  => $slug,
					'hidden' => $top_hidden,
					'cap'    => $item[1],
				);
			}
			if ( $top_hidden ) {
				unset( $submenu[ $slug ], $menu[ $i ] );
			}
		}

		self::block_request( $candidates );
	}

	/**
	 * Die Anfrage in den Lesarten, in denen WordPress sie auswerten kann: nur Adresszeile
	 * sowie Adresszeile plus Formulardaten (post_type, taxonomy … liest WordPress auch aus
	 * POST). Gesperrt wird, sobald eine Lesart auf einen ausgeblendeten Bereich führt.
	 */
	private static function current_requests() {
		$get  = wp_unslash( $_GET );
		$reqs = array( self::current_request( $get ) );
		if ( ! empty( $_POST ) ) {
			$merged = array_merge( $get, wp_unslash( $_POST ) );
			// Die Plugin-Seite bestimmt WordPress ausschließlich aus der Adresszeile.
			unset( $merged['page'] );
			if ( isset( $get['page'] ) ) {
				$merged['page'] = $get['page'];
			}
			$reqs[] = self::current_request( $merged );
		}
		return $reqs;
	}

	private static function current_request( $source ) {
		global $pagenow;

		$args = array();
		foreach ( (array) $source as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$args[ $k ] = (string) $v;
			}
		}
		$req = array(
			'file'  => (string) $pagenow,
			'args'  => $args,
			'whole' => false,
		);

		// Bearbeiten-Ansichten dem zugehörigen Menübereich zuordnen. Gesperrt wird hier
		// nur, wenn der gesamte Bereich ausgeblendet ist.
		if ( 'post.php' === $pagenow ) {
			$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : ( isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0 );
			$type    = $post_id ? get_post_type( $post_id ) : false;
			if ( $type ) {
				$req['whole'] = true;
				if ( 'attachment' === $type ) {
					$req['file'] = 'upload.php';
					$req['args'] = array();
				} else {
					$req['file'] = 'edit.php';
					$req['args'] = 'post' === $type ? array() : array( 'post_type' => $type );
				}
			}
		} elseif ( 'term.php' === $pagenow && isset( $args['taxonomy'] ) ) {
			$req['whole'] = true;
			$req['file']  = 'edit-tags.php';
			$req['args']  = array_intersect_key( $args, array( 'taxonomy' => 1, 'post_type' => 1 ) );
		}
		return $req;
	}

	/** 0 = passt nicht, sonst: je höher, desto genauer passt der Menü-Slug zur Anfrage. */
	private static function score( $req, $slug ) {
		$slug = self::norm( $slug );
		if ( '' === $slug || preg_match( '#^([a-z][a-z0-9+.-]*:)?//#i', $slug ) ) {
			return 0; // Externer Link.
		}
		$q     = strpos( $slug, '?' );
		$path  = false === $q ? $slug : substr( $slug, 0, $q );
		$query = false === $q ? '' : substr( $slug, $q + 1 );
		$want  = array();
		$score = 0;

		if ( self::is_core_file( $path ) ) {
			if ( $path !== $req['file'] ) {
				return 0;
			}
			parse_str( $query, $want );
			if ( isset( $req['args']['page'] ) && ! isset( $want['page'] ) ) {
				return 0; // Anfrage gilt einer Plugin-Seite unterhalb dieser Datei.
			}
			if ( in_array( $path, array( 'edit.php', 'post-new.php' ), true ) && ! isset( $want['post_type'] ) ) {
				$type = isset( $req['args']['post_type'] ) ? $req['args']['post_type'] : 'post';
				if ( 'post' !== $type ) {
					return 0;
				}
			}
			$score = 1;
		} else {
			parse_str( 'page=' . $slug, $want ); // Plugin-Seite, ggf. mit Zusatzparametern.
		}

		foreach ( $want as $k => $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			if ( ! isset( $req['args'][ $k ] ) || $req['args'][ $k ] !== (string) $v ) {
				return 0;
			}
			$score++;
		}
		return $score;
	}

	private static function block_request( $candidates ) {
		global $pagenow;

		$blocked = false;
		foreach ( self::current_requests() as $req ) {
			if ( self::is_blocked( $req, $candidates ) ) {
				$blocked = true;
				break;
			}
		}
		if ( ! $blocked ) {
			return;
		}

		// Ausgeblendetes Dashboard: statt Fehlermeldung zur ersten sichtbaren Seite leiten.
		if ( 'index.php' === $pagenow && ! isset( $_GET['page'] ) && empty( $_POST ) ) {
			foreach ( $candidates as $c ) {
				if ( $c['hidden'] || ! current_user_can( $c['cap'] ) ) {
					continue;
				}
				$url = self::item_url( $c['slug'], $c['parent'] );
				if ( $url ) {
					wp_safe_redirect( $url );
					exit;
				}
			}
		}

		wp_die(
			'<h1>Kein Zugriff</h1><p>Dieser Bereich wurde für dein Benutzerkonto deaktiviert.</p>',
			'Kein Zugriff',
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/** Führt diese Lesart der Anfrage auf einen ausgeblendeten Bereich? */
	private static function is_blocked( $req, $candidates ) {
		$best = 0;
		$hits = array();
		foreach ( $candidates as $c ) {
			$s = self::score( $req, $c['slug'] );
			if ( $s > $best ) {
				$best = $s;
				$hits = array( $c );
			} elseif ( $s && $s === $best ) {
				$hits[] = $c;
			}
		}
		if ( ! $hits ) {
			return false;
		}
		foreach ( $hits as $c ) {
			if ( ! $c['hidden'] ) {
				return false; // Ein sichtbarer Menüpunkt führt auf diese Seite.
			}
		}
		if ( $req['whole'] ) {
			foreach ( $candidates as $c ) {
				if ( $c['group'] === $hits[0]['group'] && ! $c['hidden'] ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function item_url( $slug, $parent ) {
		$slug = self::norm( $slug );
		if ( '' === $slug || preg_match( '#^([a-z][a-z0-9+.-]*:)?//#i', $slug ) ) {
			return '';
		}
		$q    = strpos( $slug, '?' );
		$path = false === $q ? $slug : substr( $slug, 0, $q );
		if ( self::is_core_file( $path ) ) {
			return admin_url( $slug );
		}
		if ( $parent ) {
			$parent = self::norm( $parent );
			$pq     = strpos( $parent, '?' );
			if ( self::is_core_file( false === $pq ? $parent : substr( $parent, 0, $pq ) ) ) {
				return admin_url( $parent . ( false === $pq ? '?' : '&' ) . 'page=' . $slug );
			}
		}
		return admin_url( 'admin.php?page=' . $slug );
	}

	/* ------------------------------------------------------------------
	 * Admin-Leiste
	 * ---------------------------------------------------------------- */

	public static function admin_bar() {
		global $wp_admin_bar;

		if ( ! is_object( $wp_admin_bar ) ) {
			return;
		}

		// Superuser: gesehene Einträge merken (Backend und Frontend unterscheiden sich).
		if ( self::is_superuser() ) {
			$nodes = self::is_paused() ? null : $wp_admin_bar->get_nodes();
			if ( ! $nodes ) {
				return;
			}
			$known   = get_option( self::BAR_OPTION, array() );
			$known   = is_array( $known ) ? $known : array();
			$changed = false;
			foreach ( $nodes as $node ) {
				if ( isset( $known[ $node->id ] ) ) {
					continue;
				}
				// Nur für Screenreader gedachte Texte doppeln sonst den sichtbaren Text.
				$title = trim( wp_strip_all_tags( preg_replace( '/<span[^>]*screen-reader-text[^>]*>.*?<\/span>/s', '', (string) $node->title ) ) );
				if ( '' === $title ) {
					$title = trim( wp_strip_all_tags( (string) $node->title ) );
				}
				$known[ $node->id ] = array(
					't' => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 80 ) : substr( $title, 0, 80 ),
					'p' => (string) $node->parent,
					'g' => ! empty( $node->group ),
				);
				$changed             = true;
			}
			if ( $changed ) {
				update_option( self::BAR_OPTION, $known, false );
			}
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}
		$stored = self::get_hidden( get_current_user_id() );
		foreach ( $stored['bar'] as $id ) {
			if ( ! in_array( $id, self::BAR_PROTECTED, true ) ) {
				$wp_admin_bar->remove_node( $id );
			}
		}
	}

	/** Bekannte Leisten-Einträge als Baum: array( elternId => array( id => titel ) ). Gruppen werden übersprungen. */
	private static function bar_tree( $user = null ) {
		$known = get_option( self::BAR_OPTION, array() );
		$known = is_array( $known ) ? $known : array();
		$tree  = array();

		// Einträge, deren Titel Zähler oder den Namen des Superusers enthalten, neutral benennen.
		$name   = $user ? $user->display_name : '[username]';
		$labels = array(
			'wp-logo'    => 'WordPress-Logo',
			'updates'    => 'Aktualisierungen',
			'comments'   => 'Kommentare',
			'my-account' => 'Willkommen, ' . $name,
			'user-info'  => $name . ' – Profil bearbeiten',
		);

		foreach ( $known as $id => $node ) {
			if ( ! empty( $node['g'] ) || in_array( $id, self::BAR_PROTECTED, true ) ) {
				continue;
			}
			$parent = isset( $node['p'] ) ? $node['p'] : '';
			$guard  = 0;
			while ( '' !== $parent && isset( $known[ $parent ] ) && ! empty( $known[ $parent ]['g'] ) && $guard++ < 10 ) {
				$parent = isset( $known[ $parent ]['p'] ) ? $known[ $parent ]['p'] : '';
			}
			if ( '' !== $parent && ! isset( $known[ $parent ] ) ) {
				$parent = '';
			}
			if ( isset( $labels[ $id ] ) ) {
				$tree[ $parent ][ $id ] = $labels[ $id ];
			} else {
				$tree[ $parent ][ $id ] = isset( $node['t'] ) && '' !== $node['t'] ? $node['t'] : $id;
			}
		}
		return $tree;
	}

	/* ------------------------------------------------------------------
	 * Einstellungsseite (nur Superuser)
	 * ---------------------------------------------------------------- */

	public static function register_page() {
		if ( ! self::is_superuser() ) {
			return;
		}
		$hook = add_users_page( 'CD.RestrictMenu', 'CD.RestrictMenu', 'read', self::PAGE, array( __CLASS__, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_save' ) );
		}
		// Seite bleibt erreichbar, taucht aber nicht in der Navigation auf.
		add_action( 'admin_head', array( __CLASS__, 'hide_own_menu' ) );
	}

	public static function hide_own_menu() {
		remove_submenu_page( self::parent_file(), self::PAGE );
	}

	/** Zielbenutzer prüfen. Bricht mit Fehlermeldung ab, wenn etwas nicht stimmt. */
	private static function target_user() {
		if ( ! self::is_superuser() ) {
			wp_die( 'Du bist nicht berechtigt, diese Seite aufzurufen.', 'Kein Zugriff', array( 'response' => 403 ) );
		}
		$user = get_userdata( isset( $_REQUEST['user_id'] ) ? (int) $_REQUEST['user_id'] : 0 );
		if ( ! $user ) {
			wp_die( 'Benutzer nicht gefunden.', 'Fehler', array( 'response' => 404, 'back_link' => true ) );
		}
		if ( CDMV_SUPERUSER === $user->user_login ) {
			wp_die( 'Für den Superuser kann die Navigation nicht eingeschränkt werden.', 'Nicht möglich', array( 'response' => 403, 'back_link' => true ) );
		}
		return $user;
	}

	/** Aktuelle Backend-Navigation (aus Sicht des Superusers) als Baum. */
	private static function menu_tree() {
		global $menu, $submenu;

		$tree = array();
		foreach ( (array) $menu as $item ) {
			if ( self::is_separator( $item ) ) {
				$tree[] = array( 'sep' => true );
				continue;
			}
			if ( empty( $item[2] ) ) {
				continue;
			}
			$slug     = $item[2];
			$children = array();
			if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( self::PAGE === $sub[2] ) {
						continue;
					}
					$sub_title  = self::clean_title( $sub[0] );
					$children[] = array(
						'key'   => self::key_sub( $slug, $sub[2] ),
						'title' => '' !== $sub_title ? $sub_title : self::norm( $sub[2] ),
						'cap'   => $sub[1],
						'slug'  => $sub[2],
					);
				}
			}
			$top_title = self::clean_title( $item[0] );
			$tree[]    = array(
				'sep'      => false,
				'key'      => self::key_top( $slug ),
				'title'    => '' !== $top_title ? $top_title : self::norm( $slug ),
				'cap'      => $item[1],
				'slug'     => $slug,
				'icon'     => isset( $item[6] ) ? (string) $item[6] : '',
				'dom_id'   => isset( $item[5] ) ? preg_replace( '|[^a-zA-Z0-9_:.]|', '-', (string) $item[5] ) : '',
				'children' => $children,
			);
		}
		return $tree;
	}

	public static function handle_save() {
		// Wer die Einstellungsseite öffnet, nutzt das Plugin weiter: Speicherpause beenden.
		if ( self::is_paused() && self::is_superuser() ) {
			self::set_pause( false );
		}
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		$user = self::target_user();

		// Rechteprofil auf einen anderen Benutzer derselben Benutzergruppe übertragen.
		if ( isset( $_POST['cdmv_action'] ) && 'copy' === $_POST['cdmv_action'] ) {
			check_admin_referer( 'cdmv_copy_' . $user->ID );
			$dest = get_userdata( isset( $_POST['cdmv_copy_to'] ) ? (int) $_POST['cdmv_copy_to'] : 0 );
			if (
				! $dest
				|| $dest->ID === $user->ID
				|| CDMV_SUPERUSER === $dest->user_login
				|| ! array_intersect( (array) $dest->roles, (array) $user->roles )
			) {
				wp_die( 'Auf diesen Benutzer kann das Rechteprofil nicht übertragen werden.', 'Nicht möglich', array( 'response' => 400, 'back_link' => true ) );
			}
			$profile = get_user_meta( $user->ID, self::META, true );
			if ( is_array( $profile ) && $profile ) {
				update_user_meta( $dest->ID, self::META, $profile );
			} else {
				delete_user_meta( $dest->ID, self::META );
			}
			wp_safe_redirect( add_query_arg( 'cdmv_copied', $dest->ID, self::page_url( $user->ID ) ) );
			exit;
		}

		check_admin_referer( self::NONCE . $user->ID );

		$old = self::get_hidden( $user->ID );

		// Backend-Navigation: gespeichert wird, was NICHT angehakt ist.
		$checked = isset( $_POST['cdmv_menu'] ) ? array_flip( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cdmv_menu'] ) ) ) : array();
		$wanted  = isset( $_POST['cdmv_grant'] ) ? array_flip( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cdmv_grant'] ) ) ) : array();
		$known   = array();
		$hide    = array();
		$grant   = array();
		$caps    = array();
		foreach ( self::menu_tree() as $node ) {
			if ( $node['sep'] ) {
				continue;
			}
			$known[ $node['key'] ] = true;
			$top_off               = ! isset( $checked[ $node['key'] ] );
			$visible               = 0;
			if ( isset( $wanted[ $node['key'] ] ) && 'do_not_allow' !== $node['cap'] && ! self::role_can( $user, $node['cap'] ) ) {
				$grant[] = $node['key'];
				$caps    = array_merge( $caps, self::caps_for( $node['cap'], $node['slug'] ) );
			}
			foreach ( $node['children'] as $child ) {
				$known[ $child['key'] ] = true;
				if ( isset( $wanted[ $child['key'] ] ) && 'do_not_allow' !== $child['cap'] && ! self::role_can( $user, $child['cap'] ) ) {
					$grant[] = $child['key'];
					$caps    = array_merge( $caps, self::caps_for( $child['cap'], $child['slug'] ) );
				}
				if ( $top_off || ! isset( $checked[ $child['key'] ] ) ) {
					$hide[] = $child['key'];
				} else {
					$visible++;
				}
			}
			if ( $top_off || ( $node['children'] && ! $visible ) ) {
				$hide[] = $node['key'];
			}
		}
		// Einträge momentan nicht geladener Plugins behalten ihren Status.
		foreach ( $old['menu'] as $key ) {
			if ( ! isset( $known[ $key ] ) ) {
				$hide[] = $key;
			}
		}

		// Admin-Leiste.
		$bar_checked = isset( $_POST['cdmv_bar'] ) ? array_flip( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cdmv_bar'] ) ) ) : array();
		$bar_known   = array();
		$bar_hide    = array();
		foreach ( self::bar_tree() as $children ) {
			foreach ( $children as $id => $title ) {
				$bar_known[ $id ] = true;
				if ( ! isset( $bar_checked[ $id ] ) ) {
					$bar_hide[] = (string) $id;
				}
			}
		}
		foreach ( $old['bar'] as $id ) {
			if ( ! isset( $bar_known[ $id ] ) ) {
				$bar_hide[] = $id;
			}
		}

		$hide     = array_values( array_unique( $hide ) );
		$bar_hide = array_values( array_unique( $bar_hide ) );
		$caps     = array_values( array_unique( $caps ) );
		if ( $hide || $bar_hide || $grant ) {
			update_user_meta(
				$user->ID,
				self::META,
				array(
					'menu'  => $hide,
					'bar'   => $bar_hide,
					'grant' => $grant,
					'caps'  => $caps,
				)
			);
		} else {
			delete_user_meta( $user->ID, self::META );
		}

		wp_safe_redirect( add_query_arg( 'cdmv_saved', 1, self::page_url( $user->ID ) ) );
		exit;
	}

	private static function icon_html( $icon, $dom_id ) {
		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			return '<span class="cdmv-ico dashicons ' . esc_attr( sanitize_html_class( $icon ) ) . '" aria-hidden="true"></span>';
		}
		// Alles andere (SVG, Icon-Font, CSS) wird per Skript aus der echten Navigation übernommen.
		$inner = '';
		if ( '' !== $icon && 'none' !== $icon && 'div' !== $icon && 0 !== strpos( $icon, 'data:' ) ) {
			$inner = '<img src="' . esc_url( $icon ) . '" alt="">';
		}
		return '<span class="cdmv-ico" data-src="' . esc_attr( $dom_id ) . '" aria-hidden="true">' . $inner . '</span>';
	}

	/** Hinweis + Freischalt-Haken für Einträge, die die Rolle allein nicht sehen darf. */
	private static function grant_html( $key, $cap, $granted, $slug = '' ) {
		if ( 'do_not_allow' === $cap ) {
			return ' <em class="cdmv-norole cdmv-grantbox">für niemanden freigegeben, nicht freischaltbar</em>';
		}
		// Berechtigungen, die praktisch Administrator-Rechte bedeuten, deutlich kennzeichnen.
		$critical = array_values( array_intersect( self::caps_for( $cap, $slug ), self::CRITICAL_CAPS ) );
		$data     = $critical ? ' data-danger="' . esc_attr( implode( ', ', $critical ) ) . '"' : '';
		$warn     = $critical
			? ' <span class="cdmv-danger" title="Diese Berechtigung kommt Administrator-Rechten gleich oder ermöglicht es, sie sich zu verschaffen.">&#9888; weitreichend</span>'
			: '';

		return ' <label class="cdmv-grantbox"><em class="cdmv-norole">für diese Rolle nicht sichtbar –</em> '
			. '<input type="checkbox" class="cdmv-grant" name="cdmv_grant[]" value="' . esc_attr( $key ) . '"' . $data . checked( isset( $granted[ $key ] ), true, false ) . '> '
			. '<span class="cdmv-norole">trotzdem freischalten</span> <code>' . esc_html( $cap ) . '</code>' . $warn . '</label>';
	}

	private static function render_bar_level( $tree, $parent, $hidden, $depth = 0 ) {
		if ( empty( $tree[ $parent ] ) || $depth > 6 ) {
			return;
		}
		echo 0 === $depth ? '<ul class="cdmv-tree cdmv-bar">' : '<ul>';
		foreach ( $tree[ $parent ] as $id => $title ) {
			$id = (string) $id;
			echo '<li><label><input type="checkbox" name="cdmv_bar[]" value="' . esc_attr( $id ) . '"' . checked( ! isset( $hidden[ $id ] ), true, false ) . '> ';
			echo '<span class="cdmv-title">' . esc_html( $title ) . '</span> <code>' . esc_html( $id ) . '</code></label>';
			self::render_bar_level( $tree, $id, $hidden, $depth + 1 );
			echo '</li>';
		}
		echo '</ul>';
	}

	public static function render() {
		$user   = self::target_user();
		$stored = self::get_hidden( $user->ID );
		$hidden = array_flip( $stored['menu'] );
		$bar    = array_flip( $stored['bar'] );
		$roles  = array();
		foreach ( (array) $user->roles as $role ) {
			$roles[] = translate_user_role( isset( wp_roles()->role_names[ $role ] ) ? wp_roles()->role_names[ $role ] : $role );
		}
		$granted = array_flip( $stored['grant'] );

		// Mögliche Empfänger für „Copy2User“: gleiche Benutzergruppe, ohne Superuser und ohne den Benutzer selbst.
		$peers = array();
		if ( $user->roles ) {
			$found = get_users(
				array(
					'role__in' => (array) $user->roles,
					'exclude'  => array( $user->ID ),
					'orderby'  => 'display_name',
				)
			);
			foreach ( $found as $peer ) {
				if ( CDMV_SUPERUSER !== $peer->user_login ) {
					$peers[] = $peer;
				}
			}
		}
		$copied = isset( $_GET['cdmv_copied'] ) ? get_userdata( (int) $_GET['cdmv_copied'] ) : false;
		?>
		<div class="wrap cdmv">
			<div class="cdmv-head">
				<div>
					<h1>CD.RestrictMenu: <?php echo esc_html( $user->display_name ); ?></h1>
					<p class="description">
						Benutzername <strong><?php echo esc_html( $user->user_login ); ?></strong>
						<?php if ( $roles ) : ?>
							· Rolle: <?php echo esc_html( implode( ', ', $roles ) ); ?>
						<?php endif; ?>
						<span class="cdmv-noprint">· <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Zurück zur Benutzerliste</a></span>
						<span class="cdmv-printonly">· Stand: <?php echo esc_html( wp_date( 'd.m.Y H:i' ) ); ?></span>
					</p>
				</div>
				<div class="cdmv-actions cdmv-noprint">
					<button type="button" class="button cdmv-copy-btn" aria-expanded="false" title="Hiermit wird dieses Rechteprofil auf einen anderen Nutzer der gleichen Nutzergruppe übertragen"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> Copy2User</button>
					<button type="button" class="button cdmv-print-btn"><span class="dashicons dashicons-printer" aria-hidden="true"></span> Drucken / PDF</button>
					<div class="cdmv-copy-pop" hidden>
						<?php if ( $peers ) : ?>
							<form method="post" action="<?php echo esc_url( self::page_url( $user->ID ) ); ?>" class="cdmv-copy-form">
								<?php wp_nonce_field( 'cdmv_copy_' . $user->ID ); ?>
								<input type="hidden" name="cdmv_action" value="copy">
								<label for="cdmv-copy-to">Gespeichertes Rechteprofil übertragen auf:</label>
								<select name="cdmv_copy_to" id="cdmv-copy-to" required>
									<option value="">– Benutzer wählen –</option>
									<?php foreach ( $peers as $peer ) : ?>
										<option value="<?php echo esc_attr( $peer->ID ); ?>"><?php echo esc_html( $peer->display_name . ' (' . $peer->user_login . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button button-primary">Übertragen</button>
								<p class="description">Übertragen wird der zuletzt gespeicherte Stand. Das bisherige Profil des gewählten Benutzers wird überschrieben.</p>
							</form>
						<?php else : ?>
							<p>Es gibt keinen weiteren Benutzer in dieser Benutzergruppe.</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<hr class="wp-header-end">

			<?php if ( $copied ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					Rechteprofil wurde auf <strong><?php echo esc_html( $copied->display_name ); ?></strong> übertragen.
					<a href="<?php echo esc_url( self::page_url( $copied->ID ) ); ?>">Dessen Profil ansehen</a>
				</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['cdmv_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>
			<?php endif; ?>

			<p class="cdmv-noprint">Angehakte Einträge sind für diesen Benutzer sichtbar. Ohne Haken wird der Eintrag ausgeblendet und der direkte Aufruf der Seite gesperrt.</p>
			<p class="cdmv-noprint">Einträge, die die Rolle des Benutzers nicht sehen darf, lassen sich über „trotzdem freischalten“ zusätzlich erlauben. Der Benutzer erhält dafür die dahinter genannte Berechtigung – sie gilt überall, wo WordPress oder ein Plugin sie abfragt, nicht nur für diesen einen Menüpunkt.</p>

			<form method="post" class="cdmv-main-form" action="<?php echo esc_url( self::page_url( $user->ID ) ); ?>">
				<?php wp_nonce_field( self::NONCE . $user->ID ); ?>

				<div class="cdmv-cols">
					<div class="cdmv-card">
						<h2>Backend-Navigation</h2>
						<p class="cdmv-tools cdmv-noprint">
							<button type="button" class="button-link" data-cdmv-all="1">Alle auswählen</button> |
							<button type="button" class="button-link" data-cdmv-all="0">Alle abwählen</button>
						</p>
						<ul class="cdmv-tree" data-autoparent="1">
							<?php
							$last_sep = true;
							foreach ( self::menu_tree() as $node ) {
								if ( $node['sep'] ) {
									if ( ! $last_sep ) {
										echo '<li class="cdmv-sep" aria-hidden="true"></li>';
									}
									$last_sep = true;
									continue;
								}
								$last_sep = false;
								$top_off  = isset( $hidden[ $node['key'] ] );
								$can      = self::role_can( $user, $node['cap'] );
								foreach ( $node['children'] as $child ) {
									$can = $can || self::role_can( $user, $child['cap'] );
								}
								echo '<li><label><input type="checkbox" name="cdmv_menu[]" value="' . esc_attr( $node['key'] ) . '"' . checked( ! $top_off, true, false ) . '>';
								echo self::icon_html( $node['icon'], $node['dom_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
								echo '<span class="cdmv-title">' . esc_html( $node['title'] ) . '</span></label>';
								echo $can ? '' : self::grant_html( $node['key'], $node['cap'], $granted, $node['slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput
								if ( $node['children'] ) {
									echo '<ul>';
									foreach ( $node['children'] as $child ) {
										$on = ! $top_off && ! isset( $hidden[ $child['key'] ] );
										echo '<li><label><input type="checkbox" name="cdmv_menu[]" value="' . esc_attr( $child['key'] ) . '"' . checked( $on, true, false ) . '> ';
										echo '<span class="cdmv-title">' . esc_html( $child['title'] ) . '</span></label>';
										echo self::role_can( $user, $child['cap'] ) ? '' : self::grant_html( $child['key'], $child['cap'], $granted, $child['slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput
										echo '</li>';
									}
									echo '</ul>';
								}
								echo '</li>';
							}
							?>
						</ul>
					</div>

					<div class="cdmv-card">
						<h2>Admin-Leiste</h2>
						<p class="cdmv-tools cdmv-noprint">
							<button type="button" class="button-link" data-cdmv-all="1">Alle auswählen</button> |
							<button type="button" class="button-link" data-cdmv-all="0">Alle abwählen</button>
						</p>
						<?php self::render_bar_level( self::bar_tree( $user ), '', $bar ); ?>
						<p class="description cdmv-noprint">Die Liste enthält alle Einträge, die dir selbst bisher in der Leiste angezeigt wurden. Einträge, die nur im Frontend erscheinen (z.&nbsp;B. „Seite bearbeiten“), kommen hinzu, sobald du die Website einmal angemeldet besucht hast.</p>
					</div>
				</div>

				<div class="cdmv-noprint"><?php submit_button( 'Speichern' ); ?></div>
			</form>
		</div>

		<style>
			.cdmv-cols { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
			.cdmv-card { background: #fff; border: 1px solid #c3c4c7; padding: 4px 20px 16px; min-width: 360px; flex: 1 1 0; box-sizing: border-box; }
			.cdmv-tools { margin: 0 0 8px; color: #a7aaad; }
			.cdmv-tree, .cdmv-tree ul { list-style: none; margin: 0; padding: 0; }
			.cdmv-tree li { margin: 0; padding: 3px 0; }
			.cdmv-tree ul { margin: 2px 0 6px 25px; padding-left: 14px; border-left: 2px solid #dcdcde; }
			.cdmv-tree > li > label .cdmv-title { font-weight: 600; }
			.cdmv-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
			.cdmv-actions { position: relative; margin-top: 12px; white-space: nowrap; }
			.cdmv-actions .button .dashicons { vertical-align: text-bottom; }
			.cdmv-copy-pop { position: absolute; right: 0; top: 100%; margin-top: 6px; z-index: 50; width: 320px; padding: 12px 14px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 3px 10px rgba(0,0,0,.15); white-space: normal; }
			.cdmv-copy-pop label { display: block; font-weight: 600; margin-bottom: 6px; }
			.cdmv-copy-pop select { width: 100%; max-width: none; margin-bottom: 8px; }
			.cdmv-copy-pop p { margin: 8px 0 0; }
			.cdmv-printonly { display: none; }

			@media print {
				@page { margin: 15mm; }
				html.wp-toolbar { padding-top: 0 !important; }
				#adminmenumain, #wpadminbar, #wpfooter, #screen-meta, #screen-meta-links, .notice, .update-nag, .cdmv-noprint { display: none !important; }
				#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; float: none !important; }
				body, #wpwrap, #wpcontent { background: #fff !important; }
				.cdmv, .cdmv * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
				.cdmv-printonly { display: inline; }
				.cdmv-cols { display: block; }
				/* Kasten als Tabelle: die Überschrift wird so auf jeder Druckseite wiederholt. */
				.cdmv-card { display: table; width: 100%; max-width: none; border: 0; padding: 0; }
				.cdmv-card + .cdmv-card { break-before: page; page-break-before: always; }
				.cdmv-card > h2 { display: table-header-group; font-size: 16px; line-height: 2.4; border-bottom: 1px solid #000; }
				.cdmv-card > .cdmv-tree { display: table-row-group; }
				/* Jeder Hauptpunkt bleibt samt Unterpunkten auf einer Seite zusammen. */
				.cdmv-card > .cdmv-tree > li { display: table-row; break-inside: avoid; page-break-inside: avoid; }
				.cdmv-card > .cdmv-tree > li > label { display: inline-block; padding-top: 6px; }
				.cdmv-tree ul li { break-inside: avoid; page-break-inside: avoid; }
				.cdmv-sep { display: none !important; }
				.cdmv-grantbox { white-space: normal; }
			}
			.cdmv-tree code { font-size: 11px; color: #646970; background: none; }
			.cdmv-sep { height: 0; padding: 0 !important; margin: 6px 0 !important; border-top: 1px dashed #c3c4c7; }
			.cdmv-ico, .cdmv-ico.dashicons { display: inline-block; width: 28px; height: 28px; line-height: 28px; font-size: 18px; text-align: center; vertical-align: middle; margin: 0 8px 0 6px; border-radius: 4px; color: #f0f0f1; background: #1d2327 center / 18px auto no-repeat; overflow: hidden; }
			.cdmv-ico img { max-width: 18px; max-height: 18px; vertical-align: middle; }
			.cdmv-norole { color: #996800; font-size: 12px; }
			.cdmv-grantbox { margin-left: 8px; white-space: nowrap; }
			.cdmv-danger { color: #b32d2e; font-size: 12px; font-weight: 600; margin-left: 6px; cursor: help; }
			.cdmv-grantbox input { margin: 0 2px 0 4px; }
		</style>
		<script>
		( function () {
			var wrap = document.querySelector( '.cdmv' );
			if ( ! wrap ) { return; }

			function ownBox( li ) {
				return li.querySelector( ':scope > label > input[type=checkbox]' );
			}

			// Eltern-/Kind-Logik der Checkboxen.
			wrap.addEventListener( 'change', function ( e ) {
				var cb = e.target;
				if ( ! cb.matches || ! cb.matches( '.cdmv-tree input[type=checkbox]:not(.cdmv-grant)' ) ) { return; }
				var li = cb.closest( 'li' );
				li.querySelectorAll( 'ul input[type=checkbox]:not(.cdmv-grant)' ).forEach( function ( c ) { c.checked = cb.checked; } );

				var auto = !! cb.closest( '[data-autoparent]' );
				var parent = li.parentElement.closest( 'li' );
				while ( parent ) {
					var box = ownBox( parent );
					if ( cb.checked ) {
						box.checked = true;
					} else if ( auto ) {
						var any = false;
						parent.querySelectorAll( ':scope > ul > li > label > input[type=checkbox]:not(.cdmv-grant)' ).forEach( function ( c ) { any = any || c.checked; } );
						if ( ! any ) { box.checked = false; }
					}
					parent = parent.parentElement.closest( 'li' );
				}
			} );

			wrap.querySelector( '.cdmv-print-btn' ).addEventListener( 'click', function () { window.print(); } );

			// Neu angehakte weitreichende Freischaltungen vor dem Speichern bestätigen lassen.
			var mainForm = wrap.querySelector( '.cdmv-main-form' );
			if ( mainForm ) {
				mainForm.addEventListener( 'submit', function ( e ) {
					var caps = [];
					mainForm.querySelectorAll( '.cdmv-grant[data-danger]' ).forEach( function ( c ) {
						if ( c.checked && ! c.defaultChecked ) {
							c.getAttribute( 'data-danger' ).split( ', ' ).forEach( function ( cap ) {
								if ( caps.indexOf( cap ) < 0 ) { caps.push( cap ); }
							} );
						}
					} );
					if ( caps.length && ! window.confirm(
						'Achtung: Du schaltest weitreichende Berechtigungen frei:\n\n' + caps.join( '\n' ) +
						'\n\nSie kommen Administrator-Rechten gleich oder ermöglichen es, sich diese zu verschaffen. Wirklich speichern?'
					) ) {
						e.preventDefault();
					}
				} );
			}

			// Copy2User: Auswahl ein-/ausblenden, vor dem Übertragen nachfragen.
			var copyBtn = wrap.querySelector( '.cdmv-copy-btn' );
			var copyPop = wrap.querySelector( '.cdmv-copy-pop' );
			copyBtn.addEventListener( 'click', function () {
				copyPop.hidden = ! copyPop.hidden;
				copyBtn.setAttribute( 'aria-expanded', copyPop.hidden ? 'false' : 'true' );
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( ! copyPop.hidden && ! copyPop.contains( e.target ) && ! copyBtn.contains( e.target ) ) {
					copyPop.hidden = true;
					copyBtn.setAttribute( 'aria-expanded', 'false' );
				}
			} );
			var copyForm = wrap.querySelector( '.cdmv-copy-form' );
			if ( copyForm ) {
				copyForm.addEventListener( 'submit', function ( e ) {
					var sel = copyForm.querySelector( 'select' );
					var name = sel.options[ sel.selectedIndex ].text;
					if ( ! window.confirm( 'Rechteprofil auf ' + name + ' übertragen? Dessen bisheriges Profil wird überschrieben.' ) ) {
						e.preventDefault();
					}
				} );
			}

			wrap.querySelectorAll( '[data-cdmv-all]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var on = '1' === btn.getAttribute( 'data-cdmv-all' );
					btn.closest( '.cdmv-card' ).querySelectorAll( '.cdmv-tree input[type=checkbox]:not(.cdmv-grant)' ).forEach( function ( c ) { c.checked = on; } );
				} );
			} );

			// Icons, die nicht als Dashicon vorliegen, aus der echten Navigation übernehmen.
			window.addEventListener( 'load', function () {
				wrap.querySelectorAll( '.cdmv-ico[data-src]' ).forEach( function ( el ) {
					var id = el.getAttribute( 'data-src' );
					var li = id ? document.getElementById( id ) : null;
					var src = li ? li.querySelector( '.wp-menu-image' ) : null;
					var done = !! el.querySelector( 'img' );
					if ( src && ! done ) {
						var before = window.getComputedStyle( src, '::before' );
						var content = before ? before.content : '';
						if ( content && 'none' !== content && 'normal' !== content && '""' !== content ) {
							el.textContent = content.replace( /^["']|["']$/g, '' );
							el.style.fontFamily = before.fontFamily;
							done = true;
						} else {
							var bg = window.getComputedStyle( src ).backgroundImage;
							var img = src.querySelector( 'img' );
							if ( bg && 'none' !== bg ) {
								el.style.backgroundImage = bg;
								done = true;
							} else if ( img ) {
								el.appendChild( img.cloneNode( false ) );
								done = true;
							}
						}
					}
					if ( ! done ) {
						el.classList.add( 'dashicons', 'dashicons-admin-generic' );
					}
				} );
			} );
		}() );
		</script>
		<?php
	}
}

CDMV_Menu_Visibility::init();
