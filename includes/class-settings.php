<?php
namespace Procyon\DigEngine;

if (!defined('ABSPATH')) exit;

class Settings {
    private const OPTION_GROUP = 'procyon_dig_settings';
    private const PAGE_SLUG = 'procyon-dig-engine';
    private const HIDDEN_TECHNICAL_TAXONOMIES = [
        'product_type',
        'product_visibility',
        'pos_product_visibility',
    ];

    public static function init(): void {
        if (!is_admin()) return;

        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_menu(): void {
        add_options_page(
            'Procyon Dig Engine',
            'Procyon Dig Engine',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting(self::OPTION_GROUP, 'procyon_dig_index_fields', [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_index_fields_option'],
            'default' => Indexer::default_index_fields(),
        ]);

        register_setting(self::OPTION_GROUP, 'procyon_dig_taxonomies', [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_custom_taxonomies_option'],
            'default' => [],
        ]);

        register_setting(self::OPTION_GROUP, 'procyon_dig_replace_wc_search', [
            'type' => 'boolean',
            'sanitize_callback' => [__CLASS__, 'sanitize_boolean_option'],
            'default' => false,
        ]);
    }

    public static function sanitize_index_fields_option($value): array {
        return Indexer::normalize_index_fields($value);
    }

    public static function sanitize_custom_taxonomies_option($value): array {
        if (!is_array($value)) return [];

        $available = self::available_custom_taxonomies();
        $allow_map = array_fill_keys(array_keys($available), true);

        $out = [];
        foreach ($value as $taxonomy) {
            if (!is_scalar($taxonomy)) continue;
            $key = sanitize_key((string)$taxonomy);
            if (!isset($allow_map[$key])) continue;
            $out[] = $key;
        }

        return array_values(array_unique($out));
    }

    public static function sanitize_boolean_option($value): bool {
        return (bool) ((int) $value === 1 || $value === '1' || $value === true);
    }

    private static function available_custom_taxonomies(): array {
        $all = get_object_taxonomies('product', 'objects');
        if (!is_array($all)) return [];

        $hidden = apply_filters('procyon_dig_hidden_custom_taxonomies', self::HIDDEN_TECHNICAL_TAXONOMIES);
        if (!is_array($hidden)) $hidden = self::HIDDEN_TECHNICAL_TAXONOMIES;
        $hidden_map = array_fill_keys(array_map('strval', $hidden), true);

        $out = [];
        foreach ($all as $taxonomy => $obj) {
            if (!is_string($taxonomy)) continue;
            if ($taxonomy === 'product_cat' || $taxonomy === 'product_tag') continue;
            if (strpos($taxonomy, 'pa_') === 0) continue;
            if (isset($hidden_map[$taxonomy])) continue;

            $label = isset($obj->labels->singular_name) && is_string($obj->labels->singular_name)
                ? $obj->labels->singular_name
                : $taxonomy;
            $out[$taxonomy] = $label;
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $field_labels = Indexer::available_index_fields();
        $index_fields = Indexer::index_fields();
        $index_map = array_fill_keys($index_fields, true);

        $custom_taxes = self::available_custom_taxonomies();
        $selected_custom_taxes = self::sanitize_custom_taxonomies_option(get_option('procyon_dig_taxonomies', []));
        $selected_custom_map = array_fill_keys($selected_custom_taxes, true);

        $replace_wc_search = (bool) get_option('procyon_dig_replace_wc_search', false);
        ?>
        <div class="wrap">
            <h1>Procyon Dig Engine</h1>

            <?php if (!empty($_GET['settings-updated'])): ?>
                <div class="notice notice-warning is-dismissible">
                    <p>Settings saved. Run full reindex to apply indexing scope changes: <code>wp procyon dig reindex --truncate=1</code></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <input type="hidden" name="procyon_dig_index_fields[]" value="" />
                <input type="hidden" name="procyon_dig_taxonomies[]" value="" />
                <input type="hidden" name="procyon_dig_replace_wc_search" value="0" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Searchable fields</th>
                        <td>
                            <?php foreach ($field_labels as $field_key => $field_label): ?>
                                <label style="display:block; margin: 0 0 6px;">
                                    <input
                                        type="checkbox"
                                        name="procyon_dig_index_fields[]"
                                        value="<?php echo esc_attr($field_key); ?>"
                                        <?php checked(isset($index_map[$field_key])); ?>
                                    />
                                    <?php echo esc_html($field_label); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">At least one field is required. If none are selected, title is used.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Additional product taxonomies</th>
                        <td>
                            <?php if (!$custom_taxes): ?>
                                <p>No additional custom product taxonomies found.</p>
                            <?php else: ?>
                                <select name="procyon_dig_taxonomies[]" multiple size="8" style="min-width: 360px;">
                                    <?php foreach ($custom_taxes as $taxonomy => $label): ?>
                                        <option value="<?php echo esc_attr($taxonomy); ?>" <?php selected(isset($selected_custom_map[$taxonomy])); ?>>
                                            <?php echo esc_html($label . ' (' . $taxonomy . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <p class="description">Defaults are always included: <code>product_cat</code>, <code>product_tag</code> and all <code>pa_*</code> attributes.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">WooCommerce search replacement</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="procyon_dig_replace_wc_search"
                                    value="1"
                                    <?php checked($replace_wc_search); ?>
                                />
                                Replace default WooCommerce product search with Procyon Dig Engine
                            </label>
                            <p class="description">Uses relevance from Procyon index. Falls back to native Woo search when query is too broad or ordering is unsupported.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
