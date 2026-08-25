<?php
/**
 * Normalized post event.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageValidator;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor docs cover this immutable DTO.

/**
 * Represents one runtime or preview event after WordPress state has settled.
 */
final class NormalizedEvent {
	public string $mode;
	public string $source;
	public string $trigger;
	public string $event_key;
	public int $post_id;
	public string $post_type;
	public string $post_status;
	public int $author_id;
	public string $canonical_url;
	public string $published_at_gmt;
	public string $modified_at_gmt;
	/**
	 * Sorted taxonomy term facts.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $terms;
	/**
	 * Allowlisted metadata.
	 *
	 * @var array<string,mixed>
	 */
	public array $metadata;
	/**
	 * Template input values.
	 *
	 * @var array<string,mixed>
	 */
	public array $template_values;
	/**
	 * Canonical PressQueue post control bundle.
	 *
	 * @var array<string,mixed>
	 */
	public array $control_bundle;
	public string $control_bundle_hash;
	public string $trigger_fingerprint;
	public ?string $previous_trigger_fingerprint;
	public ?string $previous_runtime_fingerprint;
	public string $legacy_runtime_fingerprint;
	public string $revision_fingerprint;
	public ?string $intent_occurrence_key;

	/**
	 * Constructor.
	 *
	 * @param string                         $mode Event mode.
	 * @param string                         $source Diagnostic event source.
	 * @param string                         $trigger Runtime trigger.
	 * @param int                            $post_id WordPress post ID.
	 * @param string                         $post_type Post type.
	 * @param string                         $post_status Post status.
	 * @param int                            $author_id Post author ID.
	 * @param string                         $canonical_url Canonical URL.
	 * @param string                         $published_at_gmt Published timestamp.
	 * @param string                         $modified_at_gmt Modified timestamp.
	 * @param array<int,array<string,mixed>> $terms Sorted term facts.
	 * @param array<string,mixed>            $metadata Allowlisted metadata.
	 * @param array<string,mixed>            $template_values Template input values.
	 * @param string|null                    $previous_trigger_fingerprint Previous trigger fingerprint.
	 * @param string|null                    $revision_fingerprint Optional precomputed delivery revision fingerprint.
	 * @param array<string,mixed>|null       $control_bundle Canonical control bundle.
	 * @param string|null                    $trigger_fingerprint Optional precomputed trigger fingerprint.
	 * @param string|null                    $previous_runtime_fingerprint Previous legacy runtime fingerprint.
	 * @param string|null                    $legacy_runtime_fingerprint Optional precomputed legacy runtime fingerprint.
	 * @param array<string,mixed>|null       $legacy_runtime_metadata Optional legacy metadata facts.
	 * @param array<string,mixed>|null       $legacy_runtime_template_values Optional legacy template facts.
	 * @param string|null                    $intent_occurrence_key Optional manual batch occurrence key.
	 */
	public function __construct(
		string $mode,
		string $source,
		string $trigger,
		int $post_id,
		string $post_type,
		string $post_status,
		int $author_id,
		string $canonical_url,
		string $published_at_gmt,
		string $modified_at_gmt,
		array $terms,
		array $metadata,
		array $template_values,
		?string $previous_trigger_fingerprint = null,
		?string $revision_fingerprint = null,
		?array $control_bundle = null,
		?string $trigger_fingerprint = null,
		?string $previous_runtime_fingerprint = null,
		?string $legacy_runtime_fingerprint = null,
		?array $legacy_runtime_metadata = null,
		?array $legacy_runtime_template_values = null,
		?string $intent_occurrence_key = null
	) {
		StorageEnums::assert_event_mode( $mode );
		StorageEnums::assert_rule_trigger( $trigger );
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		StorageValidator::assert_unsigned_id( 'Author ID', $author_id, true );
		StorageValidator::assert_wordpress_slug( 'Post type', $post_type );
		StorageValidator::assert_wordpress_slug( 'Post status', $post_status );

		if ( null !== $previous_trigger_fingerprint ) {
			StorageValidator::assert_hash( $previous_trigger_fingerprint );
		}

		if ( null !== $trigger_fingerprint ) {
			StorageValidator::assert_hash( $trigger_fingerprint );
		}

		if ( null !== $previous_runtime_fingerprint ) {
			StorageValidator::assert_hash( $previous_runtime_fingerprint );
		}

		if ( null !== $legacy_runtime_fingerprint ) {
			StorageValidator::assert_hash( $legacy_runtime_fingerprint );
		}

		if ( null !== $intent_occurrence_key ) {
			StorageValidator::assert_hash( $intent_occurrence_key );
		}

		$control_bundle = PostControlMetadata::normalize_bundle( $control_bundle ?? PostControlMetadata::default_bundle() );

		$this->mode                         = $mode;
		$this->source                       = $source;
		$this->trigger                      = $trigger;
		$this->post_id                      = $post_id;
		$this->post_type                    = $post_type;
		$this->post_status                  = $post_status;
		$this->author_id                    = $author_id;
		$this->canonical_url                = $canonical_url;
		$this->published_at_gmt             = $published_at_gmt;
		$this->modified_at_gmt              = $modified_at_gmt;
		$this->terms                        = $terms;
		$this->metadata                     = $metadata;
		$this->template_values              = $template_values;
		$this->control_bundle               = $control_bundle;
		$this->control_bundle_hash          = PostControlMetadata::bundle_hash( $control_bundle );
		$this->previous_trigger_fingerprint = $previous_trigger_fingerprint;
		$this->previous_runtime_fingerprint = $previous_runtime_fingerprint;
		$this->trigger_fingerprint          = $trigger_fingerprint ?? $this->calculate_trigger_fingerprint();
		$this->revision_fingerprint         = $revision_fingerprint ?? $this->calculate_revision_fingerprint();
		$this->legacy_runtime_fingerprint   = $legacy_runtime_fingerprint ?? $this->calculate_legacy_runtime_fingerprint( $legacy_runtime_metadata ?? $this->metadata, $legacy_runtime_template_values ?? $this->template_values );
		$this->intent_occurrence_key        = $intent_occurrence_key;
		$this->event_key                    = HashKeys::event_key( $this->event_fields() );
	}

	/**
	 * Return event fields used by decision storage.
	 *
	 * @return array<string,mixed>
	 */
	public function event_fields(): array {
		$fields = array(
			'author_id'            => $this->author_id,
			'event_mode'           => $this->mode,
			'post_id'              => $this->post_id,
			'post_status'          => $this->post_status,
			'post_type'            => $this->post_type,
			'revision_fingerprint' => $this->revision_fingerprint,
			'trigger'              => $this->trigger,
		);

		if ( null !== $this->intent_occurrence_key ) {
			$fields['intent_occurrence_key'] = $this->intent_occurrence_key;
		}

		return $fields;
	}

	/**
	 * Return all normalized facts used in snapshots.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'canonical_url'                => $this->canonical_url,
			'control_bundle'               => $this->control_bundle,
			'control_bundle_hash'          => $this->control_bundle_hash,
			'event_key'                    => $this->event_key,
			'metadata'                     => $this->metadata,
			'mode'                         => $this->mode,
			'modified_at_gmt'              => $this->modified_at_gmt,
			'post_id'                      => $this->post_id,
			'post_status'                  => $this->post_status,
			'post_type'                    => $this->post_type,
			'published_at_gmt'             => $this->published_at_gmt,
			'revision_fingerprint'         => $this->revision_fingerprint,
			'source'                       => $this->source,
			'template_values'              => $this->template_values,
			'terms'                        => $this->terms,
			'trigger_fingerprint'          => $this->trigger_fingerprint,
			'trigger'                      => $this->trigger,
			'user_id'                      => $this->author_id,
			'previous_trigger_fingerprint' => $this->previous_trigger_fingerprint,
			'previous_runtime_fingerprint' => $this->previous_runtime_fingerprint,
			'legacy_runtime_fingerprint'   => $this->legacy_runtime_fingerprint,
			'intent_occurrence_key'        => $this->intent_occurrence_key,
		);
	}

	/**
	 * Calculate the trigger fingerprint from WordPress facts that create updates.
	 *
	 * @return string
	 */
	private function calculate_trigger_fingerprint(): string {
		return CanonicalJson::hash(
			'trigger_fingerprint',
			array(
				'author_id'       => $this->author_id,
				'canonical_url'   => $this->canonical_url,
				'metadata'        => $this->non_control_metadata(),
				'post_id'         => $this->post_id,
				'post_status'     => $this->post_status,
				'post_type'       => $this->post_type,
				'template_values' => $this->trigger_template_values(),
				'terms'           => $this->terms,
			)
		);
	}

	/**
	 * Calculate the revision fingerprint from evaluation and rendering facts.
	 *
	 * @return string
	 */
	private function calculate_revision_fingerprint(): string {
		return CanonicalJson::hash(
			'revision_fingerprint',
			array(
				'canonical_url'    => $this->canonical_url,
				'control_bundle'   => $this->control_bundle,
				'metadata'         => $this->metadata,
				'post_id'          => $this->post_id,
				'post_status'      => $this->post_status,
				'post_type'        => $this->post_type,
				'published_at_gmt' => $this->published_at_gmt,
				'template_values'  => $this->template_values,
				'terms'            => $this->terms,
				'user_id'          => $this->author_id,
			)
		);
	}

	/**
	 * Calculate the pre-control-bundle runtime fingerprint for upgrade comparison.
	 *
	 * @param array<string,mixed> $metadata Legacy metadata facts.
	 * @param array<string,mixed> $template_values Legacy template facts.
	 * @return string
	 */
	private function calculate_legacy_runtime_fingerprint( array $metadata, array $template_values ): string {
		return CanonicalJson::hash(
			'revision_fingerprint',
			array(
				'canonical_url'    => $this->canonical_url,
				'metadata'         => $metadata,
				'post_id'          => $this->post_id,
				'post_status'      => $this->post_status,
				'post_type'        => $this->post_type,
				'published_at_gmt' => $this->published_at_gmt,
				'template_values'  => $template_values,
				'terms'            => $this->terms,
				'user_id'          => $this->author_id,
			)
		);
	}

	/**
	 * Return metadata excluding PressQueue control keys.
	 *
	 * @return array<string,mixed>
	 */
	private function non_control_metadata(): array {
		$metadata = array();

		foreach ( $this->metadata as $key => $value ) {
			if ( PostControlMetadata::is_control_meta_key( (string) $key ) ) {
				continue;
			}

			$metadata[ (string) $key ] = $value;
		}

		return $metadata;
	}

	/**
	 * Return template values relevant to WordPress update detection.
	 *
	 * @return array<string,mixed>
	 */
	private function trigger_template_values(): array {
		$values = $this->template_values;

		if ( isset( $values['metadata'] ) ) {
			$values['metadata'] = $this->non_control_metadata();
		}

		return $values;
	}
}
