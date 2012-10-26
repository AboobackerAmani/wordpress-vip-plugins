<?php
/*
Plugin Name: WordPress.com Related Posts
Plugin URI: http://automattic.com
Description: Related posts using the WordPress.com Elastic Search infrastructure
Author: Daniel Bachhuber
Version: 0.0
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

class WPCOM_Related_Posts {

	public $is_elastic_search;
	public $index;

	public $options_capability = 'manage_options';
	public $default_options = array();
	public $options = array();
	public $stopwords = array();

	const key = 'wpcom-related-posts';

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new WPCOM_Related_Posts;
			self::$instance->setup_actions();
			self::$instance->setup_filters();
		}
		return self::$instance;
	}

	private function __construct() {
		/** Don't do anything **/
	}

	private function setup_actions() {

		add_action( 'init', array( self::$instance, 'action_init' ) );
		add_action( 'wp_head', array( self::$instance, 'action_wp_head' ) );

		add_action( 'admin_init', array( self::$instance, 'action_admin_init' ) );
		add_action( 'admin_menu', array( self::$instance, 'action_admin_menu' ) );
	}

	private function setup_filters() {

		add_filter( 'the_content', array( self::$instance, 'filter_the_content' ) );
	}

	public function action_init() {

		$this->default_options = array(
				'post-types' => array(),
			);
		$this->options = get_option( self::key, $this->default_options );

		// If Elastic Search exists, let's use that
		$es_path = WP_CONTENT_DIR . '/plugins/elasticsearch.php';
		if ( file_exists( $es_path ) ) {
			require_once $es_path;
			// Check if the index exists. If it doesn't, let the user know we need to create it for them
			$index_name = parse_url( site_url(), PHP_URL_HOST );
			$this->index = es_api_get_index( $index_name, get_current_blog_id() );
			if ( $this->index )
				$this->is_elastic_search = true;
			else
				$this->is_elastic_search = false;
		} else {
			$this->is_elastic_search = false;
		}

		$this->stopwords = array( 'a', 'about', 'above', 'above', 'across', 'after', 'afterwards', 'again', 'against', 'all', 'almost', 'alone', 'along', 'already', 'also','although','always','am','among', 'amongst', 'amoungst', 'amount',  'an', 'and', 'another', 'any','anyhow','anyone','anything','anyway', 'anywhere', 'are', 'around', 'as',  'at', 'back','be','became', 'because','become','becomes', 'becoming', 'been', 'before', 'beforehand', 'behind', 'being', 'below', 'beside', 'besides', 'between', 'beyond', 'bill', 'both', 'bottom','but', 'by', 'call', 'can', 'cannot', 'cant', 'co', 'con', 'could', 'couldnt', 'cry', 'de', 'describe', 'detail', 'do', 'done', 'down', 'due', 'during', 'each', 'eg', 'eight', 'either', 'eleven','else', 'elsewhere', 'empty', 'enough', 'etc', 'even', 'ever', 'every', 'everyone', 'everything', 'everywhere', 'except', 'few', 'fifteen', 'fify', 'fill', 'find', 'fire', 'first', 'five', 'for', 'former', 'formerly', 'forty', 'found', 'four', 'from', 'front', 'full', 'further', 'get', 'give', 'go', 'had', 'has', 'hasnt', 'have', 'he', 'hence', 'her', 'here', 'hereafter', 'hereby', 'herein', 'hereupon', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'however', 'hundred', 'ie', 'if', 'in', 'inc', 'indeed', 'interest', 'into', 'is', 'it', 'its', 'itself', 'keep', 'last', 'latter', 'latterly', 'least', 'less', 'ltd', 'made', 'many', 'may', 'me', 'meanwhile', 'might', 'mill', 'mine', 'more', 'moreover', 'most', 'mostly', 'move', 'much', 'must', 'my', 'myself', 'name', 'namely', 'neither', 'never', 'nevertheless', 'next', 'nine', 'no', 'nobody', 'none', 'noone', 'nor', 'not', 'nothing', 'now', 'nowhere', 'of', 'off', 'often', 'on', 'once', 'one', 'only', 'onto', 'or', 'other', 'others', 'otherwise', 'our', 'ours', 'ourselves', 'out', 'over', 'own','part', 'per', 'perhaps', 'please', 'put', 'rather', 're', 'same', 'see', 'seem', 'seemed', 'seeming', 'seems', 'serious', 'several', 'she', 'should', 'show', 'side', 'since', 'sincere', 'six', 'sixty', 'so', 'some', 'somehow', 'someone', 'something', 'sometime', 'sometimes', 'somewhere', 'still', 'such', 'system', 'take', 'ten', 'than', 'that', 'the', 'their', 'them', 'themselves', 'then', 'thence', 'there', 'thereafter', 'thereby', 'therefore', 'therein', 'thereupon', 'these', 'they', 'thickv', 'thin', 'third', 'this', 'those', 'though', 'three', 'through', 'throughout', 'thru', 'thus', 'to', 'together', 'too', 'top', 'toward', 'towards', 'twelve', 'twenty', 'two', 'un', 'under', 'until', 'up', 'upon', 'us', 'very', 'via', 'was', 'we', 'well', 'were', 'what', 'whatever', 'when', 'whence', 'whenever', 'where', 'whereafter', 'whereas', 'whereby', 'wherein', 'whereupon', 'wherever', 'whether', 'which', 'while', 'whither', 'who', 'whoever', 'whole', 'whom', 'whose', 'why', 'will', 'with', 'within', 'without', 'would', 'yet', 'you', 'your', 'yours', 'yourself', 'yourselves', 'und', 'de', 'la', 'www', 'en' );
		$this->stopwords = apply_filters( 'wrp_stopwords', $this->stopwords );

		if ( ! $this->is_elastic_search )
			add_action( 'admin_notices', array( self::$instance, 'admin_notice_no_index' ) );

	}

	public function admin_notice_no_index() {
		echo '<div class="error"><p>' . __( 'WordPress.com Related Posts needs a little extra configuration behind the scenes. Please contact support to make it happen.' ) . '</p></div>';
	}

	public function action_admin_init() {

		register_setting( self::key, self::key, array( self::$instance, 'sanitize_options' ) );
		add_settings_section( 'general', false, '__return_false', self::key );
		add_settings_field( 'post-types', __( 'Enable for these post types:', 'wpcom-related-posts' ), array( self::$instance, 'setting_post_types' ), self::key, 'general' );
	}

	public function action_admin_menu() {

		add_options_page( __( 'WordPress.com Related Posts', 'wpcom-related-posts' ), __( 'Related Posts', 'wpcom-related-posts' ), $this->options_capability, self::key, array( self::$instance, 'view_settings_page' ) );
	}

	public function setting_post_types() {
		$all_post_types = get_post_types( array( 'publicly_queryable' => true ), 'objects' );
		foreach( $all_post_types as $post_type ) {
			echo '<label for="' . esc_attr( 'post-type-' . $post_type->name ) . '">';
			echo '<input id="' . esc_attr( 'post-type-' . $post_type->name ) . '" type="checkbox" name="' . self::key . '[post-types][]" ';
			if ( ! empty( $this->options['post-types'] ) && in_array( $post_type->name, $this->options['post-types'] ) )
				echo ' checked="checked"';
			echo ' value="' . esc_attr( $post_type->name ) . '" />&nbsp&nbsp;';
			echo $post_type->labels->name;
			echo '</label><br />';
		}
	}

	public function sanitize_options( $in ) {

		$out = $this->default_options;

		// Validate the post types
		$valid_post_types = get_post_types( array( 'publicly_queryable' => true ) );
		foreach( $in['post-types'] as $maybe_post_type ) {
			if ( in_array( $maybe_post_type, $valid_post_types ) )
				$out['post-types'][] = $maybe_post_type;
		}

		return $out;
	}

	public function view_settings_page() {
	?><div class="wrap">
		<h2><?php _e( 'WordPress.com Related Posts', 'wpcom-related-posts' ); ?></h2>
		<p><?php _e( 'Related posts for the bottom of your content using WordPress.com infrastructure', 'wpcom-related-posts' ); ?></p>
		<form action="options.php" method="POST">
			<?php settings_fields( self::key ); ?>
			<?php do_settings_sections( self::key ); ?>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
	}

	/**
	 * Basic styling for the related posts so they don't look terrible
	 */
	public function action_wp_head() {
		?>
		<style>
			.wpcom-related-posts ul li {
				list-style-type: none;
				display: inline-block;
			}
		</style>
		<?php
	}

	/**
	 * Append related posts to the post content
	 */
	public function filter_the_content( $the_content ) {
		global $wp_query, $wp_the_query;

		// Related posts should only be appended on the main loop for is_singular() of acceptable post types
		if ( $wp_query !== $wp_the_query || ! in_array( get_post_type(), $this->options['post-types'] ) || ! is_singular( get_post_type() ) )
			return $the_content;

		$related_posts = $this->get_related_posts();
		$related_posts_html = array(
				'<div class="wpcom-related-posts" id="' . esc_attr( 'wpcom-related-posts-' . get_the_ID() ) . '">',
				'<ul>',
			);
		foreach( $related_posts as $related_post ) {
			$related_posts_html[] = '<li>';
			if ( has_post_thumbnail( $related_post->ID ) )
				$related_posts_html[] = '<a href="' . get_permalink( $related_post->ID ) . '">' . get_the_post_thumbnail( $related_post->ID ) . '</a>';

			$related_posts_html[] = '<a href="' . get_permalink( $related_post->ID ) . '">' . apply_filters( 'the_title', $related_post->post_title ) . '</a>';
			$related_posts_html[] = '</li>';
		}
		$related_posts_html[] = '</ul>';
		$related_posts_html[] = '</div>';

		return $the_content . implode( PHP_EOL, $related_posts_html );
	}

	/**
	 * @return array $related_posts An array of related WP_Post objects
	 */
	public function get_related_posts( $post_id = null, $args = array() ) {

		if ( is_null( $post_id ) )
			$post_id = get_the_ID();

		$defaults = array(
				'posts_per_page'          => 5,
				'post_type'               => get_post_type( $post_id ),
			);
		$args = wp_parse_args( $args, $defaults );

		$related_posts = array();

		// Use Elastic Search for the results if it's available
		if ( $this->is_elastic_search ) {

			$current_post = get_post( $post_id );
			$keywords = $this->get_keywords( $current_post->post_title ) + $this->get_keywords( $current_post->post_content ) ;
			$query = implode( ' ', array_unique( $keywords ) );
			$es_args = array(
					'more_like_this'          => array(
							'like_text'       => $query,
							'min_term_freq'   => 1,
							'max_query_terms' => 12,
						),
					'name'                => parse_url( site_url(), PHP_URL_HOST ),
					'size'                => (int)$args['posts_per_page'] + 1,
				);
			if ( is_array( $args['post_type'] ) ) {
				// @todo support for a set of post types
			} else if ( in_array( $args['post_type'], get_post_types() ) && 'all' != $args['post_type'] ) {
				$es_args['filters']['type']['value'] = $args['post_type'];
			}
			$related_es_query = es_api_query_index( $es_args );
			$related_posts = array_map( 'get_post', wp_list_pluck( $related_es_query->getResults(), 'id' ) );
			foreach( $related_posts as $key => $related_post ) {
				// Ignore the current post if it ends up being a related post
				if ( $post_id == $related_post->ID )
					unset( $related_posts[$key] );
			}
			// If we're still over the initial request, just return the first N
			if ( count( $related_posts) > (int)$args['posts_per_page'] )
				$related_posts = array_slice( $related_posts, 0, (int)$args['posts_per_page'] );
		} else {
			$related_query_args = array(
				'posts_per_page' => (int)$args['posts_per_page'],
				'post__not_in'   => $post_id,
			);
			$categories = get_the_category( $post_id );
			if ( ! empty( $categories ) )
				$related_query_args[ 'cat' ] = $categories[0]->term_id;

			$related_query = new WP_Query( $related_query_args );
			$related_posts = $related_query->get_posts();
		}
		return $related_posts;
	}

	/**
	 * Get keywords from a string of text
	 *
	 * @param string $text String of text to pull keywords from
	 * @param int $word_count Maximum number of words to pull
	 * @return array $keywords The keywords we've found
	 */
	private function get_keywords( $text, $word_count = 5 ) {
		$keywords = array();
		foreach( (array)explode( ' ', $text ) as $word ) {
			// Strip characters we don't want
			$word = trim( $word, '?.;,"' );
			if ( in_array( $word, $this->stopwords ) )
				continue;

			$keywords[] = $word;
			if ( count( $keywords ) == $word_count )
				break;
		}
		return $keywords;
	}

}

function WPCOM_Related_Posts() {
	return WPCOM_Related_Posts::instance();
}
add_action( 'plugins_loaded', 'WPCOM_Related_Posts' );