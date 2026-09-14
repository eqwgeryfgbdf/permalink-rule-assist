<?php
/**
 * Plugin Name: Permalink Rule Assist
 * Description: Generates ASCII post slugs from category / title rules (for example stock-watch-YYYY-MM-DD). Does not change the site permalink structure.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: Roy
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: permalink-rule-assist
 *
 * @package PermalinkRuleAssist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PERMALINK_RULE_ASSIST_VERSION', '1.0.0' );
define( 'PERMALINK_RULE_ASSIST_FILE', __FILE__ );
define( 'PERMALINK_RULE_ASSIST_DIR', plugin_dir_path( __FILE__ ) );

require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-options.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-generator.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-applier.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-hooks.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-abilities.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-admin.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-metabox.php';
require_once PERMALINK_RULE_ASSIST_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'PermalinkRuleAssist\\Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\PermalinkRuleAssist\Plugin::instance()->boot();
	}
);
