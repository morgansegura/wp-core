<?php
/**
 * Plugin Name: Presto Delivery
 * Description: A delivery wire between your Presto Platform account and your WordPress-based site. After authentication, your Presto assignments will be delivered to your WordPress site under a Pending Draft.
 * Plugin URI: http://presto.media/blog/plugin/
 * Author: Presto Media
 * Author URI: https://presto.media
 * Version: 1.0.4
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

require_once( dirname( __FILE__ ) . '/class-pdel-hmac-auth.php' );
require_once( dirname( __FILE__ ) . '/class-presto-post-api.php' );
require_once( dirname( __FILE__ ) . '/class-pdel-options.php' );

add_action( 'rest_api_init', function () {
	$secret = PDel_Options::get_secret();
	$auth = new PDel_HMAC_Auth( $secret );
	( new Presto_Post_API( $auth ) )->register_routes();
} );

new PDel_Options();
