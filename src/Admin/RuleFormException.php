<?php
/**
 * Rule form error that belongs to one editor section.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

/**
 * Carries a plain-language message and the editor section it belongs to.
 */
final class RuleFormException extends \InvalidArgumentException {
	/**
	 * Editor section key such as name, when, conditions, where, message, timing, image or advanced.
	 *
	 * @var string
	 */
	public string $section;

	/**
	 * Constructor.
	 *
	 * @param string $section Editor section key.
	 * @param string $message Plain-language message shown to the user.
	 */
	public function __construct( string $section, string $message ) {
		parent::__construct( $message );
		$this->section = $section;
	}

	/**
	 * Raise an error for a section.
	 *
	 * @param string $section Editor section key.
	 * @param string $message Plain-language message.
	 * @return never
	 */
	public static function raise( string $section, string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped wherever it is shown.
		throw new self( $section, $message );
	}
}
