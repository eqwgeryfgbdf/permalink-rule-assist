<?php
/**
 * Remove plugin settings. Post slugs and lock meta are left in place.
 *
 * @package PermalinkRuleAssist
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$deleted = delete_option( 'permalink_rule_assist' );
if ( false === $deleted && get_option( 'permalink_rule_assist', null ) !== null ) {
	error_log( 'Permalink Rule Assist: uninstall could not delete settings.' );
}
