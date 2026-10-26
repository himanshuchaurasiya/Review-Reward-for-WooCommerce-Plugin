<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'revrew_options' );
wp_clear_scheduled_hook( 'revrew_daily' );
