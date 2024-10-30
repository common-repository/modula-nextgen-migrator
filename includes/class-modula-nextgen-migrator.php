<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Modula_Nextgen_Migrator {

	/**
	 * Holds the class object.
	 *
	 * @var object
	 *
	 * @since 1.0.0
	 */
	public static $instance;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		require_once MODULA_NEXTGEN_MIGRATOR_PATH . 'includes/class-modula-plugin-checker.php';

		if ( class_exists( 'Modula_Plugin_Checker' ) ) {
			$modula_checker = Modula_Plugin_Checker::get_instance();

			if ( ! $modula_checker->check_for_modula() ) {
				if ( is_admin() ) {
					add_action( 'admin_notices', array( $modula_checker, 'display_modula_notice' ) );
					add_action( 'plugins_loaded', array( $this, 'set_locale', 15 ) );
				}
			} else {
				// Add AJAX
				add_action( 'wp_ajax_modula_importer_nextgen_gallery_import', array(
					$this,
					'nextgen_gallery_import'
				) );
				add_action( 'wp_ajax_modula_importer_nextgen_gallery_imported_update', array(
					$this,
					'update_imported'
				) );

				// Add infor used for Modula's migrate functionality
				add_filter( 'modula_migrator_sources', array( $this, 'add_source' ), 15, 1 );
				add_filter( 'modula_source_galleries_nextgen', array( $this, 'add_source_galleries' ), 15, 1 );
				add_filter( 'modula_g_gallery_nextgen', array( $this, 'add_gallery_info' ), 15, 3 );
				add_filter( 'modula_migrator_images_nextgen', array( $this, 'migrator_images' ), 15, 2 );
				add_filter( 'modula_migrate_attachments_nextgen', array( $this, 'attachments' ), 15, 3 );
			}
		}
	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Modula_Nextgen_Migrator ) ) {
			self::$instance = new Modula_Nextgen_Migrator();
		}

		return self::$instance;
	}


	/**
	 * Get all NextGEN Galleries
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	public function get_galleries() {
		global $wpdb;
		$empty_galleries = array();

		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "ngg_gallery'" ) ) {
			return false;
		}

		$galleries = $wpdb->get_results( ' SELECT * FROM ' . $wpdb->prefix . 'ngg_gallery' );
		if ( count( $galleries ) != 0 ) {
			foreach ( $galleries as $key => $gallery ) {
				$count = $this->images_count( $gallery->gid );

				if ( $count == 0 ) {
					unset( $galleries[ $key ] );
					$empty_galleries[ $key ] = $gallery;
				}
			}

			if ( count( $galleries ) != 0 ) {
				$return_galleries['valid_galleries'] = $galleries;
			}
			if ( count( $empty_galleries ) != 0 ) {
				$return_galleries['empty_galleries'] = $empty_galleries;
			}

			if ( count( $return_galleries ) != 0 ) {
				return $return_galleries;
			}
		}

		return false;
	}


	/**
	 * Get gallery image count
	 *
	 * @param $id
	 *
	 * @return int
	 *
	 * @since 1.0.0
	 */
	public function images_count( $id ) {
		global $wpdb;

		$sql = $wpdb->prepare( 'SELECT COUNT(pid) FROM ' . $wpdb->prefix . 'ngg_pictures
    						WHERE galleryid = %d ',
		                       $id );

		$images = $wpdb->get_results( $sql );
		$count  = get_object_vars( $images[0] );
		$count  = $count['COUNT(pid)'];

		return $count;
	}


	/**
	 * Imports a gallery from NextGEN into Modula
	 *
	 * @param  string  $gallery_id
	 *
	 * @param  string  $attachments
	 *
	 * @since 1.0.0
	 */
	public function nextgen_gallery_import( $gallery_id = '', $attachments = '' ) {
		global $wpdb;
		$modula_importer = Modula_Importer::get_instance();

		// Set max execution time so we don't timeout
		ini_set( 'max_execution_time', 0 );
		set_time_limit( 0 );

		// If no gallery ID, get from AJAX request
		if ( empty( $gallery_id ) ) {
			// Run a security check first.
			check_ajax_referer( 'modula-importer', 'nonce' );

			if ( ! isset( $_POST['id'] ) ) {
				$this->modula_import_result( false, esc_html__( 'No gallery was selected', 'modula-nextgen-migrator' ), false );
			}

			$gallery_id = absint( $_POST['id'] );
		}

		if ( isset( $_POST['imported'] ) && sanitize_text_field( wp_unslash( $_POST['imported'] ) ) ) {
			// Trigger delete function if option is set to delete
			if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
				$this->clean_entries( $gallery_id );
			}
			$this->modula_import_result( false, esc_html__( 'Gallery already migrated!', 'modula-nextgen-migrator' ), false );
		}

		if ( empty( $attachments ) ) {
			// Run a security check first.
			check_ajax_referer( 'modula-importer', 'nonce' );

			if ( ! isset( $_POST['attachments'] ) ) {
				$this->modula_import_result( false, esc_html__( 'There are no images to be imported', 'modula-nextgen-migrator' ), false );
			}

			$attachments = array();
			foreach ( wp_unslash( $_POST['attachments'] ) as $attach ) {
				foreach ( $attach as $key => $value ) {
					switch ( $key ) {
						case 'ID':
							$attach['ID'] = absint( $value );
							break;
						case 'caption':
							$attach['caption'] = wp_filter_post_kses( $value );
							break;
						default:
							$attach[ $key ] = sanitize_text_field( $value );
							break;
					}
				}
				$attachments[] = array_map( 'wp_kses_post', $attach );
			}
		}

		$imported_galleries = get_option( 'modula_importer' );

		if ( isset( $imported_galleries['galleries']['nextgen'][ $gallery_id ] ) ) {
			$modula_gallery = get_post_type( $imported_galleries['galleries']['nextgen'][ $gallery_id ] );
			// If already migrated don't migrate
			if ( 'modula-gallery' == $modula_gallery ) {
				// Trigger delete function if option is set to delete
				if ( isset( $_POST['clean'] ) && 'delete' == sanitize_text_field( wp_unslash( $_POST['clean'] ) ) ) {
					$this->clean_entries( $gallery_id );
				}
				$this->modula_import_result( false, esc_html__( 'Gallery already migrated!', 'modula-nextgen-migrator' ), false );
			}
		}

		// Get image path
		$sql2 = $wpdb->prepare( 'SELECT post_content 
                            FROM ' . $wpdb->prefix . 'posts
                            WHERE post_title = %s
                            LIMIT 1',
		                        'NextGEN Basic Thumbnails' );

		$data_settings = json_decode( base64_decode( $wpdb->get_row( $sql2 )->post_content ) );

		$sql     = $wpdb->prepare( 'SELECT path, title, galdesc, pageid 
    						FROM ' . $wpdb->prefix . 'ngg_gallery
    						WHERE gid = %d
    						LIMIT 1',
		                           $gallery_id );
		$gallery = $wpdb->get_row( $sql );

		$col_number = $data_settings->settings->number_of_columns;

		if ( (int) $col_number > 6 ) {
			$col_number = '6';
		}

		if ( (int) $col_number == 0 ) {
			$col_number = 'automatic';
		}


		if ( count( $attachments ) == 0 ) {
			// Trigger delete function if option is set to delete
			if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
				$this->clean_entries( $gallery_id );
			}
			$this->modula_import_result( false, esc_html__( 'No images found in gallery. Skipping gallery...', 'modula-nextgen-migrator' ), false );
		}

		$ngg_settings = apply_filters( 'modula_migrate_gallery_data', array(
			'type'      => 'grid',
			'grid_type' => $col_number
		),                             $data_settings, 'nextgen' );

		// Get Modula Gallery defaults, used to set modula-settings metadata
		$modula_settings = wp_parse_args( $ngg_settings, Modula_CPT_Fields_Helper::get_defaults() );

		// Build Modula Gallery modula-images metadata
		$modula_images = array();
		foreach ( $attachments as $attachment ) {
			$modula_images[] = apply_filters( 'modula_migrate_image_data', array(
				'id'          => absint( $attachment['ID'] ),
				'alt'         => sanitize_text_field( $attachment['alt'] ),
				'title'       => sanitize_text_field( $attachment['title'] ),
				'description' => wp_filter_post_kses( $attachment['caption'] ),
				'halign'      => 'center',
				'valign'      => 'middle',
				// We don't use the link from here as it is set for image URL
				'link'        => '',
				'target'      => '',
				'width'       => 2,
				'height'      => 2,
				'filters'     => ''
			),                                $attachment, $data_settings, 'nextgen' );
		}

		// Create Modula CPT
		$modula_gallery_id = wp_insert_post( array(
			                                     'post_type'   => 'modula-gallery',
			                                     'post_status' => 'publish',
			                                     'post_title'  => sanitize_text_field( $gallery->title ),
		                                     ) );

		// Attach meta modula-settings to Modula CPT
		update_post_meta( $modula_gallery_id, 'modula-settings', $modula_settings );

		// Attach meta modula-images to Modula CPT
		update_post_meta( $modula_gallery_id, 'modula-images', $modula_images );

		$nextgen_shortcode   = '[ngg_images gallery_ids="' . $gallery_id . '"]';
		$nextgen_shortcode_2 = '[ngg src="galleries" ids="' . $gallery_id . '" display="basic_thumbnail" thumbnail_crop="0"]';
		$nextgen_shortcode_3 = '[nggallery id="' . $gallery_id . '"]';
		$modula_shortcode    = '[modula id="' . $modula_gallery_id . '"]';

		// Replace NextGEN shortcode with Modula Shortcode in Posts, Pages and CPTs
		$sql   = $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . "posts SET post_content = REPLACE(post_content, '%s', '%s')",
		                         $nextgen_shortcode, $modula_shortcode );
		$sql_2 = $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . "posts SET post_content = REPLACE(post_content, '%s', '%s')",
		                         $nextgen_shortcode_2, $modula_shortcode );
		$sql_3 = $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . "posts SET post_content = REPLACE(post_content, '%s', '%s')",
		                         $nextgen_shortcode_3, $modula_shortcode );

		$wpdb->query( $sql );
		$wpdb->query( $sql_2 );
		$wpdb->query( $sql_3 );

		//@todo : gutenberg block replacement functionality
		/*$sql_gutenberg       = "SELECT * FROM " . $wpdb->prefix . "posts WHERE `post_content` LIKE '%wp:imagely/nextgen-gallery%'";
		$galleries_gutenberg = $wpdb->get_results($sql_gutenberg);

		if(count($galleries_gutenberg) > 0){
			foreach($galleries_gutenberg as $gutenberg){
				$content       = $gutenberg->post_content;
				$search_string = '/ids\s*=\s*\"([\s\S]*?)\"/';
				$pattern       = '/<!-- wp:imagely/nextgen-gallery -->\s*\[\s*ngg\s*ids\s*=\s*\"([\s\S]*?)\"/';
				$result        = preg_match_all($pattern, $content, $matches);
				var_dump($content,$result);die();
				if ( $result && $result > 0 ) {
					var_dump($matches[0]);die();
					foreach ( $matches[0] as $sc ) {
					}
				}
			}

		}*/

		if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
			$this->clean_entries( $gallery_id );
		}
		$this->modula_import_result( true, wp_kses_post( '<i class="imported-check dashicons dashicons-yes"></i>' ), $modula_gallery_id );
	}

	/**
	 * Update imported galleries
	 *
	 *
	 * @since 1.0.0
	 */
	public function update_imported() {
		check_ajax_referer( 'modula-importer', 'nonce' );

		if ( ! isset( $_POST['galleries'] ) ) {
			wp_send_json_error();
		}

		$galleries = array_map( 'absint', wp_unslash( $_POST['galleries'] ) );

		$importer_settings = get_option( 'modula_importer' );

		if ( ! is_array( $importer_settings ) ) {
			$importer_settings = array();
		}

		if ( ! isset( $importer_settings['galleries']['nextgen'] ) ) {
			$importer_settings['galleries']['nextgen'] = array();
		}

		if ( is_array( $galleries ) && count( $galleries ) > 0 ) {
			foreach ( $galleries as $key => $value ) {
				$importer_settings['galleries']['nextgen'][ absint( $key ) ] = absint( $value );
			}
		}

		update_option( 'modula_importer', $importer_settings );

		// Set url if migration complete
		$url = admin_url( 'edit.php?post_type=modula-gallery&page=modula&modula-tab=importer&migration=complete' );

		if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
			// Set url if migration and cleaning complete
			$url = admin_url( 'edit.php?post_type=modula-gallery&page=modula&modula-tab=importer&migration=complete&delete=complete' );
		}

		echo $url;
		wp_die();
	}


	/**
	 * Returns result
	 *
	 * @param $success
	 * @param $message
	 * @param $modula_gallery_id
	 *
	 * @since 1.0.0
	 */
	public function modula_import_result( $success, $message, $modula_gallery_id = false ) {
		echo json_encode( array(
			                  'success'           => (bool) $success,
			                  'message'           => (string) $message,
			                  'modula_gallery_id' => $modula_gallery_id
		                  ) );
		die;
	}

	/**
	 * Delete old entries from database
	 *
	 * @param $gallery_id
	 *
	 * @since 1.0.0
	 */
	public function clean_entries( $gallery_id ) {
		global $wpdb;

		$sql      = $wpdb->prepare( 'DELETE FROM  ' . $wpdb->prefix . "ngg_gallery WHERE gid = $gallery_id" );
		$sql_meta = $wpdb->prepare( 'DELETE FROM  ' . $wpdb->prefix . "ngg_pictures WHERE galleryid = $gallery_id" );
		$wpdb->query( $sql );
		$wpdb->query( $sql_meta );
	}

	/**
	 * Add NextGEN source to Modula gallery sources
	 *
	 * @param $sources
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function add_source( $sources ) {
		global $wpdb;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "ngg_gallery'" ) ) {
			$nextgen = $wpdb->get_results( ' SELECT COUNT(gid) FROM ' . $wpdb->prefix . 'ngg_gallery' );
		}

		$nextgen_return = ( null != $nextgen ) ? get_object_vars( $nextgen[0] ) : false;

		if ( $nextgen && null != $nextgen && ! empty( $nextgen ) && $nextgen_return && '0' != $nextgen_return['COUNT(gid)'] ) {
			$sources['nextgen'] = 'NextGEN Gallery';
		}

		return $sources;
	}

	/**
	 * Add our source galleries
	 *
	 * @param $galleries
	 *
	 * @return false|mixed
	 * @since 1.0.0
	 */
	public function add_source_galleries( $galleries ) {
		return $this->get_galleries();
	}

	/**
	 * Return Gallery info
	 *
	 * @param $g_gallery
	 * @param $gallery
	 * @param $import_settings
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function add_gallery_info( $g_gallery, $gallery, $import_settings ) {
		$modula_gallery = get_post_type( $import_settings['galleries']['nextgen'][ $gallery->ID ] );
		$imported       = false;

		if ( isset( $import_settings['galleries']['nextgen'] ) && 'modula-gallery' === $modula_gallery ) {
			$imported = true;
		}


		return array(
			'id'       => $gallery->gid,
			'imported' => $imported,
			'title'    => '<a href="' . admin_url( '/post.php?post=' . $gallery->gid . '&action=edit' ) . '" target="_blank">' . esc_html( $gallery->title ) . '</a>',
			'count'    => $this->images_count( $gallery->gid )
		);
	}

	/**
	 * Return NextGEN images
	 *
	 * @param $images
	 * @param $data
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	public function migrator_images( $images, $data ) {
		global $wpdb;

		$sql = $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ngg_pictures
    						WHERE galleryid = %d
    						ORDER BY sortorder ASC,
    						imagedate ASC',
		                       $data );

		return $wpdb->get_results( $sql );
	}

	/**
	 * Image attachments
	 *
	 * @param $attachments
	 * @param $images
	 * @param $gallery_id
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	public function attachments( $attachments, $images, $gallery_id ) {
		global $wpdb;

		$ajax_migrator = Modula_Ajax_Migrator::get_instance();

		// Add each image to Media Library
		foreach ( $images as $image ) {
			// Store image in WordPress Media Library
			$sql = $wpdb->prepare( 'SELECT path, title, galdesc, pageid 
    						FROM ' . $wpdb->prefix . 'ngg_gallery
    						WHERE gid = %d
    						LIMIT 1', $gallery_id );

			$gallery    = $wpdb->get_row( $sql );
			$attachment = $ajax_migrator->add_image_to_library( $gallery->path, $image->filename, $image->description, $image->alttext );

			if ( $attachment !== false ) {
				// Add to array of attachments
				$attachments[] = $attachment;
			}
		}

		return $attachments;
	}

	/**
	 * Set localization for the plugin
	 *
	 * @return void
	 * @since 1.0.1
	 */
	public function set_locale() {
		load_plugin_textdomain( 'modula-nextgen-migrator', false, dirname( plugin_basename( MODULA_NEXTGEN_MIGRATOR_FILE ) ) . '/languages/' );
	}

}