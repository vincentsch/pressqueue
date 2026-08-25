<?php
/**
 * Template set resolution.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\RuleDocuments;

/**
 * Resolves immutable template snapshots and service variants.
 */
final class TemplateSetResolver {
	/**
	 * Return the effective template set for a rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<string,mixed>
	 */
	public function effective_template_set( RuleRecord $rule ): array {
		if ( null !== $rule->template_set ) {
			return RuleDocuments::template_set( $rule->template_set, $rule->custom_field_allowlist );
		}

		return RuleDocuments::template_set_from_inline( $rule->inline_template, $rule->custom_field_allowlist );
	}

	/**
	 * Return the message for a service, with default fallback.
	 *
	 * @param array<string,mixed> $template_set Template set.
	 * @param string              $service Service key.
	 * @return string
	 */
	public function message_for_service( array $template_set, string $service ): string {
		if (
			isset( $template_set['service_messages'] )
			&& is_array( $template_set['service_messages'] )
			&& isset( $template_set['service_messages'][ $service ] )
			&& is_string( $template_set['service_messages'][ $service ] )
		) {
			return $template_set['service_messages'][ $service ];
		}

		return (string) $template_set['default_message'];
	}

	/**
	 * Return source provenance for payload snapshots.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<string,mixed>
	 */
	public function provenance( RuleRecord $rule ): array {
		return array(
			'source_template_public_id' => $rule->source_template_public_id,
			'source_template_version'   => $rule->source_template_version,
		);
	}
}
