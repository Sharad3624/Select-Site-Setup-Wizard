<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSW_Steps {

	const FIXED_PLUGINS = [
		'elementor'             => 'elementor/elementor.php',
		'wp-mail-smtp'          => 'wp-mail-smtp/wp_mail_smtp.php',
		'wordpress-seo'         => 'wordpress-seo/wp-seo.php',
		'duplicate-page'        => 'duplicate-page/duplicatepage.php',
		'secure-custom-fields'  => 'secure-custom-fields/secure-custom-fields.php',
		'permalink-manager'     => 'permalink-manager/permalink-manager.php',
		'classic-editor'        => 'classic-editor/classic-editor.php',
	];

	// Breakpoint values are "max" px for that device, except widescreen which is "min".
	// Matches Elementor's own viewport_* Kit settings (Core\Breakpoints\Manager).
	const BREAKPOINT_PRESETS = [
		'preset1' => [ // widescreen, desktop, laptop, tablet landscape, tablet portrait, mobile landscape, mobile portrait
			'mobile'       => 767,
			'mobile_extra' => 880,
			'tablet'       => 1024,
			'tablet_extra' => 1200,
			'laptop'       => 1438,
			'widescreen'   => 1850,
		],
		'preset2' => [ // desktop, laptop, tablet landscape, tablet portrait, mobile landscape, mobile portrait (no widescreen)
			'mobile'       => 767,
			'mobile_extra' => 1023,
			'tablet'       => 1199,
			'tablet_extra' => 1438,
			'laptop'       => 1850,
			'widescreen'   => null,
		],
	];

	// Container width per device. Elementor cascades a device's value down to any
	// narrower device that has no explicit override of its own (e.g. leaving
	// "tablet" unset means it inherits "tablet_extra"), so these 5 tiers alone
	// reproduce the requested 5-tier table across all 7 breakpoint devices.
	const CONTAINER_WIDTH_DEFAULTS = [
		'widescreen'   => [ 'size' => 1620, 'unit' => 'px' ], // > 1850
		'desktop'      => [ 'size' => 85,   'unit' => '%' ],  // 1850 - 1438 (base value, key: container_width)
		'laptop'       => [ 'size' => 90,   'unit' => '%' ],  // 1438 - 1201
		'tablet_extra' => [ 'size' => 95,   'unit' => '%' ],  // 1200 - 768
		'mobile'       => [ 'size' => 100,  'unit' => '%' ],  // 767 - 0
	];

	private static function ensure_upgrader_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	}

	private static function ok( $message, $details = [] ) {
		return [ 'ok' => true, 'message' => $message, 'details' => $details ];
	}

	private static function fail( $message, $details = [] ) {
		return [ 'ok' => false, 'message' => $message, 'details' => $details ];
	}

	private static function skin_failure_reason( $installed, $skin ) {
		if ( is_wp_error( $installed ) ) {
			return $installed->get_error_message();
		}
		$messages = $skin->get_upgrade_messages();
		return $messages ? end( $messages ) : 'unknown error';
	}

	/* ---------------- Step 1: theme from Underscores.me ---------------- */

	public static function install_theme( $theme_name, $confirm_overwrite = false, $keep_existing = false ) {
		self::ensure_upgrader_includes();

		$theme_name = $theme_name ? sanitize_text_field( $theme_name ) : 'My Underscores Theme';
		$slug       = sanitize_title( $theme_name );

		if ( $keep_existing ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return self::fail( "Theme \"{$slug}\" no longer exists - nothing to keep." );
			}
			switch_theme( $slug );
			return self::ok( "Kept the existing \"{$theme_name}\" theme and made sure it's active ({$slug})." );
		}

		if ( ! $confirm_overwrite && wp_get_theme( $slug )->exists() ) {
			return [
				'ok'                 => false,
				'needs_confirmation' => true,
				'message'            => "A theme named \"{$theme_name}\" (slug \"{$slug}\") is already installed. Overwrite it with a freshly generated one, or keep the existing one and continue.",
			];
		}

		$package  = self::fetch_underscores_zip( $theme_name, $slug );
		$fallback = false;

		if ( is_wp_error( $package ) ) {
			$fallback = true;
			$package  = 'https://github.com/Automattic/_s/archive/refs/heads/master.zip';
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		// overwrite_package: install() otherwise refuses outright (generic
		// "Theme installation failed") if a theme directory with this slug
		// already exists - which happens the moment someone re-runs Step 1
		// with the same name, including just retrying after a failure.
		$result = $upgrader->install( $package, [ 'overwrite_package' => true ] );

		if ( is_string( $package ) && 0 !== strpos( $package, 'http' ) ) {
			@unlink( $package );
		}

		if ( is_wp_error( $result ) ) {
			return self::fail( 'Theme install failed: ' . $result->get_error_message() );
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return self::fail( 'Theme install failed: ' . ( $messages ? end( $messages ) : 'unknown error' ) );
		}

		$installed_slug = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : ( $fallback ? '_s-master' : $slug );

		switch_theme( $installed_slug );

		$msg = $fallback
			? "Underscores.me generator was unreliable, installed the base _s theme instead and activated it ({$installed_slug})."
			: "Generated \"{$theme_name}\" from underscores.me, installed and activated it ({$installed_slug}).";

		return self::ok( $msg, [ 'slug' => $installed_slug, 'fallback' => $fallback ] );
	}

	private static function fetch_underscores_zip( $theme_name, $slug ) {
		$response = wp_remote_post( 'https://underscores.me/', [
			'timeout' => 45,
			'body'    => [
				'underscoresme_generate'        => 1,
				'underscoresme_name'            => $theme_name,
				'underscoresme_slug'            => $slug,
				'underscoresme_author'          => get_bloginfo( 'name' ),
				'underscoresme_author_uri'      => home_url(),
				'underscoresme_description'     => '',
				'underscoresme_generate_submit' => 'Generate',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code || 0 !== strpos( $body, 'PK' ) ) {
			return new WP_Error( 'underscores_bad_response', 'Unexpected response from underscores.me' );
		}

		$tmp_file = wp_tempnam( $slug . '.zip' );
		file_put_contents( $tmp_file, $body );

		return $tmp_file;
	}

	/* ---------------- Step 2: wipe default content ---------------- */

	public static function wipe_content( $delete_sample_page, $confirmed = false ) {
		if ( ! $confirmed ) {
			return self::fail( 'Refused: destructive step was not explicitly confirmed.' );
		}

		self::ensure_upgrader_includes();

		// Do this before touching any plugins below: it just flips an option
		// and writes .htaccess, but other active plugins (Yoast, WooCommerce,
		// etc.) have hooks that react to permalink/option changes by touching
		// their OWN classes/files - safe here since everything is still
		// installed, but a guaranteed fatal if it ran after those plugins'
		// files were already deleted while their hooks are still registered
		// in this same request (deactivate_plugins() doesn't unload them).
		$permalinks = self::ensure_clean_permalinks();

		$self_basename = plugin_basename( SSW_FILE );
		$all_plugins   = get_plugins();
		$to_remove     = [];

		foreach ( $all_plugins as $file => $data ) {
			if ( $file === $self_basename ) {
				continue;
			}
			$to_remove[] = $file;
		}

		if ( $to_remove ) {
			deactivate_plugins( $to_remove, true );

			// delete_plugins() ends by refreshing the update_plugins transient,
			// which fires pre_set_site_transient_update_plugins - and plugins
			// like WooCommerce hook that to lazily load their own classes via
			// another active plugin's bundled autoloader (e.g. Elementor's
			// Jetpack Autoloader). deactivate_plugins() doesn't undo classes
			// already loaded earlier in this same request, so if we're
			// deleting a combo like that, the callback fires against files
			// this loop just deleted and fatals. The transient refresh is
			// just bookkeeping for the admin update-notices UI, not something
			// this deletion depends on, so skip it entirely.
			remove_all_filters( 'pre_set_site_transient_update_plugins' );
			delete_plugins( $to_remove );
		}

		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
		] );
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'any',
			'numberposts'    => -1,
		] );
		$deleted_pages = [];
		foreach ( $pages as $page ) {
			$haystack = strtolower( $page->post_title . ' ' . $page->post_content );
			$is_hello  = false !== strpos( $haystack, 'hello world' );
			$is_sample = $delete_sample_page && 0 === strcasecmp( trim( $page->post_title ), 'Sample Page' );
			if ( $is_hello || $is_sample ) {
				wp_delete_post( $page->ID, true );
				$deleted_pages[] = $page->post_title;
			}
		}

		return self::ok(
			sprintf(
				'Removed %d plugin(s), %d post(s), and %d page(s) (%s). %s',
				count( $to_remove ),
				count( $posts ),
				count( $deleted_pages ),
				$deleted_pages ? implode( ', ', $deleted_pages ) : 'none matched',
				$permalinks['message']
			),
			[ 'removed_plugins' => $to_remove, 'deleted_pages' => $deleted_pages ]
		);
	}

	/**
	 * A permalink structure containing "/index.php/" (WordPress's fallback when
	 * it can't confirm URL rewriting works) breaks more than just ugly URLs: at
	 * least Permalink Manager mis-generates URIs from it - a literal
	 * "index.php/" segment with the dot stripped, 404ing every page. Since
	 * Step 4 is about to install Permalink Manager, switch to a clean
	 * structure and write .htaccess now, before it has a broken structure to
	 * read from.
	 */
	private static function ensure_clean_permalinks() {
		global $wp_rewrite;

		// Permalink Manager caches every custom URI it generates in this single
		// option, keyed by post ID - reinstalling the plugin does NOT clear it.
		// If it was ever active while the structure above was broken (e.g. a
		// wizard re-run), those entries stay broken even after the structure
		// itself is fixed here, until Permalink Manager is told to forget them.
		if ( delete_option( 'permalink-manager-uris' ) ) {
			$uris_note = ' Cleared cached Permalink Manager URIs.';
		} else {
			$uris_note = '';
		}

		if ( ! isset( $wp_rewrite ) ) {
			return [ 'message' => 'Skipped permalink cleanup - $wp_rewrite unavailable.' . $uris_note ];
		}

		$current = get_option( 'permalink_structure' );
		if ( $current && false === strpos( $current, 'index.php' ) ) {
			return [ 'message' => 'Permalink structure already clean.' . $uris_note ];
		}

		self::ensure_upgrader_includes();
		if ( ! WP_Filesystem() ) {
			return [ 'message' => 'Could not switch to a clean permalink structure - filesystem access unavailable.' . $uris_note ];
		}

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules( false );

		// flush_rules()'s own .htaccess write is gated on got_mod_rewrite(),
		// which can't detect Apache's rewrite module outside of a real Apache
		// request (e.g. when this runs via WP-CLI) - write it directly instead.
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$home_path = get_home_path();
		$rules     = explode( "\n", $wp_rewrite->mod_rewrite_rules() );
		insert_with_markers( $home_path . '.htaccess', 'WordPress', $rules );

		return [ 'message' => 'Switched to a clean "/%postname%/" permalink structure and wrote .htaccess.' . $uris_note ];
	}

	/* ---------------- Step 3: homepage ---------------- */

	public static function create_homepage( $title, $force_new = false ) {
		if ( ! $force_new ) {
			$existing_id = (int) get_option( 'page_on_front' );
			if ( 'page' === get_option( 'show_on_front' ) && $existing_id && get_post( $existing_id ) ) {
				return [
					'ok'                 => false,
					'needs_confirmation' => true,
					'message'            => 'A homepage is already set ("' . get_the_title( $existing_id ) . '", ID ' . $existing_id . ').',
				];
			}
		}

		$title = $title ? sanitize_text_field( $title ) : 'Home';

		$page_id = wp_insert_post( [
			'post_title'   => $title,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '',
		], true );

		if ( is_wp_error( $page_id ) ) {
			return self::fail( 'Could not create homepage: ' . $page_id->get_error_message() );
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		return self::ok( "Created page \"{$title}\" (ID {$page_id}) and set it as the static front page.", [ 'page_id' => $page_id ] );
	}

	/* ---------------- Step 4: fixed plugin stack (installed one at a time by JS, for progress) ---------------- */

	public static function install_and_activate_wp_org_plugin( $slug ) {
		self::ensure_upgrader_includes();

		$plugin_file = self::find_installed_plugin_file( $slug );

		if ( $plugin_file ) {
			return self::update_or_activate_installed_plugin( $slug, $plugin_file );
		}

		$api = plugins_api( 'plugin_information', [
			'slug'   => $slug,
			'fields' => [ 'sections' => false ],
		] );

		if ( is_wp_error( $api ) ) {
			return [ 'ok' => false, 'message' => "{$slug}: lookup failed - " . $api->get_error_message() ];
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $api->download_link );

		if ( is_wp_error( $installed ) || ! $installed ) {
			return [ 'ok' => false, 'message' => "{$slug}: install failed - " . self::skin_failure_reason( $installed, $skin ) ];
		}

		$plugin_file = $upgrader->plugin_info();
		if ( ! $plugin_file ) {
			return [ 'ok' => false, 'message' => "{$slug}: installed but couldn't determine plugin file" ];
		}

		$activated = activate_plugin( $plugin_file );
		if ( is_wp_error( $activated ) ) {
			return [ 'ok' => false, 'message' => "{$slug}: activation failed - " . $activated->get_error_message() ];
		}

		return [ 'ok' => true, 'message' => "{$slug}: installed and activated ({$plugin_file})." ];
	}

	/**
	 * The plugin is already on disk. Compare its installed version against
	 * wordpress.org's latest and update it if there's a newer one, then make
	 * sure it ends up active either way.
	 */
	private static function update_or_activate_installed_plugin( $slug, $plugin_file ) {
		$api = plugins_api( 'plugin_information', [
			'slug'   => $slug,
			'fields' => [ 'sections' => false ],
		] );

		$installed_data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$installed_version = $installed_data['Version'] ?? '';

		if ( is_wp_error( $api ) ) {
			$activation = self::ensure_plugin_active( $slug, $plugin_file );
			if ( ! $activation['ok'] ) {
				return $activation;
			}
			return [ 'ok' => true, 'message' => "{$slug}: already installed ({$installed_version}) - couldn't check wordpress.org for an update (" . $api->get_error_message() . ')' . $activation['suffix'] ];
		}

		$latest_version = $api->version ?? '';

		if ( $latest_version && $installed_version && version_compare( $latest_version, $installed_version, '>' ) ) {
			// Plugin_Upgrader::upgrade() refuses to act unless WordPress's own
			// site-wide update_plugins transient already lists this plugin -
			// that's a second, independent version-check system this method
			// doesn't otherwise touch. install() with overwrite_package instead
			// reuses the exact same download path as a fresh install, which is
			// already known to work here.
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$upgraded = $upgrader->install( $api->download_link, [ 'overwrite_package' => true ] );

			if ( is_wp_error( $upgraded ) || ! $upgraded ) {
				return [ 'ok' => false, 'message' => "{$slug}: update {$installed_version} \u{2192} {$latest_version} failed - " . self::skin_failure_reason( $upgraded, $skin ) ];
			}

			$activation = self::ensure_plugin_active( $slug, $plugin_file );
			if ( ! $activation['ok'] ) {
				return $activation;
			}

			return [ 'ok' => true, 'message' => "{$slug}: updated {$installed_version} \u{2192} {$latest_version}." . $activation['suffix'] ];
		}

		$activation = self::ensure_plugin_active( $slug, $plugin_file );
		if ( ! $activation['ok'] ) {
			return $activation;
		}
		return [ 'ok' => true, 'message' => "{$slug}: already up to date ({$installed_version})." . $activation['suffix'] ];
	}

	private static function ensure_plugin_active( $slug, $plugin_file ) {
		if ( is_plugin_active( $plugin_file ) ) {
			return [ 'ok' => true, 'suffix' => '' ];
		}
		$activated = activate_plugin( $plugin_file );
		if ( is_wp_error( $activated ) ) {
			return [ 'ok' => false, 'message' => "{$slug}: activation failed - " . $activated->get_error_message() ];
		}
		return [ 'ok' => true, 'suffix' => ' Activated.' ];
	}

	private static function find_installed_plugin_file( $slug ) {
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return $file;
			}
		}
		return null;
	}

	/* ---------------- Step 5: WooCommerce ---------------- */

	public static function woocommerce( $install ) {
		if ( ! $install ) {
			return self::ok( 'Skipped WooCommerce.' );
		}
		$result = self::install_and_activate_wp_org_plugin( 'woocommerce' );
		if ( ! $result['ok'] ) {
			return self::fail( $result['message'] );
		}

		$theme = self::add_shop_theme_integration();

		return self::ok(
			$result['message'] . ' ' . $theme['message'],
			[ 'shortcode' => 'ssw_category_sidebar' ]
		);
	}

	/**
	 * Where a grid/list icon uploaded in Step 5 is staged (via upload_shop_icon())
	 * before the theme exists to copy it into - the uploads dir always exists
	 * regardless of which theme is active.
	 */
	private static function shop_icon_staging_dir() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/ssw-shop-icons';
	}

	public static function upload_shop_icon( $type, $tmp_path ) {
		if ( ! in_array( $type, [ 'grid', 'list' ], true ) ) {
			return self::fail( 'Unknown icon type.' );
		}

		$contents = file_get_contents( $tmp_path );
		$trimmed  = ltrim( (string) $contents );
		if ( 0 !== strpos( $trimmed, '<?xml' ) && 0 !== strpos( $trimmed, '<svg' ) ) {
			return self::fail( 'That file does not look like an SVG.' );
		}

		self::ensure_upgrader_includes();
		if ( ! WP_Filesystem() ) {
			return self::fail( 'Could not access the filesystem to store the icon.' );
		}

		global $wp_filesystem;
		$dir = self::shop_icon_staging_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::fail( "Could not create {$dir}." );
		}

		if ( ! $wp_filesystem->put_contents( $dir . "/icon-{$type}.svg", $contents, FS_CHMOD_FILE ) ) {
			return self::fail( 'Could not save the icon.' );
		}

		return self::ok( ucfirst( $type ) . " view icon saved - it'll be used once WooCommerce is installed." );
	}

	/**
	 * Copies the shop-archive template files (WooCommerce theme support, a
	 * grid/list view toggle, and the [ssw_category_sidebar] shortcode) into
	 * the active theme's inc/ folder, and wires them up via functions.php.
	 */
	private static function add_shop_theme_integration() {
		self::ensure_upgrader_includes();

		if ( ! WP_Filesystem() ) {
			return [ 'message' => 'Could not access the filesystem to add shop theme files - skipped.' ];
		}

		global $wp_filesystem;

		$theme_dir = get_stylesheet_directory();
		$inc_dir   = $theme_dir . '/inc';

		if ( ! wp_mkdir_p( $inc_dir ) ) {
			return [ 'message' => "Could not create {$inc_dir} - skipped shop theme files." ];
		}

		$template_dir = SSW_DIR . 'includes/theme-templates/';
		$copied       = [];

		foreach ( [ 'global-functions.php', 'shop-archive.css', 'shop-archive.js' ] as $file ) {
			$contents = file_get_contents( $template_dir . $file );
			if ( false === $contents ) {
				continue;
			}
			if ( $wp_filesystem->put_contents( $inc_dir . '/' . $file, $contents, FS_CHMOD_FILE ) ) {
				$copied[] = $file;
			}
		}

		if ( ! in_array( 'global-functions.php', $copied, true ) ) {
			return [ 'message' => 'Could not write shop theme files into the active theme - skipped.' ];
		}

		$icons_copied = [];
		$staging_dir  = self::shop_icon_staging_dir();
		foreach ( [ 'grid', 'list' ] as $type ) {
			$staged = $staging_dir . "/icon-{$type}.svg";
			if ( ! $wp_filesystem->exists( $staged ) ) {
				continue;
			}
			$svg = $wp_filesystem->get_contents( $staged );
			if ( false !== $svg && $wp_filesystem->put_contents( $inc_dir . "/icon-{$type}.svg", $svg, FS_CHMOD_FILE ) ) {
				$icons_copied[] = $type;
			}
		}
		$icons_note = $icons_copied ? ( ' Using your uploaded ' . implode( ' & ', $icons_copied ) . ' icon(s).' ) : '';

		$functions_php = $theme_dir . '/functions.php';
		$require_line  = "require_once get_stylesheet_directory() . '/inc/global-functions.php';";

		if ( $wp_filesystem->exists( $functions_php ) ) {
			$functions_contents = $wp_filesystem->get_contents( $functions_php );

			if ( false !== strpos( $functions_contents, $require_line ) ) {
				return [ 'message' => "Shop theme files updated in \"{$theme_dir}\"; functions.php already wires them up.{$icons_note}" ];
			}

			$trimmed = rtrim( $functions_contents );
			$addition = "\n" . $require_line . "\n";
			$new_contents = ( '?>' === substr( $trimmed, -2 ) )
				? substr( $trimmed, 0, -2 ) . $addition . "?>\n"
				: $functions_contents . $addition;

			if ( ! $wp_filesystem->put_contents( $functions_php, $new_contents, FS_CHMOD_FILE ) ) {
				return [ 'message' => "Shop theme files written to \"{$theme_dir}/inc/\", but couldn't update functions.php automatically - add `{$require_line}` to it manually." ];
			}
		} else {
			return [ 'message' => "Shop theme files written to \"{$theme_dir}/inc/\", but the theme has no functions.php to wire them into - add `{$require_line}` to it manually." ];
		}

		return [ 'message' => "Added WooCommerce support, the shop grid/list toggle, and the [ssw_category_sidebar] shortcode to the active theme (\"{$theme_dir}\").{$icons_note}" ];
	}

	/* ---------------- Step 6: Elementor site settings ---------------- */

	public static function install_identity_mirror() {
		self::ensure_upgrader_includes();

		$repo_api = wp_remote_get( 'https://api.github.com/repos/Sharad3624/Identity-Mirror', [
			'headers' => [ 'User-Agent' => 'SiteSetupWizard' ],
			'timeout' => 20,
		] );
		if ( is_wp_error( $repo_api ) ) {
			return self::fail( 'Could not reach GitHub API: ' . $repo_api->get_error_message() );
		}
		$repo_data = json_decode( wp_remote_retrieve_body( $repo_api ), true );
		$branch    = $repo_data['default_branch'] ?? 'main';

		$zip_url = 'https://codeload.github.com/Sharad3624/Identity-Mirror/zip/refs/heads/' . rawurlencode( $branch );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $zip_url );

		if ( is_wp_error( $installed ) || ! $installed ) {
			return self::fail( 'Identity Mirror install failed: ' . self::skin_failure_reason( $installed, $skin ) . ' - the zip may need a manual upload instead.' );
		}

		$plugin_file = $upgrader->plugin_info();
		if ( ! $plugin_file ) {
			return self::fail( 'Identity Mirror installed but its main plugin file could not be located; activate it manually from Plugins.' );
		}

		$activated = activate_plugin( $plugin_file );
		if ( is_wp_error( $activated ) ) {
			return self::fail( 'Identity Mirror installed but activation failed: ' . $activated->get_error_message() );
		}

		return self::ok( "Identity Mirror installed and activated from branch \"{$branch}\" ({$plugin_file})." );
	}

	public static function is_identity_mirror_active() {
		foreach ( get_option( 'active_plugins', [] ) as $file ) {
			if ( false !== stripos( $file, 'identity-mirror' ) || false !== stripos( $file, 'identity_mirror' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A live read of current site state for the wizard's final summary panel -
	 * queried fresh rather than tracked client-side, so it's correct
	 * regardless of which steps were skipped, kept-existing, or retried.
	 */
	public static function get_summary() {
		$theme        = wp_get_theme();
		$kit          = self::get_current_kit_settings();
		$front_page   = (int) get_option( 'page_on_front' );
		$woo_active   = function_exists( 'WC' );
		$active_count = count( get_option( 'active_plugins', [] ) );

		return self::ok( 'Summary', [
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url( '/' ),
			'theme'            => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'plugin_count'     => $active_count,
			'woocommerce'      => $woo_active,
			'identity_mirror'  => self::is_identity_mirror_active(),
			'breakpoint_mode'  => $kit['breakpoint_mode'] ?? null,
			'front_page_id'    => $front_page,
			'front_page_title' => $front_page ? get_the_title( $front_page ) : '',
		] );
	}

	/**
	 * Reads back whatever's already saved on the active Elementor Kit, so the
	 * wizard's Step 6 form can show current values instead of always
	 * resetting to hardcoded defaults on a re-run. Returns null if Elementor
	 * isn't active or there's no Kit yet.
	 */
	public static function get_current_kit_settings() {
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}
		if ( empty( \Elementor\Plugin::$instance->kits_manager ) ) {
			return null;
		}
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit ) {
			return null;
		}

		$settings = get_post_meta( $kit->get_id(), '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$breakpoints = [];
		foreach ( array_keys( self::BREAKPOINT_PRESETS['preset1'] ) as $key ) {
			$breakpoints[ $key ] = isset( $settings[ "viewport_{$key}" ] ) ? (int) $settings[ "viewport_{$key}" ] : null;
		}

		$mode = null;
		if ( array_filter( $breakpoints, static function ( $v ) { return null !== $v; } ) ) {
			if ( self::BREAKPOINT_PRESETS['preset1'] === $breakpoints ) {
				$mode = 'preset1';
			} elseif ( self::BREAKPOINT_PRESETS['preset2'] === $breakpoints ) {
				$mode = 'preset2';
			} else {
				$mode = 'custom';
			}
		}

		$container_width = [];
		foreach ( array_keys( self::CONTAINER_WIDTH_DEFAULTS ) as $tier ) {
			$key = 'desktop' === $tier ? 'container_width' : "container_width_{$tier}";
			$container_width[ $tier ] = isset( $settings[ $key ]['size'], $settings[ $key ]['unit'] )
				? [ 'size' => $settings[ $key ]['size'], 'unit' => $settings[ $key ]['unit'] ]
				: null;
		}

		return [
			'breakpoint_mode' => $mode,
			'breakpoints'     => $breakpoints,
			'container_width' => $container_width,
			'padding'         => isset( $settings['container_padding'] ) ? $settings['container_padding'] : null,
		];
	}

	public static function elementor_settings( $args ) {
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			return self::fail( 'Elementor is not active; install/activate it in Step 4 first.' );
		}

		$messages = [];
		$settings = [];

		// Identity.
		if ( ! empty( $args['use_identity_mirror'] ) ) {
			$im = self::install_identity_mirror();
			$messages[] = $im['message'];
		} else {
			if ( ! empty( $args['site_name'] ) ) {
				update_option( 'blogname', sanitize_text_field( $args['site_name'] ) );
				$settings['site_name'] = sanitize_text_field( $args['site_name'] );
			}
			if ( ! empty( $args['site_description'] ) ) {
				update_option( 'blogdescription', sanitize_text_field( $args['site_description'] ) );
				$settings['site_description'] = sanitize_text_field( $args['site_description'] );
			}
			if ( ! empty( $args['logo_id'] ) ) {
				$logo_id = absint( $args['logo_id'] );
				set_theme_mod( 'custom_logo', $logo_id );
				$settings['site_logo'] = [ 'id' => $logo_id, 'url' => wp_get_attachment_url( $logo_id ) ];
			}
			$messages[] = 'Applied native site identity fields.';
		}

		// Breakpoints.
		$breakpoints = self::resolve_breakpoints( $args );
		if ( $breakpoints ) {
			update_option( 'elementor_experiment-additional_custom_breakpoints', 'active' );

			$active = [ 'viewport_mobile', 'viewport_tablet' ];
			foreach ( [ 'mobile_extra', 'tablet_extra', 'laptop', 'widescreen' ] as $key ) {
				if ( isset( $breakpoints[ $key ] ) && '' !== $breakpoints[ $key ] && null !== $breakpoints[ $key ] ) {
					$active[] = 'viewport_' . $key;
					$settings[ 'viewport_' . $key ] = (int) $breakpoints[ $key ];
				}
			}
			$settings['viewport_mobile'] = (int) $breakpoints['mobile'];
			$settings['viewport_tablet'] = (int) $breakpoints['tablet'];
			$settings['active_breakpoints'] = $active;

			$messages[] = 'Applied breakpoints: ' . implode( ', ', array_map(
				function ( $k, $v ) { return "{$k}={$v}px"; },
				array_keys( $breakpoints ),
				$breakpoints
			) );
		}

		// Container width (responsive, per device).
		$cw = self::resolve_container_width( $args['container_width'] ?? [] );
		$settings['container_width']                = $cw['desktop'];
		$settings['container_width_widescreen']     = $cw['widescreen'];
		$settings['container_width_laptop']         = $cw['laptop'];
		$settings['container_width_tablet_extra']   = $cw['tablet_extra'];
		$settings['container_width_mobile']         = $cw['mobile'];
		$messages[] = 'Applied container width: ' . implode( ', ', array_map(
			function ( $k, $v ) { return "{$k}={$v['size']}{$v['unit']}"; },
			array_keys( $cw ),
			$cw
		) );

		// Padding.
		if ( isset( $args['padding'] ) && is_array( $args['padding'] ) ) {
			$p    = $args['padding'];
			$unit = self::sanitize_css_unit( $p['unit'] ?? '', 'px' );
			$settings['container_padding'] = [
				'top'    => isset( $p['top'] ) ? (string) floatval( $p['top'] ) : '10',
				'right'  => isset( $p['right'] ) ? (string) floatval( $p['right'] ) : '10',
				'bottom' => isset( $p['bottom'] ) ? (string) floatval( $p['bottom'] ) : '10',
				'left'   => isset( $p['left'] ) ? (string) floatval( $p['left'] ) : '10',
				'unit'   => $unit,
			];
			$messages[] = "Applied container padding ({$unit}).";
		}

		// Default page layout: full-width content, but keeps the header/footer area
		// so the Theme Builder templates below actually have somewhere to show.
		$settings['default_page_template'] = 'elementor_header_footer';
		$messages[] = 'Set default page layout to Elementor Full Width.';

		if ( $settings ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( ! $kit ) {
				return self::fail( 'Could not find the active Elementor Kit.' );
			}
			$kit->update_settings( $settings );
		}

		// The Kit's default_page_template fallback (above) only applies to pages
		// already "Built with Elementor" (Modules\PageTemplates\Module::template_include()
		// checks is_built_with_elementor() before using it) - a plain page, like the
		// one Step 3 creates, would otherwise ignore it and the header/footer
		// templates below would never get a chance to render on the homepage.
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && ! get_post_meta( $front_page_id, '_elementor_edit_mode', true ) ) {
			update_post_meta( $front_page_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $front_page_id, '_elementor_data', wp_slash( '[]' ) );
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $front_page_id, '_elementor_version', ELEMENTOR_VERSION );
			}
			$messages[] = "Marked the homepage (ID {$front_page_id}) as built with Elementor so the layout/header/footer settings actually apply to it.";
		}

		if ( class_exists( '\Elementor\Core\Breakpoints\Manager' ) ) {
			$upload_dir = wp_upload_dir();
			wp_mkdir_p( $upload_dir['basedir'] . '/elementor/css' );
			\Elementor\Core\Breakpoints\Manager::compile_stylesheet_templates();
		}
		if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		$tb = self::create_blank_header_footer();
		$messages[] = $tb['message'];

		return self::ok( implode( ' ', $messages ), [ 'settings' => $settings ] );
	}

	private static function resolve_container_width( $input ) {
		$input  = is_array( $input ) ? $input : [];
		$result = [];

		foreach ( self::CONTAINER_WIDTH_DEFAULTS as $tier => $default ) {
			$size = isset( $input[ $tier ]['size'] ) && '' !== $input[ $tier ]['size']
				? floatval( $input[ $tier ]['size'] )
				: $default['size'];
			$unit = self::sanitize_css_unit( $input[ $tier ]['unit'] ?? '', $default['unit'] );

			$result[ $tier ] = [ 'size' => $size, 'unit' => $unit ];
		}

		return $result;
	}

	/**
	 * sanitize_key() strips anything outside [a-z0-9_-], which silently turns
	 * "%" into an empty string - exactly the unit 4 of the 5 container-width
	 * defaults use. Validate against Elementor's own allowed unit list instead.
	 */
	private static function sanitize_css_unit( $unit, $fallback ) {
		$allowed = [ 'px', '%', 'em', 'rem', 'vw', 'custom' ];
		return in_array( $unit, $allowed, true ) ? $unit : $fallback;
	}

	private static function detect_elementor_pro() {
		return class_exists( '\ElementorPro\Plugin' )
			|| class_exists( '\ProElements\Plugin' )
			|| defined( 'ELEMENTOR_PRO_VERSION' )
			|| defined( 'PROELEMENTS_VERSION' );
	}

	/**
	 * Creates blank "Header" and "Footer" Theme Builder templates. Safe to call
	 * repeatedly - skips creation if one with that title/type already exists.
	 *
	 * Note: with Elementor Pro/ProElements detected, this also attempts to set
	 * the template's display condition to "Entire Site" via `_elementor_conditions`.
	 * That specific meta format is not verifiable without Pro installed (it's a
	 * licensed plugin, no public source to check against) - if the templates
	 * don't show up automatically after activating Pro, open each one in
	 * Theme Builder and set its condition to Entire Site manually.
	 */
	private static function create_blank_header_footer() {
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return [ 'message' => 'Skipped blank Header/Footer templates - Elementor library post type not available.' ];
		}

		$has_pro = self::detect_elementor_pro();
		$created = [];
		$existed = [];

		foreach ( [ 'header', 'footer' ] as $type ) {
			$title = ucfirst( $type );

			$existing = get_posts( [
				'post_type'      => 'elementor_library',
				'post_status'    => 'any',
				'numberposts'    => 1,
				'tax_query'      => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'taxonomy' => 'elementor_library_type',
					'field'    => 'slug',
					'terms'    => $type,
				] ],
			] );

			if ( $existing ) {
				$existed[] = $title;
				continue;
			}

			$post_id = wp_insert_post( [
				'post_title'  => $title,
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
			], true );

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( '[]' ) );
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
			}
			wp_set_object_terms( $post_id, $type, 'elementor_library_type' );

			if ( $has_pro ) {
				// Pro/ProElements resolves a post's Document class from this meta
				// (Documents_Manager::get_doc_type_by_id()); without it the post falls
				// back to the generic "library" document, isn't a Theme_Document, and
				// silently never enters the Theme Builder at all - conditions or not.
				update_post_meta( $post_id, '_elementor_template_type', $type );
				update_post_meta( $post_id, '_elementor_conditions', [ 'include/general' ] );
			}

			$created[] = $title;
		}

		// Pro/ProElements caches the location->template map in a separate option
		// (Theme_Builder\Classes\Conditions_Cache, option "elementor_pro_theme_builder_conditions")
		// rather than reading `_elementor_conditions` live, so the condition set above
		// won't take effect until that cache is rebuilt.
		if ( $has_pro && $created ) {
			$cache_class = '\ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache';
			if ( class_exists( $cache_class ) ) {
				( new $cache_class() )->regenerate();
			}
		}

		$parts = [];
		if ( $created ) {
			$parts[] = 'created blank ' . implode( ' & ', $created ) . ' template(s) in Theme Builder';
		}
		if ( $existed ) {
			$parts[] = implode( ' & ', $existed ) . ' already existed, left untouched';
		}
		$suffix = $has_pro
			? ' (Elementor Pro/ProElements detected - set to display on the entire site, verified against ProElements\' own Conditions_Manager/Conditions_Cache source).'
			: ' (Elementor Pro/ProElements not detected - these are blank saved templates only; install Pro and set each one\'s condition to Entire Site to have them apply automatically).';

		return [ 'message' => ucfirst( implode( '; ', $parts ) ) . $suffix ];
	}

	private static function resolve_breakpoints( $args ) {
		$mode = $args['breakpoint_mode'] ?? '';

		if ( isset( self::BREAKPOINT_PRESETS[ $mode ] ) ) {
			return self::BREAKPOINT_PRESETS[ $mode ];
		}

		if ( 'custom' === $mode && ! empty( $args['custom_breakpoints'] ) ) {
			$c = $args['custom_breakpoints'];
			return [
				'mobile'       => isset( $c['mobile'] ) ? (int) $c['mobile'] : 767,
				'mobile_extra' => isset( $c['mobile_extra'] ) && '' !== $c['mobile_extra'] ? (int) $c['mobile_extra'] : null,
				'tablet'       => isset( $c['tablet'] ) ? (int) $c['tablet'] : 1024,
				'tablet_extra' => isset( $c['tablet_extra'] ) && '' !== $c['tablet_extra'] ? (int) $c['tablet_extra'] : null,
				'laptop'       => isset( $c['laptop'] ) && '' !== $c['laptop'] ? (int) $c['laptop'] : null,
				'widescreen'   => isset( $c['widescreen'] ) && '' !== $c['widescreen'] ? (int) $c['widescreen'] : null,
			];
		}

		return null;
	}

	/* ---------------- Manual zip upload (Elementor Pro / ProElements) ---------------- */

	public static function install_uploaded_plugin_zip( $tmp_path ) {
		self::ensure_upgrader_includes();

		$skin      = new Automatic_Upgrader_Skin();
		$upgrader  = new Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $tmp_path );

		if ( is_wp_error( $installed ) || ! $installed ) {
			return self::fail( 'Could not install the uploaded zip: ' . self::skin_failure_reason( $installed, $skin ) );
		}

		$plugin_file = $upgrader->plugin_info();
		if ( ! $plugin_file ) {
			return self::fail( 'Installed, but could not determine the plugin file to activate it automatically.' );
		}

		$activated = activate_plugin( $plugin_file );
		if ( is_wp_error( $activated ) ) {
			return self::fail( 'Installed but activation failed: ' . $activated->get_error_message() );
		}

		return self::ok( "Installed and activated {$plugin_file}." );
	}
}
