<?php
/**
 * Plugin Name: Procyon Dig Engine
 * Description: Fast WooCommerce product search using dedicated FULLTEXT index + taxonomy mapping for filters/facets + REST API + WP-CLI.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Author: bartek5186
 */

if (!defined('ABSPATH')) exit;

define('PROCYON_DIG_VER', '0.2.0');
define('PROCYON_DIG_PATH', plugin_dir_path(__FILE__));
define('PROCYON_DIG_TABLE_VERSION', '2');

require_once PROCYON_DIG_PATH . 'includes/class-indexer.php';
require_once PROCYON_DIG_PATH . 'includes/class-rest.php';
require_once PROCYON_DIG_PATH . 'includes/class-cli.php';
require_once PROCYON_DIG_PATH . 'includes/class-settings.php';
require_once PROCYON_DIG_PATH . 'includes/class-woo-search.php';

register_activation_hook(__FILE__, function () {
    \Procyon\DigEngine\Indexer::install_tables();
});

add_action('plugins_loaded', function () {
    $table_version = (string) get_option('procyon_dig_table_version', '');
    if ($table_version !== PROCYON_DIG_TABLE_VERSION) {
        \Procyon\DigEngine\Indexer::install_tables();
    }

    \Procyon\DigEngine\Rest::init();
    \Procyon\DigEngine\Indexer::init_hooks();
    \Procyon\DigEngine\Settings::init();
    \Procyon\DigEngine\WooSearch::init();

    if (defined('WP_CLI') && WP_CLI) {
        \Procyon\DigEngine\Cli::register();
    }
});
