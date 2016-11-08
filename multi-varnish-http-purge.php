<?php
/*
Plugin Name: Multi-Varnish HTTP Purge
Plugin URI: http://wordpress.org/extend/plugins/multi-varnish-http-purge/
Description: Sends HTTP PURGE requests to URLs of changed posts/pages on multiple Varnish instances when they are modified.
Version: 3.9.2
Author: Nelson Hallidy
Author URI:
License: http://www.apache.org/licenses/LICENSE-2.0
Text Domain: multi-varnish-http-purge
Network: true

Varnish HTTP Purge Copyright 2013: Mika A. Epstein (email: ipstenu@ipstenu.org)

Varnish HTTP Purge Original Author: Leon Weidauer ( http:/www.lnwdr.de/ )

Fork of Varnish HTTP Purge 3.3.1
modified purgeUrl($url) to reflect use of define('VHP_VARNISH_IP','123.45.67.89,169.123.80.80,...'); in wp-config.php file
this lets you define a string of comma separated ip-address to be purged

	This file is part of Multi-Varnish HTTP Purge, a plugin for WordPress.

	Multi-Varnish HTTP Purge is free software: you can redistribute it and/or modify
	it under the terms of the Apache License 2.0 license.

	Multi-Varnish HTTP Purge is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

*/

/**
 * Purge Varnish Class
 *
 * @since 2.0
 */

class VarnishPurger {
	protected $purgeUrls = array();

	/**
	 * Init
	 *
	 * @since 2.0
	 * @access public
	 */
	public function __construct( ) {
		defined('VHP_VARNISH_IP') || define('VHP_VARNISH_IP', false );
		add_action( 'init', array( &$this, 'init' ) );
		add_action( 'activity_box_end', array( $this, 'varnish_rightnow' ), 100 );
	}

	/**
	 * Plugin Init
	 *
	 * @since 1.0
	 * @access public
	 */
	public function init() {
		global $blog_id;

		// get my events
		$events = $this->getRegisterEvents();
		$noIDevents = $this->getNoIDEvents();

		// make sure we have events and they're in an array
		if ( !empty( $events ) && !empty( $noIDevents ) ) {

			// Force it to be an array, in case someone's stupid
			$events = (array) $events;
			$noIDevents = (array) $noIDevents;

			// Add the action for each event
			foreach ( $events as $event) {
				if ( in_array($event, $noIDevents ) ) {
					// These events have no post ID and, thus, will perform a full purge
					add_action( $event, array($this, 'purgeNoID') );
				} else {
					add_action( $event, array($this, 'purgePost'), 10, 2 );
				}
			}
		}

		add_action( 'shutdown', array($this, 'executePurge') );

		// Success: Admin notice when purging
		if ( isset($_GET['vhp_flush_all']) && check_admin_referer('varnish-http-purge') ) {
			add_action( 'admin_notices' , array( $this, 'purgeMessage'));
		}

		// Warning: No Pretty Permalinks!
		if ( '' == get_option( 'permalink_structure' ) && current_user_can('manage_options') ) {
			add_action( 'admin_notices' , array( $this, 'prettyPermalinksMessage'));
		}

		// Checking user permissions for who can and cannot use the admin button
		if (
			// SingleSite - admins can always purge
			( !is_multisite() && current_user_can('activate_plugins') ) ||
			// Multisite - Network Admin can always purge
			current_user_can('manage_network') ||
			// Multisite - Site admins can purge UNLESS it's a subfolder install and we're on site #1
			( is_multisite() && !current_user_can('manage_network') && ( SUBDOMAIN_INSTALL || ( !SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE != $blog_id ) ) ) )
			) {
				add_action( 'admin_bar_menu', array( $this, 'varnish_rightnow_adminbar' ), 100 );
		}

	}

	/**
	 * Purge Message
	 * Informs of a succcessful purge
	 *
	 * @since 2.0
	 */
	function purgeMessage() {
		echo "<div id='message' class='notice notice-success fade is-dismissible'><p><strong>".__('Varnish cache purged!', 'varnish-http-purge')."</strong></p></div>";
	}

	/**
	 * Permalinks Message
	 * Explains you need Pretty Permalinks on to use this plugin
	 *
	 * @since 2.0
	 */
	function prettyPermalinksMessage() {
		echo "<div id='message' class='error'><p>" . sprintf( __( 'Varnish HTTP Purge requires you to use custom permalinks. Please go to the <a href="%1$s">Permalinks Options Page</a> to configure them.', 'varnish-http-purge' ), admin_url( 'options-permalink.php' ) ) . "</p></div>";
	}

	/**
	 * Varnish Purge Button in the Admin Bar
	 *
	 * @since 2.0
	 */
	function varnish_rightnow_adminbar($admin_bar){
		$admin_bar->add_menu( array(
			'id'	=> 'purge-varnish-cache-all',
			'title' => __('Purge Varnish','varnish-http-purge'),
			'href'  => wp_nonce_url(add_query_arg('vhp_flush_all', 1), 'varnish-http-purge'),
			'meta'  => array(
				'title' => __('Purge Varnish','varnish-http-purge'),
			),
		));
	}

	/**
	 * Varnish Right Now Information
	 * This information is put on the Dashboard 'Right now' widget
	 *
	 * @since 1.0
	 */
	function varnish_rightnow() {
		global $blog_id;
		$url = wp_nonce_url(add_query_arg('vhp_flush_all', 1), 'varnish-http-purge');
		$intro = sprintf( __('<a href="%1$s">Varnish HTTP Purge</a> automatically purges your posts when published or updated. Sometimes you need a manual flush.', 'varnish-http-purge' ), 'http://wordpress.org/plugins/varnish-http-purge/' );
		$button =  __('Press the button below to force it to purge your entire cache.', 'varnish-http-purge' );
		$button .= '</p><p><span class="button"><a href="'.$url.'"><strong>';
		$button .= __('Purge Varnish', 'varnish-http-purge' );
		$button .= '</strong></a></span>';
		$nobutton =  __('You do not have permission to purge the cache for the whole site. Please contact your administrator.', 'varnish-http-purge' );

		if (
			// SingleSite - admins can always purge
			( !is_multisite() && current_user_can('activate_plugins') ) ||
			// Multisite - Network Admin can always purge
			current_user_can('manage_network') ||
			// Multisite - Site admins can purge UNLESS it's a subfolder install and we're on site #1
			( is_multisite() && !current_user_can('manage_network') && ( SUBDOMAIN_INSTALL || ( !SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE != $blog_id ) ) ) )
		) {
			$text = $intro.' '.$button;
		} else {
			$text = $intro.' '.$nobutton;
		}
		echo "<p class='varnish-rightnow'>$text</p>\n";
	}

	/**
	 * Registered Events
	 * These are when the purge is triggered
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function getRegisterEvents() {

		// Define registered purge events
		$actions = array(
			'switch_theme',						// After a theme is changed
			'autoptimize_action_cachepurged', 	// Compat with https://wordpress.org/plugins/autoptimize/
			'save_post',     			       // Save a post
			'deleted_post',  			       // Delete a post
			'trashed_post',  			       // Empty Trashed post
			'edit_post',  			          // Edit a post - includes leaving comments
			'delete_attachment', 			   // Delete an attachment - includes re-uploading
		);

		// send back the actions array, filtered
		// @param array $actions the actions that trigger the purge event
		return apply_filters( 'varnish_http_purge_events', $actions );
	}

	/**
	 * Events that have no post IDs
	 * These are when a full purge is triggered
	 *
	 * @since 3.9
	 * @access protected
	 */
	protected function getNoIDEvents() {

		// Define registered purge events
		$actions = array(
			'switch_theme',						// After a theme is changed
			'autoptimize_action_cachepurged,' 	// Compat with https://wordpress.org/plugins/autoptimize/
		);

		// send back the actions array, filtered
		// @param array $actions the actions that trigger the purge event
		// DEVELOPERS! USE THIS SPARINGLY! YOU'RE A GREAT BIG :poop: IF YOU USE IT FLAGRANTLY
		return apply_filters( 'varnish_http_purge_events_full', $actions );
	}

	/**
	 * Execute Purge
	 * Run the purge command for the URLs. Calls $this->purgeUrl for each URL
	 *
	 * @since 1.0
	 * @access protected
	 */
	public function executePurge( ) {
		$purgeUrls = array_unique($this->purgeUrls);

		if (empty($purgeUrls)) {
			if ( isset($_GET['vhp_flush_all']) && check_admin_referer('varnish-http-purge') ) {
				$this->purgeUrl( home_url() .'/?vhp-regex' );
			}
		} else {
			foreach($purgeUrls as $url) {
				$this->purgeUrl($url);
			}
		}
	}

	/**
	 * Purge URL
	 * Parse the URL for proxy proxies
	 *
	 * @since 1.0
	 * @param array $url the url to be purged
	 * @access public
	 */
	public function purgeUrl($url) {
		$p = parse_url($url);

		if ( isset($p['query']) && ( $p['query'] == 'vhp-regex' ) ) {
			$pregex = '.*';
			$varnish_x_purgemethod = 'regex';
		} else {
			$pregex = '';
			$varnish_x_purgemethod = 'default';
		}

		// Build a varniship
		if ( VHP_VARNISH_IP != false ) {
			$varniship = VHP_VARNISH_IP;
		} else {
			$varniship = get_option('vhp_varnish_ip');
		}
		$varniship = apply_filters('vhp_varnish_ip', $varniship);

		if (isset($p['path'] ) ) {
			$path = $p['path'];
		} else {
			$path = '';
		}

		/**
		 * Schema filter
		 *
		 * Allows default http:// schema to be changed to https
		 * varnish_http_purge_schema()
		 *
		 * @since 3.7.3
		 *
		 */

		$schema = apply_filters( 'varnish_http_purge_schema', 'http://' );

		// If we made varniship, let it sail
		if ( isset($varniship) && $varniship != null ) {
			$host = $varniship;
		} else {
			$host = $p['host'];
		}

		// XXX multi IPs here
		$varnishArray = explode( ',', $varniship );

		foreach ( $varnishArray as $host ) {
			$purgeme = $schema.$host.$path.$pregex;

			if (!empty($p['query']) && $p['query'] != 'vhp-regex') {
				$purgeme .= "?{$p['query']}";
			}

			// Cleanup CURL functions to be wp_remote_request and thus better
			// http://wordpress.org/support/topic/incompatability-with-editorial-calendar-plugin
			$response = wp_remote_request( $purgeme, array( 'method' => 'PURGE', 'headers' => array( 'host' => $p['host'], 'X-Purge-Method' => $varnish_x_purgemethod ) ) );
			do_action('after_purge_url', $url, $purgeme, $response);
		}
	}

	/**
	 * Purge - No IDs
	 * Flush the whole cache
	 *
	 * @since 3.9
	 * @access private
	 */
	public function purgeNoID( $postId ) {
		$listofurls = array();

		array_push($listofurls, home_url('/?vhp-regex' ) );

		// Now flush all the URLs we've collected provided the array isn't empty
		if ( !empty($listofurls) ) {
			foreach ($listofurls as $url) {
				array_push($this->purgeUrls, $url ) ;
			}
		}
	}

	/**
	 * Purge Post
	 * Flush the post
	 *
	 * @since 1.0
	 * @param array $postId the ID of the post to be purged
	 * @access public
	 */
	public function purgePost( $postId ) {

		// If this is a valid post we want to purge the post,
		// the home page and any associated tags and categories

		$validPostStatus = array("publish", "trash");
		$thisPostStatus  = get_post_status($postId);

		// array to collect all our URLs
		$listofurls = array();

		if( get_permalink($postId) == true && in_array($thisPostStatus, $validPostStatus) ) {
			// If this is a post with a permalink AND it's published or trashed,
			// we're going to add a ton of things to flush.

			// Category purge based on Donnacha's work in WP Super Cache
			$categories = get_the_category($postId);
			if ( $categories ) {
				foreach ($categories as $cat) {
					array_push($listofurls, get_category_link( $cat->term_id ) );
				}
			}
			// Tag purge based on Donnacha's work in WP Super Cache
			$tags = get_the_tags($postId);
			if ( $tags ) {
				foreach ($tags as $tag) {
					array_push($listofurls, get_tag_link( $tag->term_id ) );
				}
			}

			// Author URL
			array_push($listofurls,
				get_author_posts_url( get_post_field( 'post_author', $postId ) ),
				get_author_feed_link( get_post_field( 'post_author', $postId ) )
			);

			// Archives and their feeds
			$archiveurls = array();
			if ( get_post_type_archive_link( get_post_type( $postId ) ) == true ) {
				array_push($listofurls,
					get_post_type_archive_link( get_post_type( $postId ) ),
					get_post_type_archive_feed_link( get_post_type( $postId ) )
				);
			}

			// Post URL
			array_push($listofurls, get_permalink($postId) );

			// Also clean URL for trashed post.
			if ( $thisPostStatus == "trash" ) {
				$trashpost = get_permalink($postId);
				$trashpost = str_replace("__trashed", "", $trashpost);
				array_push($listofurls, $trashpost, $trashpost.'feed/' );
			}

			// Feeds
			array_push($listofurls,
				get_bloginfo_rss('rdf_url') ,
				get_bloginfo_rss('rss_url') ,
				get_bloginfo_rss('rss2_url'),
				get_bloginfo_rss('atom_url'),
				get_bloginfo_rss('comments_rss2_url'),
				get_post_comments_feed_link($postId)
			);

			// Home Page and (if used) posts page
			array_push($listofurls, home_url('/') );
			if ( get_option('show_on_front') == 'page' ) {
				array_push($listofurls, get_permalink( get_option('page_for_posts') ) );
			}
		} else {
			// We're not sure how we got here, but bail instead of processing anything else.
			return;
		}

		// Now flush all the URLs we've collected provided the array isn't empty
		if ( !empty($listofurls) ) {
			foreach ($listofurls as $url) {
				array_push($this->purgeUrls, $url ) ;
			}
		}

		// Filter to add or remove urls to the array of purged urls
		// @param array $purgeUrls the urls (paths) to be purged
		// @param int $postId the id of the new/edited post
		$this->purgeUrls = apply_filters( 'vhp_purge_urls', $this->purgeUrls, $postId );
	}

}

$purger = new VarnishPurger();

// WP-CLI
if ( defined('WP_CLI') && WP_CLI ) {
	include( 'wp-cli.php' );
}
