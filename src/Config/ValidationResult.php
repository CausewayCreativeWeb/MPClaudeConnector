<?php
/**
 * Result of validating a config.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Errors block a save; warnings need a confirm (docs/08 save flow).
 *
 * Each problem has a JSON-pointer-style `path` (e.g. `/sections/news/label`) so the admin UI can link to the field.
 */
final class ValidationResult {

	/**
	 * Problems that block saving.
	 *
	 * @var list<array{path: string, code: string, message: string}>
	 */
	private array $errors = array();

	/**
	 * Problems the admin must confirm before saving.
	 *
	 * @var list<array{path: string, code: string, message: string}>
	 */
	private array $warnings = array();

	/**
	 * Records an error.
	 *
	 * @param string $path    Path of the offending value.
	 * @param string $code    Machine-readable code.
	 * @param string $message Plain-English message.
	 */
	public function add_error( string $path, string $code, string $message ): void {
		$this->errors[] = array(
			'path'    => $path,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Records a warning.
	 *
	 * @param string $path    Path of the offending value.
	 * @param string $code    Machine-readable code.
	 * @param string $message Plain-English message.
	 */
	public function add_warning( string $path, string $code, string $message ): void {
		$this->warnings[] = array(
			'path'    => $path,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Whether there are no errors (warnings are allowed).
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Errors.
	 *
	 * @return list<array{path: string, code: string, message: string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Warnings.
	 *
	 * @return list<array{path: string, code: string, message: string}>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Paths that have at least one error.
	 *
	 * @return list<string>
	 */
	public function error_paths(): array {
		return array_values( array_unique( array_column( $this->errors, 'path' ) ) );
	}
}
