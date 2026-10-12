<?php
/**
 * Social images settings screen.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\SocialCard\CardStore;
use PressQueue\SocialCard\Catalog;
use PressQueue\SocialCard\LinkPreview;
use PressQueue\SocialCard\Renderer;
use PressQueue\SocialCard\Settings;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\ValidationException;

/**
 * Lets site owners pick the look of the generated social images.
 */
final class SocialCardsController {
	public const SLUG = 'pressqueue-social';

	private const PREVIEW_ACTION = 'pressqueue_social_preview';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_pressqueue_save_social_cards', array( self::class, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::PREVIEW_ACTION, array( self::class, 'handle_preview' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Return the URL of a card preview image.
	 *
	 * @param array<string,scalar> $args Query arguments, such as post or title.
	 * @return string
	 */
	public static function preview_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'action'   => self::PREVIEW_ACTION,
					'_wpnonce' => wp_create_nonce( self::PREVIEW_ACTION ),
				),
				$args
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Enqueue the screen's assets.
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page || ! defined( 'PRESSQUEUE_FILE' ) ) {
			return;
		}

		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'pressqueue-social', plugins_url( 'assets/admin/social.css', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/social.css' ) );
		wp_enqueue_script( 'pressqueue-social', plugins_url( 'assets/admin/social.js', PRESSQUEUE_FILE ), array( 'wp-i18n' ), Ui::asset_version( 'assets/admin/social.js' ), true );
		wp_set_script_translations( 'pressqueue-social', 'pressqueue' );
		wp_add_inline_script(
			'pressqueue-social',
			'window.pressqueueSocial = ' . wp_json_encode(
				array(
					'previewUrl' => self::preview_url(),
				)
			) . ';',
			'before'
		);

		// Show each font in its own face on the font picker.
		$css = '';
		foreach ( Catalog::fonts() as $id => $font ) {
			$css .= '@font-face{font-family:"pq-card-' . $id . '";src:url("' . esc_url_raw( plugins_url( 'assets/social/fonts/' . $font['file'], PRESSQUEUE_FILE ) ) . '") format("truetype");font-display:swap;}';
		}
		wp_add_inline_style( 'pressqueue-social', $css );
	}

	/**
	 * Render the screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PressQueue settings.', 'pressqueue' ) );
		}

		$settings = Settings::read();
		$samples  = self::sample_titles();

		Ui::page_start( __( 'Settings', 'pressqueue' ) );
		SettingsController::render_tabs( 'social' );
		self::render_notice();

		if ( ! Renderer::is_supported() ) {
			echo '<div class="pq-callout pq-callout--bad"><p><strong>' . esc_html__( 'This server cannot draw images.', 'pressqueue' ) . '</strong> ' . esc_html__( 'Social images need the PHP GD extension with FreeType. Ask your host to turn it on. Until then, posts are shared without an image.', 'pressqueue' ) . '</p></div>';
		}

		Ui::form_start( 'pressqueue_save_social_cards', 'pressqueue_save_social_cards', array(), array( 'class' => 'pq-social' ) );

		echo '<div class="pq-social-layout">';

		// Preview column.
		echo '<div class="pq-social-preview">';
		echo '<div class="pq-social-frame">';
		echo '<img src="' . esc_url( self::preview_url( array( 'title' => $samples[0] ) ) ) . '" width="1200" height="630" alt="' . esc_attr__( 'Preview of the social image', 'pressqueue' ) . '" data-pq-social-image />';
		echo '<span class="pq-social-loading" data-pq-social-loading hidden>' . esc_html__( 'Updating', 'pressqueue' ) . '</span>';
		echo '</div>';
		echo '<label class="pq-label" for="pq-social-sample">' . esc_html__( 'Preview with', 'pressqueue' ) . '</label>';
		echo '<select id="pq-social-sample" class="pq-field" data-pq-social-sample>';
		foreach ( $samples as $index => $title ) {
			echo '<option value="' . esc_attr( $title ) . '"' . ( 0 === $index ? ' selected' : '' ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '<option value="__own">' . esc_html__( 'My own text', 'pressqueue' ) . '</option>';
		echo '</select>';
		echo '<input type="text" class="pq-field" data-pq-social-own hidden maxlength="200" aria-label="' . esc_attr__( 'Preview text', 'pressqueue' ) . '" placeholder="' . esc_attr__( 'Type a title to try', 'pressqueue' ) . '" />';
		echo '<p class="pq-help">' . esc_html__( 'Each post gets its own image with its title. The image is made once, the first time it is needed, and made again when the title or style changes.', 'pressqueue' ) . '</p>';
		echo '<div><button type="submit" class="button button-primary">' . esc_html__( 'Save style', 'pressqueue' ) . '</button></div>';
		echo '</div>';

		// Controls column.
		echo '<div class="pq-stack pq-social-controls">';

		self::render_backgrounds( $settings );
		self::render_type( $settings );
		self::render_link_preview( $settings );
		self::render_usage();

		echo '<div><button type="submit" class="button button-primary">' . esc_html__( 'Save style', 'pressqueue' ) . '</button></div>';
		echo '</div>';

		echo '</div>';
		Ui::form_end();
		Ui::page_end();
	}

	/**
	 * Render the background gallery.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function render_backgrounds( array $settings ): void {
		$groups = array(
			'Gradients' => __( 'Gradients', 'pressqueue' ),
			'Textures'  => __( 'Paper and texture', 'pressqueue' ),
			'Shapes'    => __( 'Shapes', 'pressqueue' ),
			'Photo'     => __( 'Photos', 'pressqueue' ),
			'Tech'      => __( 'Tech', 'pressqueue' ),
		);

		$by_group = array();
		foreach ( Catalog::backgrounds() as $background ) {
			$by_group[ $background['group'] ][] = $background;
		}

		echo '<fieldset class="pq-card"><div class="pq-section">';
		echo '<legend class="pq-section-title"><h2>' . esc_html__( 'Background', 'pressqueue' ) . '</h2></legend>';

		foreach ( $by_group as $group => $backgrounds ) {
			echo '<h3 class="pq-social-group">' . esc_html( $groups[ $group ] ?? $group ) . '</h3>';
			echo '<div class="pq-social-grid">';
			foreach ( $backgrounds as $background ) {
				$id = 'pq-bg-' . $background['id'];
				echo '<label class="pq-social-tile" for="' . esc_attr( $id ) . '">';
				echo '<input type="radio" id="' . esc_attr( $id ) . '" name="background" value="' . esc_attr( $background['id'] ) . '" ' . checked( $settings['background'], $background['id'], false ) . ' />';
				echo '<img src="' . esc_url( Catalog::background_url( $background['id'], true ) ) . '" width="360" height="189" alt="" loading="lazy" />';
				echo '<span class="pq-social-tile-name">' . esc_html( $background['name'] ) . '</span>';
				echo '</label>';
			}
			echo '</div>';
		}

		$custom_id  = (int) $settings['custom_image'];
		$custom_url = $custom_id > 0 ? wp_get_attachment_image_url( $custom_id, 'medium' ) : '';
		echo '<h3 class="pq-social-group">' . esc_html__( 'Your own', 'pressqueue' ) . '</h3>';
		echo '<div class="pq-social-grid">';
		echo '<label class="pq-social-tile pq-social-tile--own" for="pq-bg-custom">';
		echo '<input type="radio" id="pq-bg-custom" name="background" value="custom" ' . checked( $settings['background'], 'custom', false ) . ( $custom_id > 0 ? '' : ' disabled' ) . ' data-pq-social-custom-radio />';
		echo '<img src="' . esc_url( is_string( $custom_url ) ? $custom_url : '' ) . '" width="360" height="189" alt="" data-pq-social-custom-thumb' . ( is_string( $custom_url ) && '' !== $custom_url ? '' : ' hidden' ) . ' />';
		echo '<span class="pq-social-tile-name">' . esc_html__( 'Your image', 'pressqueue' ) . '</span>';
		echo '</label>';
		echo '<div class="pq-social-own-actions">';
		echo '<input type="hidden" name="custom_image" value="' . esc_attr( (string) $custom_id ) . '" data-pq-social-custom-id />';
		if ( current_user_can( 'upload_files' ) ) {
			echo '<button type="button" class="button" data-pq-social-upload>' . esc_html( $custom_id > 0 ? __( 'Choose another image', 'pressqueue' ) : __( 'Choose an image', 'pressqueue' ) ) . '</button>';
		}
		echo '<p class="pq-help">' . esc_html__( 'Use a wide image, at least 1200 by 630 pixels. It is cropped from the middle to fit.', 'pressqueue' ) . '</p>';
		echo '</div>';
		echo '</div>';

		echo '</div></fieldset>';
	}

	/**
	 * Render font, color and layout choices.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function render_type( array $settings ): void {
		echo '<div class="pq-card"><div class="pq-section">';
		echo '<fieldset><legend class="pq-section-title"><h2>' . esc_html__( 'Font', 'pressqueue' ) . '</h2></legend>';
		echo '<div class="pq-social-fonts">';
		foreach ( Catalog::fonts() as $id => $font ) {
			echo '<label class="pq-social-font"><input type="radio" name="font" value="' . esc_attr( $id ) . '" ' . checked( $settings['font'], $id, false ) . ' />';
			echo '<span class="pq-social-font-sample" style="font-family:&quot;pq-card-' . esc_attr( $id ) . '&quot;,sans-serif" aria-hidden="true">Aa</span>';
			echo '<span class="pq-social-font-name">' . esc_html( $font['name'] ) . '</span></label>';
		}
		echo '</div></fieldset>';
		echo '</div>';

		echo '<div class="pq-section pq-social-options">';
		echo '<fieldset><legend class="pq-label">' . esc_html__( 'Text color', 'pressqueue' ) . '</legend><div class="pq-social-segments">';
		$colors = array(
			'auto'  => __( 'Automatic', 'pressqueue' ),
			'light' => __( 'White', 'pressqueue' ),
			'dark'  => __( 'Black', 'pressqueue' ),
		);
		foreach ( $colors as $value => $label ) {
			echo '<label><input type="radio" name="text" value="' . esc_attr( $value ) . '" ' . checked( $settings['text'], $value, false ) . ' /><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div></fieldset>';

		echo '<fieldset><legend class="pq-label">' . esc_html__( 'Title position', 'pressqueue' ) . '</legend><div class="pq-social-segments">';
		$alignments = array(
			'left'   => __( 'Left', 'pressqueue' ),
			'center' => __( 'Centered', 'pressqueue' ),
		);
		foreach ( $alignments as $value => $label ) {
			echo '<label><input type="radio" name="align" value="' . esc_attr( $value ) . '" ' . checked( $settings['align'], $value, false ) . ' /><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div></fieldset>';
		echo '</div>';

		$default_label = Settings::site_label(
			array_merge(
				$settings,
				array(
					'site_label' => '',
					'show_site'  => true,
				)
			)
		);
		echo '<div class="pq-section">';
		echo '<label class="pq-social-check"><input type="checkbox" name="show_site" value="1" ' . checked( (bool) $settings['show_site'], true, false ) . ' data-pq-social-show-site /> <span>' . esc_html__( 'Show the site name under the title', 'pressqueue' ) . '</span></label>';
		echo '<div class="pq-social-site-label" data-pq-social-site-label' . ( $settings['show_site'] ? '' : ' hidden' ) . '>';
		echo '<label class="pq-label" for="pq-social-site">' . esc_html__( 'Site name', 'pressqueue' ) . '</label>';
		echo '<input type="text" id="pq-social-site" class="pq-field pq-field--narrow" name="site_label" maxlength="60" value="' . esc_attr( (string) $settings['site_label'] ) . '" placeholder="' . esc_attr( $default_label ) . '" />';
		echo '<p class="pq-help">' . esc_html__( 'Leave empty to use your domain.', 'pressqueue' ) . '</p>';
		echo '</div>';
		echo '</div></div>';
	}

	/**
	 * Render link preview choices.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function render_link_preview( array $settings ): void {
		$plugin = LinkPreview::seo_plugin();
		$name   = LinkPreview::seo_plugin_name( $plugin );

		echo '<fieldset class="pq-card"><div class="pq-section">';
		echo '<legend class="pq-section-title"><h2>' . esc_html__( 'Link previews', 'pressqueue' ) . '</h2></legend>';
		echo '<p class="pq-sub">' . esc_html__( 'When someone shares a link to your post, social networks show the picture from the page itself. Use the social image there too so it shows up every time.', 'pressqueue' ) . '</p>';

		$choices = array(
			'always'  => array( __( 'Use the social image for every post', 'pressqueue' ), __( 'Recommended. Your posts look the same wherever they are shared.', 'pressqueue' ) ),
			'missing' => array( __( 'Only for posts without an image of their own', 'pressqueue' ), __( 'Posts that already have a preview image keep it.', 'pressqueue' ) ),
			'off'     => array( __( 'Do not change link previews', 'pressqueue' ), __( 'Your theme or SEO plugin decides.', 'pressqueue' ) ),
		);
		echo '<div class="pq-choices pq-social-stack">';
		foreach ( $choices as $value => $choice ) {
			echo '<label class="pq-choice"><input type="radio" name="link_preview" value="' . esc_attr( $value ) . '" ' . checked( $settings['link_preview'], $value, false ) . ' />';
			echo '<span><b>' . esc_html( $choice[0] ) . '</b><span class="pq-choice-help">' . esc_html( $choice[1] ) . '</span></span></label>';
		}
		echo '</div>';

		if ( '' !== $name ) {
			/* translators: %s: SEO plugin name, such as Yoast SEO. */
			echo '<p class="pq-help">' . esc_html( sprintf( __( '%s prints your link preview tags. PressQueue hands it the social image, and an image you set for a single post in that plugin still wins.', 'pressqueue' ), $name ) ) . '</p>';
		} elseif ( 'other' === $plugin ) {
			echo '<p class="pq-help">' . esc_html__( 'Your SEO plugin prints the link preview tags and PressQueue cannot hand it the image. Set the image in that plugin instead.', 'pressqueue' ) . '</p>';
		} else {
			echo '<p class="pq-help">' . esc_html__( 'No SEO plugin was found, so PressQueue adds the image tags to your posts itself.', 'pressqueue' ) . '</p>';
		}
		echo '</div></fieldset>';
	}

	/**
	 * Show which sharing rules use the social image.
	 */
	private static function render_usage(): void {
		try {
			$rules = ( new RuleRepository() )->find_all();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return;
		}

		$sharing = array_values(
			array_filter(
				$rules,
				static function ( $rule ): bool {
					return 'include' === $rule->effect;
				}
			)
		);

		echo '<div class="pq-card"><div class="pq-section">';
		echo '<h2>' . esc_html__( 'Where it is used', 'pressqueue' ) . '</h2>';
		if ( array() === $sharing ) {
			echo '<p class="pq-muted" style="margin:0">' . esc_html__( 'You have no sharing rules yet. When you add one, set its Image to "Social image with the post title".', 'pressqueue' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<ul class="pq-social-rules">';
		foreach ( $sharing as $rule ) {
			$uses = 'social_card' === ( $rule->media_policy['mode'] ?? '' );
			echo '<li><a href="' . esc_url(
				Ui::url(
					Ui::PAGE_RULES,
					array(
						'view' => 'edit',
						'rule' => $rule->public_id,
					)
				)
			) . '">' . esc_html( '' === $rule->name ? __( 'Untitled rule', 'pressqueue' ) : $rule->name ) . '</a> ';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill escapes.
			echo $uses ? Ui::pill( 'ok', __( 'Uses the social image', 'pressqueue' ) ) : Ui::pill( 'idle', __( 'Not used', 'pressqueue' ) );
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="pq-help">' . esc_html__( 'To share with the social image, open a rule and set its Image to "Social image with the post title". Each post can also choose its image in the editor.', 'pressqueue' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Return titles of recent posts to preview with.
	 *
	 * @return array<int,string>
	 */
	private static function sample_titles(): array {
		$titles = array();
		$posts  = get_posts(
			array(
				'no_found_rows'  => true,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'posts_per_page' => 6,
			)
		);
		foreach ( $posts as $post ) {
			$title = CardStore::title( $post );
			if ( '' !== $title ) {
				$titles[] = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		if ( array() === $titles ) {
			$titles[] = __( 'How we cut our grocery bill in half without eating less', 'pressqueue' );
		}

		return array_values( array_unique( $titles ) );
	}

	/**
	 * Stream a preview image.
	 */
	public static function handle_preview(): void {
		if ( ! check_ajax_referer( self::PREVIEW_ACTION, false, false ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce checked above.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$title   = isset( $_GET['title'] ) ? sanitize_text_field( wp_unslash( $_GET['title'] ) ) : '';
		$style   = Settings::style( Settings::read() );

		if ( isset( $_GET['background'] ) ) {
			if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( '', '', array( 'response' => 403 ) );
			}

			$input = array();
			foreach ( array( 'align', 'background', 'custom_image', 'font', 'site_label', 'text' ) as $key ) {
				if ( isset( $_GET[ $key ] ) ) {
					$input[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				}
			}
			$input['show_site'] = ! empty( $_GET['show_site'] );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( 'custom' === ( $input['background'] ?? '' ) && ! self::can_use_custom_image( (int) ( $input['custom_image'] ?? 0 ) ) ) {
				$input['background'] = Catalog::DEFAULT_BACKGROUND;
			}
			$style = Settings::style( Settings::normalize( $input ) );
		} elseif ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) && ! current_user_can( Capabilities::MANAGE_RULES ) && ! current_user_can( Capabilities::EDIT_POST_CONTROLS ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post_id ) ) {
				wp_die( '', '', array( 'response' => 403 ) );
			}
			$title = CardStore::title( $post );
		}

		if ( ! Renderer::is_supported() ) {
			wp_die( '', '', array( 'response' => 501 ) );
		}

		try {
			$bytes = ( new Renderer() )->render( '' === $title ? __( 'Your post title', 'pressqueue' ) : $title, $style );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			wp_die( '', '', array( 'response' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image.
		exit;
	}

	/**
	 * Save the style.
	 */
	public static function handle_save(): void {
		AdminPostBoundary::check( 'pressqueue_save_social_cards', Capabilities::MANAGE_SETTINGS, __( 'You are not allowed to manage PressQueue settings.', 'pressqueue' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$input = array();
		foreach ( array( 'align', 'background', 'custom_image', 'font', 'link_preview', 'site_label', 'text' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		$input['show_site'] = ! empty( $_POST['show_site'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		try {
			if ( 'custom' === ( $input['background'] ?? '' ) && ! self::can_use_custom_image( (int) ( $input['custom_image'] ?? 0 ) ) ) {
				throw new ValidationException( __( 'Choose an image from your Media Library for your own background.', 'pressqueue' ) );
			}
			Settings::save( $input );
			self::redirect( 'saved' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Check whether the user may use an attachment as the background.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function can_use_custom_image( int $attachment_id ): bool {
		return $attachment_id > 0
			&& wp_attachment_is_image( $attachment_id )
			&& ! CardStore::is_card( $attachment_id )
			&& current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Render the result notice.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query arg.
		$message = isset( $_GET['pressqueue_social'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_social'] ) ) : '';
		if ( 'saved' === $message ) {
			Ui::notice( 'success', __( 'Style saved. New social images use it from now on.', 'pressqueue' ) );
		} elseif ( 'error' === $message ) {
			$detail = '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query arg.
			if ( isset( $_GET['pressqueue_detail'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query arg.
				$detail = SafeOperatorError::notice_detail( sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) );
			}
			Ui::notice( 'error', __( 'The style could not be saved.', 'pressqueue' ), $detail );
		}
	}

	/**
	 * Redirect back to the screen.
	 *
	 * @param string      $message Message key.
	 * @param string|null $detail Detail.
	 */
	private static function redirect( string $message, ?string $detail = null ): void {
		$args = array( 'pressqueue_social' => $message );
		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( Ui::url( self::SLUG, $args ) );
		exit;
	}
}
