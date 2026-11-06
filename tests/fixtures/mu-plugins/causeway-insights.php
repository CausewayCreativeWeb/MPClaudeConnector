<?php
/**
 * Test double for the Causeway Insights 1.0 plugin (docs/reference/existing-plugins.md).
 * Registers the same post type, taxonomies and ACF field group. The real plugin is never changed.
 *
 * @package Causeway\ClaudeBridge
 */

add_action(
	'init',
	static function (): void {
		register_post_type(
			'resource',
			array(
				'label'        => 'Resources',
				'labels'       => array(
					'name'          => 'Resources',
					'singular_name' => 'Resource',
				),
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'post-formats' ),
				'can_export'   => false,
			)
		);
		register_taxonomy(
			'resource_category',
			'resource',
			array(
				'label'        => 'Categories',
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);
		register_taxonomy(
			'content-type',
			'resource',
			array(
				'label'        => 'Types',
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);
	},
	0
);

add_action(
	'acf/include_fields',
	static function (): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		acf_add_local_field_group(
			array(
				'key'          => 'group_655493151878f',
				'title'        => 'Resource Details',
				'fields'       => array(
					array(
						'key'           => 'field_655493153192f',
						'label'         => 'Downloadable file',
						'name'          => 'downloadable_file',
						'type'          => 'file',
						'return_format' => 'array',
						'required'      => 0,
					),
					array(
						'key'          => 'field_6895f57a6a12e',
						'label'        => 'Online reader URL',
						'name'         => 'online_reader_url',
						'type'         => 'page_link',
						'required'     => 0,
						'instructions' => 'Only to be used where an online reader is set up',
					),
					array(
						'key'      => 'field_68bef927b733c',
						'label'    => 'Author(s)',
						'name'     => 'authors',
						'type'     => 'textarea',
						'required' => 0,
					),
				),
				'location'     => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'resource',
						),
					),
				),
				'show_in_rest' => 0,
			)
		);
	}
);
