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
                'tax' => [
                    'required' => false,
                    'sanitize_callback' => [__CLASS__, 'sanitize_tax_arg'],
                    'validate_callback' => [__CLASS__, 'validate_tax_arg'],
                ],
                'facets' => ['type' => 'boolean', 'default' => false],
                'facet_taxonomies' => [
                    'required' => false,
                    'sanitize_callback' => [__CLASS__, 'sanitize_facet_taxonomies_arg'],
                    'validate_callback' => [__CLASS__, 'validate_facet_taxonomies_arg'],
                ],
            ],
        ]);

        register_rest_route('procyon-dig/v1', '/status', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'handle_status'],
            'permission_callback' => [__CLASS__, 'can_view_status'],
        ]);
    }

    public static function can_view_status(\WP_REST_Request $_req): bool {
        return current_user_can('manage_options');
    }

    public static function handle_status(\WP_REST_Request $req) {
        return [
            'version' => PROCYON_DIG_VER,
            'indexed' => Indexer::count_indexed(),
            'table_search' => Indexer::table_search(),
            'table_terms' => Indexer::table_terms(),
            'index_fields' => Indexer::index_fields(),
            'taxonomies' => Indexer::allowed_taxonomies(),
            'woo_search_replacement' => (bool) get_option('procyon_dig_replace_wc_search', false),
        ];
    }

    public static function validate_tax_arg($value, \WP_REST_Request $req, string $param): bool {
        unset($req, $param);
        return is_null($value) || is_array($value) || is_string($value);
    }

    public static function sanitize_tax_arg($value, \WP_REST_Request $req, string $param): array {
        unset($req, $param);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                return [];
            }
        }

        if (!is_array($value)) return [];

        $out = [];
        foreach ($value as $taxonomy => $terms) {
            $taxonomy = sanitize_key((string)$taxonomy);
            if ($taxonomy === '') continue;

            if (is_array($terms)) {
                $vals = [];
                foreach ($terms as $term) {
                    if (!is_scalar($term)) continue;
                    $v = sanitize_text_field((string)$term);
                    if ($v !== '') $vals[] = $v;
                }
                if ($vals) $out[$taxonomy] = $vals;
                continue;
            }

            if (!is_scalar($terms)) continue;
            $v = sanitize_text_field((string)$terms);
            if ($v !== '') $out[$taxonomy] = $v;
        }

        return $out;
    }

    public static function validate_facet_taxonomies_arg($value, \WP_REST_Request $req, string $param): bool {
        unset($req, $param);
        return is_null($value) || is_string($value) || is_array($value);
    }

    public static function sanitize_facet_taxonomies_arg($value, \WP_REST_Request $req, string $param): array {
        unset($req, $param);
        return self::parse_facet_taxonomies($value);
    }

    private static function parse_facet_taxonomies($value): array {
        if (is_array($value)) {
            $list = $value;
        } else {
            $csv = is_scalar($value) ? (string)$value : '';
            $list = explode(',', $csv);
        }

        $out = [];
        foreach ($list as $v) {
            if (!is_scalar($v)) continue;
            $tax = sanitize_key((string)$v);
            if ($tax === '') continue;
            $out[] = $tax;
        }

        return array_values(array_unique($out));
    }

    public static function handle_search(\WP_REST_Request $req) {
        $q = (string)$req->get_param('q');
        $page = (int)$req->get_param('page');
        $per_page = (int)$req->get_param('per_page');
        $include_products = (bool)$req->get_param('include_products');
        $facets_requested = (bool)$req->get_param('facets');
        $page = max(1, $page);
        $per_page = min(max(1, $per_page), 50);

        $tax_filters = Indexer::parse_tax_filters($req->get_param('tax'));
        $total = Indexer::search_total($q, $tax_filters);

        $scores = Indexer::search_ids($q, $page, $per_page, $tax_filters);
        $used_like_fallback = false;

        if (
            $total === 0
            && !$scores
            && $page === 1
            && Indexer::can_use_like_fallback($q)
        ) {
            $fallback_limit = min($per_page, Indexer::like_fallback_limit());
            $scores = Indexer::search_ids_like_fallback($q, $tax_filters, $fallback_limit);
            if ($scores) {
                $used_like_fallback = true;
                $total = count($scores);
            }
        }

        $ids = array_keys($scores);

        $response = [
            'q' => $q,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total > 0 ? (int)ceil($total / $per_page) : 0,
            'ids' => $ids,
            'tax_filters' => $tax_filters,
            'search_mode' => $used_like_fallback ? 'like_fallback' : 'fulltext',
        ];
        if ($used_like_fallback) {
            $response['fallback_limited'] = true;
            $response['fallback_limit'] = Indexer::like_fallback_limit();
        }

        if ($facets_requested && !$used_like_fallback) {
            $facet_taxonomies = self::parse_facet_taxonomies($req->get_param('facet_taxonomies'));
            if (!$facet_taxonomies) {
                $facet_taxonomies = Indexer::allowed_taxonomies();
            }
            $response['facets'] = Indexer::facets_for_query($q, $tax_filters, $facet_taxonomies);
        } elseif ($facets_requested && $used_like_fallback) {
            $response['facets'] = [];
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
