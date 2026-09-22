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

	public static function install_theme( $theme_name ) {
		self::ensure_upgrader_includes();

		$theme_name = $theme_name ? sanitize_text_field( $theme_name ) : 'My Underscores Theme';
		$slug       = sanitize_title( $theme_name );
		$package    = self::fetch_underscores_zip( $theme_name, $slug );
		$fallback   = false;

		if ( is_wp_error( $package ) ) {
			$fallback = true;
			$package  = 'https://github.com/Automattic/_s/archive/refs/heads/master.zip';
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package );

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
				'Removed %d plugin(s), %d post(s), and %d page(s) (%s).',
				count( $to_remove ),
				count( $posts ),
				count( $deleted_pages ),
				$deleted_pages ? implode( ', ', $deleted_pages ) : 'none matched'
			),
			[ 'removed_plugins' => $to_remove, 'deleted_pages' => $deleted_pages ]
		);
	}

	/* ---------------- Step 3: homepage ---------------- */

	public static function create_homepage( $title ) {
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

		if ( is_plugin_active_for_slug( $slug ) ) {
			return [ 'ok' => true, 'message' => "{$slug} already active." ];
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

	/* ---------------- Step 5: WooCommerce ---------------- */

	public static function woocommerce( $install ) {
		if ( ! $install ) {
			return self::ok( 'Skipped WooCommerce.' );
		}
		$result = self::install_and_activate_wp_org_plugin( 'woocommerce' );
		return $result['ok'] ? self::ok( $result['message'] ) : self::fail( $result['message'] );
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
			$unit = ! empty( $p['unit'] ) ? sanitize_key( $p['unit'] ) : 'px';
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
			$unit = ! empty( $input[ $tier ]['unit'] )
				? sanitize_key( $input[ $tier ]['unit'] )
				: $default['unit'];

			$result[ $tier ] = [ 'size' => $size, 'unit' => $unit ];
		}

		return $result;
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

if ( ! function_exists( 'is_plugin_active_for_slug' ) ) {
	function is_plugin_active_for_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) && is_plugin_active( $file ) ) {
				return true;
			}
		}
		return false;
	}
}
