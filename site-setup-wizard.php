<?php
/**
 * Plugin Name: Site Setup Wizard
 * Description: One-time first-run wizard: install a theme, wipe default content, install a fixed plugin stack, and configure Elementor's global layout/breakpoints.
 * Version: 1.2.1
 * Author: Sharad Gupta
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSW_FILE', __FILE__ );
define( 'SSW_DIR', plugin_dir_path( __FILE__ ) );

require_once SSW_DIR . 'includes/class-ssw-steps.php';

add_action( 'admin_menu', function () {
	add_management_page(
		'Site Setup Wizard',
		'Site Setup Wizard',
		'manage_options',
		'site-setup-wizard',
		'ssw_render_page'
	);
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'tools_page_site-setup-wizard' !== $hook ) {
		return;
	}
	wp_enqueue_media();
} );

function ssw_unit_select( $id, $selected ) {
	$units = [ 'px', '%', 'em', 'rem', 'vw', 'custom' ];
	echo '<select id="' . esc_attr( $id ) . '">';
	foreach ( $units as $unit ) {
		printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $unit ), selected( $unit, $selected, false ) );
	}
	echo '</select>';
}

function ssw_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	if ( is_multisite() ) {
		wp_die( 'Site Setup Wizard is not supported on multisite: it wipes and reinstalls the whole plugins directory, which is shared network-wide, and that needs network-admin capabilities this page does not check for.' );
	}
	$nonce   = wp_create_nonce( 'ssw_nonce' );
	$current = SSW_Steps::get_current_kit_settings();
	$bp      = $current['breakpoints'] ?? [];
	$bp_mode = $current['breakpoint_mode'] ?? 'preset1';
	$cw      = $current['container_width'] ?? [];
	$pad     = $current['padding'] ?? [];
	$identity_mirror_active = SSW_Steps::is_identity_mirror_active();

	// Custom-breakpoint fields fall back to preset1's numbers when nothing is
	// saved yet, purely as sane starting points to edit from.
	$bp_defaults = SSW_Steps::BREAKPOINT_PRESETS['preset1'];
	foreach ( $bp_defaults as $key => $default ) {
		if ( ! isset( $bp[ $key ] ) || null === $bp[ $key ] ) {
			$bp[ $key ] = $default;
		}
	}
	?>
	<div class="wrap ssw-wrap">
		<h1><span class="dashicons dashicons-admin-generic"></span> Site Setup Wizard</h1>

		<div class="ssw-stepper">
			<div class="ssw-step" data-crumb="1"><span class="ssw-step-dot">1</span><span class="ssw-step-label">Theme</span></div>
			<div class="ssw-step" data-crumb="2"><span class="ssw-step-dot">2</span><span class="ssw-step-label">Wipe</span></div>
			<div class="ssw-step" data-crumb="3"><span class="ssw-step-dot">3</span><span class="ssw-step-label">Homepage</span></div>
			<div class="ssw-step" data-crumb="4"><span class="ssw-step-dot">4</span><span class="ssw-step-label">Plugins</span></div>
			<div class="ssw-step" data-crumb="5"><span class="ssw-step-dot">5</span><span class="ssw-step-label">WooCommerce</span></div>
			<div class="ssw-step" data-crumb="6"><span class="ssw-step-dot">6</span><span class="ssw-step-label">Elementor</span></div>
			<div class="ssw-step" data-crumb="7"><span class="ssw-step-dot">7</span><span class="ssw-step-label">Done</span></div>
		</div>

		<div id="ssw-bar-wrap" class="ssw-bar-wrap" style="display:none;">
			<div class="ssw-bar"><div id="ssw-bar-fill" class="ssw-bar-fill"></div></div>
			<p id="ssw-bar-label" class="ssw-bar-label"></p>
		</div>

		<div id="ssw-shortcode-box" class="ssw-hint-strong" style="display:none;">
			<span class="dashicons dashicons-cart"></span>
			<span>
				WooCommerce is active. Drop this shortcode into an Elementor Shortcode widget (e.g. in an Archive Products sidebar) to show the product category list:
				<code id="ssw-shortcode-text">[ssw_category_sidebar]</code>
				<button type="button" class="button button-small" id="ssw-copy-shortcode">Copy</button>
			</span>
		</div>

		<div id="ssw-log" class="ssw-log" aria-live="polite"></div>

		<div class="ssw-panel" data-panel="1">
			<h2><span class="dashicons dashicons-art"></span> Step 1 &mdash; Theme from Underscores.me</h2>
			<p class="ssw-field">
				<label for="ssw-theme-name">Theme name</label>
				<input type="text" id="ssw-theme-name" value="My Theme" />
			</p>
			<p class="ssw-nav"><button class="button button-primary button-hero" data-step="1"><span class="dashicons dashicons-download"></span> Generate theme &amp; continue</button></p>
		</div>

		<div class="ssw-panel" data-panel="2" style="display:none;">
			<h2><span class="dashicons dashicons-trash"></span> Step 2 &mdash; Wipe default content</h2>
			<p class="ssw-warning"><span class="dashicons dashicons-warning"></span>
				<span><strong>Warning:</strong> this deletes <em>every currently installed plugin</em> (except this wizard) and
				<em>every post</em>. It also deletes any page containing "hello world" in its title or content, and
				optionally the default "Sample Page". This cannot be undone.</span>
			</p>
			<label class="ssw-toggle">
				<input type="checkbox" id="ssw-delete-sample" checked /><span class="ssw-toggle-track"></span>
				<span class="ssw-toggle-text">Also delete the default "Sample Page"</span>
			</label>
			<label class="ssw-toggle">
				<input type="checkbox" id="ssw-confirm-wipe" /><span class="ssw-toggle-track"></span>
				<span class="ssw-toggle-text"><strong>Yes, I understand this will delete existing content</strong></span>
			</label>
			<p class="ssw-nav">
				<button class="button" data-back="1"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
				<button class="button button-primary button-hero" data-step="2" id="ssw-run-step2" disabled><span class="dashicons dashicons-trash"></span> Delete &amp; continue</button>
				<button class="button" id="ssw-skip-step2">Skip this step <span class="dashicons dashicons-arrow-right-alt2"></span></button>
			</p>
		</div>

		<div class="ssw-panel" data-panel="3" style="display:none;">
			<h2><span class="dashicons dashicons-admin-home"></span> Step 3 &mdash; Create &amp; set homepage</h2>
			<p class="ssw-field">
				<label for="ssw-home-title">Page title</label>
				<input type="text" id="ssw-home-title" value="Home" />
			</p>
			<p class="ssw-nav">
				<button class="button" data-back="2"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
				<button class="button button-primary button-hero" data-step="3"><span class="dashicons dashicons-plus-alt2"></span> Create &amp; continue</button>
			</p>
		</div>

		<div class="ssw-panel" data-panel="4" style="display:none;">
			<h2><span class="dashicons dashicons-admin-plugins"></span> Step 4 &mdash; Install plugin stack</h2>
			<p class="ssw-plugin-pills">
				<span class="ssw-pill">Elementor</span><span class="ssw-pill">WP Mail SMTP</span><span class="ssw-pill">Yoast SEO</span>
				<span class="ssw-pill">Duplicate Page</span><span class="ssw-pill">Secure Custom Fields</span><span class="ssw-pill">Permalink Manager</span>
				<span class="ssw-pill">Classic Editor</span>
			</p>

			<h3>Elementor Pro / ProElements (manual)</h3>
			<p class="ssw-hint">Not on wordpress.org &mdash; <a href="https://proelements.org/" target="_blank" rel="noopener">proelements.org</a> requires a licensed download. Upload the zip here (optional, either before or after continuing):</p>
			<p class="ssw-field-row">
				<input type="file" id="ssw-pro-zip" accept=".zip" />
				<button class="button" id="ssw-upload-pro"><span class="dashicons dashicons-upload"></span> Upload &amp; Install</button>
			</p>

			<h3>Site email (optional)</h3>
			<p class="ssw-hint">Sets WP Mail SMTP's From Email. A recognized test address also gets its Gmail OAuth credentials filled in automatically.</p>
			<p class="ssw-field">
				<label for="ssw-site-email">Site email</label>
				<input type="email" id="ssw-site-email" placeholder="you@example.com" />
			</p>

			<p class="ssw-nav">
				<button class="button" data-back="3"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
				<button class="button button-primary button-hero" data-step="4"><span class="dashicons dashicons-download"></span> Install plugins &amp; continue</button>
				<button class="button" id="ssw-retry-step4" style="display:none;"><span class="dashicons dashicons-update"></span> Retry failed</button>
			</p>
		</div>

		<div class="ssw-panel" data-panel="5" style="display:none;">
			<h2><span class="dashicons dashicons-cart"></span> Step 5 &mdash; Install WooCommerce?</h2>
			<div class="ssw-options ssw-options-2col">
				<label class="ssw-option-card">
					<input type="radio" name="ssw-woo" value="1" checked />
					<span><span class="ssw-option-title">Yes, install WooCommerce</span><span class="ssw-option-desc">Adds the store plugin and activates it.</span></span>
				</label>
				<label class="ssw-option-card">
					<input type="radio" name="ssw-woo" value="0" />
					<span><span class="ssw-option-title">No</span><span class="ssw-option-desc">Skip &mdash; this isn't a store site.</span></span>
				</label>
			</div>

			<div id="ssw-shop-icons">
				<h3>Shop view icons (optional)</h3>
				<p class="ssw-hint">Upload your own SVGs for the grid/list view toggle on the shop page, or leave blank to use the default icons.</p>
				<p class="ssw-field-row">
					<span>Grid icon</span>
					<input type="file" id="ssw-icon-grid" accept=".svg" />
					<button class="button" id="ssw-upload-icon-grid" data-type="grid"><span class="dashicons dashicons-upload"></span> Upload</button>
				</p>
				<p class="ssw-field-row">
					<span>List icon</span>
					<input type="file" id="ssw-icon-list" accept=".svg" />
					<button class="button" id="ssw-upload-icon-list" data-type="list"><span class="dashicons dashicons-upload"></span> Upload</button>
				</p>
			</div>

			<p class="ssw-nav">
				<button class="button" data-back="4"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
				<button class="button button-primary button-hero" data-step="5">Continue <span class="dashicons dashicons-arrow-right-alt2"></span></button>
			</p>
		</div>

		<div class="ssw-panel" data-panel="6" style="display:none;">
			<h2><span class="dashicons dashicons-admin-customizer"></span> Step 6 &mdash; Elementor site settings</h2>

			<?php if ( $current ) : ?>
				<p class="ssw-hint ssw-hint-strong"><span class="dashicons dashicons-info-outline"></span> Elementor already has settings saved from a previous run &mdash; the fields below are pre-filled with the current values instead of the defaults.</p>
			<?php endif; ?>

			<h3>Identity</h3>
			<label class="ssw-toggle">
				<input type="checkbox" id="ssw-use-identity-mirror" <?php checked( $identity_mirror_active ); ?> /><span class="ssw-toggle-track"></span>
				<span class="ssw-toggle-text">Use the <a href="https://github.com/Sharad3624/Identity-Mirror" target="_blank" rel="noopener">Identity Mirror</a> plugin for site identity</span>
			</label>
			<div id="ssw-native-identity" class="ssw-field-grid" style="margin-top:1em;<?php echo $identity_mirror_active ? ' display:none;' : ''; ?>">
				<p class="ssw-field"><label for="ssw-site-name">Site title</label><input type="text" id="ssw-site-name" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></p>
				<p class="ssw-field"><label for="ssw-site-description">Tagline</label><input type="text" id="ssw-site-description" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>" /></p>
				<p class="ssw-field">
					<label>Logo</label>
					<span class="ssw-field-row">
						<button class="button" id="ssw-pick-logo"><span class="dashicons dashicons-format-image"></span> Select logo</button>
						<span id="ssw-logo-preview"><?php
							$logo_id = get_theme_mod( 'custom_logo' );
							if ( $logo_id ) {
								echo wp_get_attachment_image( $logo_id, [ 40, 40 ], false, [ 'style' => 'height:40px;width:auto;vertical-align:middle' ] );
							}
						?></span>
					</span>
					<input type="hidden" id="ssw-logo-id" value="<?php echo esc_attr( $logo_id ? $logo_id : '' ); ?>" />
				</p>
			</div>

			<h3>Layout &amp; breakpoints</h3>
			<div class="ssw-options">
				<label class="ssw-option-card">
					<input type="radio" name="ssw-bp-mode" value="preset1" <?php checked( $bp_mode, 'preset1' ); ?> />
					<span><span class="ssw-option-title">Option 1 &mdash; 7 breakpoints, with Widescreen</span>
					<span class="ssw-option-desc">Widescreen &ge;1851 &middot; Desktop 1438&ndash;1850 &middot; Laptop 1201&ndash;1438 &middot; Tablet Landscape 1025&ndash;1200 &middot; Tablet Portrait 881&ndash;1024 &middot; Mobile Landscape 768&ndash;880 &middot; Mobile Portrait 0&ndash;767</span></span>
				</label>
				<label class="ssw-option-card">
					<input type="radio" name="ssw-bp-mode" value="preset2" <?php checked( $bp_mode, 'preset2' ); ?> />
					<span><span class="ssw-option-title">Option 2 &mdash; 6 breakpoints, no Widescreen</span>
					<span class="ssw-option-desc">Desktop &ge;1851 &middot; Laptop 1439&ndash;1850 &middot; Tablet Landscape 1200&ndash;1438 &middot; Tablet Portrait 1024&ndash;1199 &middot; Mobile Landscape 768&ndash;1023 &middot; Mobile Portrait 0&ndash;767</span></span>
				</label>
				<label class="ssw-option-card">
					<input type="radio" name="ssw-bp-mode" value="custom" <?php checked( $bp_mode, 'custom' ); ?> />
					<span><span class="ssw-option-title">Option 3 &mdash; Custom<?php echo 'custom' === $bp_mode ? ' (currently active)' : ''; ?></span>
					<span class="ssw-option-desc">Set every breakpoint value yourself below.</span></span>
				</label>
			</div>
			<div id="ssw-custom-bp" class="ssw-field-grid" style="display:<?php echo 'custom' === $bp_mode ? 'grid' : 'none'; ?>;">
				<p class="ssw-field"><label>Mobile Portrait max</label><input type="number" id="ssw-bp-mobile" value="<?php echo esc_attr( $bp['mobile'] ); ?>" /></p>
				<p class="ssw-field"><label>Mobile Landscape max</label><input type="number" id="ssw-bp-mobile_extra" value="<?php echo esc_attr( $bp['mobile_extra'] ); ?>" /></p>
				<p class="ssw-field"><label>Tablet Portrait max</label><input type="number" id="ssw-bp-tablet" value="<?php echo esc_attr( $bp['tablet'] ); ?>" /></p>
				<p class="ssw-field"><label>Tablet Landscape max</label><input type="number" id="ssw-bp-tablet_extra" value="<?php echo esc_attr( $bp['tablet_extra'] ); ?>" /></p>
				<p class="ssw-field"><label>Laptop max</label><input type="number" id="ssw-bp-laptop" value="<?php echo esc_attr( $bp['laptop'] ); ?>" /></p>
				<p class="ssw-field"><label>Widescreen min (blank = disabled)</label><input type="number" id="ssw-bp-widescreen" value="<?php echo esc_attr( $bp['widescreen'] ); ?>" /></p>
			</div>

			<h3>Container width</h3>
			<p class="ssw-hint">Default width of the content area per device. Leave the defaults or set your own per tier &mdash; any device not listed here (e.g. Tablet Portrait, Mobile Landscape) inherits the next wider tier's value.</p>
			<div class="ssw-field-grid">
				<?php foreach ( [ 'widescreen' => 'Widescreen (&ge;1851px)', 'desktop' => 'Desktop (1439&ndash;1850px)', 'laptop' => 'Laptop (1201&ndash;1438px)', 'tablet_extra' => 'Tablet Landscape down to 768px', 'mobile' => 'Mobile Portrait (0&ndash;767px)' ] as $tier => $label ) :
					$val = $cw[ $tier ] ?? SSW_Steps::CONTAINER_WIDTH_DEFAULTS[ $tier ];
				?>
					<p class="ssw-field"><label><?php echo $label; ?></label><span class="ssw-field-row"><input type="number" id="ssw-cw-<?php echo esc_attr( $tier ); ?>" value="<?php echo esc_attr( $val['size'] ); ?>" /><?php ssw_unit_select( "ssw-cw-{$tier}-unit", $val['unit'] ); ?></span></p>
				<?php endforeach; ?>
			</div>

			<h3>Default container padding</h3>
			<div class="ssw-field-grid">
				<p class="ssw-field"><label>Top</label><input type="number" id="ssw-pad-top" value="<?php echo esc_attr( $pad['top'] ?? 10 ); ?>" /></p>
				<p class="ssw-field"><label>Right</label><input type="number" id="ssw-pad-right" value="<?php echo esc_attr( $pad['right'] ?? 10 ); ?>" /></p>
				<p class="ssw-field"><label>Bottom</label><input type="number" id="ssw-pad-bottom" value="<?php echo esc_attr( $pad['bottom'] ?? 10 ); ?>" /></p>
				<p class="ssw-field"><label>Left</label><input type="number" id="ssw-pad-left" value="<?php echo esc_attr( $pad['left'] ?? 10 ); ?>" /></p>
				<p class="ssw-field"><label>Unit</label><?php ssw_unit_select( 'ssw-pad-unit', $pad['unit'] ?? 'px' ); ?></p>
			</div>

			<p class="ssw-hint ssw-hint-strong"><span class="dashicons dashicons-info-outline"></span> Default page layout will be set to Elementor Full Width, and blank Header/Footer Theme Builder templates will be created automatically.</p>

			<p class="ssw-nav">
				<button class="button" data-back="5"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
				<button class="button button-primary button-hero" data-step="6"><span class="dashicons dashicons-yes-alt"></span> Apply &amp; finish</button>
			</p>
		</div>

		<div class="ssw-panel" data-panel="7" style="display:none;">
			<h2><span class="dashicons dashicons-yes-alt ssw-done-icon"></span> Setup complete</h2>
			<p>Review the log above for a full run report &mdash; every step's result is listed there in order. Here's where the site stands right now:</p>
			<div id="ssw-summary"><p>Loading summary&hellip;</p></div>

			<p class="ssw-hint ssw-hint-strong"><span class="dashicons dashicons-email-alt"></span>
				<span><strong>Finish email delivery:</strong> Step 4 set WP Mail SMTP's From Email if you entered one. If that was the recognized demo test address, the Gmail mailer and OAuth credentials were also filled in automatically &mdash; go to <strong>Settings &rarr; WP Mail SMTP &rarr; Authorize</strong> and log into that Google account to finish connecting it. For any other address, pick a mailer (Gmail, Outlook, SMTP, etc.) under <strong>Settings &rarr; WP Mail SMTP</strong>, enter that provider's own credentials, and click <strong>Authorize</strong> or <strong>Save</strong> &mdash; that always needs your own credentials and, for OAuth mailers, a live login in your browser, so it can't be automated by the wizard.</span>
			</p>

			<p class="ssw-nav"><button class="button" data-back="6"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button></p>
		</div>
	</div>

	<style>
		.ssw-wrap { --ssw-accent: #2271b1; --ssw-accent-dark: #135e96; --ssw-green: #00a32a; --ssw-red: #d63638; --ssw-border: #dcdcde; --ssw-muted: #646970; --ssw-ink: #1d2327; }
		.ssw-wrap h1 { display: flex; align-items: center; gap: .4em; }
		.ssw-wrap h1 .dashicons { font-size: 26px; width: 26px; height: 26px; color: var(--ssw-accent); }
		.ssw-wrap h2 { margin-top: 0; display: flex; align-items: center; gap: .45em; font-size: 1.25em; }
		.ssw-wrap h2 .dashicons { color: var(--ssw-accent); }
		.ssw-wrap h3 { margin: 1.75em 0 .5em; font-size: 1em; }

		/* Stepper */
		.ssw-stepper { display: flex; margin: 1.75em 0; }
		.ssw-step { flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; }
		.ssw-step:not(:first-child)::before { content: ''; position: absolute; top: 15px; right: 50%; width: 100%; height: 2px; background: var(--ssw-border); z-index: 0; transition: background .3s ease; }
		.ssw-step.is-done:not(:first-child)::before,
		.ssw-step.is-current:not(:first-child)::before { background: var(--ssw-accent); }
		.ssw-step-dot { position: relative; z-index: 1; width: 32px; height: 32px; border-radius: 50%; background: #fff; border: 2px solid var(--ssw-border); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; color: var(--ssw-muted); transition: all .25s ease; }
		.ssw-step-dot .dashicons { font-size: 16px; width: 16px; height: 16px; }
		.ssw-step.is-current .ssw-step-dot { border-color: var(--ssw-accent); background: var(--ssw-accent); color: #fff; box-shadow: 0 0 0 4px rgba(34,113,177,.15); }
		.ssw-step.is-done .ssw-step-dot { border-color: var(--ssw-green); background: var(--ssw-green); color: #fff; }
		.ssw-step-label { font-size: 11.5px; margin-top: 6px; color: var(--ssw-muted); max-width: 80px; }
		.ssw-step.is-current .ssw-step-label { color: var(--ssw-ink); font-weight: 600; }

		/* Panels */
		.ssw-panel { background: #fff; border: 1px solid var(--ssw-border); border-radius: 10px; padding: 1.75em 2em; margin-top: 1.25em; max-width: 820px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
		.ssw-panel.is-active { animation: ssw-panel-in .3s ease; }
		@keyframes ssw-panel-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
		.ssw-nav { margin-top: 1.75em; display: flex; gap: .6em; flex-wrap: wrap; }

		/* Buttons: flex so an icon + label always center on the same line,
		   regardless of the dashicon glyph's own font metrics. line-height:1
		   matches WP core's own .wp-core-ui .button .dashicons rule - without
		   it the glyph's line box (inherited from the button's own line-height)
		   still pushes it a few px off vertical-center even inside a flex row. */
		.ssw-wrap .button { display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: .4em; }
		.ssw-wrap .button .dashicons { flex-shrink: 0; line-height: 1 !important; vertical-align: middle; }

		/* Fields */
		.ssw-field { margin: 0 0 1em; }
		.ssw-field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--ssw-muted); margin-bottom: .35em; }
		.ssw-field input[type=text], .ssw-field input[type=number] { width: 100%; max-width: 280px; }
		.ssw-field-row { display: inline-flex; align-items: center; gap: .5em; }
		.ssw-field-row input[type=number] { width: 90px; }
		.ssw-field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: .5em 1.5em; }
		.ssw-hint { color: var(--ssw-muted); font-size: 13px; }
		.ssw-hint-strong { background: #f0f6fc; border-left: 3px solid var(--ssw-accent); padding: .75em 1em; border-radius: 0 6px 6px 0; display: flex; gap: .5em; align-items: flex-start; }
		#ssw-shortcode-box { max-width: 820px; margin-bottom: 1em; }
		#ssw-shortcode-text { background: #fff; border: 1px solid var(--ssw-border); border-radius: 4px; padding: .2em .5em; margin: 0 .5em; }
		.ssw-summary-table { border-collapse: collapse; margin: 1em 0; }
		.ssw-summary-table th, .ssw-summary-table td { text-align: left; padding: .5em 1.5em .5em 0; border-bottom: 1px solid var(--ssw-border); }
		.ssw-summary-table th { color: var(--ssw-muted); font-weight: 600; font-size: 13px; }

		/* Warning */
		.ssw-warning { background: #fcf0f1; border-left: 4px solid var(--ssw-red); padding: .9em 1.1em; border-radius: 0 6px 6px 0; display: flex; gap: .6em; align-items: flex-start; margin-bottom: 1.25em; }
		.ssw-warning .dashicons { color: var(--ssw-red); flex-shrink: 0; margin-top: 2px; }

		/* Toggle switches */
		.ssw-toggle { display: flex; align-items: center; gap: .7em; cursor: pointer; user-select: none; margin-bottom: .85em; }
		.ssw-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
		.ssw-toggle-track { width: 38px; height: 22px; border-radius: 11px; background: var(--ssw-border); position: relative; flex-shrink: 0; transition: background .2s ease; }
		.ssw-toggle-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.3); transition: transform .2s ease; }
		.ssw-toggle input:checked + .ssw-toggle-track { background: var(--ssw-accent); }
		.ssw-toggle input:checked + .ssw-toggle-track::after { transform: translateX(16px); }
		.ssw-toggle input:focus-visible + .ssw-toggle-track { outline: 2px solid var(--ssw-accent); outline-offset: 2px; }
		.ssw-toggle-text { font-size: 13.5px; }

		/* Option cards */
		.ssw-options { display: grid; gap: .65em; margin-bottom: 1em; }
		.ssw-options-2col { grid-template-columns: 1fr 1fr; }
		.ssw-option-card { display: flex; gap: .75em; align-items: flex-start; border: 2px solid var(--ssw-border); border-radius: 8px; padding: .9em 1.1em; cursor: pointer; transition: border-color .15s ease, background .15s ease; background: #fff; }
		.ssw-option-card:hover { border-color: #8c8f94; }
		.ssw-option-card input { margin-top: 3px; }
		.ssw-option-card:has(input:checked) { border-color: var(--ssw-accent); background: #f0f6fc; }
		.ssw-option-title { display: block; font-weight: 600; margin-bottom: .2em; }
		.ssw-option-desc { display: block; font-size: 12px; color: var(--ssw-muted); line-height: 1.5; }

		/* Plugin pills */
		.ssw-plugin-pills { display: flex; flex-wrap: wrap; gap: .4em; }
		.ssw-pill { background: #f0f6fc; color: var(--ssw-accent-dark); border-radius: 999px; padding: .3em .9em; font-size: 12.5px; font-weight: 600; }

		/* Progress bar */
		.ssw-bar-wrap { margin: 1em 0; max-width: 820px; }
		.ssw-bar { height: 10px; border-radius: 5px; background: var(--ssw-border); overflow: hidden; }
		.ssw-bar-fill { height: 100%; width: 30%; background: var(--ssw-accent); border-radius: 5px; transition: width .3s ease; }
		.ssw-bar-wrap.is-indeterminate .ssw-bar-fill { width: 30%; animation: ssw-indeterminate 1.1s ease-in-out infinite; }
		.ssw-bar-wrap.is-done .ssw-bar-fill { width: 100%; background: var(--ssw-green); animation: none; }
		@keyframes ssw-indeterminate { 0% { margin-left: -30%; } 100% { margin-left: 100%; } }
		.ssw-bar-label { font-size: 12px; color: var(--ssw-muted); margin: .4em 0 0; }

		/* Log */
		.ssw-log { max-height: 220px; overflow-y: auto; background: #1d2327; color: #c3c4c7; font-family: Consolas, Monaco, monospace; font-size: 12px; line-height: 1.7; padding: 12px 14px; margin: 1em 0; border-radius: 8px; display: none; }
		.ssw-log.is-visible { display: block; animation: ssw-panel-in .25s ease; }
		.ssw-log .ok::before { content: '\2713  '; color: #7ad07a; }
		.ssw-log .err::before { content: '\2715  '; color: #ff7b72; }
		.ssw-log .ok { color: #7ad07a; }
		.ssw-log .err { color: #ff7b72; }

		.ssw-done-icon { color: var(--ssw-green) !important; font-size: 28px !important; width: 28px !important; height: 28px !important; }
	</style>

	<script>
	( function () {
		var ajaxurl = window.ajaxurl;
		var adminUrl = <?php echo wp_json_encode( admin_url() ); ?>;
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var fixedPlugins = <?php echo wp_json_encode( array_keys( SSW_Steps::FIXED_PLUGINS ) ); ?>;
		var log = document.getElementById( 'ssw-log' );
		var barWrap = document.getElementById( 'ssw-bar-wrap' );
		var barFill = document.getElementById( 'ssw-bar-fill' );
		var barLabel = document.getElementById( 'ssw-bar-label' );

		function barStart( label ) {
			barWrap.style.display = '';
			barWrap.classList.remove( 'is-done' );
			barWrap.classList.add( 'is-indeterminate' );
			barFill.style.width = '';
			barLabel.textContent = label;
		}

		function barSet( percent, label ) {
			barWrap.style.display = '';
			barWrap.classList.remove( 'is-indeterminate' );
			barFill.style.width = Math.round( percent ) + '%';
			barLabel.textContent = label;
		}

		function barDone( label ) {
			barWrap.classList.remove( 'is-indeterminate' );
			barWrap.classList.add( 'is-done' );
			barLabel.textContent = label;
			setTimeout( function () { barWrap.style.display = 'none'; }, 600 );
		}
		var panels = document.querySelectorAll( '.ssw-panel' );
		var steps = document.querySelectorAll( '.ssw-step' );

		function goTo( n ) {
			panels.forEach( function ( p ) {
				var active = p.getAttribute( 'data-panel' ) === String( n );
				p.style.display = active ? '' : 'none';
				p.classList.toggle( 'is-active', active );
			} );
			steps.forEach( function ( s ) {
				var i = parseInt( s.getAttribute( 'data-crumb' ), 10 );
				var dot = s.querySelector( '.ssw-step-dot' );
				s.classList.toggle( 'is-current', i === n );
				s.classList.toggle( 'is-done', i < n );
				dot.innerHTML = i < n ? '<span class="dashicons dashicons-yes"></span>' : i;
			} );
			window.scrollTo( { top: document.querySelector( '.ssw-stepper' ).offsetTop - 40, behavior: 'smooth' } );
			if ( 7 === n ) {
				loadSummary();
			}
		}

		function loadSummary() {
			var box = document.getElementById( 'ssw-summary' );
			box.innerHTML = '<p>Loading summary&hellip;</p>';
			var body = new FormData();
			body.append( 'action', 'ssw_get_summary' );
			body.append( 'nonce', nonce );
			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						box.innerHTML = '<p>Could not load the summary.</p>';
						return;
					}
					var d = json.data.details || {};
					var rows = [
						[ 'Site', d.site_name ],
						[ 'Theme', d.theme ],
						[ 'Active plugins', String( d.plugin_count ) ],
						[ 'WooCommerce', d.woocommerce ? 'Installed' : 'Not installed' ],
						[ 'Site identity', d.identity_mirror ? 'Identity Mirror' : 'Native fields' ],
						[ 'Breakpoints', d.breakpoint_mode ? d.breakpoint_mode : 'Not set' ],
						[ 'Homepage', d.front_page_title ? d.front_page_title : 'Not set' ]
					];
					var html = '<table class="ssw-summary-table">' + rows.map( function ( r ) {
						return '<tr><th>' + r[0] + '</th><td>' + r[1] + '</td></tr>';
					} ).join( '' ) + '</table>';

					html += '<p class="ssw-nav">';
					html += '<a class="button button-primary" href="' + d.site_url + '" target="_blank"><span class="dashicons dashicons-admin-site-alt3"></span> View site</a>';
					if ( d.front_page_id ) {
						html += '<a class="button" href="' + adminUrl + 'post.php?post=' + d.front_page_id + '&action=elementor" target="_blank"><span class="dashicons dashicons-edit"></span> Edit homepage in Elementor</a>';
					}
					html += '<a class="button" href="' + adminUrl + 'plugins.php"><span class="dashicons dashicons-admin-plugins"></span> Plugins</a>';
					if ( d.woocommerce ) {
						html += '<a class="button" href="' + adminUrl + 'admin.php?page=wc-settings"><span class="dashicons dashicons-cart"></span> WooCommerce settings</a>';
					}
					html += '</p>';

					box.innerHTML = html;
				} );
		}

		goTo( 1 );

		document.querySelectorAll( 'button[data-back]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				goTo( parseInt( btn.getAttribute( 'data-back' ), 10 ) );
			} );
		} );

		function write( text, cls ) {
			log.classList.add( 'is-visible' );
			var line = document.createElement( 'div' );
			if ( cls ) { line.className = cls; }
			line.textContent = text;
			log.appendChild( line );
			log.scrollTop = log.scrollHeight;
		}

		function call( action, data ) {
			data = data || {};
			data.action = action;
			data.nonce = nonce;
			var body = new FormData();
			Object.keys( data ).forEach( function ( k ) {
				var v = data[ k ];
				body.append( k, typeof v === 'object' ? JSON.stringify( v ) : v );
			} );
			return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					var payload = json.data || {};
					write( payload.message || JSON.stringify( payload ), json.success ? 'ok' : 'err' );
					return json;
				} )
				.catch( function ( e ) {
					write( 'request error: ' + e.message, 'err' );
				} );
		}

		document.getElementById( 'ssw-confirm-wipe' ).addEventListener( 'change', function ( e ) {
			document.getElementById( 'ssw-run-step2' ).disabled = ! e.target.checked;
		} );

		document.getElementById( 'ssw-skip-step2' ).addEventListener( 'click', function () {
			write( 'Skipped wiping default content.', 'ok' );
			goTo( 3 );
		} );

		document.getElementById( 'ssw-use-identity-mirror' ).addEventListener( 'change', function ( e ) {
			document.getElementById( 'ssw-native-identity' ).style.display = e.target.checked ? 'none' : '';
		} );

		Array.prototype.forEach.call( document.querySelectorAll( 'input[name="ssw-woo"]' ), function ( r ) {
			r.addEventListener( 'change', function () {
				document.getElementById( 'ssw-shop-icons' ).style.display = ( '1' === this.value ) ? '' : 'none';
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( 'input[name="ssw-bp-mode"]' ), function ( r ) {
			r.addEventListener( 'change', function () {
				document.getElementById( 'ssw-custom-bp' ).style.display = ( this.value === 'custom' && this.checked ) ? 'grid' : ( this.checked ? 'none' : document.getElementById( 'ssw-custom-bp' ).style.display );
			} );
		} );

		document.getElementById( 'ssw-copy-shortcode' ).addEventListener( 'click', function () {
			var text = document.getElementById( 'ssw-shortcode-text' ).textContent;
			var btn = this;
			navigator.clipboard.writeText( text ).then( function () {
				btn.textContent = 'Copied!';
				setTimeout( function () { btn.textContent = 'Copy'; }, 1500 );
			} );
		} );

		document.getElementById( 'ssw-pick-logo' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var frame = wp.media( { title: 'Select logo', multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				document.getElementById( 'ssw-logo-id' ).value = att.id;
				document.getElementById( 'ssw-logo-preview' ).innerHTML = '<img src="' + att.url + '" style="height:40px;vertical-align:middle" />';
			} );
			frame.open();
		} );

		document.getElementById( 'ssw-upload-pro' ).addEventListener( 'click', function () {
			var input = document.getElementById( 'ssw-pro-zip' );
			if ( ! input.files.length ) { write( 'choose a zip file first', 'err' ); return; }
			barStart( 'Uploading & installing ' + input.files[0].name + '…' );
			var body = new FormData();
			body.append( 'action', 'ssw_upload_plugin_zip' );
			body.append( 'nonce', nonce );
			body.append( 'plugin_zip', input.files[0] );
			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					var payload = json.data || {};
					write( payload.message, json.success ? 'ok' : 'err' );
					barDone( json.success ? 'Done' : 'Failed' );
				} );
		} );

		[ 'grid', 'list' ].forEach( function ( type ) {
			document.getElementById( 'ssw-upload-icon-' + type ).addEventListener( 'click', function () {
				var input = document.getElementById( 'ssw-icon-' + type );
				if ( ! input.files.length ) { write( 'choose an SVG file first', 'err' ); return; }
				barStart( 'Uploading ' + type + ' icon…' );
				var body = new FormData();
				body.append( 'action', 'ssw_upload_shop_icon' );
				body.append( 'nonce', nonce );
				body.append( 'type', type );
				body.append( 'icon', input.files[0] );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						var payload = json.data || {};
						write( payload.message, json.success ? 'ok' : 'err' );
						barDone( json.success ? 'Done' : 'Failed' );
					} );
			} );
		} );

		function runStep1( confirmOverwrite ) {
			var themeName = document.getElementById( 'ssw-theme-name' ).value;
			return call( 'ssw_step1', {
				theme_name: themeName,
				confirm_overwrite: confirmOverwrite ? 1 : 0
			} ).then( function ( json ) {
				var payload = json && json.data ? json.data : {};
				if ( json && ! json.success && payload.needs_confirmation ) {
					if ( window.confirm( payload.message + '\n\nOK = overwrite it. Cancel = keep the existing theme and continue.' ) ) {
						return runStep1( true );
					}
					return call( 'ssw_step1', { theme_name: themeName, keep_existing: 1 } );
				}
				return json;
			} );
		}

		function runStep3( forceNew ) {
			return call( 'ssw_step3', {
				title: document.getElementById( 'ssw-home-title' ).value,
				force_new: forceNew ? 1 : 0
			} ).then( function ( json ) {
				var payload = json && json.data ? json.data : {};
				if ( json && ! json.success && payload.needs_confirmation ) {
					if ( window.confirm( payload.message + '\n\nOK = keep it and continue. Cancel = create a new page instead.' ) ) {
						write( 'Keeping existing homepage.', 'ok' );
						return { success: true };
					}
					return runStep3( true );
				}
				return json;
			} );
		}

		var step4Failed = [];

		function installPluginSlugs( slugs ) {
			var total = slugs.length;
			var i = 0;
			var failed = [];

			function next() {
				if ( i >= total ) {
					return Promise.resolve( failed );
				}
				var slug = slugs[ i ];
				barSet( ( i / total ) * 100, 'Installing ' + slug + '… (' + ( i + 1 ) + '/' + total + ')' );
				return call( 'ssw_step4_plugin', { slug: slug } ).then( function ( json ) {
					if ( ! json || ! json.success ) {
						failed.push( slug );
					}
					i++;
					return next();
				} );
			}

			return next().then( function () { return failed; } );
		}

		function updateRetryButton() {
			var btn = document.getElementById( 'ssw-retry-step4' );
			if ( step4Failed.length ) {
				btn.style.display = '';
				btn.lastChild.textContent = ' Retry failed (' + step4Failed.length + ')';
			} else {
				btn.style.display = 'none';
			}
		}

		document.getElementById( 'ssw-retry-step4' ).addEventListener( 'click', function () {
			var btn = this;
			btn.disabled = true;
			barStart( 'Retrying ' + step4Failed.length + ' failed plugin(s)…' );
			installPluginSlugs( step4Failed ).then( function ( failed ) {
				step4Failed = failed;
				updateRetryButton();
				barDone( failed.length ? failed.length + ' still failing' : 'All retried plugins installed' );
				btn.disabled = false;
			} );
		} );

		function runStep4() {
			return installPluginSlugs( fixedPlugins ).then( function ( failed ) {
				step4Failed = failed;
				updateRetryButton();
				barDone( failed.length
					? ( fixedPlugins.length - failed.length ) + '/' + fixedPlugins.length + ' installed, ' + failed.length + ' failed'
					: 'Plugin stack installed (' + fixedPlugins.length + '/' + fixedPlugins.length + ')' );

				var email = document.getElementById( 'ssw-site-email' ).value.trim();
				if ( ! email ) {
					return { success: true };
				}
				return call( 'ssw_configure_mail', { email: email } ).then( function () {
					return { success: true };
				} );
			} );
		}

		document.querySelectorAll( 'button[data-step]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var step = parseInt( btn.getAttribute( 'data-step' ), 10 );
				btn.disabled = true;
				var advance = function ( json ) {
					btn.disabled = false;
					if ( ! barWrap.classList.contains( 'is-done' ) ) {
						barDone( json && json.success ? 'Done' : 'Failed' );
					}
					if ( json && json.success ) {
						goTo( step + 1 );
					}
				};

				if ( step === 1 ) {
					barStart( 'Generating theme from underscores.me & installing…' );
					runStep1( false ).then( advance );
				} else if ( step === 2 ) {
					barStart( 'Wiping plugins, posts & pages…' );
					call( 'ssw_step2', {
						delete_sample_page: document.getElementById( 'ssw-delete-sample' ).checked ? 1 : 0,
						confirmed: document.getElementById( 'ssw-confirm-wipe' ).checked ? 1 : 0
					} ).then( advance );
				} else if ( step === 3 ) {
					barStart( 'Creating homepage…' );
					runStep3( false ).then( advance );
				} else if ( step === 4 ) {
					runStep4().then( advance );
				} else if ( step === 5 ) {
					var woo = document.querySelector( 'input[name="ssw-woo"]:checked' ).value;
					barStart( '1' === woo ? 'Installing WooCommerce…' : 'Skipping WooCommerce…' );
					call( 'ssw_step5', { install: woo } ).then( function ( json ) {
						if ( json && json.success && '1' === woo ) {
							document.getElementById( 'ssw-shortcode-box' ).style.display = '';
						}
						advance( json );
					} );
				} else if ( step === 6 ) {
					barStart( 'Applying Elementor site settings…' );
					var mode = document.querySelector( 'input[name="ssw-bp-mode"]:checked' ).value;
					call( 'ssw_step6', {
						use_identity_mirror: document.getElementById( 'ssw-use-identity-mirror' ).checked ? 1 : 0,
						site_name: document.getElementById( 'ssw-site-name' ).value,
						site_description: document.getElementById( 'ssw-site-description' ).value,
						logo_id: document.getElementById( 'ssw-logo-id' ).value,
						breakpoint_mode: mode,
						custom_breakpoints: {
							mobile: document.getElementById( 'ssw-bp-mobile' ).value,
							mobile_extra: document.getElementById( 'ssw-bp-mobile_extra' ).value,
							tablet: document.getElementById( 'ssw-bp-tablet' ).value,
							tablet_extra: document.getElementById( 'ssw-bp-tablet_extra' ).value,
							laptop: document.getElementById( 'ssw-bp-laptop' ).value,
							widescreen: document.getElementById( 'ssw-bp-widescreen' ).value
						},
						padding: {
							top: document.getElementById( 'ssw-pad-top' ).value,
							right: document.getElementById( 'ssw-pad-right' ).value,
							bottom: document.getElementById( 'ssw-pad-bottom' ).value,
							left: document.getElementById( 'ssw-pad-left' ).value,
							unit: document.getElementById( 'ssw-pad-unit' ).value
						},
						container_width: {
							widescreen: { size: document.getElementById( 'ssw-cw-widescreen' ).value, unit: document.getElementById( 'ssw-cw-widescreen-unit' ).value },
							desktop: { size: document.getElementById( 'ssw-cw-desktop' ).value, unit: document.getElementById( 'ssw-cw-desktop-unit' ).value },
							laptop: { size: document.getElementById( 'ssw-cw-laptop' ).value, unit: document.getElementById( 'ssw-cw-laptop-unit' ).value },
							tablet_extra: { size: document.getElementById( 'ssw-cw-tablet_extra' ).value, unit: document.getElementById( 'ssw-cw-tablet_extra-unit' ).value },
							mobile: { size: document.getElementById( 'ssw-cw-mobile' ).value, unit: document.getElementById( 'ssw-cw-mobile-unit' ).value }
						}
					} ).then( advance );
				} else {
					btn.disabled = false;
				}
			} );
		} );
	} )();
	</script>
	<?php
}

/* ---------------- AJAX wiring ---------------- */

function ssw_check_auth() {
	if ( is_multisite() || ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ssw_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
	}
}

function ssw_respond( $result ) {
	if ( $result['ok'] ) {
		wp_send_json_success( $result );
	}
	wp_send_json_error( $result );
}

add_action( 'wp_ajax_ssw_step1', function () {
	ssw_check_auth();
	ssw_respond( SSW_Steps::install_theme(
		sanitize_text_field( wp_unslash( $_POST['theme_name'] ?? '' ) ),
		! empty( $_POST['confirm_overwrite'] ),
		! empty( $_POST['keep_existing'] )
	) );
} );

add_action( 'wp_ajax_ssw_step2', function () {
	ssw_check_auth();
	ssw_respond( SSW_Steps::wipe_content( ! empty( $_POST['delete_sample_page'] ), ! empty( $_POST['confirmed'] ) ) );
} );

add_action( 'wp_ajax_ssw_step3', function () {
	ssw_check_auth();
	ssw_respond( SSW_Steps::create_homepage(
		sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
		! empty( $_POST['force_new'] )
	) );
} );

add_action( 'wp_ajax_ssw_step4_plugin', function () {
	ssw_check_auth();
	$slug = sanitize_key( $_POST['slug'] ?? '' );
	if ( ! isset( SSW_Steps::FIXED_PLUGINS[ $slug ] ) ) {
		wp_send_json_error( [ 'message' => 'Unknown plugin slug.' ] );
	}
	ssw_respond( SSW_Steps::install_and_activate_wp_org_plugin( $slug ) );
} );

add_action( 'wp_ajax_ssw_step5', function () {
	ssw_check_auth();
	ssw_respond( SSW_Steps::woocommerce( ! empty( $_POST['install'] ) && '1' === $_POST['install'] ) );
} );

add_action( 'wp_ajax_ssw_step6', function () {
	ssw_check_auth();
	$args = [
		'use_identity_mirror' => ! empty( $_POST['use_identity_mirror'] ),
		'site_name'           => sanitize_text_field( wp_unslash( $_POST['site_name'] ?? '' ) ),
		'site_description'    => sanitize_text_field( wp_unslash( $_POST['site_description'] ?? '' ) ),
		'logo_id'             => absint( $_POST['logo_id'] ?? 0 ),
		'breakpoint_mode'     => sanitize_key( $_POST['breakpoint_mode'] ?? '' ),
		'custom_breakpoints'  => isset( $_POST['custom_breakpoints'] ) ? json_decode( wp_unslash( $_POST['custom_breakpoints'] ), true ) : [],
		'padding'             => isset( $_POST['padding'] ) ? json_decode( wp_unslash( $_POST['padding'] ), true ) : [],
		'container_width'     => isset( $_POST['container_width'] ) ? json_decode( wp_unslash( $_POST['container_width'] ), true ) : [],
	];
	ssw_respond( SSW_Steps::elementor_settings( $args ) );
} );

add_action( 'wp_ajax_ssw_upload_plugin_zip', function () {
	ssw_check_auth();

	if ( empty( $_FILES['plugin_zip'] ) || UPLOAD_ERR_OK !== $_FILES['plugin_zip']['error'] ) {
		wp_send_json_error( [ 'message' => 'Upload failed.' ] );
	}

	$tmp = $_FILES['plugin_zip']['tmp_name'];
	if ( ! is_uploaded_file( $tmp ) ) {
		wp_send_json_error( [ 'message' => 'Invalid upload.' ] );
	}

	ssw_respond( SSW_Steps::install_uploaded_plugin_zip( $tmp ) );
} );

add_action( 'wp_ajax_ssw_upload_shop_icon', function () {
	ssw_check_auth();

	if ( empty( $_FILES['icon'] ) || UPLOAD_ERR_OK !== $_FILES['icon']['error'] ) {
		wp_send_json_error( [ 'message' => 'Upload failed.' ] );
	}

	$tmp = $_FILES['icon']['tmp_name'];
	if ( ! is_uploaded_file( $tmp ) ) {
		wp_send_json_error( [ 'message' => 'Invalid upload.' ] );
	}

	$type = sanitize_key( $_POST['type'] ?? '' );
	ssw_respond( SSW_Steps::upload_shop_icon( $type, $tmp ) );
} );

add_action( 'wp_ajax_ssw_get_summary', function () {
	ssw_check_auth();
	ssw_respond( SSW_Steps::get_summary() );
} );

add_action( 'wp_ajax_ssw_configure_mail', function () {
	ssw_check_auth();
	ssw_respond( SSW_Steps::configure_wp_mail_smtp( wp_unslash( $_POST['email'] ?? '' ) ) );
} );
