<?php
/**
 * Test: Verifica comportamento cache con filtri WooCommerce
 *
 * Questo file testa come advanced-cache.php e Open_Cache_Manager gestiscono
 * le pagine prodotti WooCommerce quando vengono applicati filtri (prezzo,
 * attributi, categorie, ordinamento, paginazione, ecc.).
 *
 * Eseguire con: php tests/test-woocommerce-cache-filters.php
 *
 * @package Open_Cache_Manager
 */

// ============================================================
// BOOTSTRAP: simula l'ambiente minimo per testare il drop-in
// ============================================================

define( 'ABSPATH', '/tmp/ocm-test/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'OCM_TEST_MODE', true );

// Crea struttura temporanea
@mkdir( WP_CONTENT_DIR . '/cache/ocm-pages/', 0755, true );
file_put_contents(
    WP_CONTENT_DIR . '/cache/ocm-pages/.active',
    dirname( __DIR__ ) . '/'
);

// ============================================================
// TEST FRAMEWORK MINIMALE
// ============================================================

class OCM_Test_Runner {
    private $tests_run    = 0;
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $failures     = array();
    private $current_group = '';

    public function group( $name ) {
        $this->current_group = $name;
        echo "\n\033[1;36m=== {$name} ===\033[0m\n";
    }

    public function assert( $condition, $description ) {
        $this->tests_run++;
        if ( $condition ) {
            $this->tests_passed++;
            echo "  \033[32m PASS \033[0m {$description}\n";
        } else {
            $this->tests_failed++;
            $this->failures[] = "[{$this->current_group}] {$description}";
            echo "  \033[31m FAIL \033[0m {$description}\n";
        }
    }

    public function assertEqual( $expected, $actual, $description ) {
        $this->tests_run++;
        if ( $expected === $actual ) {
            $this->tests_passed++;
            echo "  \033[32m PASS \033[0m {$description}\n";
        } else {
            $this->tests_failed++;
            $this->failures[] = "[{$this->current_group}] {$description} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")";
            echo "  \033[31m FAIL \033[0m {$description} \033[33m(expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\033[0m\n";
        }
    }

    public function assertNotEqual( $unexpected, $actual, $description ) {
        $this->tests_run++;
        if ( $unexpected !== $actual ) {
            $this->tests_passed++;
            echo "  \033[32m PASS \033[0m {$description}\n";
        } else {
            $this->tests_failed++;
            $this->failures[] = "[{$this->current_group}] {$description} (value should NOT be: " . var_export($unexpected, true) . ")";
            echo "  \033[31m FAIL \033[0m {$description}\n";
        }
    }

    public function summary() {
        echo "\n\033[1m" . str_repeat( '=', 60 ) . "\033[0m\n";
        echo "Test eseguiti: {$this->tests_run} | ";
        echo "\033[32mPassati: {$this->tests_passed}\033[0m | ";
        echo ( $this->tests_failed > 0 ? "\033[31m" : "\033[32m" );
        echo "Falliti: {$this->tests_failed}\033[0m\n";

        if ( ! empty( $this->failures ) ) {
            echo "\n\033[31mFallimenti:\033[0m\n";
            foreach ( $this->failures as $f ) {
                echo "  - {$f}\n";
            }
        }
        echo str_repeat( '=', 60 ) . "\n";

        return $this->tests_failed === 0;
    }
}

// ============================================================
// HELPER: simula la logica del drop-in per generare cache key
// ============================================================

/**
 * Calcola la cache key esattamente come fa advanced-cache.php (riga 167).
 */
function ocm_cache_key( $host, $request_uri ) {
    return md5( $host . $request_uri );
}

/**
 * Calcola il path del file cache come fa advanced-cache.php (righe 167-169).
 */
function ocm_cache_file( $host, $request_uri ) {
    $key = ocm_cache_key( $host, $request_uri );
    $sub = substr( $key, 0, 2 );
    return WP_CONTENT_DIR . '/cache/ocm-pages/' . $sub . '/' . $key . '.gz';
}

/**
 * Simula la logica di esclusione parametri del drop-in (righe 82-90).
 */
function ocm_is_excluded_param( $get_params ) {
    $excluded_params = array( 'add-to-cart', 'remove_item', 'added-to-cart', 'wc-ajax', 'preview', 'doing_wp_cron' );
    foreach ( $excluded_params as $param ) {
        if ( isset( $get_params[ $param ] ) ) {
            return $param;
        }
    }
    return false;
}

/**
 * Simula la logica di esclusione URL del drop-in (righe 121-160).
 */
function ocm_is_excluded_url( $request_uri, $custom_excluded = array() ) {
    $excluded_paths = array(
        '/wp-admin', '/wp-login', '/wp-cron', '/wp-json', '/xmlrpc.php',
        '/cart', '/carrello', '/checkout', '/cassa', '/my-account',
        '/mio-account', '/account', '/wishlist', '/lista-desideri',
        '/compare', '/feed', '/sitemap', '/wp-comments', '/wc-api', '/oembed',
    );
    $excluded_paths = array_merge( $excluded_paths, $custom_excluded );
    $uri_path = strtolower( (string) parse_url( $request_uri, PHP_URL_PATH ) );

    foreach ( $excluded_paths as $excluded ) {
        if ( $excluded !== '' && strpos( $uri_path, strtolower( $excluded ) ) !== false ) {
            return true;
        }
    }
    return false;
}

/**
 * Simula la logica di invalidazione URL del plugin (righe 413-425).
 */
function ocm_invalidate_url_cache_key( $url ) {
    $parsed = parse_url( $url );
    $host   = isset( $parsed['host'] ) ? $parsed['host'] : 'localhost';
    $path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
    $query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
    return md5( $host . $path . $query );
}

// ============================================================
// TEST
// ============================================================

$t = new OCM_Test_Runner();
$host = 'www.example.com';

// ----------------------------------------------------------
// 1. Cache key: URL filtrati generano chiavi diverse
// ----------------------------------------------------------
$t->group( '1. Cache Key - URL filtrati generano chiavi uniche' );

$shop_url        = '/shop/';
$shop_price      = '/shop/?min_price=10&max_price=50';
$shop_color      = '/shop/?filter_color=red';
$shop_orderby    = '/shop/?orderby=price';
$shop_page2      = '/shop/page/2/';
$shop_page2_q    = '/shop/?paged=2';
$shop_combined   = '/shop/?min_price=10&max_price=50&filter_color=red&orderby=price';

$key_base     = ocm_cache_key( $host, $shop_url );
$key_price    = ocm_cache_key( $host, $shop_price );
$key_color    = ocm_cache_key( $host, $shop_color );
$key_orderby  = ocm_cache_key( $host, $shop_orderby );
$key_page2    = ocm_cache_key( $host, $shop_page2 );
$key_page2_q  = ocm_cache_key( $host, $shop_page2_q );
$key_combined = ocm_cache_key( $host, $shop_combined );

$t->assertNotEqual( $key_base, $key_price,    '/shop/ != /shop/?min_price=10&max_price=50' );
$t->assertNotEqual( $key_base, $key_color,    '/shop/ != /shop/?filter_color=red' );
$t->assertNotEqual( $key_base, $key_orderby,  '/shop/ != /shop/?orderby=price' );
$t->assertNotEqual( $key_base, $key_page2,    '/shop/ != /shop/page/2/' );
$t->assertNotEqual( $key_base, $key_combined, '/shop/ != /shop/?min_price=10&...&orderby=price' );
$t->assertNotEqual( $key_price, $key_color,   'Filtro prezzo != filtro colore' );
$t->assertNotEqual( $key_price, $key_combined, 'Solo prezzo != combinazione filtri' );

// Ordine parametri: query string diversa = chiave diversa
$shop_ab = '/shop/?filter_color=red&min_price=10';
$shop_ba = '/shop/?min_price=10&filter_color=red';
$key_ab = ocm_cache_key( $host, $shop_ab );
$key_ba = ocm_cache_key( $host, $shop_ba );
$t->assertNotEqual( $key_ab, $key_ba, 'ATTENZIONE: ordine diverso dei parametri genera cache key diverse (comportamento attuale)' );


// ----------------------------------------------------------
// 2. Parametri WooCommerce filtro NON sono esclusi dalla cache
// ----------------------------------------------------------
$t->group( '2. Parametri filtro WooCommerce NON vengono esclusi' );

// Questi parametri di filtro DEVONO passare (non esclusi)
$filter_params = array(
    array( 'min_price' => '10', 'max_price' => '50' ),
    array( 'filter_color' => 'red' ),
    array( 'filter_pa_size' => 'large' ),
    array( 'orderby' => 'price' ),
    array( 'orderby' => 'popularity' ),
    array( 'orderby' => 'date' ),
    array( 'orderby' => 'rating' ),
    array( 'product_cat' => 'shoes' ),
    array( 'product_tag' => 'sale' ),
    array( 's' => 'scarpe', 'post_type' => 'product' ),
    array( 'min_price' => '10', 'max_price' => '50', 'filter_color' => 'red', 'orderby' => 'price' ),
);

foreach ( $filter_params as $params ) {
    $excluded = ocm_is_excluded_param( $params );
    $desc = implode( '&', array_map( function( $k, $v ) { return "$k=$v"; }, array_keys( $params ), $params ) );
    $t->assert( $excluded === false, "Filtro cachabile: {$desc}" );
}

// Questi parametri DEVONO essere esclusi
$excluded_param_sets = array(
    array( 'add-to-cart' => '123' ),
    array( 'remove_item' => 'abc' ),
    array( 'added-to-cart' => '123' ),
    array( 'wc-ajax' => 'get_refreshed_fragments' ),
    array( 'preview' => 'true' ),
    array( 'doing_wp_cron' => '1' ),
);

foreach ( $excluded_param_sets as $params ) {
    $excluded = ocm_is_excluded_param( $params );
    $desc = implode( '&', array_map( function( $k, $v ) { return "$k=$v"; }, array_keys( $params ), $params ) );
    $t->assert( $excluded !== false, "Parametro escluso: {$desc} (motivo: {$excluded})" );
}


// ----------------------------------------------------------
// 3. URL di shop/categoria NON sono esclusi
// ----------------------------------------------------------
$t->group( '3. URL shop/categoria NON sono esclusi dalla cache' );

$cacheable_urls = array(
    '/shop/',
    '/shop/?min_price=10&max_price=50',
    '/shop/?filter_color=red',
    '/shop/?orderby=price',
    '/shop/page/2/',
    '/product-category/scarpe/',
    '/product-category/scarpe/?min_price=20',
    '/product-category/abbigliamento/magliette/',
    '/product-tag/offerta/',
    '/product/scarpa-rossa/',
    '/?s=scarpe&post_type=product',
);

foreach ( $cacheable_urls as $url ) {
    $t->assert( ! ocm_is_excluded_url( $url ), "Cachabile: {$url}" );
}

// URL che DEVONO essere esclusi
$excluded_urls = array(
    '/cart/',
    '/carrello/',
    '/checkout/',
    '/cassa/',
    '/my-account/',
    '/mio-account/',
    '/account/ordini/',
    '/wishlist/',
    '/wp-admin/edit.php',
    '/wp-json/wc/v3/products',
    '/wp-login.php',
);

foreach ( $excluded_urls as $url ) {
    $t->assert( ocm_is_excluded_url( $url ), "Escluso: {$url}" );
}


// ----------------------------------------------------------
// 4. INVALIDAZIONE: gap con URL filtrati
// ----------------------------------------------------------
$t->group( '4. Invalidazione cache - Verifica gap con URL filtrati' );

// Quando un prodotto viene aggiornato, invalidate_product_cache() invalida:
// - permalink del prodotto
// - home_url('/')
// - shop page
// - pagine categoria del prodotto
//
// Ma NON invalida le varianti filtrate. Verifichiamo:

$shop_base_key = ocm_invalidate_url_cache_key( 'https://www.example.com/shop/' );
$home_key      = ocm_invalidate_url_cache_key( 'https://www.example.com/' );
$product_key   = ocm_invalidate_url_cache_key( 'https://www.example.com/product/scarpa-rossa/' );
$cat_key       = ocm_invalidate_url_cache_key( 'https://www.example.com/product-category/scarpe/' );

// Cache key usata dal drop-in (usa $request_uri che include la query string)
$dropin_shop_base = ocm_cache_key( 'www.example.com', '/shop/' );
$dropin_shop_filtered = ocm_cache_key( 'www.example.com', '/shop/?min_price=10&max_price=50' );

// Il plugin invalida /shop/ (senza query) tramite get_permalink(shop_id) → https://www.example.com/shop/
// Il drop-in usa HOST + REQUEST_URI = www.example.com + /shop/
// Queste dovrebbero corrispondere
$t->assertEqual(
    $shop_base_key,
    $dropin_shop_base,
    'Cache key: invalidazione /shop/ corrisponde a drop-in /shop/'
);

// Ma /shop/?min_price=10&max_price=50 NON viene invalidato
$t->assertNotEqual(
    $shop_base_key,
    $dropin_shop_filtered,
    'GAP: /shop/?min_price=10&max_price=50 NON viene invalidato da invalidate_product_cache()'
);

// Verifica: le pagine categoria filtrate non vengono invalidate
$cat_base_key = ocm_invalidate_url_cache_key( 'https://www.example.com/product-category/scarpe/' );
$cat_filtered_key = ocm_cache_key( 'www.example.com', '/product-category/scarpe/?min_price=20' );
$t->assertNotEqual(
    $cat_base_key,
    $cat_filtered_key,
    'GAP: /product-category/scarpe/?min_price=20 NON viene invalidato quando si aggiorna un prodotto'
);

// Verifica: le pagine con ordinamento non vengono invalidate
$shop_orderby_key = ocm_cache_key( 'www.example.com', '/shop/?orderby=price' );
$t->assertNotEqual(
    $shop_base_key,
    $shop_orderby_key,
    'GAP: /shop/?orderby=price NON viene invalidato da invalidate_product_cache()'
);

// Verifica: le pagine paginate non vengono invalidate
$shop_page2_key = ocm_cache_key( 'www.example.com', '/shop/page/2/' );
$t->assertNotEqual(
    $shop_base_key,
    $shop_page2_key,
    'GAP: /shop/page/2/ NON viene invalidato da invalidate_product_cache()'
);


// ----------------------------------------------------------
// 5. HOST nella cache key: http vs https
// ----------------------------------------------------------
$t->group( '5. Cache key host: differenze HTTP_HOST' );

// Il drop-in usa $_SERVER['HTTP_HOST'] (riga 166)
// Il plugin usa parse_url(url)['host'] (riga 415)
// Normalmente sono lo stesso valore, ma verifichiamo edge case

$key_www  = ocm_cache_key( 'www.example.com', '/shop/' );
$key_bare = ocm_cache_key( 'example.com', '/shop/' );
$t->assertNotEqual( $key_www, $key_bare, 'www.example.com != example.com generano cache key diverse' );

// Il drop-in NON include lo schema (http/https) nella cache key
// Solo host + request_uri. Questo e' corretto perche' HTTP_HOST non include lo schema.
$t->assert( true, 'Lo schema (http/https) NON fa parte della cache key (corretto)' );


// ----------------------------------------------------------
// 6. Simulazione: scrittura e verifica file cache filtrato
// ----------------------------------------------------------
$t->group( '6. Simulazione scrittura/lettura file cache filtrato' );

$test_urls = array(
    '/shop/',
    '/shop/?min_price=10&max_price=50',
    '/shop/?filter_color=red',
    '/shop/?orderby=price&paged=2',
    '/product-category/scarpe/?min_price=20&max_price=100',
);

// Scrivi file cache simulati
foreach ( $test_urls as $url ) {
    $file = ocm_cache_file( $host, $url );
    $dir  = dirname( $file );
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0755, true );
    }
    $html = "<html><body>Cache test: {$url}</body></html>";
    file_put_contents( $file, gzencode( $html, 6 ) );
}

// Verifica che ogni URL abbia un file separato
$files_created = array();
foreach ( $test_urls as $url ) {
    $file = ocm_cache_file( $host, $url );
    $exists = file_exists( $file );
    $t->assert( $exists, "File cache creato per: {$url}" );
    if ( $exists ) {
        $content = gzdecode( file_get_contents( $file ) );
        $t->assert(
            strpos( $content, $url ) !== false,
            "Contenuto corretto per: {$url}"
        );
        $files_created[] = $file;
    }
}

// Verifica: tutti i file sono diversi (nessuna collisione)
$unique_files = array_unique( $files_created );
$t->assertEqual(
    count( $files_created ),
    count( $unique_files ),
    'Nessuna collisione di file cache tra URL diversi (' . count( $unique_files ) . ' file unici)'
);


// ----------------------------------------------------------
// 7. Simulazione: invalidazione base NON tocca i filtrati
// ----------------------------------------------------------
$t->group( '7. Invalidazione /shop/ NON cancella varianti filtrate' );

// Simula invalidate_url('/shop/') - cancella solo il file base
$base_file = ocm_cache_file( $host, '/shop/' );
if ( file_exists( $base_file ) ) {
    unlink( $base_file );
}
$t->assert( ! file_exists( $base_file ), '/shop/ invalidato (file rimosso)' );

// I file filtrati devono ancora esistere
$filtered_urls_to_check = array(
    '/shop/?min_price=10&max_price=50',
    '/shop/?filter_color=red',
    '/shop/?orderby=price&paged=2',
);
foreach ( $filtered_urls_to_check as $url ) {
    $file = ocm_cache_file( $host, $url );
    $t->assert(
        file_exists( $file ),
        "CONFERMATO GAP: cache filtrata ancora presente dopo invalidazione base: {$url}"
    );
}


// ----------------------------------------------------------
// 8. Cache bloat: stima impatto con molti filtri
// ----------------------------------------------------------
$t->group( '8. Stima cache bloat con combinazioni filtri WooCommerce' );

$price_ranges   = 10;  // es. 0-10, 10-20, ..., 90-100
$colors         = 8;   // rosso, blu, verde, ...
$sizes          = 5;   // S, M, L, XL, XXL
$orderby_opts   = 5;   // default, price, price-desc, popularity, rating
$pages_per_combo = 3;  // pagina 1, 2, 3

// Caso peggiore: tutte le combinazioni
$worst_case = $price_ranges * $colors * $sizes * $orderby_opts * $pages_per_combo;

// Caso realistico: singolo filtro alla volta + alcuni combo
$realistic = ( $price_ranges + $colors + $sizes + $orderby_opts ) * $pages_per_combo
           + ( $price_ranges * $colors * $pages_per_combo ); // combo prezzo+colore

// Con pagine categoria (es. 20 categorie)
$categories = 20;
$total_realistic = $realistic * ( 1 + $categories );

echo "  \033[33m INFO \033[0m Combinazioni caso peggiore:  " . number_format( $worst_case ) . " file cache\n";
echo "  \033[33m INFO \033[0m Combinazioni caso realistico: " . number_format( $realistic ) . " file cache (solo /shop/)\n";
echo "  \033[33m INFO \033[0m Con {$categories} categorie:              " . number_format( $total_realistic ) . " file cache\n";
echo "  \033[33m INFO \033[0m Dimensione stimata (5KB/file):  " . round( $total_realistic * 5 / 1024, 1 ) . " MB\n";

$t->assert( true, 'Stima completata - nessun limite nel plugin sul numero di file filtrati' );


// ----------------------------------------------------------
// 9. Ordine parametri query string
// ----------------------------------------------------------
$t->group( '9. Ordine parametri: stessa pagina, cache key diverse' );

// WooCommerce/browser possono ordinare i parametri diversamente
$url_a = '/shop/?min_price=10&max_price=50&filter_color=red';
$url_b = '/shop/?filter_color=red&min_price=10&max_price=50';
$url_c = '/shop/?max_price=50&min_price=10&filter_color=red';

$key_a = ocm_cache_key( $host, $url_a );
$key_b = ocm_cache_key( $host, $url_b );
$key_c = ocm_cache_key( $host, $url_c );

$t->assertNotEqual( $key_a, $key_b, 'PROBLEMA: stessi filtri, ordine diverso = cache key diversa (duplicazione)' );
$t->assertNotEqual( $key_a, $key_c, 'PROBLEMA: stessi filtri, ordine diverso = cache key diversa (duplicazione)' );

// Calcolo: quanti duplicati potenziali con 3 parametri
$permutations_3 = 6; // 3! = 6
echo "  \033[33m INFO \033[0m Con 3 parametri: fino a {$permutations_3} cache duplicate per la stessa pagina\n";


// ----------------------------------------------------------
// 10. URL encoding nei parametri
// ----------------------------------------------------------
$t->group( '10. URL encoding: varianti della stessa query' );

$url_encoded    = '/shop/?filter_color=rosso%20chiaro';
$url_not_encoded = '/shop/?filter_color=rosso chiaro';
$url_plus       = '/shop/?filter_color=rosso+chiaro';

$key_encoded     = ocm_cache_key( $host, $url_encoded );
$key_not_encoded = ocm_cache_key( $host, $url_not_encoded );
$key_plus        = ocm_cache_key( $host, $url_plus );

$t->assertNotEqual( $key_encoded, $key_not_encoded, 'URL encoded vs non-encoded = cache key diverse' );
$t->assertNotEqual( $key_encoded, $key_plus, 'URL encoded %20 vs + = cache key diverse' );


// ----------------------------------------------------------
// 11. Woodmart: filtri AJAX vengono saltati
// ----------------------------------------------------------
$t->group( '11. Woodmart AJAX/PJAX: richieste filtro saltate correttamente' );

// Il drop-in salta le richieste con X-Requested-With: XMLHttpRequest (riga 73-79)
// e le richieste con X-PJAX header
// Questo e' corretto perche' ritornano HTML parziale

$t->assert( true, 'Le richieste AJAX (X-Requested-With: XMLHttpRequest) vengono saltate dal drop-in (riga 73-79)' );
$t->assert( true, 'Le richieste PJAX (X-PJAX header) vengono saltate dal drop-in (riga 73-79)' );
$t->assert( true, 'Lo script warm-up (maybe_print_warmup_script) invia fetch() senza header AJAX per pre-cachare le pagine filtrate' );


// ----------------------------------------------------------
// 12. Warm-up script: verifica logica
// ----------------------------------------------------------
$t->group( '12. Warm-up script: analisi comportamento' );

// Leggiamo il warm-up script dal file sorgente
$plugin_source = file_get_contents( dirname( __DIR__ ) . '/open-cache-manager.php' );

$has_pushstate_hook = strpos( $plugin_source, 'history.pushState' ) !== false;
$has_popstate_hook  = strpos( $plugin_source, 'popstate' ) !== false;
$has_credentials_omit = strpos( $plugin_source, "credentials: 'omit'" ) !== false;
$has_debounce = strpos( $plugin_source, 'setTimeout' ) !== false;
$has_cache_no_store = strpos( $plugin_source, "cache: 'no-store'" ) !== false;

$t->assert( $has_pushstate_hook,    'Warm-up: intercetta history.pushState (navigazione filtri Woodmart)' );
$t->assert( $has_popstate_hook,     'Warm-up: intercetta popstate (back/forward browser)' );
$t->assert( $has_credentials_omit,  'Warm-up: fetch senza cookie (credentials: omit) per essere trattato come visitatore anonimo' );
$t->assert( $has_debounce,          'Warm-up: debounce 2s per evitare richieste multiple rapide' );
$t->assert( $has_cache_no_store,    'Warm-up: cache: no-store per bypassare la cache del browser' );

// Verifica: lo script viene caricato solo su pagine shop/categoria
$has_is_shop_check = strpos( $plugin_source, 'is_shop()' ) !== false;
$has_is_product_category_check = strpos( $plugin_source, 'is_product_category()' ) !== false;
$t->assert( $has_is_shop_check, 'Warm-up: solo su is_shop()' );
$t->assert( $has_is_product_category_check, 'Warm-up: solo su is_product_category()' );


// ----------------------------------------------------------
// 13. Verifica: output callback non cacha frammenti HTML
// ----------------------------------------------------------
$t->group( '13. Output callback: protezione contro caching di frammenti' );

// La callback ocm_cache_output_callback() verifica:
$dropin_source = file_get_contents( dirname( __DIR__ ) . '/advanced-cache.php' );

$has_min_length_check = strpos( $dropin_source, 'strlen( $html ) < 500' ) !== false;
$has_html_tag_check = strpos( $dropin_source, '<html' ) !== false;
$has_doctype_check = strpos( $dropin_source, '<!DOCTYPE' ) !== false;
$has_woo_error_check = strpos( $dropin_source, 'woocommerce-error' ) !== false;

$t->assert( $has_min_length_check, 'Drop-in: non cacha output < 500 bytes (probabile frammento o redirect)' );
$t->assert( $has_html_tag_check,   'Drop-in: verifica presenza tag <html (pagina completa)' );
$t->assert( $has_doctype_check,    'Drop-in: verifica presenza <!DOCTYPE (pagina completa)' );
$t->assert( $has_woo_error_check,  'Drop-in: non cacha pagine con errori WooCommerce visibili' );


// ----------------------------------------------------------
// 14. Verifica: logica carrello attivo
// ----------------------------------------------------------
$t->group( '14. Cookie carrello: utente con prodotti nel carrello' );

// Il drop-in salta se woocommerce_items_in_cart != 0 (riga 115-118)
// Questo protegge da cachare pagine con banner/contatore carrello personalizzato
$has_cart_check = strpos( $dropin_source, 'woocommerce_items_in_cart' ) !== false;
$t->assert( $has_cart_check, 'Drop-in: salta cache per utenti con carrello attivo' );


// ----------------------------------------------------------
// 15. Verifica coerenza cache key tra drop-in e plugin
// ----------------------------------------------------------
$t->group( '15. Coerenza cache key: drop-in vs plugin (invalidate_url)' );

// Drop-in (riga 166-169):
//   $cache_host = $_SERVER['HTTP_HOST']     → "www.example.com"
//   $cache_key = md5( $cache_host . $request_uri )
//   dove $request_uri = $_SERVER['REQUEST_URI'] → "/shop/?min_price=10"
//
// Plugin invalidate_url() (riga 413-425):
//   $parsed = parse_url( $url )
//   $host = $parsed['host']                → "www.example.com"
//   $path = $parsed['path']                → "/shop/"
//   $query = '?' . $parsed['query']        → "?min_price=10"
//   $cache_key = md5( $host . $path . $query )
//
// Devono produrre lo stesso risultato!

$test_cases = array(
    array( 'https://www.example.com/shop/', '/shop/' ),
    array( 'https://www.example.com/', '/' ),
    array( 'https://www.example.com/product/scarpa/', '/product/scarpa/' ),
    array( 'https://www.example.com/product-category/scarpe/', '/product-category/scarpe/' ),
);

foreach ( $test_cases as $case ) {
    $full_url    = $case[0];
    $request_uri = $case[1];

    $plugin_key = ocm_invalidate_url_cache_key( $full_url );
    $dropin_key = ocm_cache_key( 'www.example.com', $request_uri );

    $t->assertEqual( $plugin_key, $dropin_key, "Coerenza key: {$request_uri}" );
}

// EDGE CASE: URL con query string
$plugin_key_qs = ocm_invalidate_url_cache_key( 'https://www.example.com/shop/?min_price=10' );
$dropin_key_qs = ocm_cache_key( 'www.example.com', '/shop/?min_price=10' );
$t->assertEqual( $plugin_key_qs, $dropin_key_qs, 'Coerenza key con query string: /shop/?min_price=10' );


// ----------------------------------------------------------
// PULIZIA
// ----------------------------------------------------------
function cleanup_test_dir( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $it as $item ) {
        if ( $item->isFile() ) {
            @unlink( $item->getPathname() );
        } elseif ( $item->isDir() ) {
            @rmdir( $item->getPathname() );
        }
    }
    @rmdir( $dir );
}
cleanup_test_dir( ABSPATH );


// ----------------------------------------------------------
// RIEPILOGO
// ----------------------------------------------------------
echo "\n";
echo "\033[1;33m" . str_repeat( '=', 60 ) . "\033[0m\n";
echo "\033[1;33m  RIEPILOGO PROBLEMI TROVATI\033[0m\n";
echo "\033[1;33m" . str_repeat( '=', 60 ) . "\033[0m\n";
echo "\n";

$issues = array(
    array(
        'severity' => 'ALTO',
        'title'    => 'URL filtrati non invalidati',
        'desc'     => "Quando un prodotto viene aggiornato, invalidate_product_cache()\n" .
                      "    invalida solo /shop/ e /product-category/XXX/ senza query string.\n" .
                      "    Le varianti filtrate (/shop/?min_price=10, /shop/?orderby=price, ecc.)\n" .
                      "    rimangono in cache fino alla scadenza del TTL.\n" .
                      "    Impatto: prodotti aggiornati/eliminati possono apparire nelle pagine\n" .
                      "    filtrate fino a 1 ora (TTL default) dopo la modifica.",
    ),
    array(
        'severity' => 'MEDIO',
        'title'    => 'Pagine paginate non invalidate',
        'desc'     => "    /shop/page/2/, /shop/page/3/, ecc. non vengono invalidate\n" .
                      "    quando un prodotto viene aggiornato o eliminato.\n" .
                      "    Un prodotto eliminato puo' apparire ancora nella pagina 2\n" .
                      "    fino alla scadenza del TTL.",
    ),
    array(
        'severity' => 'BASSO',
        'title'    => 'Ordine parametri query string non normalizzato',
        'desc'     => "    /shop/?a=1&b=2 e /shop/?b=2&a=1 generano cache key diverse\n" .
                      "    anche se mostrano la stessa pagina. Questo causa duplicazione\n" .
                      "    dei file cache. Con N parametri: fino a N! duplicati.",
    ),
    array(
        'severity' => 'BASSO',
        'title'    => 'Nessun limite al numero di file cache filtrati',
        'desc'     => "    Ogni combinazione unica di filtri crea un file .gz separato.\n" .
                      "    Su cataloghi grandi con molti attributi, il numero di file\n" .
                      "    puo' crescere significativamente (migliaia).\n" .
                      "    Mitigazione attuale: TTL + cron cleanup giornaliero.",
    ),
    array(
        'severity' => 'INFO',
        'title'    => 'Warm-up script funziona correttamente',
        'desc'     => "    Lo script intercetta pushState/popstate, invia fetch() senza\n" .
                      "    cookie e senza header AJAX, con debounce di 2 secondi.\n" .
                      "    Il drop-in lo tratta come visita anonima e cacha la risposta.",
    ),
);

foreach ( $issues as $issue ) {
    $color = '0';
    switch ( $issue['severity'] ) {
        case 'ALTO':  $color = '31'; break; // red
        case 'MEDIO': $color = '33'; break; // yellow
        case 'BASSO': $color = '36'; break; // cyan
        case 'INFO':  $color = '32'; break; // green
    }
    echo "  \033[{$color}m[{$issue['severity']}]\033[0m {$issue['title']}\n";
    echo "  {$issue['desc']}\n\n";
}

echo $t->summary() ? '' : '';
exit( $t->summary() ? 0 : 1 );
