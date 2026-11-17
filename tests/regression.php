<?php
/**
 * Disposable WordPress only: define SSW_TEST_SITE as true in wp-config.php.
 * Run with WP-CLI: wp eval-file tests/regression.php pages|without-pro|with-pro|plugins
 * Requires this plugin and Elementor active; activate ProElements only for with-pro.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
if ( ! defined( 'SSW_TEST_SITE' ) || ! SSW_TEST_SITE ) {
	throw new RuntimeException( 'Requires a disposable site with SSW_TEST_SITE enabled.' );
}

function ssw_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$mode = $args[0] ?? '';
wp_set_current_user( 1 );

if ( 'pages' === $mode ) {
	$titles = [ 'Home', 'Thank You', '404', 'Terms and Conditions', 'Privacy Policy', 'Refund and Return Policy' ];
	foreach ( $titles as $title ) {
		foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'title' => $title, 'numberposts' => -1 ] ) as $page ) {
			wp_delete_post( $page->ID, true );
		}
	}
	update_option( 'show_on_front', 'posts' );
	$result = SSW_Steps::create_homepage( 'Home' );
	ssw_assert( $result['ok'] && 6 === count( $result['details']['created_page_ids'] ), 'Creates six standard pages.' );
	foreach ( $result['details']['pages'] as $title => $id ) {
		$page = get_post( $id );
		ssw_assert( 'page' === $page->post_type && 'publish' === $page->post_status && '' === $page->post_content, $title . ' must be an empty published Page.' );
	}
	$home_id = $result['details']['page_id'];
	ssw_assert( $home_id === (int) get_option( 'page_on_front' ) && 'page' === get_option( 'show_on_front' ), 'Static homepage is linked.' );
	ssw_assert( $result['details']['pages']['Privacy Policy'] === (int) get_option( 'wp_page_for_privacy_policy' ), 'Privacy page is linked.' );
	$repeat = SSW_Steps::create_homepage( 'Home' );
	ssw_assert( ! $repeat['ok'] && $repeat['needs_confirmation'], 'Existing homepage still requires confirmation.' );
	$repeat = SSW_Steps::create_homepage( 'Home', true );
	ssw_assert( $repeat['ok'] && ! $repeat['details']['created_page_ids'] && $home_id === $repeat['details']['page_id'], 'Confirmed rerun reuses every title.' );
	ssw_assert( 6 === substr_count( $repeat['message'], 'already existed' ), 'Reports every existing page.' );
	wp_delete_post( $result['details']['pages']['Thank You'], true );
	$repeat = SSW_Steps::create_homepage( 'Another Home', false, true );
	ssw_assert( $repeat['ok'] && 1 === count( $repeat['details']['created_page_ids'] ) && $home_id === (int) get_option( 'page_on_front' ), 'Keep-homepage path creates missing pages without replacing the homepage.' );
	ssw_assert( ! get_posts( [ 'post_type' => 'page', 'title' => 'Another Home' ] ), 'Keep-homepage path does not create an unused homepage.' );
	$replacement = SSW_Steps::create_homepage( 'Another Home', true );
	ssw_assert( $replacement['ok'] && 1 === count( $replacement['details']['created_page_ids'] ) && $replacement['details']['page_id'] !== $home_id, 'Replacement homepage supports a new title.' );
	$replacement = SSW_Steps::create_homepage( 'Home', true );
	ssw_assert( $replacement['ok'] && $home_id === $replacement['details']['page_id'], 'Switching back reuses Home.' );
} elseif ( in_array( $mode, [ 'without-pro', 'with-pro' ], true ) ) {
	$with_pro = 'with-pro' === $mode;
	ssw_assert( $with_pro === class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ), 'ProElements activation must match the test mode.' );
	$result = SSW_Steps::elementor_settings( [ 'site_name' => 'Regression Site', 'breakpoint_mode' => 'preset1' ] );
	ssw_assert( $result['ok'], $result['message'] );
	$templates = $result['details']['templates'];
	ssw_assert( 2 === count( $templates ), 'Returns exactly two template IDs.' );
	foreach ( $templates as $type => $id ) {
		ssw_assert( 'elementor_library' === get_post_type( $id ), 'Templates never belong to Pages.' );
		ssw_assert( $type === get_post_meta( $id, '_elementor_template_type', true ) && has_term( $type, 'elementor_library_type', $id ), 'Document meta and taxonomy match the location.' );
		if ( $with_pro ) {
			$document = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_document( $id );
			ssw_assert( $document && $type === $document->get_location(), 'ProElements recognizes the Theme Builder document/location.' );
			ssw_assert( [ 'include/general' ] === get_post_meta( $id, '_elementor_conditions', true ), 'Entire Site condition is saved.' );
			$cache = get_option( 'elementor_pro_theme_builder_conditions', [] );
			ssw_assert( isset( $cache[ $type ][ $id ] ) && [ 'include/general' ] === $cache[ $type ][ $id ], 'Conditions cache includes the document at its location.' );
		} else {
			ssw_assert( ! get_post_meta( $id, '_elementor_conditions', true ), 'No pretend site-wide wiring without ProElements.' );
			ssw_assert( false !== strpos( $result['message'], 'saved library templates only' ), 'Missing ProElements is an explicit successful skip.' );
		}
		// Simulate the legacy missing document-type metadata without changing content.
		delete_post_meta( $id, '_elementor_template_type' );
	}
	$repeat = SSW_Steps::elementor_settings( [] );
	ssw_assert( $repeat['ok'] && $templates === $repeat['details']['templates'], 'Rerun repairs legacy templates without duplicates.' );
	foreach ( $templates as $type => $id ) {
		ssw_assert( $type === get_post_meta( $id, '_elementor_template_type', true ), 'Legacy template type is repaired.' );
		ssw_assert( 1 === count( get_posts( [ 'post_type' => 'elementor_library', 'title' => ucfirst( $type ), 'numberposts' => -1 ] ) ), 'Exactly one document per template title.' );
	}
} elseif ( 'plugins' === $mode ) {
	ssw_assert( ! class_exists( '\ElementorPro\Plugin' ), 'Plugin-stack test runs without ProElements.' );
	foreach ( array_keys( SSW_Steps::FIXED_PLUGINS ) as $slug ) {
		$result = SSW_Steps::install_and_activate_wp_org_plugin( $slug );
		ssw_assert( $result['ok'], $result['message'] );
		ssw_assert( isset( $result['details'] ), 'Installer preserves result contract.' );
		WP_CLI::log( $result['message'] );
	}
	$noise = function ( $result ) {
		echo 'Injected third-party activation output';
		trigger_error( 'Injected third-party notice', E_USER_NOTICE );
		return new WP_Error( 'offline', 'Test offline lookup' );
	};
	add_filter( 'plugins_api', $noise );
	ob_start();
	$result = SSW_Steps::install_and_activate_wp_org_plugin( 'classic-editor' );
	$output = ob_get_clean();
	remove_filter( 'plugins_api', $noise );
	ssw_assert( $result['ok'] && '' === $output, 'Third-party output/notice cannot corrupt AJAX JSON; installed plugin still activates offline.' );
	$fault = function () { throw new RuntimeException( 'Injected activation failure' ); };
	add_filter( 'plugins_api', $fault );
	$result = SSW_Steps::install_and_activate_wp_org_plugin( 'classic-editor' );
	remove_filter( 'plugins_api', $fault );
	ssw_assert( ! $result['ok'] && false !== strpos( $result['message'], 'classic-editor:' ), 'PHP errors become failures for that plugin.' );
	$result = SSW_Steps::install_and_activate_wp_org_plugin( 'duplicate-page' );
	ssw_assert( $result['ok'], 'A previous plugin failure does not affect the next plugin.' );
	ssw_assert( SSW_Steps::woocommerce( false )['ok'], 'WooCommerce can be skipped without ProElements.' );
} else {
	throw new RuntimeException( 'Choose pages, without-pro, with-pro, or plugins.' );
}

WP_CLI::success( $mode . ' regression checks passed.' );
