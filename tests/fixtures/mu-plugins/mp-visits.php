<?php
/**
 * Test double for the MP Visits 1.3 plugin (docs/reference/mp-visits-findings.md).
 * Registers the same post type and image size. The real plugin is never changed.
 *
 * @package Causeway\ClaudeBridge
 */

add_image_size( 'map-thumb', 250, 140, array( 'center', 'center' ) );

add_action(
	'init',
	static function (): void {
		register_post_type(
			'visits',
			array(
				'label'           => 'Visit',
				'labels'          => array(
					'name'          => 'Visits',
					'singular_name' => 'Visit',
				),
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'hierarchical'    => false,
				'public'          => true,
				'show_ui'         => true,
				'has_archive'     => true,
				'capability_type' => 'post',
			)
		);
	},
	0
);
