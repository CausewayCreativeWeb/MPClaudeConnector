<?php
/**
 * Differences between two configs.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Lists every leaf value that differs between two configs, for preset and import previews,
 * version history and the update-live summary later on.
 */
final class ConfigDiff {

	/**
	 * Keys that change on every save and aren't worth showing.
	 */
	private const IGNORED = array( '/version', '/saved_by', '/saved_at_gmt' );

	/**
	 * Changes from $before to $after.
	 *
	 * @param array<string, mixed> $before Old config.
	 * @param array<string, mixed> $after  New config.
	 * @return list<array{path: string, before: mixed, after: mixed}>
	 */
	public static function diff( array $before, array $after ): array {
		$changes = array();
		self::walk( $before, $after, '', $changes );
		return array_values( array_filter( $changes, static fn ( array $c ): bool => ! in_array( $c['path'], self::IGNORED, true ) ) );
	}

	/**
	 * Recursive comparison. Lists are compared as whole values; maps key by key.
	 *
	 * @param mixed                                                  $before  Old value.
	 * @param mixed                                                  $after   New value.
	 * @param string                                                 $path    Path so far.
	 * @param list<array{path: string, before: mixed, after: mixed}> $changes Collected changes.
	 */
	private static function walk( mixed $before, mixed $after, string $path, array &$changes ): void {
		$is_map = static fn ( mixed $v ): bool => is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );

		if ( $is_map( $before ) && $is_map( $after ) && ( array() !== $before || array() !== $after ) ) {
			$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
			foreach ( $keys as $key ) {
				self::walk( $before[ $key ] ?? null, $after[ $key ] ?? null, $path . '/' . $key, $changes );
			}
			return;
		}

		if ( $before !== $after ) {
			$changes[] = array(
				'path'   => '' === $path ? '/' : $path,
				'before' => $before,
				'after'  => $after,
			);
		}
	}
}
