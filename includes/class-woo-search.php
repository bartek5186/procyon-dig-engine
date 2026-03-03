<?php
namespace Procyon\DigEngine;

if (!defined('ABSPATH')) exit;

class WooSearch {
    private const DEFAULT_MAX_CANDIDATE_IDS = 2000;

    public static function init(): void {
        add_action('pre_get_posts', [__CLASS__, 'maybe_override_search_query'], 20);
        add_filter('posts_search', [__CLASS__, 'strip_default_search_sql'], 20, 2);
    }

    private static function is_enabled(): bool {
        return (bool) get_option('procyon_dig_replace_wc_search', false);
    }

    private static function max_candidate_ids(): int {
        $max = (int) apply_filters('procyon_dig_woo_max_candidate_ids', self::DEFAULT_MAX_CANDIDATE_IDS);
        return min(max(200, $max), 10000);
    }

    private static function is_supported_orderby(\WP_Query $query): bool {
        $orderby = $query->get('orderby');
        if (is_array($orderby)) return false;

        $value = strtolower(trim((string)$orderby));
        $allowed = ['', 'relevance', 'date', 'title', 'menu_order title', 'modified'];

        return in_array($value, $allowed, true);
    }

    private static function is_product_search(\WP_Query $query): bool {
        if (!$query->is_main_query()) return false;
        if (is_admin()) return false;
        if (!$query->is_search()) return false;

        $post_type = $query->get('post_type');
        if (is_string($post_type)) {
            return $post_type === 'product';
        }

        if (is_array($post_type)) {
            $types = array_values(array_unique(array_map('strval', $post_type)));
            return count($types) === 1 && in_array('product', $types, true);
        }

        return (string)$query->get('wc_query') === 'product_query';
    }

    public static function maybe_override_search_query(\WP_Query $query): void {
        if (!self::is_enabled()) return;
        if (!self::is_product_search($query)) return;
        if (!self::is_supported_orderby($query)) return;

        $raw_search = (string) $query->get('s');
        $search = Indexer::sanitize_query($raw_search);
        if ($search === '') return;
        if (Indexer::to_boolean_query($search) === '' && !Indexer::can_use_like_fallback($search)) {
            // Very short / non-indexable query; keep native Woo behavior.
            return;
        }

        $candidate_ids = Indexer::search_all_ids($search, [], self::max_candidate_ids());
        if ($candidate_ids === null) {
            // Too broad query; keep native Woo search.
            return;
        }

        $search_mode = 'fulltext';
        if (!$candidate_ids) {
            $page = max(1, (int)$query->get('paged'));
            if ($page === 1 && Indexer::can_use_like_fallback($search)) {
                $fallback = Indexer::search_ids_like_fallback($search, [], Indexer::like_fallback_limit());
                $candidate_ids = array_keys($fallback);
                if ($candidate_ids) {
                    $search_mode = 'like_fallback';
                }
            }
        }

        $existing_post_in = $query->get('post__in');
        if (is_array($existing_post_in) && $existing_post_in) {
            $candidate_ids = array_values(array_intersect($candidate_ids, array_values(array_unique(array_map('intval', $existing_post_in)))));
        }

        if (!$candidate_ids) {
            $candidate_ids = [0];
        }

        $query->set('post_type', 'product');
        $query->set('post__in', $candidate_ids);
        $query->set('orderby', 'post__in');
        $query->set('ignore_sticky_posts', true);
        $query->set('procyon_dig_woo_override', 1);
        $query->set('procyon_dig_woo_search_mode', $search_mode);
    }

    public static function strip_default_search_sql(string $search_sql, \WP_Query $query): string {
        if (!$query->get('procyon_dig_woo_override')) return $search_sql;
        return '';
    }
}
