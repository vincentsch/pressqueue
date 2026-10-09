<?php
/**
 * Opt-in default for new posts.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Editorial\NewPostDefaults;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Storage\SettingsRepository;
use WP_Post;

use function pressqueue_test_reset_wordpress_stubs;
use function update_post_meta;

/**
 * @covers \PressQueue\Editorial\NewPostDefaults
 */
final class NewPostDefaultsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_hook_runs_before_the_event_collector(): void {
		NewPostDefaults::register_hooks();

		$hooks = $GLOBALS['pressqueue_test_hooks']['transition_post_status'] ?? array();

		self::assertArrayHasKey( NewPostDefaults::PRIORITY, $hooks );
		self::assertLessThan( 10, NewPostDefaults::PRIORITY );
		self::assertSame( array( NewPostDefaults::class, 'handle_transition' ), $hooks[ NewPostDefaults::PRIORITY ][0]['callback'] );
		self::assertSame( 3, $hooks[ NewPostDefaults::PRIORITY ][0]['accepted_args'] );
	}

	public function test_opt_in_marks_created_posts_as_excluded(): void {
		$this->set_mode( 'opt_in' );

		NewPostDefaults::handle_transition( 'auto-draft', 'new', $this->post( 7 ) );

		self::assertSame( 'exclude', get_post_meta( 7, PostControlMetadata::LEGACY_META_KEY, true ) );
		self::assertFalse( metadata_exists( 'post', 7, PostControlMetadata::META_KEY ) );
		self::assertSame( 'exclude', PostControlMetadata::read_for_post( 7 )['policy'] );
	}

	public function test_opt_in_also_covers_posts_created_as_published(): void {
		$this->set_mode( 'opt_in' );

		NewPostDefaults::handle_transition( 'publish', 'new', $this->post( 8, 'page' ) );

		self::assertSame( 'exclude', PostControlMetadata::read_for_post( 8 )['policy'] );
	}

	public function test_automatic_mode_writes_nothing(): void {
		NewPostDefaults::handle_transition( 'auto-draft', 'new', $this->post( 9 ) );
		$this->set_mode( 'automatic' );
		NewPostDefaults::handle_transition( 'auto-draft', 'new', $this->post( 10 ) );

		self::assertSame( array(), $GLOBALS['pressqueue_test_post_meta'] );
		self::assertSame( 'inherit', PostControlMetadata::read_for_post( 10 )['policy'] );
	}

	public function test_existing_choices_are_never_overwritten(): void {
		$this->set_mode( 'opt_in' );
		$bundle = PostControlMetadata::default_bundle();
		update_post_meta( 11, PostControlMetadata::META_KEY, PostControlMetadata::encoded( $bundle ) );
		update_post_meta( 12, PostControlMetadata::LEGACY_META_KEY, 'include' );

		self::assertFalse( NewPostDefaults::apply_to_new_post( $this->post( 11 ) ) );
		self::assertFalse( NewPostDefaults::apply_to_new_post( $this->post( 12 ) ) );

		self::assertFalse( metadata_exists( 'post', 11, PostControlMetadata::LEGACY_META_KEY ) );
		self::assertSame( 'inherit', PostControlMetadata::read_for_post( 11 )['policy'] );
		self::assertSame( 'include', get_post_meta( 12, PostControlMetadata::LEGACY_META_KEY, true ) );
	}

	public function test_a_second_writer_leaves_the_marker_alone(): void {
		$this->set_mode( 'opt_in' );

		// Another writer, such as a site mu-plugin, already stored the marker.
		update_post_meta( 13, PostControlMetadata::LEGACY_META_KEY, 'exclude' );

		self::assertFalse( NewPostDefaults::apply_to_new_post( $this->post( 13 ) ) );
		self::assertSame( array( 'exclude' ), $GLOBALS['pressqueue_test_post_meta'][13][ PostControlMetadata::LEGACY_META_KEY ] );
	}

	public function test_only_creation_of_supported_post_types_is_marked(): void {
		$this->set_mode( 'opt_in' );

		NewPostDefaults::handle_transition( 'publish', 'draft', $this->post( 14 ) );
		NewPostDefaults::handle_transition( 'inherit', 'new', $this->post( 15, 'attachment' ) );
		NewPostDefaults::handle_transition( 'publish', 'new', $this->post( 16, 'internal' ) );
		NewPostDefaults::handle_transition( 'publish', 'new', null );

		self::assertSame( array(), $GLOBALS['pressqueue_test_post_meta'] );
	}

	/**
	 * Set the new post sharing option.
	 *
	 * @param string $mode Mode.
	 */
	private function set_mode( string $mode ): void {
		$GLOBALS['pressqueue_test_options'][ SettingsRepository::NEW_POST_SHARING_OPTION ] = $mode;
	}

	/**
	 * Build a test post.
	 *
	 * @param int    $id Post ID.
	 * @param string $type Post type.
	 * @return WP_Post
	 */
	private function post( int $id, string $type = 'post' ): WP_Post {
		$post                                  = new WP_Post(
			array(
				'ID'          => $id,
				'post_status' => 'auto-draft',
				'post_type'   => $type,
			)
		);
		$GLOBALS['pressqueue_test_posts'][ $id ] = $post;

		return $post;
	}
}
