<?php
/*
Plugin Name: Simple Copy Attachment
Plugin URI: http://plugins.findingsimple.com
Description: Copy attachments (but not the file) for assigning to multiple posts
Version: 1.0
Author: Finding Simple
Author URI: http://findingsimple.com
License: GPL2
*/
/*
Copyright 2012  Finding Simple  (email : plugins@findingsimple.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! class_exists( 'Simple_Copy_Attachment' ) ) :

/**
 * So that themes and other plugins can customise the text domain, the Simple_Quotes
 * should not be initialized until after the plugins_loaded and after_setup_theme hooks.
 * However, it also needs to run early on the init hook.
 *
 * @author Jason Conroy <jason@findingsimple.com>
 * @package Simple Copy Attachment
 * @since 1.0
 */
function initialize_copy_attachment(){
	Simple_Copy_Attachment::init();
}
add_action( 'init', 'initialize_copy_attachment', -1 );

/**
 * Plugin Main Class.
 *
 * @package Simple Copy Attachment
 * @author Jason Conroy <jason@findingsimple.com>
 * @since 1.0
 */
class Simple_Copy_Attachment {

	static $text_domain;
	
	/**
	 * Initialise
	 */
	public static function init() {
	
		global $wp_version;

		self::$text_domain = apply_filters( 'sca_text_domain', 'Simple_CA' );
		
		// Apply background colors to admin area to help differentiate between copies and originals
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'sca_enqueue_admin_styles_and_scripts' ) );
		add_action( 'admin_footer', array( __CLASS__, 'sca_check_attachment_originality' ) );
	
		// Filter duplicates from media listings
		add_filter('posts_where', array( __CLASS__, 'sca_add_library_query_vars' ) );
		
		// Add copy action
		add_filter( 'media_row_actions', array( __CLASS__, 'sca_add_media_actions' ) , 1000, 2 );

		// Handle copying of attachments
		add_action('admin_action_sca_copy_attachment', array( __CLASS__, 'sca_copy_attachment_admin_action') );
		add_action('admin_notices', array( __CLASS__, 'sca_copy_attachment_admin_notices') );

		// Handle deletion of attachments
		add_filter( 'wp_delete_file', array( __CLASS__, 'sca_cancel_file_deletion_if_attachment_copies') );
		add_action( 'delete_attachment', array( __CLASS__, 'sca_handle_deleted_attachment' ) ); //normal delete

	}
	
	/**
	 * Enqueues the necessary scripts and styles for the plugin
	 *
	 */
	public static function sca_enqueue_admin_styles_and_scripts() {
				
		if ( is_admin() ) {
	
			wp_register_style( 'simple-copy-attachment', self::get_url( '/css/simple-copy-attachment-admin.css', __FILE__ ) , false, '1.0' );
			wp_enqueue_style( 'simple-copy-attachment' );
		
		}
		
	}

	/**
	 * Append JS for assigning class to attachment list items based on whether copy or original
	 *
	 */	
	public static function sca_check_attachment_originality() {
	
		global $wp_query, $wpdb;
	
		if ( 'upload' == get_current_screen()->base && ! empty($wp_query->posts) ) {
		
			$ids = array();
			$copies = array();
			$originals = array();

			foreach( $wp_query->posts as $post ) {
			
				$ids[] = $post->ID;
				
			}
		
			if ( ! empty($ids) && $results = $wpdb->get_results("SELECT post_id, meta_key FROM $wpdb->postmeta WHERE meta_key IN ('_has_copies', '_is_copy_of') AND post_id IN ('" . implode("', '", $ids) . "')") ) {
				
				foreach( $results as $r ) {
				
					if( '_has_copies' == $r->meta_key )
						$originals[] = $r->post_id;
				
					if( '_is_copy_of' == $r->meta_key )
						$copies[] = $r->post_id;
						
				}
				
			}
		
			if ( ! empty($originals) || ! empty($copies) ) {
			
				if ( ! empty($originals) )
					$originals = '"#post-' . implode(', #post-', $originals) . '"';
				else
					$originals = 'null';

				if ( ! empty($copies) )
					$copies = '"#post-' . implode(', #post-', $copies) . '"';
				else
					$copies = 'null';
			
			?>
				<script type="text/javascript">
					var sca_originals = <?php echo $originals; ?>,
						sca_copies = <?php echo $copies; ?>;

					if ( null !== sca_originals )
						jQuery(sca_originals).addClass("attachment-original");
				
					if ( null !== sca_copies )
						jQuery(sca_copies).addClass("attachment-copy");
						
				</script>
			<?php
			}
		}
	}


	/**
	 * Media library extensions
	 */
	function sca_add_library_query_vars( $input ) {
	
		global $wpdb, $pagenow;
	
		if ( is_admin() ) {
		
			$options = get_option('file_gallery');
	
			// affect the query only if we're on a certain page
			if( "media-upload.php" == $pagenow && "library" == $_GET["tab"] && is_numeric($_GET['post_id']) ) {
			
				if( isset($_GET['exclude']) && "current" == $_GET['exclude'] )
					$input .= " AND `post_parent` != " . (int) $_GET["post_id"] . " ";
	
				if( isset($options["library_filter_duplicates"]) && true == $options["library_filter_duplicates"] )
					$input .= " AND $wpdb->posts.ID NOT IN ( SELECT ID FROM $wpdb->posts AS ps INNER JOIN $wpdb->postmeta AS pm ON pm.post_id = ps.ID WHERE pm.meta_key = '_is_copy_of' ) ";
			
			} elseif( "upload.php" == $pagenow && isset($options["library_filter_duplicates"]) && true == $options["library_filter_duplicates"] ) {
				
				$input .= " AND $wpdb->posts.ID NOT IN ( SELECT ID FROM $wpdb->posts AS ps INNER JOIN $wpdb->postmeta AS pm ON pm.post_id = ps.ID WHERE pm.meta_key = '_is_copy_of' ) ";
			
			}
			
		}

		return $input;
		
	}

	/**
	 * Add "copy" action
	 *
	 * @since 1.0
	 */		
	public static function sca_add_media_actions( $actions, $post ) {
	
		$nonce = wp_create_nonce( basename( __FILE__ ) );
		$url = admin_url( 'admin.php' );
		$wp_list_table = _get_list_table('WP_Media_List_Table');  
		$pagenum = $wp_list_table->get_pagenum();
	
		if ( !isset( $actions['sca-copy'] ) )
			$actions['sca-copy'] = '<a href="' . admin_url( "admin.php?action=sca_copy_attachment&amp;aid=$post->ID&amp;pagenum=$pagenum&amp;scanonce=$nonce") . '" id="sca_copy_attachment-' . $post->ID . '" class="sca_copy_attachment">' . __('Copy', self::$text_domain ) . '</a>';
	
		return $actions;
		
	}
	
	/**
	 * Copies an attachment to a post 
	 */
	function sca_copy_attachment_to_post( $aid, $post_id ) {
	
		global $wpdb;
	
		if( ! is_numeric($aid) || ! is_numeric($post_id) || 0 === (int) $aid || 0 === (int) $post_id )
			return -1;
	
		$attachment = get_post($aid);
	
		// don't duplicate - if it's unattached, just attach it without copying the data
		if( 0 === $attachment->post_parent )
			return $wpdb->update( $wpdb->posts, array('post_parent' => $post_id), array('ID' => $attachment->ID), array('%d'), array('%d') );

		$attachment->metadata      = get_post_meta($attachment->ID, '_wp_attachment_metadata', true);
		$attachment->attached_file = get_post_meta($attachment->ID, '_wp_attached_file', true);

		unset($attachment->ID);
	
		// maybe include this as an option on media settings screen...?
		$attachment->post_title .= apply_filters('sca_attachment_copy_title_extension', '', $post_id);
	
		// copy main attachment data
		$attachment_id = wp_insert_attachment( $attachment, false, $post_id );
	
		// copy attachment custom fields
		$acf = get_post_custom($aid);
	
		foreach( $acf as $key => $val )
		{
			if( in_array($key, array('_is_copy_of', '_has_copies')) )
				continue;

			foreach( $val as $v )
			{
				add_post_meta($attachment_id, $key, $v);
			}
		}
	
		// other meta values	
		update_post_meta( $attachment_id, '_wp_attached_file',  $attachment->attached_file );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $attachment->metadata );

		/* copies and originals */

		// if we're duplicating a copy, set duplicate's "_is_copy_of" value to original's ID
		if( $is_a_copy = get_post_meta($aid, '_is_copy_of', true) )
			$aid = $is_a_copy;
	
		update_post_meta($attachment_id, '_is_copy_of', $aid);
	
		// meta for the original attachment (array holding ids of its copies)
		$has_copies = get_post_meta($aid, '_has_copies', true);
		$has_copies[] = $attachment_id;
		$has_copies = array_unique($has_copies);
	
		update_post_meta($aid, '_has_copies',  $has_copies);
	
		/*  / copies and originals */
			
		return $attachment_id;
		
	}

	/**
	 * Copies an attachment (but does not attach to a post)
	 */
	function sca_copy_attachment( $aid ) {
	
		global $wpdb;
	
		if( ! is_numeric($aid) || 0 === (int) $aid )
			return -1;
	
		$attachment = get_post($aid);
		$attachment->metadata = get_post_meta($attachment->ID, '_wp_attachment_metadata', true);
		$attachment->attached_file = get_post_meta($attachment->ID, '_wp_attached_file', true);
		
		// remove original ID from attachment array
		unset( $attachment->ID );
		
		// remove parent ID from attachment array
		unset( $attachment->post_parent );
		
		// copy main attachment data
		$attachment_id = wp_insert_attachment( $attachment, false ); // no parent ID
	
		// copy attachment custom fields
		$acf = get_post_custom($aid);
	
		foreach( $acf as $key => $val ) {
		
			if( in_array($key, array('_is_copy_of', '_has_copies')) )
				continue;

			foreach( $val as $v ) {
				add_post_meta($attachment_id, $key, $v);
			}
			
		}
	
		// other meta values	
		update_post_meta( $attachment_id, '_wp_attached_file',  $attachment->attached_file );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $attachment->metadata );

		// if we're duplicating a copy, set duplicate's "_is_copy_of" value to original's ID
		if( $is_a_copy = get_post_meta($aid, '_is_copy_of', true) )
			$aid = $is_a_copy;
	
		update_post_meta( $attachment_id, '_is_copy_of', $aid );
	
		// meta for the original attachment (array holding ids of its copies)
		$has_copies = get_post_meta($aid, '_has_copies', true);
		$has_copies[] = $attachment_id;
		$has_copies = array_unique($has_copies);
	
		update_post_meta($aid, '_has_copies',  $has_copies);
				
		return $attachment_id;
		
	}
	

	/**
	 * Cancels deletion of the actual _file_ by returning an empty string as file path
	 * if the deleted attachment had copies or was a copy itself
	 */
	public static function sca_cancel_file_deletion_if_attachment_copies( $file ) {
	
		global $wpdb;

		//if( defined('SCA_SKIP_DELETE_CANCEL') && true === SCA_SKIP_DELETE_CANCEL )
		//	return $file;
	
		$_file = $file;
		$was_original = true;
		
		// get '_wp_attached_file' value based on upload path
		if ( false != get_option('uploads_use_yearmonth_folders') ) {
		
			$_file = explode('/', $_file);
			$c     = count($_file);
			
			$_file = $_file[$c-3] . '/' . $_file[$c-2] . '/' . $_file[$c-1];
			
		} else {
		
			$_file = basename($file);
			
		}
	
		// find all attachments that share the same file
		$this_copies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT `post_id` 
				 FROM $wpdb->postmeta 
				 WHERE `meta_key` = '_wp_attached_file' 
				 AND `meta_value` = '%s'", 
				$_file
			)
		);
	
		if ( is_array($this_copies) && ! empty($this_copies) ) {
			
			// determine if original was deleted
			foreach ( $this_copies as $tc ) {
			
				if ( '' != get_post_meta($tc, '_has_copies', true) )
					$was_original = false;
					
			}
			
			// original is deleted, promote first copy
			if ( $was_original ) 
				$promoted_id = self::sca_promote_first_attachment_copy(0, $this_copies);
		
			$uploadpath = wp_upload_dir();
			$file_path  = path_join($uploadpath['basedir'], $_file);
			
			// if it's an image - regenerate its intermediate sizes
			if ( self::sca_file_is_displayable_image( $file_path ) ) 
				$regenerate = wp_update_attachment_metadata( $promoted_id, wp_generate_attachment_metadata($promoted_id, $file_path ) );

			return '';
			
		}
	
		return $file;
	}


	public static function sca_handle_deleted_attachment( $post_id ) {
	
		$is_copy_of = get_post_meta($post_id, '_is_copy_of', true);

		if ( !empty($is_copy_of) && is_numeric($is_copy_of) && $copies = get_post_meta( $is_copy_of, '_has_copies', true ) ) {

			foreach( $copies as $k => $v ) {
			
				if( (int) $post_id === (int) $v )
					unset($copies[$k]);
					
			}
		
			if ( empty($copies) )
				delete_post_meta($is_copy_of, '_has_copies');
			else
				update_post_meta($is_copy_of, '_has_copies', $copies);
				
		}
		
	}


	/**
	 * Promotes the first copy of an attachment (probably to be deleted)
	 * into the original (with other copies becoming its copies now)
	 */
	function sca_promote_first_attachment_copy( $attachment_id, $copies = false ) {
	
		if ( false === $copies )
			$copies = get_post_meta($attachment_id, '_has_copies', true);
	
		if ( is_array($copies) && ! empty($copies) ) {
		
			$promoted_id = array_shift($copies);
			do_action('sca_promote_first_attachment_copy', $attachment_id, array(&$promoted_id));
			delete_post_meta($promoted_id, '_is_copy_of');

			if ( ! empty($copies) ) {
				// update promoted attachments meta
				add_post_meta($promoted_id, '_has_copies', $copies);
			
				// update copies' meta
				foreach( $copies as $copy ) {
					update_post_meta($copy, '_is_copy_of', $promoted_id);
				}
				
			}
		
			return $promoted_id;
			
		}
	
		// no copies
		return false;
		
	}	
	
	/**
	 * Copy of the standard WordPress function found in admin
	 */
	public static function sca_file_is_displayable_image( $path ) {
	
		$path = preg_replace(array("#\\\#", "#/+#"), array("/", "/"), $path);		
		$info = @getimagesize($path);

		if ( empty($info) )
			$result = false;
		elseif ( !in_array($info[2], array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG)) )    // only gif, jpeg and png images can reliably be displayed
			$result = false;
		else
			$result = true;
	
		return apply_filters('file_is_displayable_image', $result, $path);
		
	}
		
	/**
	 * Handle the copy action
	 */
	public static function sca_copy_attachment_admin_action() {
	
		/* Verify the nonce before proceeding. */
		if ( !isset( $_REQUEST['scanonce'] ) || !wp_verify_nonce( $_REQUEST['scanonce'], basename( __FILE__ ) ) )
			return;
							
		// this is based on wp-admin/edit.php
		$sendback = remove_query_arg( array('exported', 'untrashed', 'deleted', 'ids'), wp_get_referer() );
	
		if ( ! $sendback )
		  $sendback = admin_url( "upload.php" );

		$pagenum = $_REQUEST['pagenum'];
					
		$sendback = add_query_arg( 'paged', $pagenum, $sendback );
		
		$copied = 'no';
		
   		// get the action
    	$action = $_REQUEST['action'];
    	$aid = (int) $_REQUEST['aid'];
    	    	
		switch ( $action ) {
	  
		  case 'sca_copy_attachment':
		  
		  	$result = self::sca_copy_attachment( $aid ); 
			
			if ( $result >= 1 )
				$copied = 'yes';
		  
			$sendback = add_query_arg( array('sca_copied' => $copied ) , $sendback );
		
		  break;

		  default: return;
	  
		}

		$sendback = remove_query_arg( array('sca_copy_attachment', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',  'post', 'bulk_edit', 'post_view'), $sendback );

		wp_redirect($sendback);
	
		exit();
				
	}


	/**
	 * Display an admin notice after copying the attachment
	 */
	public static function sca_copy_attachment_admin_notices() {
	
		global $post_type, $pagenow;

		if ( $pagenow == 'upload.php' && isset($_REQUEST['sca_copied']) ) {
		
			$message == 'Error copying attachment.';
			
			if ( $_REQUEST['sca_copied'] == 'yes' )
				$message = 'Attachment Copied.';
						
			echo "<div class=\"updated\"><p>{$message}</p></div>";
			
		}
		
	}
	
		
	/**
	 * Helper function to get the URL of a given file. 
	 * 
	 * As this plugin may be used as both a stand-alone plugin and as a submodule of 
	 * a theme, the standard WP API functions, like plugins_url() can not be used. 
	 *
	 * @since 1.0
	 * @return array $post_name => $post_content
	 */
	public static function get_url( $file ) {

		// Get the path of this file after the WP content directory
		$post_content_path = substr( dirname( str_replace('\\','/',__FILE__) ), strpos( __FILE__, basename( WP_CONTENT_DIR ) ) + strlen( basename( WP_CONTENT_DIR ) ) );
		
		// Return a content URL for this path & the specified file
		return content_url( $post_content_path . $file );
		
	}	

}

endif;