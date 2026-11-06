<?php
/**
 * Creating and updating posts (docs/03 `ccb/create-post`, `ccb/update-post`; docs/05).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use Causeway\ClaudeBridge\Abilities\AbilityContext;
use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Abilities\Sections;
use Causeway\ClaudeBridge\Audit\RequestContext;
use Causeway\ClaudeBridge\Checks\PostVerifier;
use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Media\ImageRules;
use Causeway\ClaudeBridge\Roles\RoleManager;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Validates everything first, then writes in this order: post, terms, fields, featured image, SEO,
 * Bridge meta. Field values (e.g. `visits_location`) are written after wp_insert_post() and
 * wp_update_post(), so other plugins' save_post routines run before them.
 *
 * Never writes Elementor meta, never creates terms, never sets publish or future.
 */
final class PostWriter {

	public const TITLE_MAX = 200;

	/**
	 * Sections, config and structure.
	 *
	 * @var Sections
	 */
	private Sections $sections;

	/**
	 * Sets up the writer.
	 *
	 * @param Sections|null $sections Sections.
	 */
	public function __construct( ?Sections $sections = null ) {
		$this->sections = $sections ?? new Sections();
	}

	/**
	 * Creates a post.
	 *
	 * @param array<string, mixed> $input   Validated ability input.
	 * @param AbilityContext       $context Audit context.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $input, AbilityContext $context ): array|WP_Error {
		$user_id = get_current_user_id();
		$key     = (string) $input['section'];

		if ( isset( $input['idempotency_key'] ) ) {
			$previous = Idempotency::find( $user_id, (string) $input['idempotency_key'] );
			if ( null !== $previous ) {
				$context->details = array( 'idempotent_replay' => true );
				$context->post_id = isset( $previous['post_id'] ) ? (int) $previous['post_id'] : null;
				return $previous;
			}
		}

		$section = $this->sections->get( $key );
		if ( $section instanceof WP_Error ) {
			return $section;
		}
		$context->section = $key;
		$guide            = $this->guide( $key );

		if ( ! isset( $input['title'] ) ) {
			return AbilityError::make( 'ccb_invalid_input', __( 'A title is required.', 'causeway-claude-bridge' ), 400, 'title' );
		}

		$status = $this->status_for_create( $input['status'] ?? null );
		if ( $status instanceof WP_Error ) {
			return $status;
		}

		$prepared = $this->prepare( $input, $section, $guide, null );
		if ( $prepared instanceof WP_Error ) {
			return $prepared;
		}
		$context->title     = $prepared['post']['post_title'];
		$context->status_to = $status;

		if ( empty( $input['confirm_duplicate'] ) ) {
			$duplicate = $this->find_duplicate( $section['post_type'], $prepared['post']['post_title'] );
			if ( null !== $duplicate ) {
				return AbilityError::make(
					'ccb_possible_duplicate',
					/* translators: 1: post title, 2: post ID */
					sprintf( __( 'A post called "%1$s" was created in this section in the last 24 hours (ID %2$d).', 'causeway-claude-bridge' ), $prepared['post']['post_title'], $duplicate ),
					409,
					'title',
					__( 'Check with the user. If they want a second post, call create-post again with confirm_duplicate set to true.', 'causeway-claude-bridge' ),
					null,
					array( 'existing_post_id' => $duplicate )
				);
			}
		}

		$post_id = wp_insert_post(
			wp_slash(
				array_merge(
					$prepared['post'],
					array(
						'post_type'   => $section['post_type'],
						'post_status' => $status,
						'post_author' => $user_id,
					)
				)
			),
			true
		);
		if ( $post_id instanceof WP_Error ) {
			return AbilityError::make( 'ccb_internal', __( 'WordPress couldn\'t save the post.', 'causeway-claude-bridge' ), 500, null, $post_id->get_error_message() );
		}
		$context->post_id = $post_id;

		$this->write_extras( $post_id, $prepared, ( new TermResolver( $guide ) )->with_defaults( $prepared['terms'] ) );
		update_post_meta(
			$post_id,
			'_ccb_created_via',
			wp_slash(
				(string) wp_json_encode(
					array(
						'via'          => 'claude',
						'user_id'      => $user_id,
						'app_password' => RequestContext::app_password()['name'] ?? null,
					)
				)
			)
		);
		self::mark_change( $post_id, 'create' );

		$response         = $this->response( $post_id, $section, $guide, $prepared['warnings'], $status, $user_id );
		$context->details = array(
			'fields'   => array_keys( $prepared['changed'] ),
			'warnings' => $prepared['warnings'],
			'verified' => $response['verification']['passed'],
		);

		if ( isset( $input['idempotency_key'] ) ) {
			Idempotency::store( $user_id, (string) $input['idempotency_key'], $post_id, $response );
		}

		/**
		 * Fires after the Bridge creates a post (notifications hook on in M7).
		 *
		 * @param int    $post_id Post.
		 * @param string $status  Status.
		 */
		do_action( 'ccb_post_created', $post_id, $status );

		return $response;
	}

	/**
	 * Updates a draft or pending post.
	 *
	 * @param array<string, mixed> $input   Validated ability input.
	 * @param AbilityContext       $context Audit context.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( array $input, AbilityContext $context ): array|WP_Error {
		$found = $this->sections->post( (int) $input['post_id'] );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post, $key ] = $found;
		$context->about_post( $post, $key );

		if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			return AbilityError::make(
				'ccb_wrong_status',
				/* translators: %s: post status */
				sprintf( __( 'This post is %s, so it can\'t be edited with update-post.', 'causeway-claude-bridge' ), $post->post_status ),
				409,
				'post_id',
				in_array( $post->post_status, array( 'publish', 'future' ), true )
					? __( 'Use prepare-update-live, show the user the summary, then update-live-post.', 'causeway-claude-bridge' )
					: __( 'Restore it from the Bin first.', 'causeway-claude-bridge' )
			);
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'You can\'t edit this post.', 'causeway-claude-bridge' ), 403, 'post_id' );
		}
		if ( isset( $input['expected_modified_gmt'] ) && ! self::same_time( (string) $input['expected_modified_gmt'], $post->post_modified_gmt ) ) {
			return AbilityError::make(
				'ccb_post_changed',
				__( 'Someone changed this post since you last read it.', 'causeway-claude-bridge' ),
				409,
				'expected_modified_gmt',
				__( 'Call get-post, show the user the latest version, and ask before changing it.', 'causeway-claude-bridge' )
			);
		}

		$section = $this->sections->all()[ $key ];
		$guide   = $this->guide( $key );

		$status = $post->post_status;
		if ( isset( $input['status'] ) && current_user_can( RoleManager::CAP_PUBLISH ) ) {
			if ( ! in_array( $input['status'], array( 'draft', 'pending' ), true ) ) {
				return self::live_status_refused();
			}
			$status = (string) $input['status'];
		}

		$prepared = $this->prepare( $input, $section, $guide, $post );
		if ( $prepared instanceof WP_Error ) {
			return $prepared;
		}
		$context->status_to = $status;
		if ( isset( $prepared['post']['post_title'] ) ) {
			$context->title = $prepared['post']['post_title'];
		}

		$changes = array_merge(
			$prepared['post'],
			array(
				'ID'          => $post->ID,
				'post_status' => $status,
			)
		);
		$result  = wp_update_post( wp_slash( $changes ), true );
		if ( $result instanceof WP_Error ) {
			return AbilityError::make( 'ccb_internal', __( 'WordPress couldn\'t save the post.', 'causeway-claude-bridge' ), 500, null, $result->get_error_message() );
		}

		$this->write_extras( $post->ID, $prepared, $prepared['terms'] );
		self::mark_change( $post->ID, 'update' );

		$response         = $this->response( $post->ID, $section, $guide, $prepared['warnings'], $status, null );
		$context->details = array(
			'fields'   => array_keys( $prepared['changed'] ),
			'warnings' => $prepared['warnings'],
			'verified' => $response['verification']['passed'],
		);

		/**
		 * Fires after the Bridge updates a draft or pending post.
		 *
		 * @param int    $post_id Post.
		 * @param string $status  Status.
		 */
		do_action( 'ccb_post_updated', $post->ID, $status );

		return $response;
	}

	/**
	 * Validates and cleans every given field without writing anything.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param array<string, mixed> $section Section config.
	 * @param array<string, mixed> $guide   Section guide entry.
	 * @param WP_Post|null         $post    Existing post (update) or null (create).
	 * @return array{post: array<string, mixed>, terms: array<string, list<int>>, fields: list<array{name: string, source: string, key: string, value: mixed}>, featured_image: int|null, seo: array<string, string>, warnings: list<string>, changed: array<string, true>}|WP_Error
	 */
	public function prepare( array $input, array $section, array $guide, ?WP_Post $post ): array|WP_Error {
		$config   = $this->sections->config();
		$writing  = $config['writing'];
		$limits   = $section['limits'];
		$warnings = array();
		$fields   = array();
		$changed  = array();

		if ( array_key_exists( 'title', $input ) ) {
			$title = trim( sanitize_text_field( wp_strip_all_tags( (string) $input['title'] ) ) );
			if ( '' === $title ) {
				return AbilityError::make( 'ccb_invalid_input', __( 'The title can\'t be empty.', 'causeway-claude-bridge' ), 400, 'title' );
			}
			if ( mb_strlen( $title ) > self::TITLE_MAX ) {
				/* translators: %d: maximum characters */
				return AbilityError::make( 'ccb_invalid_input', sprintf( __( 'The title must be %d characters or fewer.', 'causeway-claude-bridge' ), self::TITLE_MAX ), 400, 'title' );
			}
			$fields['post_title'] = $title;
			$changed['title']     = true;
		}

		if ( array_key_exists( 'content', $input ) ) {
			if ( null !== $post && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
				return AbilityError::make(
					'ccb_elementor_post',
					__( 'This post\'s layout is built with Elementor, so its content can only be edited in WP Admin.', 'causeway-claude-bridge' ),
					409,
					'content',
					__( 'Leave the content out. The title, terms, fields and featured image can still be changed.', 'causeway-claude-bridge' )
				);
			}
			$clean = ( new ContentSanitiser( $writing ) )->sanitise( (string) $input['content'], $section['post_type'] );
			array_push( $warnings, ...$clean['warnings'] );

			if ( $limits['max_words'] > 0 && $clean['word_count'] > $limits['max_words'] ) {
				return AbilityError::make(
					'ccb_limit_exceeded',
					/* translators: 1: word count, 2: maximum */
					sprintf( __( 'The content has %1$d words; this section allows %2$d.', 'causeway-claude-bridge' ), $clean['word_count'], $limits['max_words'] ),
					400,
					'content',
					__( 'Shorten it and try again.', 'causeway-claude-bridge' )
				);
			}
			if ( $limits['min_words'] > 0 && $clean['word_count'] < $limits['min_words'] ) {
				/* translators: 1: word count, 2: minimum */
				$warnings[] = sprintf( __( 'The content has %1$d words; this section suggests at least %2$d.', 'causeway-claude-bridge' ), $clean['word_count'], $limits['min_words'] );
			}
			if ( $limits['max_images'] > 0 && count( $clean['image_ids'] ) > $limits['max_images'] ) {
				return AbilityError::make(
					'ccb_limit_exceeded',
					/* translators: 1: image count, 2: maximum */
					sprintf( __( 'The content has %1$d images; this section allows %2$d.', 'causeway-claude-bridge' ), count( $clean['image_ids'] ), $limits['max_images'] ),
					400,
					'content',
					__( 'Remove some images and try again.', 'causeway-claude-bridge' )
				);
			}
			$fields['post_content'] = $clean['html'];
			$changed['content']     = true;
		}

		if ( array_key_exists( 'excerpt', $input ) ) {
			$excerpt = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $input['excerpt'] ) ) );
			$max     = (int) $limits['excerpt_max_chars'];
			if ( $max > 0 && mb_strlen( $excerpt ) > $max ) {
				$excerpt = self::truncate_words( $excerpt, $max );
				/* translators: %d: maximum characters */
				$warnings[] = sprintf( __( 'The excerpt was shortened to %d characters.', 'causeway-claude-bridge' ), $max );
			}
			$fields['post_excerpt'] = $excerpt;
			$changed['excerpt']     = true;
		}

		if ( array_key_exists( 'slug', $input ) ) {
			$slug = sanitize_title( (string) $input['slug'] );
			if ( '' === $slug ) {
				return AbilityError::make( 'ccb_invalid_input', __( 'The slug must contain letters or numbers.', 'causeway-claude-bridge' ), 400, 'slug' );
			}
			$fields['post_name'] = $slug;
			$changed['slug']     = true;
		}

		$terms = array();
		if ( isset( $input['terms'] ) ) {
			$terms = ( new TermResolver( $guide ) )->resolve( (array) $input['terms'] );
			if ( $terms instanceof WP_Error ) {
				return $terms;
			}
			$changed['terms'] = true;
		}

		$field_writes = array();
		if ( isset( $input['fields'] ) ) {
			$writer       = new FieldWriter( $guide, $section, $this->sections->structure()['acf']['fields_by_post_type'][ $section['post_type'] ] ?? array(), $writing );
			$field_writes = $writer->prepare( (array) $input['fields'] );
			if ( $field_writes instanceof WP_Error ) {
				return $field_writes;
			}
			$changed['fields'] = true;
		}

		$featured = null;
		if ( array_key_exists( 'featured_image_id', $input ) ) {
			$featured = (int) $input['featured_image_id'];
			if ( $featured > 0 ) {
				$problem = self::check_featured_image( $featured, $section['featured_image'] );
				if ( null !== $problem ) {
					return $problem;
				}
			}
			$changed['featured_image'] = true;
		}

		$seo = array();
		if ( isset( $input['seo'] ) && is_array( $input['seo'] ) && array() !== array_filter( $input['seo'], 'is_string' ) ) {
			$keys = SeoFields::keys( $config );
			if ( null === $keys ) {
				$warnings[] = __( 'No SEO plugin is active, so the SEO title and description were not saved.', 'causeway-claude-bridge' );
			} else {
				foreach ( array(
					'title'       => 'title_max',
					'description' => 'description_max',
				) as $part => $limit ) {
					if ( ! isset( $input['seo'][ $part ] ) ) {
						continue;
					}
					$value = trim( sanitize_text_field( (string) $input['seo'][ $part ] ) );
					$max   = (int) $writing['seo'][ $limit ];
					if ( mb_strlen( $value ) > $max ) {
						$value = self::truncate_words( $value, $max );
						/* translators: 1: SEO part, 2: maximum characters */
						$warnings[] = sprintf( __( 'The SEO %1$s was shortened to %2$d characters.', 'causeway-claude-bridge' ), $part, $max );
					}
					$seo[ $keys[ $part ] ] = $value;
				}
				$changed['seo'] = true;
			}
		}

		return array(
			'post'           => $fields,
			'terms'          => $terms,
			'fields'         => $field_writes,
			'featured_image' => $featured,
			'seo'            => $seo,
			'warnings'       => $warnings,
			'changed'        => $changed,
		);
	}

	/**
	 * Writes terms, fields, featured image and SEO for a saved post.
	 *
	 * @param int                      $post_id  Post.
	 * @param array<string, mixed>     $prepared From prepare().
	 * @param array<string, list<int>> $terms    Terms to write.
	 */
	public function write_extras( int $post_id, array $prepared, array $terms ): void {
		TermResolver::write( $post_id, $terms );
		FieldWriter::write( $post_id, $prepared['fields'] );

		if ( null !== $prepared['featured_image'] ) {
			if ( $prepared['featured_image'] > 0 ) {
				set_post_thumbnail( $post_id, $prepared['featured_image'] );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		foreach ( $prepared['seo'] as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
		}
	}

	/**
	 * Status for a new post (docs/03 create-post rule 2).
	 *
	 * @param mixed $requested Status sent.
	 * @return string|WP_Error
	 */
	private function status_for_create( mixed $requested ): string|WP_Error {
		if ( ! current_user_can( RoleManager::CAP_PUBLISH ) ) {
			return 'pending'; // AI Contributors: always Pending Review, whatever was sent.
		}
		if ( null === $requested ) {
			return 'pending';
		}
		if ( in_array( $requested, array( 'draft', 'pending' ), true ) ) {
			return (string) $requested;
		}
		return self::live_status_refused();
	}

	/**
	 * Error for trying to publish or schedule through create/update.
	 */
	private static function live_status_refused(): WP_Error {
		return AbilityError::make(
			'ccb_wrong_status',
			__( 'Posts can\'t be published or scheduled from create-post or update-post.', 'causeway-claude-bridge' ),
			409,
			'status',
			__( 'Save it as a draft or pending, then use prepare-publish or prepare-schedule and ask the user to confirm.', 'causeway-claude-bridge' )
		);
	}

	/**
	 * Checks a featured image against the section rules (ratio ±3%, minimum width).
	 *
	 * @param int                  $attachment_id Attachment.
	 * @param array<string, mixed> $rules         Section featured_image rules.
	 */
	public static function check_featured_image( int $attachment_id, array $rules ): ?WP_Error {
		if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return AbilityError::make( 'ccb_field_invalid', __( 'The featured image must be an image in the Media Library.', 'causeway-claude-bridge' ), 400, 'featured_image_id', __( 'Upload it with upload-image first.', 'causeway-claude-bridge' ) );
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return AbilityError::make( 'ccb_field_invalid', __( 'The featured image\'s size couldn\'t be read.', 'causeway-claude-bridge' ), 400, 'featured_image_id', __( 'Upload it again with upload-image.', 'causeway-claude-bridge' ) );
		}
		return ImageRules::check_featured( (int) $meta['width'], (int) $meta['height'], $rules, 'featured_image_id' );
	}

	/**
	 * Post created in the same section with the same title in the last 24 hours.
	 *
	 * @param string $post_type Post type.
	 * @param string $title     Title.
	 */
	private function find_duplicate( string $post_type, string $title ): ?int {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'draft', 'pending', 'future', 'publish', 'private' ),
				'title'                  => $title,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'column' => 'post_date',
						'after'  => (string) wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
					),
				),
			)
		);
		return array() === $query->posts ? null : (int) $query->posts[0];
	}

	/**
	 * Response for create and update.
	 *
	 * @param int                  $post_id  Post.
	 * @param array<string, mixed> $section  Section config.
	 * @param array<string, mixed> $guide    Section guide entry.
	 * @param string[]             $warnings Warnings.
	 * @param string               $status   Expected status.
	 * @param int|null             $author   Expected author.
	 * @return array<string, mixed>
	 */
	private function response( int $post_id, array $section, array $guide, array $warnings, string $status, ?int $author ): array {
		$expected = array( 'status' => $status );
		if ( null !== $author ) {
			$expected['author'] = $author;
		}
		$verification = PostVerifier::verify( $post_id, $section, $guide, $this->sections->config()['writing'], $expected );
		$post         = get_post( $post_id );

		return array(
			'post_id'      => $post_id,
			'status'       => $post instanceof WP_Post ? $post->post_status : $status,
			'preview_url'  => $post instanceof WP_Post ? get_preview_post_link( $post ) : null,
			'edit_url'     => (string) get_edit_post_link( $post_id, 'raw' ),
			'verification' => $verification,
			'warnings'     => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Section entry from the publishing guide.
	 *
	 * @param string $key Section key.
	 * @return array<string, mixed>
	 */
	public function guide( string $key ): array {
		return PublishingGuide::build( $this->sections->config(), $this->sections->structure(), $key )['sections'][0];
	}

	/**
	 * Records the last Bridge action on a post.
	 *
	 * @param int    $post_id Post.
	 * @param string $action  Action.
	 */
	public static function mark_change( int $post_id, string $action ): void {
		update_post_meta(
			$post_id,
			'_ccb_last_change',
			wp_slash(
				(string) wp_json_encode(
					array(
						'action'  => $action,
						'user_id' => get_current_user_id(),
						'at_gmt'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
					)
				)
			)
		);
	}

	/**
	 * Whether an ISO 8601 time from Claude matches a stored GMT time (to the second).
	 *
	 * @param string $iso    Time from Claude.
	 * @param string $stored MySQL GMT time.
	 */
	private static function same_time( string $iso, string $stored ): bool {
		$given = strtotime( $iso );
		$have  = strtotime( $stored . ' UTC' );
		return false !== $given && false !== $have && $given === $have;
	}

	/**
	 * Shortens text to a maximum length at a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 */
	private static function truncate_words( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false === $space || $space < $max / 2 ? $cut : mb_substr( $cut, 0, $space ), ' ,.;:-' );
	}
}
