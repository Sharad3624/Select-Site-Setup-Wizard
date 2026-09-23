<?php
/**
 * Shop archive integration: WooCommerce theme support, a grid/list view
 * toggle on the shop/category archives, and the [ssw_category_sidebar]
 * shortcode for use in an Elementor Archive Products sidebar.
 *
 * Installed by the Site Setup Wizard plugin after WooCommerce is installed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_category() ) ) {
		return;
	}

	$css = get_stylesheet_directory() . '/inc/shop-archive.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'ssw-shop-archive', get_stylesheet_directory_uri() . '/inc/shop-archive.css', [], filemtime( $css ) );
	}

	$js = get_stylesheet_directory() . '/inc/shop-archive.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'ssw-shop-archive', get_stylesheet_directory_uri() . '/inc/shop-archive.js', [], filemtime( $js ), true );
	}
}, 20 );

/*
 * Replace the default result-count + ordering row with a combined topbar
 * that also has a grid/list view toggle.
 */
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
add_action( 'woocommerce_before_shop_loop', 'ssw_shop_topbar', 20 );

function ssw_view_icon( $type, $fallback_dashicon ) {
	$path = get_stylesheet_directory() . "/inc/icon-{$type}.svg";
	if ( file_exists( $path ) ) {
		printf( '<img src="%s" alt="" class="ssw-view-icon" />', esc_url( get_stylesheet_directory_uri() . "/inc/icon-{$type}.svg" ) );
		return;
	}
	printf( '<span class="dashicons %s"></span>', esc_attr( $fallback_dashicon ) );
}

function ssw_shop_topbar() {
	if ( ! function_exists( 'woocommerce_catalog_ordering' ) ) {
		return;
	}
	?>
	<div class="ssw-shop-topbar">
		<div class="ssw-shop-topbar-left">
			<div class="ssw-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Product view', 'ssw' ); ?>">
				<button type="button" class="ssw-view-btn active" data-view="grid" aria-label="<?php esc_attr_e( 'Grid view', 'ssw' ); ?>">
					<?php ssw_view_icon( 'grid', 'dashicons-grid-view' ); ?>
				</button>
				<button type="button" class="ssw-view-btn" data-view="list" aria-label="<?php esc_attr_e( 'List view', 'ssw' ); ?>">
					<?php ssw_view_icon( 'list', 'dashicons-menu' ); ?>
				</button>
			</div>
			<div class="ssw-result-count">
				<?php woocommerce_result_count(); ?>
			</div>
		</div>
		<div class="ssw-shop-topbar-right">
			<?php woocommerce_catalog_ordering(); ?>
		</div>
	</div>
	<?php
}

/*
 * [ssw_category_sidebar] - nested product-category navigation for the shop
 * page or a category archive. Drop it into an Elementor Shortcode widget in
 * the sidebar of an Archive Products template.
 */
add_shortcode( 'ssw_category_sidebar', 'ssw_category_sidebar_shortcode' );

function ssw_category_sidebar_structure() {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return [];
	}

	$current_term = get_queried_object();
	$is_category  = $current_term && isset( $current_term->taxonomy ) && 'product_cat' === $current_term->taxonomy;

	if ( ! $is_category ) {
		if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
			return [];
		}

		$top_level = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false ] );
		if ( is_wp_error( $top_level ) || empty( $top_level ) ) {
			return [];
		}

		$structure = [];
		foreach ( $top_level as $category ) {
			$children    = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $category->term_id, 'hide_empty' => false ] );
			$structure[] = [
				'category'   => $category,
				'is_current' => false,
				'children'   => is_wp_error( $children ) ? [] : $children,
			];
		}

		return [ 'current' => null, 'structure' => $structure ];
	}

	$current_id = $current_term->term_id;
	$parent_id  = $current_term->parent;

	if ( 0 === $parent_id ) {
		$children = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $current_id, 'hide_empty' => false ] );

		return [
			'current'   => $current_term,
			'structure' => [
				[
					'category'   => $current_term,
					'is_current' => true,
					'children'   => is_wp_error( $children ) ? [] : $children,
				],
			],
		];
	}

	$siblings = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $parent_id, 'hide_empty' => false ] );
	if ( is_wp_error( $siblings ) || empty( $siblings ) ) {
		return [];
	}

	$structure = [];
	foreach ( $siblings as $sibling ) {
		$children    = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $sibling->term_id, 'hide_empty' => false ] );
		$structure[] = [
			'category'   => $sibling,
			'is_current' => ( $sibling->term_id === $current_id ),
			'children'   => is_wp_error( $children ) ? [] : $children,
		];
	}

	return [ 'current' => $current_term, 'structure' => $structure ];
}

function ssw_category_sidebar_shortcode() {
	$data = ssw_category_sidebar_structure();
	if ( empty( $data ) ) {
		return '';
	}

	$current_id = ! empty( $data['current'] ) ? (int) $data['current']->term_id : 0;

	$output  = '<h3 class="ssw-sidebar-title">' . esc_html__( 'Product Categories', 'ssw' ) . '</h3>';
	$output .= '<div class="ssw-category-sidebar">';

	foreach ( $data['structure'] as $item ) {
		$category = $item['category'];
		$link     = get_term_link( $category );
		if ( is_wp_error( $link ) ) {
			continue;
		}

		$output .= '<div class="ssw-category-group' . ( $item['is_current'] ? ' is-current' : '' ) . '">';
		$output .= '<a href="' . esc_url( $link ) . '" class="ssw-parent-category">';
		$output .= '<span class="ssw-category-dot" aria-hidden="true"></span>' . esc_html( $category->name );
		$output .= '</a>';

		if ( ! empty( $item['children'] ) ) {
			$output .= '<ul class="ssw-child-categories">';
			foreach ( $item['children'] as $child ) {
				$child_link = get_term_link( $child );
				if ( is_wp_error( $child_link ) ) {
					continue;
				}
				$active  = ( (int) $child->term_id === $current_id ) ? ' class="is-active"' : '';
				$output .= '<li' . $active . '><a href="' . esc_url( $child_link ) . '"><span class="ssw-category-dot" aria-hidden="true"></span>' . esc_html( $child->name ) . '</a></li>';
			}
			$output .= '</ul>';
		}

		$output .= '</div>';
	}

	$output .= '</div>';

	return $output;
}
