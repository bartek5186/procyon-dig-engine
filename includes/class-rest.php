<?php
namespace Procyon\DigEngine;

if (!defined('ABSPATH')) exit;

class Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('procyon-dig/v1', '/search', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'handle_search'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['type' => 'string', 'required' => true],
                'page' => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 12],
                'include_products' => ['type' => 'boolean', 'default' => true],
                'tax' => ['required' => false],
                'facets' => ['type' => 'boolean', 'default' => false],
                'facet_taxonomies' => ['type' => 'string', 'required' => false],
            ],
        ]);

        register_rest_route('procyon-dig/v1', '/status', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'handle_status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_status(\WP_REST_Request $req) {
        return [
            'version' => PROCYON_DIG_VER,
            'indexed' => Indexer::count_indexed(),
            'table_search' => Indexer::table_search(),
            'table_terms' => Indexer::table_terms(),
            'taxonomies' => Indexer::allowed_taxonomies(),
        ];
    }

    public static function handle_search(\WP_REST_Request $req) {
        $q = (string)$req->get_param('q');
        $page = (int)$req->get_param('page');
        $per_page = (int)$req->get_param('per_page');
        $include_products = (bool)$req->get_param('include_products');

        $tax_filters = Indexer::parse_tax_filters($req->get_param('tax'));

        $scores = Indexer::search_ids($q, $page, $per_page, $tax_filters);
        $ids = array_keys($scores);

        $response = [
            'q' => $q,
            'page' => max(1, $page),
            'per_page' => min(max(1, $per_page), 50),
            'ids' => $ids,
            'tax_filters' => $tax_filters,
        ];

        $facets = (bool)$req->get_param('facets');
        if ($facets) {
            $csv = (string)$req->get_param('facet_taxonomies');
            $facet_taxonomies = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== ''));
            if (!$facet_taxonomies) {
                $facet_taxonomies = Indexer::allowed_taxonomies();
            }
            $response['facets'] = Indexer::facets_for_ids($ids, $facet_taxonomies);
        }

        if (!$include_products) {
            $response['scores'] = $scores;
            return $response;
        }

        $products = [];
        foreach ($ids as $id) {
            $p = function_exists('wc_get_product') ? wc_get_product($id) : null;
            if (!$p) continue;

            $products[] = [
                'id' => (int)$id,
                'name' => $p->get_name(),
                'permalink' => get_permalink($id),
                'price_html' => $p->get_price_html(),
                'image' => wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail'),
                'in_stock' => $p->is_in_stock(),
                'score' => $scores[$id] ?? null,
            ];
        }

        $response['products'] = $products;
        return $response;
    }
}