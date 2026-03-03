<?php
namespace Procyon\DigEngine;

if (!defined('ABSPATH')) exit;

class Indexer {

    public static function table_search(): string {
        global $wpdb;
        return $wpdb->prefix . 'procyon_dig_search';
    }

    public static function table_terms(): string {
        global $wpdb;
        return $wpdb->prefix . 'procyon_dig_terms';
    }

    /**
     * Whitelist taxonomii do mapowania + indeksowania nazw termów do searchable.
     *
     * - Domyślnie: product_cat, product_tag, wszystkie pa_* (atrybuty)
     * - Dodatkowo: z opcji `procyon_dig_taxonomies` (tablica stringów)
     * - Dodatkowo: przez filtr `procyon_dig_taxonomies`
     */
    public static function allowed_taxonomies(): array {
        $base = ['product_cat', 'product_tag'];

        $opt = get_option('procyon_dig_taxonomies', []);
        if (!is_array($opt)) $opt = [];

        $product_taxes = get_object_taxonomies('product', 'names');
        foreach ($product_taxes as $t) {
            if (str_starts_with($t, 'pa_')) $base[] = $t;
        }

        $taxes = array_values(array_unique(array_filter(array_merge($base, $opt), 'is_string')));

        /**
         * @param string[] $taxes
         */
        $taxes = apply_filters('procyon_dig_taxonomies', $taxes);

        $valid = [];
        foreach ($taxes as $t) {
            if (!taxonomy_exists($t)) continue;
            $obj = get_taxonomy($t);
            if ($obj && in_array('product', (array)$obj->object_type, true)) {
                $valid[] = $t;
            }
        }

        return array_values(array_unique($valid));
    }

    public static function install_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $t1 = self::table_search();
        $t2 = self::table_terms();

        $sql1 = "CREATE TABLE {$t1} (
            product_id BIGINT UNSIGNED NOT NULL,
            searchable LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (product_id),
            FULLTEXT KEY ft_searchable (searchable)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$t2} (
            product_id BIGINT UNSIGNED NOT NULL,
            taxonomy VARCHAR(64) NOT NULL,
            term_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, taxonomy, term_id),
            KEY idx_tax_term (taxonomy, term_id, product_id),
            KEY idx_product (product_id)
        ) {$charset};";

        dbDelta($sql1);
        dbDelta($sql2);

        update_option('procyon_dig_table_version', PROCYON_DIG_TABLE_VERSION);
    }

    public static function init_hooks(): void {
        add_action('save_post_product', [__CLASS__, 'on_product_save'], 20, 3);
        add_action('before_delete_post', [__CLASS__, 'on_product_delete'], 10, 1);

        add_action('updated_postmeta', [__CLASS__, 'on_postmeta_change'], 20, 4);
        add_action('added_postmeta', [__CLASS__, 'on_postmeta_change'], 20, 4);

        add_action('set_object_terms', [__CLASS__, 'on_set_object_terms'], 20, 6);
    }

    public static function on_product_save(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') return;
        self::reindex_product($post_id);
    }

    public static function on_product_delete(int $post_id): void {
        if (get_post_type($post_id) !== 'product') return;
        self::delete_from_index($post_id);
        self::delete_terms_map($post_id);
    }

    public static function on_postmeta_change($meta_id, $post_id, $meta_key, $_meta_value): void {
        if (get_post_type($post_id) !== 'product') return;
        if ($meta_key !== '_sku') return;
        self::reindex_product((int)$post_id);
    }

    public static function on_set_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
        if (get_post_type($object_id) !== 'product') return;
        if (!in_array($taxonomy, self::allowed_taxonomies(), true)) return;

        self::reindex_terms_map((int)$object_id, [$taxonomy]);
        self::reindex_product((int)$object_id);
    }

    public static function reindex_product(int $product_id): bool {
        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product') return false;

        if (!in_array($post->post_status, ['publish'], true)) {
            self::delete_from_index($product_id);
            self::delete_terms_map($product_id);
            return true;
        }

        self::reindex_terms_map($product_id);

        $searchable = self::build_searchable_text($product_id);

        global $wpdb;
        $table = self::table_search();
        $now = current_time('mysql');

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (product_id, searchable, updated_at)
             VALUES (%d, %s, %s)
             ON DUPLICATE KEY UPDATE searchable = VALUES(searchable), updated_at = VALUES(updated_at)",
            $product_id,
            $searchable,
            $now
        );

        return (bool) $wpdb->query($sql);
    }

    public static function delete_from_index(int $product_id): void {
        global $wpdb;
        $wpdb->delete(self::table_search(), ['product_id' => $product_id], ['%d']);
    }

    public static function delete_terms_map(int $product_id): void {
        global $wpdb;
        $wpdb->delete(self::table_terms(), ['product_id' => $product_id], ['%d']);
    }

    /**
     * Rebuild mapy termów dla produktu.
     * Jeśli $only_taxonomies podane -> przebuduj tylko te taxonomy, resztę zostaw.
     */
    public static function reindex_terms_map(int $product_id, ?array $only_taxonomies = null): void {
        global $wpdb;

        $allowed = self::allowed_taxonomies();
        $taxes = $allowed;

        if (is_array($only_taxonomies)) {
            $taxes = array_values(array_intersect($allowed, $only_taxonomies));
        }

        if (!$taxes) return;

        $table = self::table_terms();

        $placeholders = implode(',', array_fill(0, count($taxes), '%s'));
        $params = array_merge([$product_id], $taxes);

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE product_id = %d AND taxonomy IN ({$placeholders})",
            ...$params
        ));

        foreach ($taxes as $tax) {
            $terms = get_the_terms($product_id, $tax);
            if (empty($terms) || is_wp_error($terms)) continue;

            foreach ($terms as $t) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (product_id, taxonomy, term_id) VALUES (%d, %s, %d)",
                    $product_id, $tax, (int)$t->term_id
                ));
            }
        }
    }

    public static function build_searchable_text(int $product_id): string {
        $post = get_post($product_id);
        $parts = [];

        $parts[] = $post->post_title ?? '';
        $parts[] = $post->post_excerpt ?? '';
        $parts[] = wp_strip_all_tags($post->post_content ?? '');

        $sku = get_post_meta($product_id, '_sku', true);
        if (is_string($sku) && $sku !== '') $parts[] = $sku;

        foreach (self::allowed_taxonomies() as $tax) {
            $parts[] = self::terms_text($product_id, $tax);
        }

        $text = implode(' ', array_filter($parts));
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private static function terms_text(int $product_id, string $taxonomy): string {
        $terms = get_the_terms($product_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) return '';
        $names = array_map(fn($t) => $t->name, $terms);
        return implode(' ', $names);
    }

    public static function sanitize_query(string $q): string {
        $q = wp_strip_all_tags($q);
        $q = trim($q);
        $q = preg_replace('/\s+/u', ' ', $q);
        $q = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', ' ', $q);
        $q = preg_replace('/\s+/u', ' ', $q);
        return trim($q);
    }

    public static function to_boolean_query(string $q): string {
        $terms = array_filter(explode(' ', $q), fn($t) => $t !== '');
        $terms = array_values(array_filter($terms, fn($t) => mb_strlen($t) >= 2));
        if (!$terms) return $q;
        return implode(' ', array_map(fn($t) => '+' . $t . '*', $terms));
    }

    /**
     * tax[grape_varieties]=riesling,chardonnay
     * values = slug lub term_id
     *
     * returns: [ taxonomy => [term_id...] ]
     */
    public static function parse_tax_filters($tax_param): array {
        if (!is_array($tax_param)) return [];

        $allowed = self::allowed_taxonomies();
        $out = [];

        foreach ($tax_param as $taxonomy => $raw) {
            if (!is_string($taxonomy)) continue;
            if (!in_array($taxonomy, $allowed, true)) continue;

            $vals = is_array($raw) ? $raw : explode(',', (string)$raw);
            $vals = array_values(array_filter(array_map('trim', $vals), fn($v) => $v !== ''));
            if (!$vals) continue;

            $ids = [];
            $slugs = [];

            foreach ($vals as $v) {
                if (ctype_digit($v)) $ids[] = (int)$v;
                else $slugs[] = sanitize_title($v);
            }

            $term_ids = [];

            if ($ids) $term_ids = array_merge($term_ids, $ids);

            if ($slugs) {
                $terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'fields' => 'ids',
                    'slug' => $slugs,
                ]);
                if (!is_wp_error($terms) && is_array($terms)) {
                    $term_ids = array_merge($term_ids, array_map('intval', $terms));
                }
            }

            $term_ids = array_values(array_unique(array_filter($term_ids, fn($x) => $x > 0)));
            if ($term_ids) $out[$taxonomy] = $term_ids;
        }

        return $out;
    }

    /**
     * returns: [product_id => score]
     */
    public static function search_ids(string $q, int $page = 1, int $per_page = 12, array $tax_filters = []): array {
        $q = self::sanitize_query($q);
        if ($q === '') return [];

        $page = max(1, $page);
        $per_page = min(max(1, $per_page), 50);
        $offset = ($page - 1) * $per_page;

        $boolean = self::to_boolean_query($q);

        global $wpdb;
        $t_search = self::table_search();
        $t_terms  = self::table_terms();

        $where = "WHERE MATCH(s.searchable) AGAINST (%s IN BOOLEAN MODE)";
        $params = [$boolean];

        $i = 0;
        foreach ($tax_filters as $taxonomy => $term_ids) {
            if (!$term_ids) continue;

            $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
            $where .= " AND EXISTS (
                SELECT 1 FROM {$t_terms} t{$i}
                WHERE t{$i}.product_id = s.product_id
                  AND t{$i}.taxonomy = %s
                  AND t{$i}.term_id IN ({$placeholders})
            )";

            $params[] = $taxonomy;
            foreach ($term_ids as $tid) $params[] = (int)$tid;
            $i++;
        }

        $sql = $wpdb->prepare(
            "SELECT s.product_id,
                    MATCH(s.searchable) AGAINST (%s IN BOOLEAN MODE) AS score
             FROM {$t_search} s
             {$where}
             ORDER BY score DESC, s.updated_at DESC
             LIMIT %d OFFSET %d",
            ...array_merge([$boolean], $params, [$per_page, $offset])
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['product_id']] = (float)$r['score'];
        }
        return $out;
    }

    public static function facets_for_ids(array $product_ids, array $facet_taxonomies): array {
        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        if (!$product_ids) return [];

        $allowed = self::allowed_taxonomies();
        $facet_taxonomies = array_values(array_unique(array_filter(
            $facet_taxonomies,
            fn($t) => is_string($t) && in_array($t, $allowed, true)
        )));
        if (!$facet_taxonomies) return [];

        $product_ids = array_slice($product_ids, 0, 500);

        global $wpdb;
        $t_terms = self::table_terms();

        $ph_ids = implode(',', array_fill(0, count($product_ids), '%d'));
        $ph_tax = implode(',', array_fill(0, count($facet_taxonomies), '%s'));
        $params = array_merge($product_ids, $facet_taxonomies);

        $sql = $wpdb->prepare(
            "SELECT taxonomy, term_id, COUNT(*) AS cnt
             FROM {$t_terms}
             WHERE product_id IN ({$ph_ids})
               AND taxonomy IN ({$ph_tax})
             GROUP BY taxonomy, term_id
             ORDER BY taxonomy ASC, cnt DESC",
            ...$params
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return [];

        $out = [];
        foreach ($rows as $r) {
            $tax = (string)$r['taxonomy'];
            $term_id = (int)$r['term_id'];
            $cnt = (int)$r['cnt'];

            $term = get_term($term_id, $tax);
            if (!$term || is_wp_error($term)) continue;

            if (!isset($out[$tax])) $out[$tax] = [];
            $out[$tax][] = [
                'term_id' => $term_id,
                'slug' => $term->slug,
                'name' => $term->name,
                'count' => $cnt,
            ];
        }

        return $out;
    }

    public static function count_indexed(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_search());
    }
}