<?php
/**
 * Config storage and versioning (docs/04 "Storage and versioning").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;

/**
 * @covers \Causeway\ClaudeBridge\Config\ConfigRepository
 */
final class ConfigRepositoryTest extends IntegrationTestCase {

	private ConfigRepository $repository;

	public function set_up(): void {
		parent::set_up();
		$this->repository = new ConfigRepository();
	}

	public function test_save_bumps_the_version_and_records_who_and_where(): void {
		$before                        = $this->repository->version();
		$config                        = $this->repository->get();
		$config['writing']['sign_off'] = 'Best wishes, the office';

		$saved = $this->repository->save( $config, 7, 'ui', 'Added sign-off' );

		$this->assertIsArray( $saved );
		$this->assertSame( $before + 1, $saved['version'] );
		$this->assertSame( $before + 1, $this->repository->version() );
		$this->assertSame( 'Best wishes, the office', $this->repository->get()['writing']['sign_off'] );
		$this->assertSame( 7, $saved['saved_by'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $saved['saved_at_gmt'] );

		$latest = $this->repository->versions()[0];
		$this->assertSame( $before + 1, $latest['version'] );
		$this->assertSame( 'ui', $latest['source'] );
		$this->assertSame( 'Added sign-off', $latest['note'] );
		$this->assertSame( 'Best wishes, the office', $this->repository->get_version_config( $latest['id'] )['writing']['sign_off'] );
	}

	public function test_invalid_config_is_not_saved_and_errors_carry_paths(): void {
		$before                          = $this->repository->version();
		$config                          = $this->repository->get();
		$config['media']['webp_quality'] = 120;

		$result = $this->repository->save( $config, 1, 'ui' );

		$this->assertWPError( $result );
		$this->assertSame( 'ccb_config_invalid', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( '/media/webp_quality', $data['errors'][0]['path'] );
		$this->assertSame( $before, $this->repository->version() );
	}

	public function test_stale_base_version_is_a_conflict(): void {
		$base   = $this->repository->version();
		$config = $this->repository->get();
		$this->assertIsArray( $this->repository->save( $config, 1, 'ui', null, $base ) );

		$result = $this->repository->save( $config, 2, 'ui', null, $base );

		$this->assertWPError( $result );
		$this->assertSame( 'ccb_config_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $base + 1, $result->get_error_data()['live_version'] );
	}

	public function test_unknown_source_is_refused(): void {
		$result = $this->repository->save( $this->repository->get(), 1, 'claude' );
		$this->assertWPError( $result );
		$this->assertSame( 'ccb_invalid_input', $result->get_error_code() );
	}

	public function test_only_the_latest_20_versions_are_kept(): void {
		$config = $this->repository->get();
		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertIsArray( $this->repository->save( $config, 1, 'ui' ) );
		}

		$versions = $this->repository->versions();
		$this->assertCount( ConfigRepository::MAX_VERSIONS, $versions );
		$this->assertSame( $this->repository->version(), $versions[0]['version'] );
		$this->assertSame( $this->repository->version() - 19, end( $versions )['version'] );
		$this->assertSame( ConfigRepository::MAX_VERSIONS, $this->row_count( Schema::CONFIG_VERSIONS ) );
	}

	public function test_sections_round_trip_with_empty_maps_kept_as_objects(): void {
		$config                                = $this->repository->get();
		$config['sections']['news']            = \Causeway\ClaudeBridge\Config\DefaultConfig::section( 'post', 'News' );
		$config['sections']['news']['enabled'] = true;

		$this->assertIsArray( $this->repository->save( $config, 1, 'ui' ) );

		$stored = (string) get_option( ConfigRepository::OPTION );
		$this->assertStringContainsString( '"default_terms":{}', $stored );
		$this->assertStringContainsString( '"field_rules":{}', $stored );
		$this->assertSame( 'News', $this->repository->get()['sections']['news']['label'] );
		$this->assertTrue( $this->repository->validate_stored()->is_valid() );
	}

	public function test_lost_option_continues_numbering_from_the_history(): void {
		$latest = $this->repository->versions()[0]['version'];
		delete_option( ConfigRepository::OPTION );

		$saved = $this->repository->save( $this->repository->get(), 1, 'ui' );

		$this->assertIsArray( $saved );
		$this->assertSame( $latest + 1, $saved['version'] );
	}

	public function test_unreadable_stored_config_falls_back_to_defaults(): void {
		update_option( ConfigRepository::OPTION, 'not json', false );
		$config = $this->repository->get();
		$this->assertSame( array(), $config['sections'] );
	}
}
