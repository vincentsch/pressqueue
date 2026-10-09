<?php
/**
 * Planning contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Planning\ConditionEvaluator;
use PressQueue\Planning\EventCollector;
use PressQueue\Planning\NormalizedEvent;
use PressQueue\Planning\TemplateRenderer;
use PressQueue\Storage\ValidationException;

/**
 * @covers \PressQueue\Planning\ConditionEvaluation
 * @covers \PressQueue\Planning\ConditionEvaluator
 * @covers \PressQueue\Planning\NormalizedEvent
 * @covers \PressQueue\Planning\ReasonFactory
 * @covers \PressQueue\Planning\TemplateRenderer
 */
final class PlanningContractsTest extends TestCase {
	public function test_revision_fingerprint_excludes_diagnostic_source(): void {
		$editor = $this->event( 'editor' );
		$rest   = $this->event( 'rest' );

		self::assertSame( $editor->revision_fingerprint, $rest->revision_fingerprint );
		self::assertSame( $editor->event_key, $rest->event_key );
	}

	public function test_revision_fingerprint_ignores_modified_timestamp(): void {
		$first  = $this->event( 'editor', '2026-08-24 12:05:00' );
		$second = $this->event( 'editor', '2026-08-24 12:30:00' );

		self::assertSame( $first->revision_fingerprint, $second->revision_fingerprint );
		self::assertSame( $first->event_key, $second->event_key );
	}

	public function test_control_only_change_keeps_trigger_fingerprint_and_changes_delivery_revision(): void {
		$inherit = $this->event();
		$exclude = $this->event(
			'editor',
			'2026-08-24 12:05:00',
			array(
				'schema_version' => 1,
				'policy'         => 'exclude',
				'channels'       => array(
					'mode' => 'inherit',
				),
			)
		);

		self::assertSame( $inherit->trigger_fingerprint, $exclude->trigger_fingerprint );
		self::assertNotSame( $inherit->revision_fingerprint, $exclude->revision_fingerprint );
		self::assertNotSame( $inherit->event_key, $exclude->event_key );
	}

	public function test_update_suppression_accepts_matching_legacy_runtime_fingerprint(): void {
		$base  = $this->event();
		$event = $this->event( 'editor', '2026-08-24 12:05:00', null, 'update', null, $base->legacy_runtime_fingerprint );
		$check = new \ReflectionMethod( EventCollector::class, 'update_matches_recorded_facts' );
		$check->setAccessible( true );

		self::assertTrue( $check->invoke( null, $event ) );

		$control_change = $this->event(
			'editor',
			'2026-08-24 12:05:00',
			array(
				'schema_version' => 1,
				'policy'         => 'exclude',
				'channels'       => array(
					'mode' => 'inherit',
				),
			),
			'update',
			null,
			$base->legacy_runtime_fingerprint
		);

		self::assertTrue( $check->invoke( null, $control_change ) );

		$changed = $this->event( 'editor', '2026-08-24 12:05:00', null, 'update', null, str_repeat( 'a', 64 ) );

		self::assertFalse( $check->invoke( null, $changed ) );
	}

	public function test_nested_conditions_collect_named_reasons_without_short_circuiting(): void {
		$tree = array(
			'schema_version' => 1,
			'type'           => 'group',
			'operator'       => 'ANY',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'taxonomy',
					'operator'  => 'has_any',
					'value'     => array(
						'taxonomy'          => 'category',
						'terms'             => array( '10' ),
						'include_ancestors' => true,
					),
				),
				array(
					'type'      => 'condition',
					'condition' => 'metadata',
					'operator'  => 'equals',
					'value'     => array(
						'key'   => '_pressqueue_fixture_lane',
						'value' => 'evergreen',
					),
				),
			),
		);

		$result = ( new ConditionEvaluator() )->evaluate( $tree, $this->event() );
		$fields = array_column( $result->reasons, 'field' );

		self::assertSame( 'matched', $result->condition_result );
		self::assertContains( 'taxonomy:category', $fields );
		self::assertContains( 'metadata:_pressqueue_fixture_lane', $fields );
	}

	public function test_non_scalar_metadata_is_a_policy_error_condition(): void {
		$tree = array(
			'schema_version' => 1,
			'type'           => 'group',
			'operator'       => 'ALL',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'metadata',
					'operator'  => 'exists',
					'value'     => array(
						'key' => '_pressqueue_non_scalar',
					),
				),
			),
		);

		$result = ( new ConditionEvaluator() )->evaluate( $tree, $this->event() );

		self::assertSame( 'error', $result->condition_result );
		self::assertSame( 'metadata_non_scalar', $result->reasons[0]['code'] );
	}

	public function test_missing_metadata_not_equals_does_not_match(): void {
		$tree = array(
			'schema_version' => 1,
			'type'           => 'group',
			'operator'       => 'ALL',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'metadata',
					'operator'  => 'not_equals',
					'value'     => array(
						'key'   => '_pressqueue_missing',
						'value' => 'ready',
					),
				),
			),
		);

		$result = ( new ConditionEvaluator() )->evaluate( $tree, $this->event() );

		self::assertSame( 'not_matched', $result->condition_result );
		self::assertSame( 'metadata:_pressqueue_missing', $result->reasons[0]['field'] );
		self::assertNull( $result->reasons[0]['observed'] );
	}

	public function test_author_public_id_conditions_match(): void {
		$tree = array(
			'schema_version' => 1,
			'type'           => 'group',
			'operator'       => 'ALL',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'author',
					'operator'  => 'equals',
					'value'     => '01K8Z8D0PKH2N8J7S3K5A9C6DE',
				),
			),
		);

		$result = ( new ConditionEvaluator() )->evaluate( $tree, $this->event() );

		self::assertSame( 'matched', $result->condition_result );
		self::assertSame( '01K8Z8D0PKH2N8J7S3K5A9C6DE', $result->reasons[0]['expected'] );
	}

	public function test_post_policy_condition_reads_canonical_control_bundle(): void {
		$tree = array(
			'schema_version' => 1,
			'type'           => 'group',
			'mode'           => 'ALL',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'post_policy',
					'operator'  => 'equals',
					'value'     => 'exclude',
				),
			),
		);

		$result = ( new ConditionEvaluator() )->evaluate(
			$tree,
			$this->event(
				'editor',
				'2026-08-24 12:05:00',
				array(
					'schema_version' => 1,
					'policy'         => 'exclude',
					'channels'       => array(
						'mode' => 'inherit',
					),
				)
			)
		);

		self::assertSame( 'matched', $result->condition_result );
	}

	public function test_invalid_taxonomy_term_configuration_is_an_error(): void {
		$tree = array(
			'schema_version' => 1,
			'type'           => 'group',
			'operator'       => 'ALL',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'taxonomy',
					'operator'  => 'has_any',
					'value'     => array(
						'include_ancestors' => false,
						'taxonomy'          => 'category',
						'terms'             => array( array( 'bad' ) ),
					),
				),
			),
		);

		$result = ( new ConditionEvaluator() )->evaluate( $tree, $this->event() );

		self::assertSame( 'error', $result->condition_result );
		self::assertSame( 'invalid_taxonomy_configuration', $result->reasons[0]['code'] );
	}

	public function test_normalized_plain_text_preserves_decoded_less_than_and_entities(): void {
		$renderer = new TemplateRenderer();
		$event = $this->event();
		$event->template_values_are_plain_text = true;
		$event->template_values['title'] = $renderer->normalize_plain_text( 'Top &lt;10 tips &amp; ideas' );
		self::assertSame( 'Top <10 tips & ideas', $renderer->render( array( 'message' => '{{ title }}' ), $event ) );
	}

	public function test_typed_metadata_matches_wordpress_boolean_and_numeric_storage(): void {
		$event = $this->event();
		$event->metadata['flag'] = '1';
		$event->metadata['score'] = '1.0';
		foreach ( array( 'flag' => true, 'score' => 1 ) as $key => $value ) {
			$result = ( new ConditionEvaluator() )->evaluate( array( 'type' => 'condition', 'condition' => 'metadata', 'operator' => 'equals', 'value' => array( 'key' => $key, 'value' => $value ) ), $event );
			self::assertSame( 'matched', $result->condition_result );
		}
	}

	public function test_template_rendering_normalizes_text_and_truncates_unicode(): void {
		$template = array(
			'schema_version'          => 1,
			'message'                 => '{{ title | truncate:8 }} {{ content }} {{ terms:category }} {{ meta:_pressqueue_fixture_lane }} {{ canonical_url }}',
			'custom_field_allowlist'  => array( '_pressqueue_fixture_lane' ),
		);

		$message = ( new TemplateRenderer() )->render( $template, $this->event() );

		self::assertSame( 'Café ... Hello World Child evergreen https://example.test/post', $message );
	}

	public function test_template_rendering_preserves_scalar_false_and_zero_metadata(): void {
		$template = array(
			'schema_version'         => 1,
			'message'                => '{{ meta:_pressqueue_false }} {{ meta:_pressqueue_zero }}',
			'custom_field_allowlist' => array( '_pressqueue_false', '_pressqueue_zero' ),
		);

		$message = ( new TemplateRenderer() )->render( $template, $this->event() );

		self::assertSame( 'false 0', $message );
	}

	public function test_missing_template_metadata_fails_closed(): void {
		$this->expectException( ValidationException::class );

		( new TemplateRenderer() )->render(
			array(
				'schema_version'         => 1,
				'message'                => '{{ meta:_pressqueue_missing }}',
				'custom_field_allowlist' => array( '_pressqueue_missing' ),
			),
			$this->event()
		);
	}

	public function test_unknown_template_tags_fail_closed(): void {
		$this->expectException( ValidationException::class );

		( new TemplateRenderer() )->render(
			array(
				'schema_version'          => 1,
				'message'                 => '{{ unknown }}',
				'custom_field_allowlist'  => array(),
			),
			$this->event()
		);
	}

	/**
	 * Build a normalized event fixture.
	 *
	 * @param string $source Source.
	 * @return NormalizedEvent
	 */
	private function event(
		string $source = 'editor',
		string $modified_at_gmt = '2026-08-24 12:05:00',
		?array $control_bundle = null,
		string $trigger = 'first_publish',
		?string $previous_trigger_fingerprint = null,
		?string $previous_runtime_fingerprint = null
	): NormalizedEvent {
		$terms = array(
			array(
				'ancestor_ids' => array( 10 ),
				'name'         => 'Child',
				'slug'         => 'child',
				'taxonomy'     => 'category',
				'term_id'      => 11,
			),
		);

		$metadata = array(
			'_pressqueue_fixture_lane' => 'evergreen',
			'_pressqueue_false'        => false,
			'_pressqueue_missing'      => null,
			'_pressqueue_non_scalar'   => array( 'bad' ),
			'_pressqueue_policy'       => 'inherit',
			'_pressqueue_zero'         => 0,
		);

		return new NormalizedEvent(
			'preview',
			$source,
			$trigger,
			42,
			'post',
			'publish',
			7,
			'https://example.test/post',
			'2026-08-24 12:00:00',
			$modified_at_gmt,
			$terms,
			$metadata,
			array(
				'author'           => 'Vincent',
				'author_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DE',
				'canonical_url'    => 'https://example.test/post',
				'content'          => '<p>Hello&nbsp;[gallery] <strong>World</strong></p>',
				'excerpt'          => 'Short',
				'metadata'         => $metadata,
				'published_at'     => '2026-08-24T12:00:00+00:00 UTC',
				'terms'            => $terms,
				'title'            => 'Café Society',
			),
			$previous_trigger_fingerprint,
			null,
			$control_bundle ?? PostControlMetadata::default_bundle(),
			null,
			$previous_runtime_fingerprint
		);
	}

}
