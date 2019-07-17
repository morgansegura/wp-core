<?php
class Presto_Post_API {

	/**
	 * Base path of this API controller.
	 *
	 * @const string
	 */
	const API_BASE = 'presto';

	/**
	 * The authenticator.
	 *
	 * @var object
	 */
	private $authenticator;

	/**
	 * Initialize a new Presto_Post_API.
	 *
	 * @param object $authenticator the authenticator
	 */
	public function __construct( $authenticator ) {
		$this->authenticator = $authenticator;
	}

	/**
	 * Register API routes.
	 */
	public function register_routes() {
		register_rest_route( self::API_BASE, '/post', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'handle_post' )
		) );

		register_rest_route( self::API_BASE, '/verify', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'verify_auth' )
		) );

		register_rest_route( self::API_BASE, '/authors', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_authors' )
		) );

		register_rest_route( self::API_BASE, '/new-secret', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'generate_secret' )
		) );
	}

	/**
	 * Handle the POST for post creation.
	 *
	 * This does the following:
	 *	 - decodes JSON payload
	 *	 - validates payload
	 *	 - downloads attachments to wp-content/uploads if provided
	 *	 - creates the post
	 *	 - links attachments to new post
	 *
	 * @param WP_REST_Request $request the request
	 *
	 * @return WP_REST_Response success and response details
	 */
	public function handle_post( WP_REST_Request $request ) {
		$payload = @json_decode( $request->get_body(), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'Could not parse JSON'
			), 400 );
		}

		list( $valid, $error ) = self::validate_post_payload( $payload );
		if ( ! $valid ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $error
			), 400 );
		}

		list( $allowed, $error ) = $this->check_authentication( $payload );
		if ( ! $allowed ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $error
			), 403 );
		}

		if ( isset( $payload['attachments'] ) ) {
			$downloads = self::download_attachments( $payload['attachments'] );
			$to_attach = $downloads['succeeded'];
		} else {
			$downloads = array();
			$to_attach = array();
		}

		if ( count( $to_attach ) > 0 && isset( $payload['featured_image'] ) ) {
			$featured_id = $payload['featured_image'];
		} else {
			$featured_id = null;
		}

		list( $result, $errors ) = self::create_post( $payload, $to_attach );
		if ( $result > -1 && null === $errors ) {
			$attached = self::import_attachments( $result, $to_attach, $featured_id );

			return new WP_REST_Response( array(
				'success'    => true,
				'post_id'    => $result,
				'post'       => $payload,
				'downloaded' => $downloads,
				'attached'   => $attached
			) );
		} else {
			return new WP_REST_Response( array(
				'success' => false,
				'errors'  => $errors
			), 400 );
		}
	}

	/**
	 * Test authentication of a request.
	 *
	 * @param WP_REST_Request $request the request
	 *
	 * @return WP_REST_Response success and response details
	 */
	public function verify_auth( WP_REST_Request $request ) {
		$payload = @json_decode( $request->get_body(), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'Could not parse JSON'
			), 400 );
		}

		list( $allowed, $error ) = $this->check_authentication( $payload );
		if ( ! $allowed ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $error,
				'payload' => $payload
			), 403 );
		}

		return new WP_REST_Response( array(
			'success' => true
		) );
	}

	/**
	 * Get authors with permission to post with the API.
	 *
	 * @param WP_REST_Request $request the request
	 *
	 * @return WP_REST_Response success and response details
	 */
	public function get_authors( WP_REST_Request $request ) {
		$capabilities = array( 'edit_posts' );
		$authors = array_filter(
			get_users(),
			function ( $author ) use ( $capabilities ) {
				$caps = $author->allcaps;
				foreach ( $capabilities as $cap ) {
					if ( ! isset( $caps[$cap] ) || true !== $caps[$cap] ) {
						return false;
					}
				}

				return true;
			}
		);

		$capable_authors = array_map( function ( $author ) {
			return array(
				'nicename' => $author->user_nicename,
				'roles'    => $author->roles,
				'id'       => $author->ID
			);
		}, $authors );

		return new WP_REST_Response( array(
			'success' => true,
			'authors' => array_values( $capable_authors )
		) );
	}

	/**
	 * Get a new secret.
	 *
	 * @param WP_REST_Request $request the request
	 *
	 * @return WP_REST_Response success and response details
	 */
	public function generate_secret( WP_REST_Request $request ) {
		return new WP_REST_Response( array(
			'success' => true,
			'secret'  => $this->authenticator->generate_secret()
		) );
	}

	/**
	 * Validate the POST payload.
	 *
	 * @param array $payload the payload
	 *
	 * @return array the success and any errors if encountered
	 */
	public static function validate_post_payload( $payload ) {
		// Request must at least contain a post title and body.
		$required_post_keys = array( 'title', 'body' );
		foreach ( $required_post_keys as $k ) {
			if ( ! isset( $payload[$k] ) ) {
				return array( false, "Missing required key '$k'" );
			}

			if ( 0 === strlen( $payload[$k] ) ) {
				return array( false, "Empty string for '$k'" );
			}
		}

		// If provided, 'attachments' should be an array of IDs to URLs.
		if ( isset( $payload['attachments'] ) ) {
			if ( ! is_array( $payload['attachments'] ) ) {
				return array( false, 'Attachments must be a list' );
			}
		}

		// If provided, 'categories' should be an array of slugs.
		if ( isset( $payload['categories'] ) ) {
			if ( ! is_array( $payload['categories'] ) ) {
				return array( false, 'Categories must be a list' );
			}
		}

		// If provided, 'tags' should be an array of tags.
		if ( isset( $payload['tags'] ) ) {
			if ( ! is_array( $payload['tags'] ) ) {
				return array( false, 'Tags must be a list' );
			}
		}

		return array( true, null );
	}

	/**
	 * Check that the current user can make this request.
	 *
	 * @param WP_REST_Request $request the request
	 *
	 * @return bool if the request is authenticated and an optional error
	 */
	public function check_authentication( $request ) {
		return $this->authenticator->authenticate( $request );
	}

	/**
	 * Create the post.
	 *
	 * @param array $payload the post payload
	 * @param array $images  an optional array of images to attach to this post
	 *
	 * If attachments are provided, the post content will also be
	 *	 altered to insert images into the body.
	 *
	 * @return array the post ID and null on success,
	 *	 -1 and errors on failure
	 */
	public static function create_post( $params, $images = array() ) {
		$status = ( isset( $params['status'] ) ) ? $params['status'] : 'publish';
		$body = self::insert_images( $params['body'], $images );

		$categories = array();
		if ( isset( $params['categories'] ) ) {
			$categories = array_reduce(
				$params['categories'],
				function ( $acc, $cat ) {
					$t = get_term_by( 'slug', $cat, 'category' );
					if ( false === $t ) {
						return array( 'error' => $cat );
					} else {
						$acc[] = $t->term_id;
						return $acc;
					}
				},
				array()
			);

			if ( isset( $categories['error'] ) ) {
				$cat = $categories['error'];
				return array( -1, array( "Cat $cat doesn't exist" ) );
			}
		}

		$tags = ( isset( $params['tags'] ) ) ? $params['tags'] : array();

		$post_args = array(
			'post_title'   => wp_strip_all_tags( $params['title'] ),
			'post_content' => $body,
			'post_status'  => $status,
			'tags_input'   => $tags
		);

		if ( isset( $params['author'] ) ) {
			$post_args['post_author'] = intval( $params['author'] );
		}

		if ( count( $categories ) ) {
			$post_args['post_category'] = $categories;
		}

		$result = wp_insert_post( $post_args, true );
		if ( is_array( $result ) && isset( $result['errors'] ) ) {
			return array( -1, $result['errors'] );
		}

		return array( $result, null );
	}

	/**
	 * Download attachments to uploads directory.
	 *
	 * @param array $attachments the attachments keyed by ID
	 *
	 * @return array successful and failed downloads
	 */
	public static function download_attachments( $attachments ) {
		$upload_dir = wp_upload_dir();
		$upload_url = $upload_dir['url'];
		$upload_dir = $upload_dir['path'];

		$files = array_map(
			function ( $id ) use ( $attachments, $upload_dir, $upload_url ) {
				$url = $attachments[$id];
				$filename = array_pop( explode( '/', $url ) );
				return array(
					'id'          => $id,
					'source_url'  => $url,
					'filename'    => $filename,
					'upload_path' => implode( '/', array( $upload_dir, $filename ) ),
					'upload_url'  => implode( '/', array( $upload_url, $filename ) )
				);
			},
			array_keys( $attachments )
		);

		return array_reduce(
			$files,
			function ( $acc, $file ) use ( $upload_dir ) {
				if ( copy( $file['source_url'], $file['upload_path'] ) ) {
					$acc['succeeded'][] = $file;
				} else {
					$acc['failed'][] = $file['id'];
				}

				return $acc;
			},
			array( 'succeeded' => array(), 'failed' => array() )
		);
	}

	/**
	 * Insert images into post body.
	 *
	 * If no images are provided, the body is obviously not altered.
	 *
	 * @param string $body	 the post body
	 * @param array  $images the images to insert
	 *
	 * @return string altered post body with images
	 */
	public static function insert_images( $body, $images ) {
		return array_reduce(
			$images,
			function ( $result, $image ) {
				$id = $image['id'];
				$url = $image['upload_url'];

				$re = "/{{img (${id})}}/";
				$tag = sprintf( '<img src="%s">', $url );

				return preg_replace( $re, $tag, $result );
			},
			$body
		);
	}

	/**
	 * Import downloaded attachments and attach to the new post.
	 *
	 * @param int		$post_id	 the new post ID
	 * @param array $to_attach the attachments
	 */
	public static function import_attachments(
		$post_id,
		$to_attach,
		$featured_id = null
	) {
		return array_reduce(
			$to_attach,
			function ( $acc, $attachment ) use ( $post_id, $featured_id ) {
				$path = $attachment['upload_path'];
				$id = $attachment['id'];

				$filename = basename( $path );
				$type = wp_check_filetype( $filename, null );
				$title = $filename;

				$attachment_params = array(
					'guid'           => $attachment['upload_url'],
					'post_mime_type' => $type['type'],
					'post_title'     => $title,
					'post_status'    => 'inherit',
					'post_content'   => ''
				);

				$attachment_id = wp_insert_attachment(
					$attachment_params,
					$path,
					$post_id
				);

				if ( $attachment_id > 0 ) {
					self::set_attachment_metadata( $attachment_id, $path );
					if ( null !== $featured_id && $featured_id === $id ) {
						$set_featured = set_post_thumbnail( $post_id, $attachment_id );
					} else {
						$set_featured = false;
					}

					$acc['succeeded'][] = array(
						'id'            => $id,
						'attachment_id' => $attachment_id,
						'set_featured'  => $set_featured
					);
				} else {
					$acc['failed'][] = $id;
				}

				return $acc;
			},
			array( 'succeeded' => array(), 'failed' => array() )
		);
	}

	/**
	 * Set attachment metadata.
	 *
	 * @param int		 $attachment_id the new attachment ID
	 * @param string $path					the path to the image
	 */
	public static function set_attachment_metadata( $attachment_id, $path ) {
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}
}
