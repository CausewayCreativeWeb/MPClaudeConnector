<?php
/**
 * Minimal WP_Error for unit tests that run without WordPress.
 *
 * @package Causeway\ClaudeBridge
 */

// phpcs:disable

class WP_Error {
	private array $errors = array();
	private array $data   = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->data[ $code ] = $data;
			}
		}
	}

	public function get_error_code() {
		return array_key_first( $this->errors ) ?? '';
	}

	public function get_error_message( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->errors[ $code ][0] ?? '';
	}

	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->data[ $code ] ?? null;
	}
}
