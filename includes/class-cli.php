<?php
namespace Procyon\DigEngine;

if (!defined('ABSPATH')) exit;

class Cli {

    public static function register(): void {
        \WP_CLI::add_command('procyon dig status', [__CLASS__, 'status']);
        \WP_CLI::add_command('procyon dig reindex', [__CLASS__, 'reindex']);
    }

    public static function status($args, $assoc_args): void {
        \WP_CLI::log('Version: ' . PROCYON_DIG_VER);
        \WP_CLI::log('Search table: ' . Indexer::table_search());
        \WP_CLI::log('Terms table: ' . Indexer::table_terms());
        \WP_CLI::log('Indexed rows: ' . Indexer::count_indexed());
        \WP_CLI::log('Index fields: ' . implode(', ', Indexer::index_fields()));
        \WP_CLI::log('Taxonomies: ' . implode(', ', Indexer::allowed_taxonomies()));
        \WP_CLI::log('Woo search replacement: ' . ((bool)get_option('procyon_dig_replace_wc_search', false) ? 'enabled' : 'disabled'));
    }

    /**
     * Usage:
     *   wp procyon dig reindex --batch=200 --truncate=1
     */
    public static function reindex($args, $assoc_args): void {
        $batch = isset($assoc_args['batch']) ? max(50, (int)$assoc_args['batch']) : 200;
        $truncate = isset($assoc_args['truncate']) ? (int)$assoc_args['truncate'] === 1 : true;

        global $wpdb;

        if ($truncate) {
            \WP_CLI::log('Truncating tables...');
            $wpdb->query('TRUNCATE TABLE ' . Indexer::table_search());
            $wpdb->query('TRUNCATE TABLE ' . Indexer::table_terms());
        }

        $q = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch,
            'paged' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);

        $total = (int)$q->found_posts;
        $pages = (int)ceil($total / $batch);

        \WP_CLI::log("Found products: {$total}, batch: {$batch}, pages: {$pages}");

        $done = 0;

        for ($page = 1; $page <= $pages; $page++) {
            $q = new \WP_Query([
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $batch,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);

            foreach ($q->posts as $id) {
                Indexer::reindex_product((int)$id);
                $done++;
            }

            \WP_CLI::log("Indexed page {$page}/{$pages} (done: {$done})");
        }

        \WP_CLI::success("Reindex complete. Indexed rows: " . Indexer::count_indexed());
    }
}
