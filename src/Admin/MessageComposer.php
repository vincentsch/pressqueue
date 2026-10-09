<?php
/**
 * Message composer shared by the rule editor and message templates.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Planning\TemplateRenderer;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Record\ChannelRecord;

/**
 * Renders the message box, placeholder buttons and live preview, and reads them back.
 */
final class MessageComposer {
	/**
	 * Default character limit when a channel does not report one.
	 */
	public const DEFAULT_LIMIT = 280;

	/**
	 * Return the placeholder buttons.
	 *
	 * @return array<string,string> Placeholder => button label.
	 */
	public static function placeholders(): array {
		return array(
			'{{ title }}'                  => __( 'Title', 'pressqueue' ),
			'{{ excerpt | truncate:120 }}' => __( 'Excerpt', 'pressqueue' ),
			'{{ author }}'                 => __( 'Author', 'pressqueue' ),
			'{{ terms:category }}'         => __( 'Categories', 'pressqueue' ),
			'{{ terms:post_tag }}'         => __( 'Tags', 'pressqueue' ),
		);
	}

	/**
	 * Render the message fields.
	 *
	 * @param array<string,mixed> $args Keys: default_message, service_messages (service => message), services (service => label), error.
	 */
	public static function render_fields( array $args ): void {
		$default  = (string) ( $args['default_message'] ?? '' );
		$messages = isset( $args['service_messages'] ) && is_array( $args['service_messages'] ) ? $args['service_messages'] : array();
		$services = isset( $args['services'] ) && is_array( $args['services'] ) ? $args['services'] : array();
		$error    = (string) ( $args['error'] ?? '' );

		echo '<div class="pq-composer" data-pq-composer>';
		echo '<input type="hidden" name="message_controls_present" value="1" />';
		echo '<label class="screen-reader-text" for="pq-message-default">' . esc_html__( 'Message', 'pressqueue' ) . '</label>';
		echo '<textarea id="pq-message-default" class="pq-field" name="message_default" rows="3" maxlength="2000" data-pq-message="default"' . ( '' !== $error ? ' aria-invalid="true" aria-describedby="pq-error-message"' : '' ) . '>' . esc_textarea( $default ) . '</textarea>';

		echo '<div class="pq-inserts" role="group" aria-label="' . esc_attr__( 'Insert a placeholder', 'pressqueue' ) . '">';
		echo '<span class="pq-small pq-muted">' . esc_html__( 'Insert', 'pressqueue' ) . '</span>';
		foreach ( self::placeholders() as $tag => $label ) {
			echo '<button type="button" class="pq-insert" data-pq-insert="' . esc_attr( $tag ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</div>';
		echo '<p class="pq-help pq-nojs-only">' . esc_html__( 'Placeholders you can type:', 'pressqueue' ) . ' <code class="pq-code">' . esc_html( implode( '  ', array_keys( self::placeholders() ) ) ) . '</code></p>';
		echo '<p class="pq-help">' . esc_html__( 'The link to the post is added at the end automatically. Line breaks become spaces.', 'pressqueue' ) . '</p>';

		if ( array() !== $services ) {
			$has_variants = array() !== array_filter(
				$messages,
				static function ( $message ): bool {
					return is_string( $message ) && '' !== trim( $message );
				}
			);
			$names        = array_values( $services );
			$summary      = sprintf(
				/* translators: %s: channel service names such as "LinkedIn, X or Bluesky". */
				__( 'Write a different message for %s', 'pressqueue' ),
				count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' ' . __( 'or', 'pressqueue' ) . ' ' . end( $names ) : (string) ( $names[0] ?? '' )
			);

			echo '<details class="pq-more"' . ( $has_variants ? ' open' : '' ) . '><summary>' . esc_html( $summary ) . '</summary>';
			echo '<div class="pq-more-body pq-stack pq-variants">';
			foreach ( $services as $service => $label ) {
				$id = 'pq-message-' . sanitize_html_class( (string) $service );
				echo '<div>';
				echo '<label class="pq-label pq-channel-name" for="' . esc_attr( $id ) . '">' . Ui::service_badge( (string) $service, 'sm' ) . esc_html( (string) $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- service_badge returns escaped markup.
				echo '<textarea id="' . esc_attr( $id ) . '" class="pq-field" name="message_service[' . esc_attr( (string) $service ) . ']" rows="2" maxlength="2000" data-pq-message="' . esc_attr( (string) $service ) . '" placeholder="' . esc_attr__( 'Leave empty to use the main message.', 'pressqueue' ) . '">' . esc_textarea( (string) ( $messages[ $service ] ?? '' ) ) . '</textarea>';
				echo '</div>';
			}
			echo '</div></details>';
		}

		echo '</div>';
	}

	/**
	 * Render the live preview card.
	 *
	 * @param array<int,array<string,mixed>> $posts Preview posts from preview_posts().
	 * @param array<int,array<string,mixed>> $channels Preview channels from preview_channels().
	 * @param string                         $note Optional note under the preview.
	 */
	public static function render_preview( array $posts, array $channels, string $note = '' ): void {
		echo '<div class="pq-card pq-card--pad pq-stack pq-preview-card" data-pq-preview data-pq-posts="' . esc_attr( (string) wp_json_encode( $posts ) ) . '" data-pq-channels="' . esc_attr( (string) wp_json_encode( $channels ) ) . '">';
		echo '<div class="pq-row pq-row--between"><h2>' . esc_html__( 'Preview', 'pressqueue' ) . '</h2>';
		echo '<span class="pq-js-only"><label class="screen-reader-text" for="pq-preview-channel">' . esc_html__( 'Preview for channel', 'pressqueue' ) . '</label><select id="pq-preview-channel" class="pq-preview-select" data-pq-preview-channel></select></span>';
		echo '</div>';
		echo '<p class="pq-muted pq-nojs-only" style="margin:0">' . esc_html__( 'Turn on JavaScript to see a live preview here. The test after saving shows every message.', 'pressqueue' ) . '</p>';
		echo '<div class="pq-js-only pq-stack">';
		echo '<div class="pq-post-preview">';
		echo '<div class="pq-post-preview-who"><span data-pq-preview-badge></span><div><b data-pq-preview-name></b><span class="pq-muted pq-small">' . esc_html__( 'via Buffer', 'pressqueue' ) . '</span></div></div>';
		echo '<div class="pq-post-preview-text" data-pq-preview-text></div>';
		echo '<div class="pq-post-preview-img" data-pq-preview-image hidden></div>';
		echo '<p class="pq-small pq-preview-warning" data-pq-preview-warning hidden></p>';
		echo '</div>';
		echo '<div class="pq-meter" data-pq-meter><div class="pq-meter-bar"><i data-pq-meter-bar></i></div><div class="pq-meter-count"><span data-pq-meter-text aria-live="polite"></span><span data-pq-meter-limit></span></div></div>';
		echo '<p class="pq-small pq-muted" style="margin:0">' . esc_html__( 'Using', 'pressqueue' ) . ' <span class="pq-strong" data-pq-preview-post></span> <button type="button" class="button-link" data-pq-preview-next>' . esc_html__( 'Try another post', 'pressqueue' ) . '</button></p>';
		echo '</div>';
		if ( '' !== $note ) {
			echo '<p class="pq-small pq-muted pq-js-only" style="margin:0">' . esc_html( $note ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Return the services a message can be customized for.
	 *
	 * @param array<int,ChannelRecord> $channels Channels.
	 * @param array<string,mixed>      $existing Existing service messages.
	 * @return array<string,string> Service => label.
	 */
	public static function services( array $channels, array $existing = array() ): array {
		$services = array();

		foreach ( $channels as $channel ) {
			if ( 'unsupported' === $channel->availability ) {
				continue;
			}

			$services[ $channel->service ] = Labels::service( $channel->service );
		}

		foreach ( array_keys( $existing ) as $service ) {
			$services[ (string) $service ] = Labels::service( (string) $service );
		}

		return $services;
	}

	/**
	 * Return channel facts the preview needs.
	 *
	 * @param array<int,ChannelRecord> $channels Channels.
	 * @return array<int,array<string,mixed>>
	 */
	public static function preview_channels( array $channels ): array {
		$facts = array();

		foreach ( $channels as $channel ) {
			if ( 'unsupported' === $channel->availability ) {
				continue;
			}

			$limit   = DeliveryCapability::text_limit( $channel->capabilities );
			$facts[] = array(
				'id'       => $channel->public_id,
				'service'  => $channel->service,
				'label'    => Labels::service( $channel->service ),
				'monogram' => Labels::service_monogram( $channel->service ),
				'name'     => ActivitySummary::channel_display_name( $channel ),
				'limit'    => $limit > 0 ? $limit : self::DEFAULT_LIMIT,
				'enabled'  => $channel->enabled,
			);
		}

		return $facts;
	}

	/**
	 * Return recent posts for the client-side preview.
	 *
	 * @param array<int,string> $post_types Post types to sample, or an empty list for posts.
	 * @param int               $limit Maximum posts.
	 * @return array<int,array<string,mixed>>
	 */
	public static function preview_posts( array $post_types, int $limit = 5 ): array {
		$post_types = array() === $post_types ? array( 'post' ) : $post_types;
		$query      = new \WP_Query(
			array(
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'post_status'            => array( 'publish', 'future', 'draft', 'pending' ),
				'post_type'              => $post_types,
				'posts_per_page'         => max( 1, min( 10, $limit ) ),
				'update_post_meta_cache' => false,
			)
		);
		$renderer   = new TemplateRenderer();
		$posts      = array();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$author  = get_userdata( (int) $post->post_author );
			$content = $renderer->normalize_plain_text( (string) $post->post_content );
			$image   = has_post_thumbnail( $post ) ? wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post ), 'medium' ) : false;
			$url     = get_permalink( $post );
			$posts[] = array(
				'id'      => (int) $post->ID,
				'title'   => $renderer->normalize_plain_text( (string) $post->post_title ),
				'excerpt' => $renderer->normalize_plain_text( (string) $post->post_excerpt ),
				'content' => function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 1200 ) : substr( $content, 0, 1200 ),
				'author'  => $author instanceof \WP_User ? $renderer->normalize_plain_text( (string) $author->display_name ) : '',
				'date'    => (string) get_the_date( '', $post ),
				'terms'   => self::post_terms( $post ),
				'url'     => is_string( $url ) ? $url : home_url( '/' ),
				'image'   => is_string( $image ) ? $image : '',
			);
		}

		if ( array() === $posts ) {
			$posts[] = array(
				'id'      => 0,
				'title'   => __( 'Your post title', 'pressqueue' ),
				'excerpt' => __( 'A short summary of the post appears here when the post has an excerpt.', 'pressqueue' ),
				'content' => '',
				'author'  => wp_get_current_user()->display_name,
				'date'    => '',
				'terms'   => array(),
				'url'     => home_url( '/your-post/' ),
				'image'   => '',
			);
		}

		return $posts;
	}

	/**
	 * Read a template set from the composer fields, or from the JSON editor when it was changed.
	 *
	 * @param array<string,mixed> $current Current template set, used to keep metadata.
	 * @return array<string,mixed>
	 */
	public static function template_set_from_request( array $current ): array {
		$json = self::post_raw( 'template_set_json' );

		if ( '1' !== self::post_text( 'message_controls_present' ) ) {
			return self::decode_object( $json, 'message' );
		}

		if ( '' !== trim( $json ) ) {
			$decoded  = self::decode_object( $json, 'advanced' );
			$original = self::post_raw( 'template_set_json_original' );
			$before   = null;

			try {
				$before = '' === trim( $original ) ? null : CanonicalJson::encode_object( CanonicalJson::decode_object( $original ) );
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
			}

			if ( null === $before || CanonicalJson::encode_object( $decoded ) !== $before ) {
				return $decoded;
			}
		}

		$set = array(
			'default_message' => self::post_message( 'message_default' ),
			'schema_version'  => 1,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller checks the nonce; each value is sanitized below.
		$variants = isset( $_POST['message_service'] ) ? wp_unslash( $_POST['message_service'] ) : array();
		if ( is_array( $variants ) ) {
			$service_messages = array();
			foreach ( $variants as $service => $message ) {
				if ( ! is_string( $service ) || ! is_string( $message ) ) {
					continue;
				}

				$message = trim( wp_strip_all_tags( $message ) );
				if ( '' !== $message ) {
					$service_messages[ sanitize_key( $service ) ] = $message;
				}
			}

			if ( array() !== $service_messages ) {
				ksort( $service_messages, SORT_STRING );
				$set['service_messages'] = $service_messages;
			}
		}

		if ( isset( $current['metadata'] ) && is_array( $current['metadata'] ) && array() !== $current['metadata'] ) {
			$set['metadata'] = $current['metadata'];
		}

		return $set;
	}

	/**
	 * Return pretty JSON for an editor textarea.
	 *
	 * @param array<int|string,mixed> $value Value.
	 * @return string
	 */
	public static function pretty_json( array $value ): string {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : CanonicalJson::encode( $value );
	}

	/**
	 * Return term names grouped by taxonomy, sorted the way messages list them.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,array<int,string>>
	 */
	private static function post_terms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type );
		$grouped    = array();

		if ( ! is_array( $taxonomies ) || array() === $taxonomies ) {
			return $grouped;
		}

		$terms = wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'all' ) );
		if ( ! is_array( $terms ) ) {
			return $grouped;
		}

		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$grouped[ $term->taxonomy ][] = (string) $term->name;
			}
		}

		foreach ( $grouped as $taxonomy => $names ) {
			sort( $names, SORT_STRING );
			$grouped[ $taxonomy ] = array_map(
				static function ( string $name ): string {
					return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				},
				$names
			);
		}

		return $grouped;
	}

	/**
	 * Read a message textarea.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function post_message( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce. Tags are stripped here and RuleDocuments normalizes the rest, so percent signs and spacing stay as written.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( wp_strip_all_tags( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * Read a POST text field.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function post_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a raw JSON POST field. JSON is decoded and validated structurally afterwards.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function post_raw( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller checks the nonce; JSON is decoded and validated structurally.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Decode a JSON object or explain the problem in words.
	 *
	 * @param string $json JSON text.
	 * @param string $section Editor section for errors.
	 * @return array<string,mixed>
	 */
	private static function decode_object( string $json, string $section ): array {
		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			RuleFormException::raise( $section, __( 'The message JSON is not valid. Check for a missing comma, quote or bracket.', 'pressqueue' ) );
		}

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			RuleFormException::raise( $section, __( 'The message JSON must be an object with a default_message.', 'pressqueue' ) );
		}

		return $value;
	}
}
