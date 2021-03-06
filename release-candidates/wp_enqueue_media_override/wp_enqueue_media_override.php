<?php
/*
	Plugin Name: wp_enqueue_media Override
	Plugin URI: https://github.com/alleyinteractive/wp_enqueue_media_override
	Description: A temporary and hacky solution to work around performance issues in wp_enqueue_media
	Version: 1.0.0
	Author: Alley Interactive
	Author URI: http://www.alleyinteractive.com/
*/
/*  This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/**
 * Beat WordPress to the punch in enqueueing media, and do so more performantly.
 *
 * This function is ripped from core, with a couple of major differences in how
 * it queries for "has_audio", "has_video", and media months. These queries are
 * extremely slow on sites with as much media as this site has, and this is a
 * band-aid to speed things up. WordPress doesn't currently offer a way to
 * intercept these queries or override this function, but the function does
 * check if `did_action( 'wp_enqueue_media' )` and returns if true. Therefore,
 * if we were to fire that action before WordPress does, we can safely override
 * the functionality.
 *
 * This plugin is a stopgap and can be deprecated once Trac tickets 32264
 * ({@see https://core.trac.wordpress.org/ticket/32264}) and 31071
 * ({@see https://core.trac.wordpress.org/ticket/31071}) are closed.
 *
 * Most of this code is copied from WordPress, with the performance updates
 * mainly coming from @philipjohn, as posted on
 * https://core.trac.wordpress.org/ticket/32264.
 */


/**
 * Enqueues all scripts, styles, settings, and templates necessary to use
 * all media JS APIs.
 *
 * @global int       $content_width
 * @global wpdb      $wpdb
 * @global WP_Locale $wp_locale
 *
 * @param array $args {
 *     Arguments for enqueuing media scripts.
 *
 *     @type int|WP_Post A post object or ID.
 * }
 */
function wemo_wp_enqueue_media( $args = array() ) {
	// Enqueue me just once per page, please.
	if ( did_action( 'wp_enqueue_media' ) ) {
		return;
	}

	global $content_width, $wpdb, $wp_locale;

	$defaults = array(
		'post' => null,
	);
	$args = wp_parse_args( $args, $defaults );

	// We're going to pass the old thickbox media tabs to `media_upload_tabs`
	// to ensure plugins will work. We will then unset those tabs.
	$tabs = array(
		// handler action suffix => tab label
		'type'     => '',
		'type_url' => '',
		'gallery'  => '',
		'library'  => '',
	);

	/** This filter is documented in wp-admin/includes/media.php */
	$tabs = apply_filters( 'media_upload_tabs', $tabs );
	unset( $tabs['type'], $tabs['type_url'], $tabs['gallery'], $tabs['library'] );

	$props = array(
		'link'  => get_option( 'image_default_link_type' ), // db default is 'file'
		'align' => get_option( 'image_default_align' ), // empty default
		'size'  => get_option( 'image_default_size' ),  // empty default
	);

	$exts = array_merge( wp_get_audio_extensions(), wp_get_video_extensions() );
	$mimes = get_allowed_mime_types();
	$ext_mimes = array();
	foreach ( $exts as $ext ) {
		foreach ( $mimes as $ext_preg => $mime_match ) {
			if ( preg_match( '#' . $ext . '#i', $ext_preg ) ) {
				$ext_mimes[ $ext ] = $mime_match;
				break;
			}
		}
	}

	// Cache these expensive queries
	$has_audio = wemo_media_has_audio();
	$has_video = wemo_media_has_video();

	$settings = array(
		'tabs'      => $tabs,
		'tabUrl'    => add_query_arg( array( 'chromeless' => true ), admin_url('media-upload.php') ),
		'mimeTypes' => wp_list_pluck( get_post_mime_types(), 0 ),
		/** This filter is documented in wp-admin/includes/media.php */
		'captions'  => ! apply_filters( 'disable_captions', '' ),
		'nonce'     => array(
			'sendToEditor' => wp_create_nonce( 'media-send-to-editor' ),
		),
		'post'    => array(
			'id' => 0,
		),
		'defaultProps' => $props,
		'attachmentCounts' => array(
			'audio' => intval( $has_audio ),
			'video' => intval( $has_video )
		),
		'embedExts'    => $exts,
		'embedMimes'   => $ext_mimes,
		'contentWidth' => $content_width,
		'months'       => wemo_get_media_months(),
		'mediaTrash'   => MEDIA_TRASH ? 1 : 0
	);

	$post = null;
	if ( isset( $args['post'] ) ) {
		$post = get_post( $args['post'] );
		$settings['post'] = array(
			'id' => $post->ID,
			'nonce' => wp_create_nonce( 'update-post_' . $post->ID ),
		);

		$thumbnail_support = current_theme_supports( 'post-thumbnails', $post->post_type ) && post_type_supports( $post->post_type, 'thumbnail' );
		if ( ! $thumbnail_support && 'attachment' === $post->post_type && $post->post_mime_type ) {
			if ( wp_attachment_is( 'audio', $post ) ) {
				$thumbnail_support = post_type_supports( 'attachment:audio', 'thumbnail' ) || current_theme_supports( 'post-thumbnails', 'attachment:audio' );
			} elseif ( wp_attachment_is( 'video', $post ) ) {
				$thumbnail_support = post_type_supports( 'attachment:video', 'thumbnail' ) || current_theme_supports( 'post-thumbnails', 'attachment:video' );
			}
		}

		if ( $thumbnail_support ) {
			$featured_image_id = get_post_meta( $post->ID, '_thumbnail_id', true );
			$settings['post']['featuredImageId'] = $featured_image_id ? $featured_image_id : -1;
		}
	}

	$hier = $post && is_post_type_hierarchical( $post->post_type );

	$strings = array(
		// Generic
		'url'         => __( 'URL' ),
		'addMedia'    => __( 'Add Media' ),
		'search'      => __( 'Search' ),
		'select'      => __( 'Select' ),
		'cancel'      => __( 'Cancel' ),
		'update'      => __( 'Update' ),
		'replace'     => __( 'Replace' ),
		'remove'      => __( 'Remove' ),
		'back'        => __( 'Back' ),
		/* translators: This is a would-be plural string used in the media manager.
		   If there is not a word you can use in your language to avoid issues with the
		   lack of plural support here, turn it into "selected: %d" then translate it.
		 */
		'selected'    => __( '%d selected' ),
		'dragInfo'    => __( 'Drag and drop to reorder media files.' ),

		// Upload
		'uploadFilesTitle'  => __( 'Upload Files' ),
		'uploadImagesTitle' => __( 'Upload Images' ),

		// Library
		'mediaLibraryTitle'      => __( 'Media Library' ),
		'insertMediaTitle'       => __( 'Insert Media' ),
		'createNewGallery'       => __( 'Create a new gallery' ),
		'createNewPlaylist'      => __( 'Create a new playlist' ),
		'createNewVideoPlaylist' => __( 'Create a new video playlist' ),
		'returnToLibrary'        => __( '&#8592; Return to library' ),
		'allMediaItems'          => __( 'All media items' ),
		'allDates'               => __( 'All dates' ),
		'noItemsFound'           => __( 'No items found.' ),
		'insertIntoPost'         => $hier ? __( 'Insert into page' ) : __( 'Insert into post' ),
		'unattached'             => __( 'Unattached' ),
		'trash'                  => _x( 'Trash', 'noun' ),
		'uploadedToThisPost'     => $hier ? __( 'Uploaded to this page' ) : __( 'Uploaded to this post' ),
		'warnDelete'             => __( "You are about to permanently delete this item.\n  'Cancel' to stop, 'OK' to delete." ),
		'warnBulkDelete'         => __( "You are about to permanently delete these items.\n  'Cancel' to stop, 'OK' to delete." ),
		'warnBulkTrash'          => __( "You are about to trash these items.\n  'Cancel' to stop, 'OK' to delete." ),
		'bulkSelect'             => __( 'Bulk Select' ),
		'cancelSelection'        => __( 'Cancel Selection' ),
		'trashSelected'          => __( 'Trash Selected' ),
		'untrashSelected'        => __( 'Untrash Selected' ),
		'deleteSelected'         => __( 'Delete Selected' ),
		'deletePermanently'      => __( 'Delete Permanently' ),
		'apply'                  => __( 'Apply' ),
		'filterByDate'           => __( 'Filter by date' ),
		'filterByType'           => __( 'Filter by type' ),
		'searchMediaLabel'       => __( 'Search Media' ),
		'noMedia'                => __( 'No media attachments found.' ),

		// Library Details
		'attachmentDetails'  => __( 'Attachment Details' ),

		// From URL
		'insertFromUrlTitle' => __( 'Insert from URL' ),

		// Featured Images
		'setFeaturedImageTitle' => __( 'Set Featured Image' ),
		'setFeaturedImage'    => __( 'Set featured image' ),

		// Gallery
		'createGalleryTitle' => __( 'Create Gallery' ),
		'editGalleryTitle'   => __( 'Edit Gallery' ),
		'cancelGalleryTitle' => __( '&#8592; Cancel Gallery' ),
		'insertGallery'      => __( 'Insert gallery' ),
		'updateGallery'      => __( 'Update gallery' ),
		'addToGallery'       => __( 'Add to gallery' ),
		'addToGalleryTitle'  => __( 'Add to Gallery' ),
		'reverseOrder'       => __( 'Reverse order' ),

		// Edit Image
		'imageDetailsTitle'     => __( 'Image Details' ),
		'imageReplaceTitle'     => __( 'Replace Image' ),
		'imageDetailsCancel'    => __( 'Cancel Edit' ),
		'editImage'             => __( 'Edit Image' ),

		// Crop Image
		'chooseImage' => __( 'Choose Image' ),
		'selectAndCrop' => __( 'Select and Crop' ),
		'skipCropping' => __( 'Skip Cropping' ),
		'cropImage' => __( 'Crop Image' ),
		'cropYourImage' => __( 'Crop your image' ),
		'cropping' => __( 'Cropping&hellip;' ),
		'suggestedDimensions' => __( 'Suggested image dimensions:' ),
		'cropError' => __( 'There has been an error cropping your image.' ),

		// Edit Audio
		'audioDetailsTitle'     => __( 'Audio Details' ),
		'audioReplaceTitle'     => __( 'Replace Audio' ),
		'audioAddSourceTitle'   => __( 'Add Audio Source' ),
		'audioDetailsCancel'    => __( 'Cancel Edit' ),

		// Edit Video
		'videoDetailsTitle'     => __( 'Video Details' ),
		'videoReplaceTitle'     => __( 'Replace Video' ),
		'videoAddSourceTitle'   => __( 'Add Video Source' ),
		'videoDetailsCancel'    => __( 'Cancel Edit' ),
		'videoSelectPosterImageTitle' => __( 'Select Poster Image' ),
		'videoAddTrackTitle'	=> __( 'Add Subtitles' ),

 		// Playlist
 		'playlistDragInfo'    => __( 'Drag and drop to reorder tracks.' ),
 		'createPlaylistTitle' => __( 'Create Audio Playlist' ),
 		'editPlaylistTitle'   => __( 'Edit Audio Playlist' ),
 		'cancelPlaylistTitle' => __( '&#8592; Cancel Audio Playlist' ),
 		'insertPlaylist'      => __( 'Insert audio playlist' ),
 		'updatePlaylist'      => __( 'Update audio playlist' ),
 		'addToPlaylist'       => __( 'Add to audio playlist' ),
 		'addToPlaylistTitle'  => __( 'Add to Audio Playlist' ),

 		// Video Playlist
 		'videoPlaylistDragInfo'    => __( 'Drag and drop to reorder videos.' ),
 		'createVideoPlaylistTitle' => __( 'Create Video Playlist' ),
 		'editVideoPlaylistTitle'   => __( 'Edit Video Playlist' ),
 		'cancelVideoPlaylistTitle' => __( '&#8592; Cancel Video Playlist' ),
 		'insertVideoPlaylist'      => __( 'Insert video playlist' ),
 		'updateVideoPlaylist'      => __( 'Update video playlist' ),
 		'addToVideoPlaylist'       => __( 'Add to video playlist' ),
 		'addToVideoPlaylistTitle'  => __( 'Add to Video Playlist' ),
	);

	/**
	 * Filter the media view settings.
	 *
	 * @since 3.5.0
	 *
	 * @param array   $settings List of media view settings.
	 * @param WP_Post $post     Post object.
	 */
	$settings = apply_filters( 'media_view_settings', $settings, $post );

	/**
	 * Filter the media view strings.
	 *
	 * @since 3.5.0
	 *
	 * @param array   $strings List of media view strings.
	 * @param WP_Post $post    Post object.
	 */
	$strings = apply_filters( 'media_view_strings', $strings,  $post );

	$strings['settings'] = $settings;

	// Ensure we enqueue media-editor first, that way media-views is
	// registered internally before we try to localize it. see #24724.
	wp_enqueue_script( 'media-editor' );
	wp_localize_script( 'media-views', '_wpMediaViewsL10n', $strings );

	wp_enqueue_script( 'media-audiovideo' );
	wp_enqueue_style( 'media-views' );
	if ( is_admin() ) {
		wp_enqueue_script( 'mce-view' );
		wp_enqueue_script( 'image-edit' );
	}
	wp_enqueue_style( 'imgareaselect' );
	wp_plupload_default_settings();

	require_once ABSPATH . WPINC . '/media-template.php';
	add_action( 'admin_footer', 'wp_print_media_templates' );
	add_action( 'wp_footer', 'wp_print_media_templates' );
	add_action( 'customize_controls_print_footer_scripts', 'wp_print_media_templates' );

	/**
	 * Fires at the conclusion of wp_enqueue_media().
	 *
	 * @since 3.5.0
	 */
	do_action( 'wp_enqueue_media' );
}

/**
 * Check if there are any audio items in the media library.
 *
 * Queries the DB to check whether the media library contains any items of type audio,
 * and caches that expensive query to avoid slowness when saving posts on large sites.
 *
 * @return int Returns 1 or 0 for 'yes' or 'no'
 */
function wemo_media_has_audio() {
	$has_audio = apply_filters( 'wemo_media_has_audio', null );
	if ( null === $has_audio ) {
		$has_audio = get_transient( 'has_audio' );
		if ( false === $has_audio ) {
			global $wpdb;
			$has_audio = $wpdb->get_var( "
				SELECT ID
				FROM $wpdb->posts
				WHERE post_type = 'attachment'
				AND post_mime_type LIKE 'audio%'
				LIMIT 1
			" );
			$has_audio = $has_audio ? 1 : 0;
			set_transient( 'has_audio', $has_audio );
		}
	}

	return $has_audio;
}

/**
 * Check if there are any video items in the media library.
 *
 * Queries the DB to check whether the media library contains any items of type video,
 * and caches that expensive query to avoid slowness when saving posts on large sites.
 *
 * @return int Returns 1 or 0 for 'yes' or 'no'
 */
function wemo_media_has_video() {
	$has_video = apply_filters( 'wemo_media_has_video', null );
	if ( null === $has_video ) {
		$has_video = get_transient( 'has_video' );
		if ( false === $has_video ) {
			global $wpdb;
			$has_video = $wpdb->get_var( "
				SELECT ID
				FROM $wpdb->posts
				WHERE post_type = 'attachment'
				AND post_mime_type LIKE 'video%'
				LIMIT 1
			" );
			$has_video = $has_video ? 1 : 0;
			set_transient( 'has_video', $has_video );
		}
	}

	return $has_video;
}

/**
 * Gets a list of months in which media has been uploaded
 *
 * Queries the DB to check in which months media items have been uploaded, then
 * caches that query which can be expensive on larger sites
 *
 * @return array An array of objects representing rows from the DB query
 */
function wemo_get_media_months() {
	$media_months = apply_filters( 'wemo_get_media_months', null );
	if ( null === $media_months ) {
		$months = get_transient( 'media_months' );
		if ( false === $months ) {
			global $wpdb;
			$months = $wpdb->get_results( $wpdb->prepare( "
				SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
				FROM $wpdb->posts
				WHERE post_type = %s
				ORDER BY post_date DESC
			", 'attachment' ) );
			set_transient( 'media_months', $months );
		}
	}

	return $media_months;
}

/**
 * Updates the has_audio and has_video transients as required
 *
 * Hooks into `add_attachment` and `delete_attachment` to determine whether or not
 * the has_audio and has_video transients needs to be refreshed.
 *
 * @param $post_id ID of the attachment post added/deleted
 */
function wemo_check_has_media( $post_id ) {
	$mime_type = get_post_mime_type( $post_id );
	$post_status = get_post_status( $post_id );

	// The value of the transient we should clear, where relevant.
	$transient_to_clear = null;
	if ( 'trash' == $post_status ) {
		// We're deleting an attachment so we should only refresh the transient
		// if it indicates there are attachments of this type
		$transient_to_clear = 1;
	} else {
		// We're adding an attachment so we should only refresh the transient
		// if it indicates there are currently no attachments of this type
		$transient_to_clear = 0;
	}
	// Based on attachment type, clear the relevant transient where necessary
	if ( 'image' == $mime_type && media_has_audio() === $transient_to_clear ){
		delete_transient( 'has_audio' );
	} elseif ( 'video' == $mime_type && media_has_video() === $transient_to_clear ) {
		delete_transient( 'has_video' );
	}
}
add_action( 'add_attachment', 'wemo_check_has_media' );
add_action( 'delete_attachment', 'wemo_check_has_media' );

function wemo_check_media_months( $post_id ) {

	// What month/year is the most recent attachment?
	global $wpdb;
	$months = $wpdb->get_results( $wpdb->prepare( "
			SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
			FROM $wpdb->posts
			WHERE post_type = %s
			ORDER BY post_date DESC
			LIMIT 1
		", 'attachment' ) );

	// Simplify by assigning the object to $months
	$months = array_shift( array_values( $months ) );

	// Compare the dates of the new, and most recent, attachment
	if (
		! $months->year == get_the_time( 'Y', $post_id ) &&
		! $months->month == get_the_time( 'm', $post_id )
	) {
		// the new attachment is not in the same month/year as the
		// most recent attachment, so we need to refresh the transient
		delete_transient('media_months');
	}

}
add_action( 'add_attachment', 'wemo_check_media_months' );

function wemo_maybe_enqueue_media() {
	if ( isset( $_GET['post'] ) ) {
	 	$post_id = (int) $_GET['post'];
	} else {
	 	$post_id = null;
	}

	wemo_wp_enqueue_media( array( 'post' => $post_id ) );
}

// Be default, we're hooking into all of the following actions because that's
// where wp_enqueue_media is currently running. Follow this pattern to add any
// pages where it runs for your site.
add_action( 'load-post.php', 'wemo_maybe_enqueue_media' );
