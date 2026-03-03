# Procyon Dig Engine

High-performance WooCommerce product search powered by dedicated FULLTEXT and taxonomy index tables.

## What This Plugin Does

- builds a searchable product index (title, excerpt, content, SKU, term names),
- stores product-to-term mappings (`taxonomy + term_id`) for filters/facets,
- exposes REST API endpoints for search and status,
- provides WP-CLI commands for status and full reindex,
- keeps the index updated automatically when products change.

## Requirements

- WordPress with WooCommerce active,
- MySQL/MariaDB with InnoDB FULLTEXT support,
- WP-CLI for `procyon dig` commands.

## Quick Start

1. Activate the plugin.
2. Optionally add custom product taxonomies to the whitelist:

```bash
wp option set procyon_dig_taxonomies '["grape_varieties","regions"]' --format=json
```

3. Build the initial index:

```bash
wp procyon dig reindex --batch=200 --truncate=1
```

4. Check status:

```bash
wp procyon dig status
```

## Allowed Taxonomies

The plugin indexes only product taxonomies:

- default: `product_cat`, `product_tag`, all `pa_*`,
- additional: values from `procyon_dig_taxonomies` option (array),
- additional: values from `procyon_dig_taxonomies` filter.

Filter example:

```php
add_filter('procyon_dig_taxonomies', function(array $taxes) {
    $taxes[] = 'grape_varieties';
    $taxes[] = 'regions';
    return array_values(array_unique($taxes));
});
```

## REST API

### Status Endpoint

`GET /wp-json/procyon-dig/v1/status`

Returns, among others:

- `version`,
- `indexed`,
- `table_search`,
- `table_terms`,
- `taxonomies`.

### Search Endpoint

`GET /wp-json/procyon-dig/v1/search`

Parameters:

- `q` (required) search query,
- `page` (default `1`),
- `per_page` (default `12`, max `50`),
- `include_products` (default `true`),
- `tax` taxonomy filters, e.g. `tax[grape_varieties]=riesling,chardonnay`,
- `facets` (default `false`) include facet response,
- `facet_taxonomies` CSV list of taxonomies for facets; if empty, all allowed taxonomies are used.

Examples:

```bash
curl "https://your-domain.com/wp-json/procyon-dig/v1/search?q=wine"
curl "https://your-domain.com/wp-json/procyon-dig/v1/search?q=riesling&tax[grape_varieties]=riesling&facets=1"
curl "https://your-domain.com/wp-json/procyon-dig/v1/search?q=whisky&include_products=0"
```

## WP-CLI

```bash
wp procyon dig status
wp procyon dig reindex --batch=200 --truncate=1
```

`reindex` options:

- `--batch` minimum `50`, default `200`,
- `--truncate=1|0` default `1`.

## Database Tables

The plugin creates these tables on activation:

- `${prefix}procyon_dig_search`
- `${prefix}procyon_dig_terms`

`procyon_dig_search`:

- `PRIMARY KEY (product_id)`
- `FULLTEXT KEY ft_searchable (searchable)`

`procyon_dig_terms`:

- `PRIMARY KEY (product_id, taxonomy, term_id)`
- `KEY idx_tax_term (taxonomy, term_id, product_id)`
- `KEY idx_product (product_id)`

## Automatic Index Updates

After the initial full reindex, updates are automatic:

- `save_post_product` -> reindex product,
- `updated_postmeta` / `added_postmeta` for `_sku` -> reindex product,
- `set_object_terms` for allowed taxonomies -> reindex term map and product,
- `before_delete_post` -> remove product from both tables.

## Notes

- Search uses BOOLEAN FULLTEXT mode (`+term*`).
- If REST returns 404, make sure plugin is active and permalinks were refreshed.
- If results are empty after migration/import, run full reindex.
