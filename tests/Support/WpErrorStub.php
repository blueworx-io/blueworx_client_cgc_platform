<?php
/**
 * Stand-in for WP_Error, so code that returns one can be unit tested without WordPress.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

if ( class_exists( 'WP_Error' ) ) {
	return;
}

/**
 * Minimal stand-in for WP_Error.
 */
class WP_Error {
	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param mixed  $data    Extra data.
	 */
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	/**
	 * Error code.
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Error message.
	 */
	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * Error data.
	 */
	public function get_error_data(): mixed {
		return $this->data;
	}
}
