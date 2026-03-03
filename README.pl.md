<p align="center">
  <img src="./procyon-logotype.png" alt="Procyon Dig Engine" width="360" />
</p>

<p align="center">
  <em>"You see, in this world, there's two kinds of people, my friend: those with loaded guns and those who dig. You dig"</em><br />
  - Clint Eastwood, <em>The Good, The Bad, and The Ugly</em>
</p>

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
- PHP 7.4+,
- MySQL/MariaDB z obsługą FULLTEXT dla InnoDB,
- WP-CLI do komend `procyon dig`.

## Szybki start

1. Aktywuj plugin.
2. Skonfiguruj plugin w **Ustawienia -> Procyon Dig Engine**:

- pola używane do budowy indeksu (title/excerpt/content/SKU/nazwy termów),
- dodatkowe custom taxonomie produktów do indeksowania,
- opcjonalny przełącznik zastąpienia wyszukiwarki WooCommerce.

3. Opcjonalnie ustaw custom taxonomie przez CLI:

```bash
wp option set procyon_dig_taxonomies '["grape_varieties","regions"]' --format=json
```

4. Zbuduj początkowy indeks:

```bash
wp procyon dig reindex --batch=200 --truncate=1
```

5. Sprawdź status:

```bash
wp procyon dig status
```

## Dozwolone taxonomie

Plugin indeksuje wyłącznie taxonomie produktów:

- domyślnie: `product_cat`, `product_tag`, wszystkie `pa_*`,
- dodatkowo: wartości z opcji `procyon_dig_taxonomies` (tablica),
- dodatkowo: wartości z filtra `procyon_dig_taxonomies`.

## Ustawienia w panelu

`Ustawienia -> Procyon Dig Engine`

- wybór pól budujących tekst FULLTEXT,
- wybór dodatkowych custom taxonomii produktów (domyślne są zawsze aktywne),
- włączenie/wyłączenie zastąpienia wyszukiwarki produktów WooCommerce.

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

Wymaga zalogowanego użytkownika z uprawnieniem `manage_options`.

Zwraca m.in.:

- `version`,
- `indexed`,
- `table_search`,
- `table_terms`,
- `index_fields`,
- `taxonomies`,
- `woo_search_replacement`.

### Endpoint wyszukiwania

`GET /wp-json/procyon-dig/v1/search`

Parametry:

- `q` (wymagane) fraza wyszukiwania,
- `page` (domyślnie `1`),
- `per_page` (domyślnie `12`, max `50`),
- `include_products` (domyślnie `true`),
- `tax` filtry taxonomii, np. `tax[grape_varieties]=riesling,chardonnay`,
- `facets` (domyślnie `false`) czy zwracać facety (liczone dla pełnego zbioru dopasowań, nie tylko bieżącej strony),
- `facet_taxonomies` lista CSV taxonomii facetów; gdy puste, używane są wszystkie dozwolone.

Odpowiedź zawiera też:

- `total` całkowitą liczbę dopasowanych produktów,
- `total_pages` liczbę stron dla bieżącego `per_page`,
- `search_mode` (`fulltext` lub `like_fallback`),
- `fallback_limited` i `fallback_limit` gdy zadziała fallback,
- w `facets[*]`: `term_id`, `slug`, `name`, `term_link`, `count`.

Zachowanie fallbacku:

- uruchamia się tylko gdy FULLTEXT zwróci 0 wyników,
- używa dopasowania podciągu (`LIKE`) z małym limitem,
- wymaga co najmniej jednego tokena o długości `>= 4`,
- działa tylko dla pierwszej strony (`page=1`),
- wyłącza facety (w trybie fallback zwraca puste `facets`).

Zachowanie przy zastąpieniu wyszukiwarki WooCommerce:

- działa tylko dla głównego frontowego search query produktów,
- przy nieobsługiwanym sortowaniu (`price`, `rating`, `popularity` itd.) zostaje natywna wyszukiwarka Woo,
- przy zbyt szerokim zapytaniu (limit bezpieczeństwa kandydatów) zostaje natywna wyszukiwarka Woo,
- kolejność wyników opiera się o relevance z Procyon (`post__in`).
- limit bezpieczeństwa można regulować filtrem `procyon_dig_woo_max_candidate_ids` (domyślnie `2000`).

Przykłady:

```bash
curl "https://twoja-domena.pl/wp-json/procyon-dig/v1/search?q=wino"
curl "https://twoja-domena.pl/wp-json/procyon-dig/v1/search?q=riesling&tax[grape_varieties]=riesling&facets=1"
curl "https://twoja-domena.pl/wp-json/procyon-dig/v1/search?q=whisky&include_products=0"
```

### Zaawansowany Przykład Facetów

Zapytanie nastawione tylko na facety:

```bash
curl -G "https://twoja-domena.pl/wp-json/procyon-dig/v1/search" \
  --data-urlencode "q=riesling" \
  --data-urlencode "include_products=0" \
  --data-urlencode "facets=1" \
  --data-urlencode "facet_taxonomies=grape_varieties,regions,pa_color"
```

Przykładowa odpowiedź (skrócona):

```json
{
  "q": "riesling",
  "total": 42,
  "search_mode": "fulltext",
  "facets": {
    "grape_varieties": [
      {
        "term_id": 123,
        "slug": "riesling",
        "name": "Riesling",
        "term_link": "https://twoja-domena.pl/grape_varieties/riesling/",
        "count": 31
      }
    ]
  }
}
```

Renderowanie klikalnych chipów facetów:

```js
const chips = (response.facets?.grape_varieties ?? []).map((f) => ({
  label: `${f.name} (${f.count})`,
  href: f.term_link ?? `/sklep/?s=riesling&tax[grape_varieties]=${encodeURIComponent(f.slug)}`
}));
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

Plugin tworzy na aktywacji (i aktualizuje automatycznie przy zmianie wersji tabel):

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
- `updated_postmeta` / `added_postmeta` / `deleted_postmeta` dla `_sku` -> reindex produktu,
- `set_object_terms` dla dozwolonych taxonomii -> reindex mapy termów i produktu,
- `before_delete_post` -> usunięcie produktu z obu tabel.

## Uwagi

- Wyszukiwanie używa trybu BOOLEAN FULLTEXT (`+term*`).
- Cache wyników zapytań jest włączony przez transients (domyślny TTL: `120s`), do zmiany filtrem `procyon_dig_cache_ttl`.
- Jeśli REST zwraca 404, upewnij się, że plugin jest aktywny i odświeżono permalinki.
- Jeśli po migracji/importach wyniki są puste, uruchom pełny reindex.
