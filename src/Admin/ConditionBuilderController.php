<?php
/**
 * Condition builder and Rules screen assets.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Storage\CanonicalJson;

/**
 * Renders the "Which posts" condition builder and loads the Rules screen assets.
 */
final class ConditionBuilderController {
	/**
	 * Most terms preloaded per taxonomy. Larger taxonomies are searched through the REST API.
	 */
	private const TERM_LIMIT = 400;

	/**
	 * Most authors preloaded.
	 */
	private const AUTHOR_LIMIT = 300;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		}
	}

	/**
	 * Enqueue builder, composer and screen styles on the Rules screen only.
	 */
	public static function enqueue_assets(): void {
		if ( ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'plugins_url' ) ) {
			return;
		}

		if ( ! self::is_rules_screen() ) {
			return;
		}

		$path = defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'assets/admin/condition-builder.js' : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-rules', plugins_url( 'assets/admin/rules.css', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/rules.css' ) );
		wp_enqueue_script( 'pressqueue-condition-builder', plugins_url( 'assets/admin/condition-builder.js', PRESSQUEUE_FILE ), array( 'wp-i18n' ), Ui::asset_version( 'assets/admin/condition-builder.js' ), true );
		wp_enqueue_script( 'pressqueue-rules', plugins_url( 'assets/admin/rules.js', PRESSQUEUE_FILE ), array( 'pressqueue-admin', 'wp-i18n' ), Ui::asset_version( 'assets/admin/rules.js' ), true );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			$languages = defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'languages' : '';
			wp_set_script_translations( 'pressqueue-condition-builder', 'pressqueue', $languages );
			wp_set_script_translations( 'pressqueue-rules', 'pressqueue', $languages );
		}
	}

	/**
	 * Render the condition builder. The canonical JSON textarea it edits is rendered separately.
	 *
	 * @param string      $textarea_id ID of the canonical JSON textarea.
	 * @param string      $json Current condition JSON.
	 * @param string      $effect include or exclude.
	 * @param RuleSummary $summary Summary helper for the no-script view.
	 */
	public static function render_builder( string $textarea_id, string $json, string $effect, RuleSummary $summary ): void {
		$tree = array();

		try {
			$tree = CanonicalJson::decode_object( $json );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}

		echo '<div class="pq-builder pressqueue-condition-builder" data-target="' . esc_attr( $textarea_id ) . '" data-pq-effect="' . esc_attr( $effect ) . '" data-pq-builder="' . esc_attr( (string) wp_json_encode( self::builder_data( $tree ) ) ) . '">';
		echo '<div class="pq-nojs-only">';
		echo '<ul class="pq-builder-lines">';
		foreach ( $summary->condition_lines( $tree ) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		echo '<p class="pq-help">' . esc_html__( 'Turn on JavaScript to change these conditions here, or edit them as JSON under Advanced.', 'pressqueue' ) . '</p>';
		echo '</div>';
		echo '<div class="pq-builder-ui pq-js-only"></div>';
		echo '</div>';
	}

	/**
	 * Render the canonical condition JSON textarea.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $json Canonical JSON value.
	 */
	public static function render_json_field( string $id, string $name, string $json ): void {
		echo '<div><label class="pq-label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Conditions as JSON', 'pressqueue' ) . '</label>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="pq-field pq-code-field pressqueue-condition-json" rows="10" spellcheck="false" aria-describedby="' . esc_attr( $id . '-help' ) . '">' . esc_textarea( $json ) . '</textarea>';
		echo '<p class="pq-help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html__( 'The editor under Which posts writes this for you. Edit it here for nested groups or other conditions, such as post status.', 'pressqueue' ) . '</p></div>';
	}

	/**
	 * Keep the old field renderer for callers outside the Rules screen.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $label Label.
	 * @param string $json Canonical JSON value.
	 */
	public static function render_field( string $id, string $name, string $label, string $json ): void {
		unset( $label );
		self::render_json_field( $id, $name, $json );
	}

	/**
	 * Return names the builder needs: post types, taxonomies with terms, and authors.
	 *
	 * @param array<string,mixed> $tree Current tree, so referenced items are always included.
	 * @return array<string,mixed>
	 */
	public static function builder_data( array $tree ): array {
		$referenced = array(
			'post_types' => array(),
			'terms'      => array(),
			'authors'    => array(),
		);
		self::collect_references( $tree, $referenced );

		return array(
			'postTypes'  => self::post_type_data( $referenced['post_types'] ),
			'taxonomies' => self::taxonomy_data( $referenced['terms'] ),
			'authors'    => self::author_data( $referenced['authors'] ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * Return public post types.
	 *
	 * @param array<int,string> $referenced Post types used by the rule.
	 * @return array<int,array<string,string>>
	 */
	private static function post_type_data( array $referenced ): array {
		$types = array();
		$seen  = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( ! $type instanceof \WP_Post_Type || 'attachment' === $type->name ) {
				continue;
			}

			$seen[]  = $type->name;
			$types[] = array(
				'value' => $type->name,
				'label' => RuleSummary::post_type_label( $type->name ),
			);
		}

		foreach ( array_diff( $referenced, $seen ) as $slug ) {
			$types[] = array(
				'value' => $slug,
				'label' => RuleSummary::post_type_label( $slug ),
			);
		}

		return $types;
	}

	/**
	 * Return public taxonomies with their terms.
	 *
	 * @param array<string,array<int,int>> $referenced Term IDs used by the rule, by taxonomy.
	 * @return array<int,array<string,mixed>>
	 */
	private static function taxonomy_data( array $referenced ): array {
		$taxonomies = array();
		$objects    = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( array_keys( $referenced ) as $slug ) {
			if ( ! isset( $objects[ $slug ] ) ) {
				$object = get_taxonomy( $slug );
				if ( $object instanceof \WP_Taxonomy ) {
					$objects[ $slug ] = $object;
				}
			}
		}

		foreach ( $objects as $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy || 'post_format' === $taxonomy->name ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'number'     => self::TERM_LIMIT + 1,
					'orderby'    => 'name',
				)
			);
			$terms = is_array( $terms ) ? $terms : array();
			$more  = count( $terms ) > self::TERM_LIMIT;
			$terms = array_slice( $terms, 0, self::TERM_LIMIT );
			$list  = array();
			$ids   = array();

			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$ids[]  = (int) $term->term_id;
					$list[] = self::term_fact( $term, $taxonomy->hierarchical );
				}
			}

			foreach ( $referenced[ $taxonomy->name ] ?? array() as $term_id ) {
				if ( in_array( $term_id, $ids, true ) ) {
					continue;
				}

				$term   = get_term( $term_id, $taxonomy->name );
				$list[] = $term instanceof \WP_Term ? self::term_fact( $term, $taxonomy->hierarchical ) : array(
					'id'   => $term_id,
					'name' => RuleSummary::term_name( $term_id, $taxonomy->name ),
					'path' => '',
				);
			}

			$rest_base    = is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
			$taxonomies[] = array(
				'slug'         => $taxonomy->name,
				'label'        => RuleSummary::taxonomy_label( $taxonomy->name ),
				'plural'       => is_string( $taxonomy->labels->name ) ? $taxonomy->labels->name : $taxonomy->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'terms'        => $list,
				'more'         => $more,
				'rest'         => $more && $taxonomy->show_in_rest ? rest_url( 'wp/v2/' . $rest_base ) : '',
			);
		}

		usort(
			$taxonomies,
			static function ( array $left, array $right ): int {
				$order = array(
					'category' => 0,
					'post_tag' => 1,
				);

				$compare = ( $order[ $left['slug'] ] ?? 2 ) <=> ( $order[ $right['slug'] ] ?? 2 );

				return 0 !== $compare ? $compare : strcmp( (string) $left['label'], (string) $right['label'] );
			}
		);

		return $taxonomies;
	}

	/**
	 * Return the facts the builder shows for a term.
	 *
	 * @param \WP_Term $term Term.
	 * @param bool     $hierarchical Whether the taxonomy is hierarchical.
	 * @return array<string,mixed>
	 */
	private static function term_fact( \WP_Term $term, bool $hierarchical ): array {
		$path = '';

		if ( $hierarchical && $term->parent > 0 ) {
			$names = array();
			foreach ( array_reverse( get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor ) {
				$names[] = RuleSummary::term_name( (int) $ancestor, $term->taxonomy );
			}
			$path = implode( ' › ', $names );
		}

		return array(
			'id'   => (int) $term->term_id,
			'name' => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'path' => $path,
		);
	}

	/**
	 * Return authors.
	 *
	 * @param array<int,int> $referenced Author IDs used by the rule.
	 * @return array<int,array<string,mixed>>
	 */
	private static function author_data( array $referenced ): array {
		$users   = get_users(
			array(
				'capability' => array( 'edit_posts' ),
				'number'     => self::AUTHOR_LIMIT,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$authors = array();
		$ids     = array();

		foreach ( $users as $user ) {
			if ( is_object( $user ) && isset( $user->ID ) ) {
				$ids[]     = (int) $user->ID;
				$authors[] = array(
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
				);
			}
		}

		foreach ( $referenced as $user_id ) {
			if ( ! in_array( $user_id, $ids, true ) ) {
				$authors[] = array(
					'id'   => $user_id,
					'name' => RuleSummary::author_name( $user_id ),
				);
			}
		}

		return $authors;
	}

	/**
	 * Collect post types, terms and authors referenced anywhere in a tree.
	 *
	 * @param mixed                                                                                         $node Node.
	 * @param array{post_types:array<int,string>,terms:array<string,array<int,int>>,authors:array<int,int>} $found Collected references.
	 */
	private static function collect_references( $node, array &$found ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( 'condition' === ( $node['type'] ?? null ) ) {
			$value = $node['value'] ?? null;
			switch ( $node['condition'] ?? null ) {
				case 'post_type':
					foreach ( is_array( $value ) ? $value : array( $value ) as $type ) {
						if ( is_string( $type ) && '' !== $type ) {
							$found['post_types'][] = sanitize_key( $type );
						}
					}
					break;
				case 'author':
					foreach ( is_array( $value ) ? $value : array( $value ) as $author ) {
						if ( is_int( $author ) || ( is_string( $author ) && ctype_digit( $author ) ) ) {
							$found['authors'][] = (int) $author;
						}
					}
					break;
				case 'taxonomy':
					if ( is_array( $value ) && is_string( $value['taxonomy'] ?? null ) && is_array( $value['terms'] ?? null ) ) {
						$taxonomy = sanitize_key( $value['taxonomy'] );
						foreach ( $value['terms'] as $term ) {
							if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
								$found['terms'][ $taxonomy ][] = (int) $term;
							}
						}
					}
					break;
			}
			return;
		}

		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				self::collect_references( $child, $found );
			}
		}
	}

	/**
	 * Check whether the current admin page is the Rules screen.
	 *
	 * @return bool
	 */
	private static function is_rules_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'pressqueue-rules' === $page;
	}
}
