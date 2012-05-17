<?php
/*
Plugin Name: Ad Code Manager
Plugin URI: http://automattic.com
Description: Easy ad code management
Author: Rinat Khaziev, Jeremy Felt, Daniel Bachhuber, Automattic, doejo
Version: 0.2.1
Author URI: http://automattic.com

GNU General Public License, Free Software Foundation <http://creativecommons.org/licenses/GPL/2.0/>

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
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/
define( 'AD_CODE_MANAGER_VERSION', '0.2.1' );
define( 'AD_CODE_MANAGER_ROOT' , dirname( __FILE__ ) );
define( 'AD_CODE_MANAGER_FILE_PATH' , AD_CODE_MANAGER_ROOT . '/' . basename( __FILE__ ) );
define( 'AD_CODE_MANAGER_URL' , plugins_url( '/', __FILE__ ) );

// Bootsrap
require_once( AD_CODE_MANAGER_ROOT .'/common/lib/acm-provider.php' );
require_once( AD_CODE_MANAGER_ROOT .'/common/lib/acm-wp-list-table.php' );
require_once( AD_CODE_MANAGER_ROOT .'/common/lib/acm-widget.php' );

class Ad_Code_Manager
{
	public $ad_codes = array();
	public $whitelisted_conditionals = array();
	public $title = 'Ad Code Manager';
	public $post_type = 'acm-code';
	public $plugin_slug = 'ad-code-manager';
	public $manage_ads_cap = 'manage_options';
	public $post_type_labels;
	public $logical_operator;
	public $ad_tag_ids;
	public $providers;
	public $current_provider_slug;
	public $current_provider;
	public $wp_list_table;

	/**
	 * Instantiate the plugin
	 *
	 * @since 0.1
	 */
	function __construct() {

		add_action( 'init', array( $this, 'action_load_providers' ) );
		add_action( 'init', array( $this, 'action_init' ) );

		// Incorporate the link to our admin menu
		add_action( 'admin_menu' , array( $this, 'action_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts_and_styles' ) );
		add_action( 'admin_print_scripts', array( $this, 'post_admin_header' ) );
		add_action( 'wp_ajax_acm_admin_action', array( $this, 'handle_admin_action' ) );

		add_action('current_screen', array( $this, 'contextual_help' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_shortcode( 'acm-tag' , array( $this, 'shortcode' ) );
	}

	/**
	 * Load all available ad providers
	 * and set selected as ACM_Provider $current_provider
	 * which holds all necessary configuration properties
	 */
	function action_load_providers() {
		$module_dirs = array_diff( scandir( AD_CODE_MANAGER_ROOT . '/providers/' ), array( '..', '.' ) );
		foreach( $module_dirs as $module_dir ) {
			$module_dir = str_replace( '.php', '', $module_dir );
			if ( file_exists( AD_CODE_MANAGER_ROOT . "/providers/$module_dir.php" ) ) {
				include_once( AD_CODE_MANAGER_ROOT . "/providers/$module_dir.php" );
			}

			$tmp = explode( '-', $module_dir );
			$class_name = '';
			$slug_name = '';
			$table_class_name = '';
			foreach( $tmp as $word ) {
				$class_name .= ucfirst( $word ) . '_';
				$slug_name .= $word . '_';
			}
			$table_class_name = $class_name . 'ACM_WP_List_Table';
			$class_name .= 'ACM_Provider';
			$slug_name = rtrim( $slug_name, '_' );

			// Store class names, but don't instantiate
			// We don't need them all at once
			if ( class_exists( $class_name ) ) {
				$this->providers->$slug_name = array( 'provider' => $class_name,
													  'table' => $table_class_name,
													);
			}

		}
		
		/**
		 * Configuration filter: acm_provider_slug
		 *
		 * By default we use doubleclick-for-publishers provider
		 * To switch to a different ad provider use this filter
		 */
		$this->current_provider_slug = apply_filters( 'acm_provider_slug', 'doubleclick_for_publishers' );
		
		// Instantiate one that we need
		if ( isset( $this->providers->{$this->current_provider_slug} ) )
			$this->current_provider = new $this->providers->{$this->current_provider_slug}['provider'];

		// Nothing to do without a provider
		if ( !is_object( $this->current_provider ) )
			return ;

		/**
		 * Configuration filter: acm_whitelisted_script_urls
		 * A security filter to whitelist which ad code script URLs can be added in the admin
		 */
		$this->current_provider->whitelisted_script_urls = apply_filters( 'acm_whitelisted_script_urls', $this->current_provider->whitelisted_script_urls );

	}

	/**
	 * Code to run on WordPress' 'init' hook
	 *
	 * @since 0.1
	 */
	function action_init() {

		$this->post_type_labels = array(
										'name' => __( 'DFP Ad Codes' ),
										'singular_name' => __( 'DFP Ad Codes' ),
										);

		// Allow other conditionals to be used
		$this->whitelisted_conditionals = array(
				'is_home',
				'is_front_page',
				'is_category',
				'has_category',
				'is_page',
				'is_tag',
				'has_tag',
			);
		/**
		 * Configuration filter: acm_whitelisted_conditionals
		 * Extend the list of usable conditional functions with your own awesome ones.
		 */
		$this->whitelisted_conditionals = apply_filters( 'acm_whitelisted_conditionals', $this->whitelisted_conditionals );
		// Allow users to filter default logical operator
		$this->logical_operator = apply_filters( 'acm_logical_operator', 'OR' );

		// Allow the ad management cap to be filtered if need be
		$this->manage_ads_cap = apply_filters( 'acm_manage_ads_cap', $this->manage_ads_cap );

		// Load default ad tags for provider
		$this->ad_tag_ids = $this->current_provider->ad_tag_ids;
		/**
		 * Configuration filter: acm_ad_tag_ids
		 * Extend set of default tag ids. Ad tag ids are used as a parameter
		 * for your template tag (e.g. do_action( 'acm_tag', 'my_top_leaderboard' ))
		 */
		$this->ad_tag_ids = apply_filters( 'acm_ad_tag_ids', $this->ad_tag_ids );

		$this->register_acm_post_type();

		// Ad tags are only run on the frontend
		if ( !is_admin() ) {
			add_action( 'acm_tag', array( $this, 'action_acm_tag' ) );
			add_filter( 'acm_output_tokens', array( $this, 'filter_output_tokens' ), 5, 3 );
		}

		// Load all of our registered ad codes
		$this->register_ad_codes( $this->get_ad_codes() );
	}

	/**
	 * Register our custom post type to store ad codes
	 *
	 * @since 0.1
	 */
	function register_acm_post_type() {
		register_post_type( $this->post_type, array( 'labels' => $this->post_type_labels, 'public' => false ) );
	}

	/**
	 * Handle any Add, Edit, or Delete actions from the admin interface
	 * Hooks into admin ajax because it's the proper context for these sort of actions
	 *
	 * @since 0.2
	 */
	function handle_admin_action() {

		if ( !wp_verify_nonce( $_REQUEST['nonce'], 'acm-admin-action' ) )
			wp_die( __( 'Doing something fishy, eh?', 'ad-code-manager' ) );

		if ( !current_user_can( $this->manage_ads_cap ) )
			wp_die( __( 'You do not have the necessary permissions to perform this action', 'ad-code-manager' ) );

		// Depending on the method we're performing, sanitize the requisite data and do it
		switch( $_REQUEST['method'] ) {
			case 'add':
			case 'edit':
				$id = ( isset( $_REQUEST['id'] ) ) ? (int)$_REQUEST['id'] : 0;
				$priority = ( isset( $_REQUEST['priority'] ) ) ? (int)$_REQUEST['priority'] : 10;
				$ad_code_vals = array(
						'priority' => $priority,
					);
				foreach( $this->current_provider->columns as $slug => $title ) {
					$ad_code_vals[$slug] = sanitize_text_field( $_REQUEST['acm-column'][$slug] );
				}
				if ( $_REQUEST['method'] == 'add')
					$id = $this->create_ad_code( $ad_code_vals );
				else
					$id = $this->edit_ad_code( $id, $ad_code_vals );
				if ( is_wp_error( $id ) ) {
					$message = 'error-adding-editing-ad-code';
					break;
				}
				$new_conditionals = array();
				$unsafe_conditionals = ( isset( $_REQUEST['acm-conditionals'] ) ) ? $_REQUEST['acm-conditionals'] : array();
				foreach( $unsafe_conditionals as $index => $unsafe_conditional ) {
					$index = (int)$index;
					$arguments = ( isset( $_REQUEST['acm-arguments'][$index] ) ) ? sanitize_text_field( $_REQUEST['acm-arguments'][$index] ) : '';
					$conditional = array(
							'function' => sanitize_key( $unsafe_conditional ),
							'arguments' => $arguments,
						);
					if ( !empty( $conditional['function'] ) ) {
						$new_conditionals[] = $conditional;
					}
				}
				if ( $_REQUEST['method'] == 'add' ) {
					foreach( $new_conditionals as $new_conditional ) {
						$this->create_conditional( $id, $new_conditional ); 
					}
					$message = 'ad-code-added';
				} else {
					$this->edit_conditionals( $id, $new_conditionals );
					$message = 'ad-code-updated';
				}
				$this->flush_cache();
				break;
			case 'delete':
				$id = (int)$_REQUEST['id'];
				$this->delete_ad_code( $id );
				$this->flush_cache();
				$message = 'ad-code-deleted';
				break;
		}

		if ( isset( $_REQUEST['doing_ajax'] ) && $_REQUEST['doing_ajax'] ){
			switch( $_REQUEST['method'] ) {
				case 'edit':
					set_current_screen( 'ad-code-manager' );
					$this->wp_list_table = new $this->providers->{$this->current_provider_slug}['table'];
					$this->wp_list_table->prepare_items();
					$new_ad_code = $this->get_ad_code( $id );
					echo $this->wp_list_table->single_row( $new_ad_code );
					break;
			}
		} else {
			// @todo support ajax and non-ajax requests
			$redirect_url = add_query_arg( 'message', $message, remove_query_arg( 'message', wp_get_referer() ) );
			wp_safe_redirect( $redirect_url );
		}
		exit;
	}

	/**
	 * Get the ad codes stored in our custom post type
	 *
	 */
	function get_ad_codes( $query_args = array() ) {

		$ad_codes_formatted = array();
		$allowed_query_params = apply_filters( 'acm_allowed_get_posts_args', array( 'offset' ) );
		
		
		/**
		 * Configuration filter: acm_ad_code_count
		 *
		 * By default we limit query to 50 ad codes
		 * Use this filter to change limit 
		 */
		$args = array(
			'post_type' => $this->post_type,
			'numberposts' => apply_filters( 'acm_ad_code_count', 50 ),
		);

		foreach ( (array) $query_args as $query_key => $query_value ) {
			if ( ! in_array( $query_key, $allowed_query_params ) ) {
				unset( $query_args[$query_key] );
			} else {
				$args[$query_key] = $query_value;
			}
		}

		if ( false === ( $ad_codes_formatted = wp_cache_get( 'ad_codes' , 'acm' ) ) ) {
			$ad_codes = get_posts( $args );
			foreach ( $ad_codes as $ad_code_cpt ) {
				$provider_url_vars = array();
				
				foreach ( $this->current_provider->columns as $slug => $title ) {
					$provider_url_vars[$slug] = get_post_meta( $ad_code_cpt->ID, $slug, true );
				}

				$priority = get_post_meta( $ad_code_cpt->ID, 'priority', true );
				$priority = ( !empty( $priority ) ) ? intval( $priority ) : 10;
	
				$ad_codes_formatted[] = array(
					'conditionals' => $this->get_conditionals( $ad_code_cpt->ID ),
					'url_vars' => $provider_url_vars,
					'priority' => $priority,
					'post_id' => $ad_code_cpt->ID
				);
			}
			wp_cache_add( 'ad_codes', $ad_codes_formatted, 'acm',  3600 );
		}	
		return $ad_codes_formatted;
	}

	/**
	 * Get a single ad code
	 *
	 * @param int $post_id Post ID for the ad code that we want
	 * @return array $ad_code Ad code representation of the data
	 */
	function get_ad_code( $post_id ) {

		$post = get_post( $post_id );
		if ( !$post )
			return false;
		
		$provider_url_vars = array();
		foreach ( $this->current_provider->columns as $slug => $title ) {
			$provider_url_vars[$slug] = get_post_meta( $post->ID, $slug, true );
		}

		$priority = get_post_meta( $post_id, 'priority', true );
		$priority = ( !empty( $priority ) ) ? intval( $priority ) : 10;
	
		$ad_code_formatted = array(
			'conditionals' => $this->get_conditionals( $post->ID ),
			'url_vars' => $provider_url_vars,
			'priority' => $priority,
			'post_id' => $post->ID
		);
		return $ad_code_formatted;

	}

	/**
	 * Flush cache
	 */
	function flush_cache() {
		wp_cache_delete('ad_codes', 'acm' );
	}

	/**
	 * Get the conditional values for an ad code
	 */
	function get_conditionals( $ad_code_id ) {
		$conditionals = get_post_meta( $ad_code_id, 'conditionals', true );
		if ( empty( $conditionals ) )
			$conditionals = array();
		return $conditionals;
	}


	/**
	 * Create a new ad code in the database
	 *
	 * @uses register_ad_code()
	 *
	 * @param array $ad_code
	 *
	 * @return int|false post_id or false
	 */
	function create_ad_code( $ad_code = array() ) {
		$titles = array();
		foreach ( $this->current_provider->columns as $slug => $col_title ) {
			// We shouldn't create an ad code,
			// If any of required fields is not set
			if ( ! $ad_code[$slug] ) {
				return;
			}
			$titles[] = $ad_code[$slug];
		}
		$acm_post = array(
			'post_title' => implode( '-', $titles ),
			'post_status' => 'publish',
			'comment_status' => 'closed',
			'ping_status' => 'closed',
			'post_type' => $this->post_type,
		);

		if ( ! is_wp_error( $acm_inserted_post_id = wp_insert_post( $acm_post, true ) ) ) {
			foreach ( $this->current_provider->columns as $slug => $title ) {
				update_post_meta( $acm_inserted_post_id, $slug, $ad_code[$slug] );
			}
			update_post_meta( $acm_inserted_post_id, 'priority', $ad_code['priority'] );
			$this->flush_cache();
			return $acm_inserted_post_id;
		}
		return false;
	}

	/**
	 * Update an existing ad code
	 */
	function edit_ad_code( $ad_code_id, $ad_code = array()) {
		foreach ( $this->current_provider->columns as $slug => $title ) {
			// We shouldn't update an ad code,
			// If any of required fields is not set
			if ( ! $ad_code[$slug] ) {
				return new WP_Error();
			}
		}
		if ( 0 !== $ad_code_id ) {
			foreach ( $this->current_provider->columns as $slug => $title ) {
				update_post_meta( $ad_code_id, $slug, $ad_code[$slug] );
			}
			update_post_meta( $ad_code_id, 'priority', $ad_code['priority'] );
		}
		$this->flush_cache();
		return $ad_code_id;
	}

	/**
	 * Delete an existing ad code
	 */
	function delete_ad_code( $ad_code_id ) {
		if ( 0 !== $ad_code_id ) {
			wp_delete_post( $ad_code_id , true ); //force delete post
			$this->flush_cache();
			return true;
		}
		return;
	}
	/**
	 * Create conditional
	 *
	 * @param int $ad_code_id id of our CPT post
	 * @param array $conditional to add
	 *
	 * @return bool
	 */
	function create_conditional( $ad_code_id, $conditional ) {
		if ( 0 !== $ad_code_id && !empty( $conditional ) ) {
			$existing_conditionals =  get_post_meta( $ad_code_id, 'conditionals', true );
			if ( ! is_array( $existing_conditionals ) ) {
				$existing_conditionals = array();
			}
			$existing_conditionals[] = array(
				'function' => $conditional['function'],
				'arguments' => explode(';', $conditional['arguments'] ),
			);
			return update_post_meta( $ad_code_id, 'conditionals', $existing_conditionals );
		}
		return false;
	}

	/**
	 * Update all conditionals for ad code
	 *
	 * @param int $ad_code_id id of our CPT post
	 * @param array of $conditionals
	 *
	 * @since v0.2
	 * @return bool
	 */
	function edit_conditionals( $ad_code_id, $conditionals = array() ) {
		if ( 0 !== $ad_code_id && !empty( $conditionals ) ) {
			$new_conditionals = array();
			foreach( $conditionals as $conditional ) {
				if ( '' == $conditional['function'] )
					continue;
				$new_conditionals[] = array(
					'function' => $conditional['function'],
					'arguments' => (array) $conditional['arguments'],
				);
			}
			return update_post_meta( $ad_code_id, 'conditionals', $new_conditionals );
		} elseif ( 0 !== $ad_code_id ) {
			return update_post_meta( $ad_code_id, 'conditionals', array() );
		}
	}

	/**
	 * Print our vars as JS
	 */
	function post_admin_header() {

		if ( !isset( $_GET['page'] ) || $_GET['page'] != $this->plugin_slug )
			return;

		$conditionals_parsed = array();
		foreach ( $this->whitelisted_conditionals as $conditional )
				$conditionals_parsed[] = $conditional . ':' . ucfirst( str_replace('_', ' ', $conditional ) );
		?>
		<script type="text/javascript">
			var acm_url = '<?php echo esc_js( admin_url( 'admin.php?page=' . $this->plugin_slug ) )  ?>';
			var acm_conditionals = '<?php echo esc_js( implode( ';', $conditionals_parsed ) )?>';
			var acm_ajax_nonce = '<?php echo esc_js( wp_create_nonce('acm_nonce') ) ?>';
			var acm_conditionals_index = 0;
		</script>
		<?php
	}

	/**
	 * Hook in our submenu page to the navigation
	 */
	function action_admin_menu() {
		add_submenu_page( 'tools.php', $this->title, $this->title, $this->manage_ads_cap, $this->plugin_slug, array( $this, 'admin_view_controller' ) );
	}

	/**
	 * Print the admin interface for managing the ad codes
	 *
	 */
	function admin_view_controller() {
		require_once( AD_CODE_MANAGER_ROOT . '/common/views/ad-code-manager.tpl.php' );
	}

	function contextual_help() {
		global $pagenow;
	if ( 'tools.php' != $pagenow || !isset( $_GET['page'] ) || $_GET['page'] != $this->plugin_slug )
		return;

		ob_start();
		?>
			<div id="conditionals-help">
		<p><strong>Note:</strong> this is not full list of conditional tags, you can always check out <a href="http://codex.wordpress.org/Conditional_Tags" class="external text">Codex page</a>.</p>

		<dl><dt> <tt><a href="http://codex.wordpress.org/Function_Reference/is_home" class="external text" title="http://codex.wordpress.org/Function_Reference/is_home">is_home()</a></tt>&nbsp;</dt><dd> When the main blog page is being displayed. This is the page which shows the time based blog content of your site, so if you've set a static Page for the Front Page (see below), then this will only be true on the Page which you set as the "Posts page" in <a href="http://codex.wordpress.org/Administration_Panels" title="Administration Panels" class="mw-redirect">Administration</a> &gt; <a href="http://codex.wordpress.org/Administration_Panels#Reading" title="Administration Panels" class="mw-redirect">Settings</a> &gt; <a href="http://codex.wordpress.org/Settings_Reading_SubPanel" title="Settings Reading SubPanel" class="mw-redirect">Reading</a>.
</dd></dl>
		<dl><dt> <tt><a href="http://codex.wordpress.org/Function_Reference/is_front_page" class="external text" title="http://codex.wordpress.org/Function_Reference/is_front_page">is_front_page()</a></tt>&nbsp;</dt><dd> When the front of the site is displayed, whether it is posts or a <a href="http://codex.wordpress.org/Pages" title="Pages">Page</a>.  Returns true when the main blog page is being displayed and the '<a href="http://codex.wordpress.org/Administration_Panels#Reading" title="Administration Panels" class="mw-redirect">Settings</a> &gt; <a href="http://codex.wordpress.org/Settings_Reading_SubPanel" title="Settings Reading SubPanel" class="mw-redirect">Reading</a> -&gt;Front page displays' is set to "Your latest posts", <b>or</b> when '<a href="http://codex.wordpress.org/Administration_Panels#Reading" title="Administration Panels" class="mw-redirect">Settings</a> &gt; <a href="http://codex.wordpress.org/Settings_Reading_SubPanel" title="Settings Reading SubPanel" class="mw-redirect">Reading</a> -&gt;Front page displays' is set to "A static page" and the "Front Page" value is the current <a href="/Pages" title="Pages">Page</a> being displayed.
</dd></dl>
<dl><dt> <tt><a href="http://codex.wordpress.org/Function_Reference/is_category" class="external text" title="http://codex.wordpress.org/Function_Reference/is_category">is_category()</a></tt>&nbsp;</dt><dd> When any Category archive page is being displayed.
</dd><dt> <tt>is_category( '9' )</tt>&nbsp;</dt><dd> When the archive page for Category 9 is being displayed.
</dd><dt> <tt>is_category( 'Stinky Cheeses' )</tt>&nbsp;</dt><dd> When the archive page for the Category with Name "Stinky Cheeses" is being displayed.
</dd><dt> <tt>is_category( 'blue-cheese' )</tt>&nbsp;</dt><dd> When the archive page for the Category with Category Slug "blue-cheese" is being displayed.
</dd><dt> <tt>is_category( array( 9, 'blue-cheese', 'Stinky Cheeses' ) )</tt>&nbsp;</dt><dd> Returns true when the category of posts being displayed is either term_ID 9, or <i>slug</i> "blue-cheese", or <i>name</i> "Stinky Cheeses".
</dd><dt> <tt>in_category( '5' )</tt>&nbsp;</dt><dd> Returns true if the current post is <b>in</b> the specified category id. <a href="http://codex.wordpress.org/Template_Tags/in_category" class="external text" title="http://codex.wordpress.org/Template_Tags/in_category">read more</a>
</dd></dl>
<dl><dt> <tt><a href="http://codex.wordpress.org/Function_Reference/is_tag" class="external text" title="http://codex.wordpress.org/Function_Reference/is_tag">is_tag()</a></tt>&nbsp;</dt><dd> When any Tag archive page is being displayed.
</dd><dt> <tt>is_tag( 'mild' )</tt>&nbsp;</dt><dd> When the archive page for tag with the slug of 'mild' is being displayed.
</dd><dt> <tt>is_tag( array( 'sharp', 'mild', 'extreme' ) )</tt>&nbsp;</dt><dd> Returns true when the tag archive being displayed has a slug of either "sharp", "mild", or "extreme".
</dd><dt> <tt>has_tag()</tt>&nbsp;</dt><dd> When the current post has a tag. Must be used inside The Loop.
</dd><dt> <tt>has_tag( 'mild' )</tt>&nbsp;</dt><dd> When the current post has the tag 'mild'.
</dd><dt> <tt>has_tag( array( 'sharp', 'mild', 'extreme' ) )</tt>&nbsp;</dt><dd> When the current post has any of the tags in the array.
</dd></dl>
	</div>
<?php
		$contextual_help = ob_get_clean();	
		
		ob_start();
?>
<p>Ad Code Manager gives non-developers an interface in the WordPress admin for configuring your complex set of ad codes.</p>
<p>We tried to streamline the process and make everyday AdOps a little bit easier</p>
<p>Depending on ad network you use, you will see a set of required fields to fill in (generally, "Ad Code" is a set of parameters you need to pass to ad server, so it could serve proper ad). Then you set conditionals. You can create ad code with conditionals in one easy step</p>
<p>Priorities work pretty much the same way they work in WordPress.  Lower numbers correspond with higher priority. 
<p>Once you've done creating ad codes, you can easily implement them in your theme using:</p>
<ul>
	<li>template tag: <code>&lt;?php do_action( 'acm_tag', $tag_id ) ?&gt;</code> </li>
	<li>shortcode: [acm-tag id="tag_id"]</li>
	<li>or using widget</li>
</ul>
	
<?php			
		$overview = ob_get_clean();
		ob_start();
?>
<p>There are some filters which will allow you to easily customize output of the plugin. You should place these filters in your themes functions.php file or someplace safe.</p>

<a href="https://gist.github.com/1631131" target="_blank">Check out this gist</a> to see all of the filters in action.

<p><strong>acm_default_url</strong></p>

<p>Currently, we don't store tokenized script URL anywhere so this filter is a nice place to set default value.</p>

<p>Arguments: <br />
* string $url The tokenized url of Ad Code</p> 
<p>Example usage: Set your default ad code URL</p>
<pre>
add_filter( 'acm_default_url', 'my_acm_default_url' ); 
function my_acm_default_url( $url ) { 
	if ( 0 === strlen( $url )  ) {
		return "http://ad.doubleclick.net/adj/%site_name%/%zone1%;s1=%zone1%;s2=;pid=%permalink%;fold=%fold%;kw=;test=%test%;ltv=ad;pos=%pos%;dcopt=%dcopt%;tile=%tile%;sz=%sz%;";
	}
}
</pre>

<p><strong>acm_output_tokens</strong></p> 

<p>Register output tokens depending on the needs of your setup. Tokens are the keys to be replaced in your script URL.</p>

<p>Arguments: <br/>
* array $output_tokens Any existing output tokens <br/>
* string $tag_id Unique tag id <br/>
* array $code_to_display Ad Code that matched conditionals 
</p>
<p>Example usage: Test to determine whether you're in test or production by passing ?test=on query argument</p>

<pre>
add_filter( 'acm_output_tokens', 'my_acm_output_tokens', 10, 3 );
function my_acm_output_tokens( $output_tokens, $tag_id, $code_to_display ) {
	$output_tokens['%test%'] = isset( $_GET['test'] ) && $_GET['test'] == 'on' ? 'on' : '';
	return $output_tokens;
}`
</pre>

<p><strong>acm_ad_tag_ids</strong></p>

<p>Extend set of default tag ids. Ad tag ids are used as a parameter for your template tag (e.g. do_action( 'acm_tag', 'my_top_leaderboard' ))</p>
<p>Arguments: <br />
* array $tag_ids array of default tag ids</p>

<p>Example usage: Add a new ad tag called 'my_top_leaderboard'</p>

<pre>
add_filter( 'acm_ad_tag_ids', 'my_acm_ad_tag_ids' );
function my_acm_ad_tag_ids( $tag_ids ) {
	$tag_ids[] = array(
		'tag' => 'my_top_leaderboard', // tag_id 
		'url_vars' => array(
			'sz' => '728x90', // %sz% token
			'fold' => 'atf', // %fold% token
			'my_custom_token' => 'something' // %my_custom_token% will be replaced with 'something'
		);
	return $tag_ids;
}
</pre>

<p><strong>acm_output_html</strong></p>

<p>Support multiple ad formats ( e.g. Javascript ad tags, or simple HTML tags ) by adjusting the HTML rendered for a given ad tag.</p>

<p>Arguments: <br />
* string $output_html The original output HTML <br />
* string $tag_id Ad tag currently being accessed <br />
</p>
<p>Example usage:</p>
<pre>
add_filter( 'acm_output_html', 'my_acm_output_html', 10, 2 );
function my_acm_output_html( $output_html, $tag_id ) {
	switch ( $tag_id ) {
		case 'my_leaderboard':
			$output_html = '&lt;a href="%url%"&gt; &lt;img src="%image_url%" /&gt;&lt;/a&gt;';
			break;
		case 'rich_media_leaderboard':
			$output_html = '&lt;script&gt; // omitted &lt;/script&gt;';
			break;
		default:
			break;
	}
	return $output_html;
}
</pre>
<p><strong>acm_whitelisted_conditionals</strong></p>

<p>Extend the list of usable conditional functions with your own awesome ones. We whitelist these so users can't execute random PHP functions.</p>

<p>Arguments: <br />
* array $conditionals Default conditionals</p>

<p>Example usage: Register a few custom conditional callbacks</p>

<pre>
add_filter( 'acm_whitelisted_conditionals', 'my_acm_whitelisted_conditionals' );
function my_acm_whitelisted_conditionals( $conditionals ) {
	$conditionals[] = 'my_is_post_type';
	$conditionals[] = 'is_post_type_archive';
	$conditionals[] = 'my_page_is_child_of';
	return $conditionals;
}
</pre>

<p><strong>acm_conditional_args</strong></p>

<p>For certain conditionals (has_tag, has_category), you might need to pass additional arguments.</p>

<p>Arguments: <br />
* array $cond_args Existing conditional arguments <br />
* string $cond_func Conditional function (is_category, is_page, etc)
</p>

<p>Example usage: has_category() and has_tag() use has_term(), which requires the object ID to function properly</p>

<pre>
add_filter( 'acm_conditional_args', 'my_acm_conditional_args', 10, 2 );
function my_acm_conditional_args( $cond_args, $cond_func ) {
	global $wp_query;
	// has_category and has_tag use has_term
	// we should pass queried object id for it to produce correct result
	if ( in_array( $cond_func, array( 'has_category', 'has_tag' ) ) ) {
		if ( $wp_query->is_single == true ) {
			$cond_args[] = $wp_query->queried_object->ID;
		}
	}
	// my_page_is_child_of is our custom WP conditional tag and we have to pass queried object ID to it
	if ( in_array( $cond_func, array( 'my_page_is_child_of' ) ) && $wp_query->is_page ) {
		$cond_args[] = $cond_args[] = $wp_query->queried_object->ID;
	}

	return $cond_args;
}
</pre>

<p><strong>acm_whitelisted_script_urls</strong></p>

<p>A security filter to whitelist which ad code script URLs can be added in the admin</p>

<p>Arguments: <br />
* array $whitelisted_urls Existing whitelisted ad code URLs</p>

<p>Example usage: Allow Doubleclick for Publishers ad codes to be used</p>

<pre>add_filter( 'acm_whitelisted_script_urls', 'my_acm_whitelisted_script_urls' );
function my_acm_whiltelisted_script_urls( $whitelisted_urls ) {
	$whitelisted_urls = array( 'ad.doubleclick.net' );
	return $whitelisted_urls;
}
</pre>

<p><strong>acm_display_ad_codes_without_conditionals</strong></p>

<p>Change the behavior of Ad Code Manager so that ad codes without conditionals display on the frontend. The default behavior is that each ad code requires a conditional to be included in the presentation logic.</p>

<p>Arguments: <br />
* bool $behavior Whether or not to display the ad codes that don't have conditionals</p>

<p>Example usage:</p>

<pre>add_filter( 'acm_display_ad_codes_without_conditionals', '__return_true' );</pre>

<p><strong>acm_provider_slug</strong></p>

<p>By default we use our bundled doubleclick_for_publishers config ( check it in /providers/doubleclick-for-publishers.php ). If you want to add your own flavor of DFP or even implement configuration for some another ad network, you'd have to apply a filter to correct the slug.</p>

<p>Example usage:</p>

<pre>add_filter( 'acm_provider_slug', function() { return 'my-ad-network-slug'; })</pre>

<p><strong>acm_logical_operator</strong></p>

<p>By default logical operator is set to "OR", that is, ad code will be displayed if at least one conditional returns true.
	You can change it to "AND", so that ad code will be displayed only if ALL of the conditionals match</p>

<p>Example usage:</p>

<pre>add_filter( 'acm_provider_slug', function( $slug ) { return 'my-ad-network-slug'; })</pre>

<p><strong>acm_manage_ads_cap</strong></p>

<p>By default user has to have "manage_options" cap. This filter comes in handy, if you want to relax the requirements.</p>

<p>Example usage:</p>

<pre>add_filter( 'acm_manage_ads_cap', function( $cap ) { return 'edit_others_posts'; })</pre>

<p><strong>acm_allowed_get_posts_args</strong></p>

<p>This filter is only for edge cases. Most likely you won't have to touch it. Allows to include additional query args for Ad_Code_Manager->get_ad_codes() method.</p>
	
<p>Example usage:</p>

<code>add_filter( 'acm_allowed_get_posts_args', function( $args_array ) { return array( 'offset', 'exclude' ); })</code>

<p><strong>acm_ad_code_count</strong></p>

<p>By default the total number of ad codes to get is 50, which is reasonable for any small to mid site. However, in some certain cases you would want to increase the limit. This will affect Ad_Code_Manager->get_ad_codes() 'numberposts' query argument.</p>

<p>Example usage:</p>

<pre>add_filter( 'acm_ad_code_count', function( $total ) { return 100; })</pre>

<p><strong>acm_list_table_columns</strong></p>

<p>This filter can alter table columns that are displayed in ACM UI.</p>

<p>Example usage:</p>

<pre>add_filter( 'acm_list_table_columns', function ( $columns ) {
			$columns = array(
				'id'             => __( 'ID', 'ad-code-manager' ),
				'name'           => __( 'Name', 'ad-code-manager' ),
				'priority'       => __( 'Priority', 'ad-code-manager' ),
				'conditionals'   => __( 'Conditionals', 'ad-code-manager' ),
			);
			return $columns;
	} )
	</pre>
<p><strong>acm_provider_columns</strong></p>

<p>This filter comes in pair with previous one, it should return array of ad network specific parameters. E.g. in acm_list_table_columns example we have
	'id', 'name', 'priority', 'conditionals'. All of them except name are generic for Ad Code Manager. Hence acm_provider_columns should return only "name"</p>

<p>Example usage:</p>
<pre>add_filter( 'acm_provider_columns', function ( $columns ) {
			$columns = array(
				'name'           => __( 'Name', 'ad-code-manager' ),
			);
			return $columns;
	} )</pre>
<?php		
		$configuration = ob_get_clean();
		
		
		get_current_screen()->add_help_tab(
			array(
				'id' => 'acm-overview',
				'title' => 'Overview',
				'content' => $overview,
			)
		);
		get_current_screen()->add_help_tab(
			array(
				'id' => 'acm-config',
				'title' => 'Configuration',
				'content' => $configuration,
			)
		);
		get_current_screen()->add_help_tab(
			array(
				'id' => 'acm-conditionals',
				'title' => 'Conditionals',
				'content' => $contextual_help,
			)
		);
	}

	/**
	 * Register a custom widget to display ad zones
	 *
	 */
	function register_widget() {
		register_widget( 'ACM_Ad_Zones' );
	}

	/**
	 * Register scripts and styles
	 *
	 */
	function register_scripts_and_styles() {
		global $pagenow;

		// Only load this on the proper page
		if ( 'tools.php' != $pagenow || !isset( $_GET['page'] ) || $_GET['page'] != $this->plugin_slug )
			return;

		wp_enqueue_style( 'acm-style', AD_CODE_MANAGER_URL . '/common/css/acm.css' );
		wp_enqueue_script( 'acm', AD_CODE_MANAGER_URL . '/common/js/acm.js', array( 'jquery', 'jquery-ui-core' ) );
	}

	/**
	 * Register an ad tag with the plugin so it can be used
	 * on the frontend of the site
	 *
	 * @since 0.1
	 *
	 * @param string $tag Ad tag for this instance of code
	 * @param string $url Script URL for ad code
	 * @param array $conditionals WordPress-style conditionals for where this code should be displayed
	 * @param int $priority What priority this registration runs at
	 * @param array $url_vars Replace tokens in $script with these values
	 * @param int $priority Priority of the ad code in comparison to others
	 * @return bool|WP_Error $success Whether we were successful in registering the ad tag
	 */
	function register_ad_code( $tag, $url, $conditionals = array(), $url_vars = array(), $priority = 10 ) {

		// Run $url aganist a whitelist to make sure it's a safe URL
		if ( !$this->validate_script_url( $url ) )
			return;

		// @todo Sanitize the conditionals against our possible set of conditionals so that users
		// can't just run arbitrary functions. These are whitelisted on execution of the ad code so we're fine for now

		// @todo Sanitize all of the other input

		// Make sure our priority is an integer
		if ( !is_int( $priority ) )
			$priority = 10;

		// Save the ad code to our set of ad codes
		$this->ad_codes[$tag][] = array(
				'url' => $url,
				'priority' => $priority,
				'conditionals' => $conditionals,
				'url_vars' => $url_vars,
			);
	}

	/**
	 * Register an array of ad tags with the plugin
	 *
	 * @since 0.1
	 *
	 * @param array $ad_codes An array of ad tags
	 */
	function register_ad_codes( $ad_codes = array() ) {
		if ( empty( $ad_codes ) )
			return;

		foreach( (array)$ad_codes as $key => $ad_code ) {

			$default = array(
						'tag' => '',
						'url' => '',
						'conditionals' => array(),
						'url_vars' => array(),
						'priority' => 10,
					);
			$ad_code = array_merge( $default, $ad_code );

			foreach ( (array)$this->ad_tag_ids as $default_tag ) {
				/**
				 * Configuration filter: acm_default_url
				 * If you don't specify a URL for your ad code when registering it in
				 * the WordPress admin or at a code level, you can simply apply it with
				 * a custom filter defined.
				 */
				$ad_code['priority'] = strlen( $ad_code['priority'] ) == 0 ? 10 : intval( $ad_code['priority'] ); //make sure priority is int, if it's unset, we set it to 10
				$this->register_ad_code( $default_tag['tag'], apply_filters( 'acm_default_url', $ad_code['url'] ), $ad_code['conditionals'], array_merge( $default_tag['url_vars'], $ad_code['url_vars'] ), $ad_code['priority'] );
			}
		}
	}

	/**
	 * Display the ad code based on what's registered
	 * and complicated sorting logic
	 *
	 * @uses do_action( 'acm_tag, 'your_tag_id' )
	 *
	 * @param string $tag_id Unique ID for the ad tag
	 */
	function action_acm_tag( $tag_id ) {

		// If there aren't any ad codes, it's not worth it for us to do anything.
		if ( !isset( $this->ad_codes[$tag_id] ) )
			return;

		// Run our ad codes through all of the conditionals to make sure we should
		// be displaying it
		$display_codes = array();
		foreach( (array)$this->ad_codes[$tag_id] as $ad_code ) {

			// If the ad code doesn't have any conditionals
			// we should add it to the display list
			if ( empty( $ad_code['conditionals'] ) && apply_filters( 'acm_display_ad_codes_without_conditionals', false ) ) {
				$display_codes[] = $ad_code;
				continue;
			}

			// If the ad code doesn't have any conditionals
			// and configuration filter acm_display_ad_codes_without_conditionals returns false
			// We should should skip it
			
			if ( empty( $ad_code['conditionals'] ) && ! apply_filters( 'acm_display_ad_codes_without_conditionals', false ) ) {
				continue;
			}

			$include = true;
			foreach( $ad_code['conditionals'] as $conditional ) {
				// If the conditional was passed as an array, then we have a complex rule
				// Otherwise, we have a function name and expect rue
				if ( is_array( $conditional ) ) {
					$cond_func = $conditional['function'];
					if ( !empty( $conditional['arguments'] ) )
						$cond_args = $conditional['arguments'];
					else
						$cond_args = array();
					if ( isset( $conditional['result'] ) )
						$cond_result = $conditional['result'];
					else
						$cond_result = true;
				} else {
					$cond_func = $conditional;
					$cond_args = array();
					$cond_result = true;
				}

				// Special trick: include '!' in front of the function name to reverse the result
				if ( 0 === strpos( $cond_func, '!' ) ) {
					$cond_func = ltrim( $cond_func, '!' );
					$cond_result = false;
				}

				// Don't run the conditional if the conditional function doesn't exist or
				// isn't in our whitelist
				if ( !function_exists( $cond_func ) || !in_array( $cond_func, $this->whitelisted_conditionals ) )
					continue;

				// Run our conditional and use any arguments that were passed
				if ( !empty( $cond_args ) ) {
					/**
					 * Configuration filter: acm_conditional_args
					 * For certain conditionals (has_tag, has_category), you might need to
					 * pass additional arguments.
					 */
					$result = call_user_func_array( $cond_func, apply_filters( 'acm_conditional_args', $cond_args, $cond_func  ) );
				} else {
					$result = call_user_func( $cond_func );
				}

				// If our results don't match what we need, don't include this ad code
				if ( $cond_result !== $result )
					$include = false;
				else
					$include = true;

				// If we have matching conditional and $this->logical_operator equals OR just break from the loop and do not try to evaluate others
				if ( $include && $this->logical_operator == 'OR' )
					break;

				// If $this->logical_operator equals AND and one conditional evaluates false, skip this ad code
				if ( !$include && $this->logical_operator == 'AND' )
					break;

			}

			// If we're supposed to include the ad code even after we've run the conditionals,
			// let's do it
			if ( $include )
				$display_codes[] = $ad_code;

		}

		// Don't do anything if we've ended up with no ad codes
		if ( empty( $display_codes ) )
			return;

		// Prioritize the display of the ad codes based on
		// the priority argument for the ad code
		$prioritized_display_codes = array();
		foreach( $display_codes as $display_code ) {
			$priority = $display_code['priority'];
			$prioritized_display_codes[$priority][] = $display_code;
		}
		ksort( $prioritized_display_codes, SORT_NUMERIC );
		$code_to_display = array_shift( array_shift( $prioritized_display_codes ) );

		// Run $url aganist a whitelist to make sure it's a safe URL
		if ( !$this->validate_script_url( $code_to_display['url'] ) )
			return;

		/**
		 * Configuration filter: acm_output_html
		 * Support multiple ad formats ( e.g. Javascript ad tags, or simple HTML tags )
		 * by adjusting the HTML rendered for a given ad tag.
		 */
		$output_html = apply_filters( 'acm_output_html', $this->current_provider->output_html, $tag_id );

		// Parse the output and replace any tokens we have left. But first, load the script URL
		$output_html = str_replace( '%url%', $code_to_display['url'], $output_html );
		/**
		 * Configuration filter: acm_output_tokens
		 * Register output tokens depending on the needs of your setup. Tokens are the
		 * keys to be replaced in your script URL.
		 */
		$output_tokens = apply_filters( 'acm_output_tokens', $this->current_provider->output_tokens, $tag_id, $code_to_display );
		foreach( (array)$output_tokens as $token => $val ) {
			$output_html = str_replace( $token, $val, $output_html );
		}

		// Print the ad code
		echo $output_html;
	}

	/**
	 * Filter the output tokens used in $this->action_acm_tag to include our URL vars
	 *
	 * @since 0.1
	 *
	 * @return array $output Placeholder tokens to be replaced with their values
	 */
	function filter_output_tokens( $output_tokens, $tag_id, $code_to_display ) {
		if ( !isset( $code_to_display['url_vars'] ) || !is_array( $code_to_display['url_vars'] ) )
			return $output_tokens;

		foreach( $code_to_display['url_vars'] as $url_var => $val ) {
			$new_key = '%' . $url_var . '%';
			$output_tokens[$new_key] = $val;
		}

		return $output_tokens;
	}

	/**
	 * Ensure the URL being used passes our whitelist check
	 *
	 * @since 0.1
	 * @see https://gist.github.com/1623788
	 */
	function validate_script_url( $url ) {
		$domain = parse_url( $url, PHP_URL_HOST );

		// Check if we match the domain exactly
		if ( in_array( $domain, $this->current_provider->whitelisted_script_urls ) )
			return true;

		$valid = false;

		foreach( $this->current_provider->whitelisted_script_urls as $whitelisted_domain ) {
			$whitelisted_domain = '.' . $whitelisted_domain; // Prevent things like 'evilsitetime.com'
			if( strpos( $domain, $whitelisted_domain ) === ( strlen( $domain ) - strlen( $whitelisted_domain ) ) ) {
				$valid = true;
				break;
			}
		}
		return $valid;
	}
	
	/**
	 * Shortcode function
	 *
	 * @since 0.2
	 */
	function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => '',
			), $atts );

		$id = sanitize_text_field( $atts['id'] );
		if ( empty( $id ) )
			return;
		
		$this->action_acm_tag( $id );
	}

}

global $ad_code_manager;
$ad_code_manager = new Ad_Code_Manager();