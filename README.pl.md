# Procyon Dig Engine

Wydajna wyszukiwarka produktów WooCommerce oparta o dedykowane tabele indeksujące FULLTEXT i relacje taxonomii.

## Co robi ten plugin

- buduje indeks wyszukiwarki produktów (tytuł, excerpt, treść, SKU, nazwy termów),
- przechowuje mapę relacji produkt -> term (`taxonomy + term_id`) pod filtry i facety,
- udostępnia endpointy REST API do wyszukiwania i statusu,
- udostępnia komendy WP-CLI do statusu i pełnego reindexu,
- automatycznie aktualizuje indeks przy zmianach produktów.

## Wymagania

- WordPress z aktywnym WooCommerce,
- MySQL/MariaDB z obsługą FULLTEXT dla InnoDB,
- WP-CLI do komend `procyon dig`.

## Szybki start

1. Aktywuj plugin.
2. Opcjonalnie dodaj własne taxonomie produktów do whitelisty:

```bash
wp option set procyon_dig_taxonomies '["grape_varieties","regions"]' --format=json
```

3. Zbuduj początkowy indeks:

```bash
wp procyon dig reindex --batch=200 --truncate=1
```

4. Sprawdź status:

```bash
wp procyon dig status
```

## Dozwolone taxonomie

Plugin indeksuje wyłącznie taxonomie produktów:

- domyślnie: `product_cat`, `product_tag`, wszystkie `pa_*`,
- dodatkowo: wartości z opcji `procyon_dig_taxonomies` (tablica),
- dodatkowo: wartości z filtra `procyon_dig_taxonomies`.

Przykład filtra:

```php
add_filter('procyon_dig_taxonomies', function(array $taxes) {
    $taxes[] = 'grape_varieties';
    $taxes[] = 'regions';
    return array_values(array_unique($taxes));
});
```

## REST API

### Endpoint statusu

`GET /wp-json/procyon-dig/v1/status`

Zwraca m.in.:

- `version`,
- `indexed`,
- `table_search`,
- `table_terms`,
- `taxonomies`.

### Endpoint wyszukiwania

`GET /wp-json/procyon-dig/v1/search`

Parametry:

- `q` (wymagane) fraza wyszukiwania,
- `page` (domyślnie `1`),
- `per_page` (domyślnie `12`, max `50`),
- `include_products` (domyślnie `true`),
- `tax` filtry taxonomii, np. `tax[grape_varieties]=riesling,chardonnay`,
- `facets` (domyślnie `false`) czy zwracać facety,
- `facet_taxonomies` lista CSV taxonomii facetów; gdy puste, używane są wszystkie dozwolone.

Przykłady:

```bash
curl "https://twoja-domena.pl/wp-json/procyon-dig/v1/search?q=wino"
curl "https://twoja-domena.pl/wp-json/procyon-dig/v1/search?q=riesling&tax[grape_varieties]=riesling&facets=1"
curl "https://twoja-domena.pl/wp-json/procyon-dig/v1/search?q=whisky&include_products=0"
```

## WP-CLI

```bash
wp procyon dig status
wp procyon dig reindex --batch=200 --truncate=1
```

Opcje `reindex`:

- `--batch` minimum `50`, domyślnie `200`,
- `--truncate=1|0` domyślnie `1`.

## Tabele w bazie

Plugin tworzy na aktywacji:

- `${prefix}procyon_dig_search`
- `${prefix}procyon_dig_terms`

`procyon_dig_search`:

- `PRIMARY KEY (product_id)`
- `FULLTEXT KEY ft_searchable (searchable)`

`procyon_dig_terms`:

- `PRIMARY KEY (product_id, taxonomy, term_id)`
- `KEY idx_tax_term (taxonomy, term_id, product_id)`
- `KEY idx_product (product_id)`

## Automatyczne aktualizacje indeksu

Po pierwszym pełnym reindexie aktualizacje są automatyczne:

- `save_post_product` -> reindex produktu,
- `updated_postmeta` / `added_postmeta` dla `_sku` -> reindex produktu,
- `set_object_terms` dla dozwolonych taxonomii -> reindex mapy termów i produktu,
- `before_delete_post` -> usunięcie produktu z obu tabel.

## Uwagi

- Wyszukiwanie używa trybu BOOLEAN FULLTEXT (`+term*`).
- Jeśli REST zwraca 404, upewnij się, że plugin jest aktywny i odświeżono permalinki.
- Jeśli po migracji/importach wyniki są puste, uruchom pełny reindex.
