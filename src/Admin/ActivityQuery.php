<?php
/**
 * Reads the activity log for the Activity tab and CSV export (docs/08 "Activity").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Admin;

use Causeway\ClaudeBridge\Db\Schema;

/**
 * Filtered, paged reads of `ccb_activity`. Read-only actions are left out unless asked for
 * (docs/06: "may be excluded from the Activity tab's default filter").
 */
final class ActivityQuery {

	public const PER_PAGE = 50;

	public const READ_ACTIONS = array( 'check', 'guide', 'list', 'get', 'prepare' );

	public const CSV_MAX_ROWS = 10000;

	/**
	 * Characters that make a spreadsheet treat a cell as a formula (docs/09).
	 */
	private const FORMULA_START = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Runs a query.
	 *
	 * @param array<string, mixed> $filters user_id, action, section, result, from, to (Y-m-d, site time), search, include_reads, page.
	 * @param int|null             $limit   Rows per page; null for PER_PAGE.
	 * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
	 */
	public static function run( array $filters, ?int $limit = null ): array {
		global $wpdb;
		$table = Schema::table( Schema::ACTIVITY );
		$limit = $limit ?? self::PER_PAGE;
		$page  = max( 1, (int) ( $filters['page'] ?? 1 ) );

		list( $where, $args ) = self::where( $filters );
		$sql_where            = '' === $where ? '' : 'WHERE ' . $where;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- custom table; every value is a placeholder.
		$count_sql = "SELECT COUNT(*) FROM `{$table}` {$sql_where}";
		$total     = (int) $wpdb->get_var( array() === $args ? $count_sql : $wpdb->prepare( $count_sql, $args ) );

		$rows_sql = "SELECT id, created_at_gmt, user_id, app_password_name, action, ability, post_id, attachment_id, section, post_title_snapshot, status_from, status_to, result, error_code, message, request_ref FROM `{$table}` {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( $limit, ( $page - 1 ) * $limit ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'     => array_map( array( self::class, 'present' ), is_array( $rows ) ? $rows : array() ),
			'total'    => $total,
			'page'     => $page,
			'pages'    => max( 1, (int) ceil( $total / $limit ) ),
			'per_page' => $limit,
		);
	}

	/**
	 * Builds the WHERE clause.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: list<int|string>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;
		$parts = array();
		$args  = array();

		if ( ! empty( $filters['user_id'] ) ) {
			$parts[] = 'user_id = %d';
			$args[]  = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$parts[] = 'action = %s';
			$args[]  = (string) $filters['action'];
		} elseif ( empty( $filters['include_reads'] ) ) {
			$parts[] = 'action NOT IN (' . implode( ',', array_fill( 0, count( self::READ_ACTIONS ), '%s' ) ) . ')';
			$args    = array_merge( $args, self::READ_ACTIONS );
		}
		if ( ! empty( $filters['section'] ) ) {
			$parts[] = 'section = %s';
			$args[]  = (string) $filters['section'];
		}
		if ( ! empty( $filters['result'] ) ) {
			$parts[] = 'result = %s';
			$args[]  = (string) $filters['result'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$parts[] = 'created_at_gmt >= %s';
			$args[]  = self::to_gmt( (string) $filters['from'], '00:00:00' );
		}
		if ( ! empty( $filters['to'] ) ) {
			$parts[] = 'created_at_gmt <= %s';
			$args[]  = self::to_gmt( (string) $filters['to'], '23:59:59' );
		}
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$parts[] = 'post_title_snapshot LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( trim( (string) $filters['search'] ) ) . '%';
		}
		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * Site-time date to a GMT datetime.
	 *
	 * @param string $date Y-m-d.
	 * @param string $time H:i:s.
	 */
	private static function to_gmt( string $date, string $time ): string {
		return get_gmt_from_date( $date . ' ' . $time );
	}

	/**
	 * One row as the UI shows it.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function present( array $row ): array {
		static $names = array();
		$user_id      = (int) $row['user_id'];
		if ( ! isset( $names[ $user_id ] ) ) {
			$user              = 0 === $user_id ? false : get_userdata( $user_id );
			$names[ $user_id ] = false === $user ? ( 0 === $user_id ? __( 'System', 'causeway-claude-bridge' ) : __( 'Deleted user', 'causeway-claude-bridge' ) ) : $user->display_name;
		}
		$post_id = null === $row['post_id'] ? 0 : (int) $row['post_id'];
		$post    = 0 === $post_id ? null : get_post( $post_id );

		return array(
			'id'          => (int) $row['id'],
			'time_gmt'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['created_at_gmt'] . ' UTC' ) ),
			'time'        => get_date_from_gmt( (string) $row['created_at_gmt'], 'j M Y H:i' ),
			'user_id'     => $user_id,
			'user'        => $names[ $user_id ],
			'device'      => (string) ( $row['app_password_name'] ?? '' ),
			'action'      => (string) $row['action'],
			'post_id'     => $post_id,
			'post_title'  => null !== $post ? get_the_title( $post ) : (string) ( $row['post_title_snapshot'] ?? '' ),
			'post_link'   => null !== $post ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
			'section'     => (string) ( $row['section'] ?? '' ),
			'status_from' => (string) ( $row['status_from'] ?? '' ),
			'status_to'   => (string) ( $row['status_to'] ?? '' ),
			'result'      => (string) $row['result'],
			'error_code'  => (string) ( $row['error_code'] ?? '' ),
			'message'     => (string) ( $row['message'] ?? '' ),
			'ref'         => (string) $row['request_ref'],
		);
	}

	/**
	 * CSV of the filtered log (all pages, up to CSV_MAX_ROWS).
	 *
	 * @param array<string, mixed> $filters Filters.
	 */
	public static function csv( array $filters ): string {
		$filters['page'] = 1;
		$result          = self::run( $filters, self::CSV_MAX_ROWS );
		$columns         = array( 'time', 'user', 'device', 'action', 'post_id', 'post_title', 'section', 'status_from', 'status_to', 'result', 'error_code', 'message', 'ref' );

		$lines = array( self::csv_line( $columns ) );
		foreach ( $result['rows'] as $row ) {
			$lines[] = self::csv_line( array_map( static fn ( string $column ): string => (string) $row[ $column ], $columns ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line, every cell quoted and formula-safe.
	 *
	 * @param string[] $cells Cells.
	 */
	public static function csv_line( array $cells ): string {
		return implode(
			',',
			array_map(
				static function ( string $cell ): string {
					if ( '' !== $cell && in_array( $cell[0], self::FORMULA_START, true ) ) {
						$cell = "'" . $cell;
					}
					return '"' . str_replace( '"', '""', $cell ) . '"';
				},
				$cells
			)
		);
	}
}
