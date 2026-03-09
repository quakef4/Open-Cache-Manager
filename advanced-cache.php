<?php
/**
 * Open Cache Manager - Advanced Cache Drop-in
 *
 * Questo file viene caricato da WordPress PRIMA di qualsiasi altro codice
 * quando WP_CACHE è definito come true in wp-config.php.
 *
 * Va copiato in: wp-content/advanced-cache.php
 *
 * Diagnostica: ogni richiesta riceve uno di questi header HTTP:
 *   X-OCM-Loaded: 1              → drop-in caricato correttamente
 *   X-OCM-Skip: {motivo}         → drop-in attivo ma ha saltato questa richiesta
 *   X-OCM-Cache: HIT             → pagina servita dalla cache (fresca)
 *   X-OCM-Cache: STALE           → pagina servita dalla cache (stale, in rigenerazione)
 *   X-OCM-Cache: MISS            → pagina non in cache, verrà salvata
 *   X-OCM-Cache: REGEN           → pagina in rigenerazione (questo processo la rigenera)
 *
 * @package Open_Cache_Manager
 * @version 2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

// Il drop-in è caricato: invia subito l'header diagnostico.
// Visibile in DevTools → Network → Headers su qualsiasi richiesta.
header( 'X-OCM-Loaded: 1' );

// ============================================================
// CONTROLLO DI SICUREZZA
// ============================================================

$active_flag = WP_CONTENT_DIR . '/cache/ocm-pages/.active';
if ( ! file_exists( $active_flag ) ) {
    header( 'X-OCM-Skip: no-active-flag' );
    return;
}

// Il file .active contiene il path reale del plugin (indipendente dal nome cartella)
$ocm_plugin_dir = trim( @file_get_contents( $active_flag ) );
if ( ! $ocm_plugin_dir || ! file_exists( $ocm_plugin_dir . 'open-cache-manager.php' ) ) {
    header( 'X-OCM-Skip: plugin-not-found (' . $ocm_plugin_dir . ')' );
    return;
}

// ============================================================
// CONFIGURAZIONE
// ============================================================
define( 'OCM_CACHE_DIR', WP_CONTENT_DIR . '/cache/ocm-pages/' );
define( 'OCM_CACHE_DEBUG', defined( 'OCM_DEBUG' ) && OCM_DEBUG );

// Leggi TTL dal file di configurazione se esiste, altrimenti usa il default
$ocm_ttl_file = OCM_CACHE_DIR . '.ttl';
define( 'OCM_CACHE_TTL', ( file_exists( $ocm_ttl_file ) ? (int) file_get_contents( $ocm_ttl_file ) : 3600 ) );

// ============================================================
// CONDIZIONI DI ESCLUSIONE
// ============================================================

// Solo richieste GET
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
    header( 'X-OCM-Skip: not-get' );
    return;
}

// No richieste POST con dati
if ( ! empty( $_POST ) ) {
    header( 'X-OCM-Skip: post-data' );
    return;
}

// No richieste AJAX/PJAX (Woodmart ajax_shop, WooCommerce fragments, ecc.)
// Queste restituiscono HTML parziale, non pagine complete da cachare.
if (
    ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) === 'xmlhttprequest' ) ||
    ! empty( $_SERVER['HTTP_X_PJAX'] )
) {
    header( 'X-OCM-Skip: ajax-pjax' );
    return;
}

// No query string con parametri dinamici WooCommerce / WordPress
$excluded_params = array( 'add-to-cart', 'remove_item', 'added-to-cart', 'wc-ajax', 'preview', 'doing_wp_cron' );
if ( ! empty( $_GET ) ) {
    foreach ( $excluded_params as $param ) {
        if ( isset( $_GET[ $param ] ) ) {
            header( 'X-OCM-Skip: excluded-param-' . $param );
            return;
        }
    }
}

// Gestione utenti loggati:
// - Admin/editor/shop_manager (hanno cookie wp-settings-time-*) → NO cache
// - Clienti WooCommerce (solo wordpress_logged_in_*) → cache OK sul catalogo
if ( ! empty( $_COOKIE ) ) {
    $is_logged_in  = false;
    $is_admin_user = false;

    foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
        if ( strpos( $cookie_name, 'wordpress_logged_in_' ) === 0 ) {
            $is_logged_in = true;
        }
        if ( strpos( $cookie_name, 'wp-settings-time-' ) === 0 ) {
            $is_admin_user = true;
        }
    }

    if ( $is_logged_in && $is_admin_user ) {
        header( 'X-OCM-Skip: admin-user' );
        return;
    }
}

// No carrello WooCommerce attivo
if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) && $_COOKIE['woocommerce_items_in_cart'] !== '0' ) {
    header( 'X-OCM-Skip: cart-active' );
    return;
}

// URL esclusi (hardcoded)
$request_uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$excluded_paths = array(
    '/wp-admin',
    '/wp-login',
    '/wp-cron',
    '/wp-json',
    '/xmlrpc.php',
    '/cart',
    '/carrello',
    '/checkout',
    '/cassa',
    '/my-account',
    '/mio-account',
    '/account',
    '/wishlist',
    '/lista-desideri',
    '/compare',
    '/feed',
    '/sitemap',
    '/wp-comments',
    '/wc-api',
    '/oembed',
);

// URL esclusi custom da file (scritto dal plugin principale)
$custom_excluded_file = OCM_CACHE_DIR . '.excluded_urls';
if ( file_exists( $custom_excluded_file ) ) {
    $custom_excluded = file( $custom_excluded_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( is_array( $custom_excluded ) ) {
        $excluded_paths = array_merge( $excluded_paths, $custom_excluded );
    }
}

$uri_path = strtolower( (string) parse_url( $request_uri, PHP_URL_PATH ) );
foreach ( $excluded_paths as $excluded ) {
    if ( $excluded !== '' && strpos( $uri_path, strtolower( $excluded ) ) !== false ) {
        header( 'X-OCM-Skip: excluded-url' );
        return;
    }
}

// ============================================================
// GESTIONE CACHE
// ============================================================

$cache_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$cache_key  = md5( $cache_host . $request_uri );
$cache_sub  = substr( $cache_key, 0, 2 );
$cache_file = OCM_CACHE_DIR . $cache_sub . '/' . $cache_key . '.gz';

// Timestamp di invalidazione globale (scritto da clear_all con soft purge).
$invalidated_at_file = OCM_CACHE_DIR . '.invalidated_at';
$invalidated_at      = file_exists( $invalidated_at_file ) ? (int) @file_get_contents( $invalidated_at_file ) : 0;

// Funzione helper per servire un file .gz cached.
function ocm_serve_cached_file( $file ) {
    header( 'Content-Type: text/html; charset=UTF-8' );

    $accepts_gzip = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
        && strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) !== false;

    if ( $accepts_gzip ) {
        header( 'Content-Encoding: gzip' );
        header( 'Vary: Accept-Encoding' );
        readfile( $file );
    } else {
        $gz = file_get_contents( $file );
        if ( $gz !== false ) {
            echo gzdecode( $gz );
        }
    }
    exit;
}

if ( file_exists( $cache_file ) ) {
    $file_mtime = filemtime( $cache_file );
    $file_age   = time() - $file_mtime;

    // Il file è stato invalidato globalmente (soft purge da clear_all)?
    $is_stale = ( $invalidated_at > 0 && $file_mtime < $invalidated_at );

    if ( ! $is_stale && $file_age < OCM_CACHE_TTL ) {
        // HIT: file fresco e non invalidato → serve direttamente.
        header( 'X-OCM-Cache: HIT' );
        header( 'X-OCM-Cache-Age: ' . $file_age . 's' );
        ocm_serve_cached_file( $cache_file );
    }

    // Il file è stale (invalidato) o scaduto per TTL.
    // Strategia stale-while-revalidate:
    // - Un solo processo acquisisce il lock e rigenera la pagina (REGEN)
    // - Tutti gli altri servono il contenuto stale (STALE) → zero lag
    $lock_file = $cache_file . '.lock';

    // Prova ad acquisire il lock in modo atomico (fopen 'x' fallisce se il file esiste).
    $lock_acquired = false;
    $lock_fh       = @fopen( $lock_file, 'x' );

    if ( $lock_fh ) {
        fclose( $lock_fh );
        $lock_acquired = true;
    } else {
        // Lock esistente: controlla se è stale (> 30s = il processo precedente è morto).
        if ( file_exists( $lock_file ) && ( time() - @filemtime( $lock_file ) ) > 30 ) {
            @unlink( $lock_file );
            $lock_fh = @fopen( $lock_file, 'x' );
            if ( $lock_fh ) {
                fclose( $lock_fh );
                $lock_acquired = true;
            }
        }
    }

    if ( ! $lock_acquired ) {
        // Un altro processo sta rigenerando → servi il contenuto stale (veloce!).
        header( 'X-OCM-Cache: STALE' );
        header( 'X-OCM-Cache-Age: ' . $file_age . 's' );
        ocm_serve_cached_file( $cache_file );
    }

    // Limite globale di rigenerazione: max 3 processi REGEN alla volta.
    // Dopo un clear_all con 1000+ pagine stale, senza questo limite
    // ogni pagina diversa lancerebbe un boot completo di WordPress,
    // sovraccaricando il server (CPU e RAM).
    $regen_count_file = OCM_CACHE_DIR . '.regen_count';
    $regen_limit      = 3;
    $regen_allowed    = false;

    // Leggi e incrementa atomicamente il contatore con flock.
    $regen_fh = @fopen( $regen_count_file, 'c+' );
    if ( $regen_fh && flock( $regen_fh, LOCK_EX ) ) {
        $current = (int) fread( $regen_fh, 10 );
        if ( $current < $regen_limit ) {
            fseek( $regen_fh, 0 );
            ftruncate( $regen_fh, 0 );
            fwrite( $regen_fh, (string) ( $current + 1 ) );
            $regen_allowed = true;
        }
        flock( $regen_fh, LOCK_UN );
        fclose( $regen_fh );
    }

    if ( ! $regen_allowed ) {
        // Troppi REGEN attivi → rilascia il lock della pagina, servi stale.
        @unlink( $lock_file );
        header( 'X-OCM-Cache: STALE' );
        header( 'X-OCM-Cache-Age: ' . $file_age . 's' );
        ocm_serve_cached_file( $cache_file );
    }

    // Questo processo ha il lock → rigenera la pagina.
    // Il file .gz viene sovrascritto dalla callback di output buffering.
    define( 'OCM_REGEN_ACTIVE', true );
    // Shutdown function di sicurezza: decrementa il contatore anche se PHP crasha.
    register_shutdown_function( 'ocm_cleanup_lock' );
    header( 'X-OCM-Cache: REGEN' );
    // Fall through a ob_start() sotto.

} else {
    // File non esiste → MISS classico.
    header( 'X-OCM-Cache: MISS' );
}

// MISS o REGEN: cattura l'output per salvarlo.

// Passa i dati alla callback tramite costanti (più affidabile di $GLOBALS).
define( 'OCM_CACHE_FILE', $cache_file );
define( 'OCM_CACHE_KEY', $cache_key );
define( 'OCM_CACHE_HOST', $cache_host );
define( 'OCM_CACHE_REQUEST_URI', $request_uri );
define( 'OCM_LOCK_FILE', isset( $lock_file ) ? $lock_file : '' );

/**
 * Callback di output buffering.
 * Salva l'HTML generato come file gzip nella cache.
 *
 * @param string $html L'output HTML completo della pagina.
 * @return string L'HTML inalterato (non modifichiamo l'output per il browser).
 */
function ocm_cache_output_callback( $html ) {

    // Non salvare pagine troppo corte (probabile redirect o errore)
    if ( strlen( $html ) < 500 ) {
        @header( 'X-OCM-Save: skip-short-' . strlen( $html ) );
        ocm_cleanup_lock();
        return $html;
    }

    // Non salvare se non sembra HTML valido
    if ( stripos( $html, '<html' ) === false && stripos( $html, '<!DOCTYPE' ) === false ) {
        @header( 'X-OCM-Save: skip-no-html' );
        ocm_cleanup_lock();
        return $html;
    }

    // Non salvare pagine con messaggi di errore WooCommerce visibili
    if ( strpos( $html, 'woocommerce-error' ) !== false ) {
        @header( 'X-OCM-Save: skip-woo-error' );
        ocm_cleanup_lock();
        return $html;
    }

    $cache_file = OCM_CACHE_FILE;
    $cache_dir  = dirname( $cache_file );

    // Crea la sottodirectory se non esiste
    if ( ! is_dir( $cache_dir ) ) {
        if ( ! @mkdir( $cache_dir, 0755, true ) ) {
            @header( 'X-OCM-Save: skip-mkdir-failed' );
            ocm_cleanup_lock();
            return $html;
        }
    }

    // Aggiungi commento debug se abilitato (define OCM_DEBUG true in wp-config.php)
    if ( OCM_CACHE_DEBUG ) {
        $html = rtrim( $html ) . "\n<!-- OCM cached: " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
    }

    // Comprimi e salva con scrittura atomica (tmp → rename)
    if ( ! function_exists( 'gzencode' ) ) {
        @header( 'X-OCM-Save: skip-no-gzencode' );
        ocm_cleanup_lock();
        return $html;
    }

    $compressed = gzencode( $html, 6 );
    if ( $compressed === false ) {
        @header( 'X-OCM-Save: skip-gzencode-failed' );
        ocm_cleanup_lock();
        return $html;
    }

    $tmp = $cache_file . '.tmp.' . getmypid();
    if ( @file_put_contents( $tmp, $compressed, LOCK_EX ) === false ) {
        @header( 'X-OCM-Save: skip-write-failed path=' . $tmp );
        ocm_cleanup_lock();
        return $html;
    }

    if ( ! @rename( $tmp, $cache_file ) ) {
        @unlink( $tmp );
        @header( 'X-OCM-Save: skip-rename-failed' );
        ocm_cleanup_lock();
        return $html;
    }

    // Aggiorna l'indice URL per consentire l'invalidazione per prefisso path.
    // Formato: hash|host|request_uri (una riga per URL cachato).
    // Append diretto senza dedup nel hot path: i duplicati vengono puliti
    // periodicamente dal cron (cleanup_url_index). Leggere l'intero file
    // qui causerebbe I/O O(N²) dopo un clear_all con 1000+ pagine stale.
    $index_file = OCM_CACHE_DIR . '.url_index';
    $index_line = OCM_CACHE_KEY . '|' . OCM_CACHE_HOST . '|' . OCM_CACHE_REQUEST_URI . "\n";
    @file_put_contents( $index_file, $index_line, FILE_APPEND | LOCK_EX );

    ocm_cleanup_lock();
    @header( 'X-OCM-Save: ok' );
    return $html;
}

/**
 * Rimuove il lock file e decrementa il contatore globale REGEN.
 * Idempotente: può essere chiamata sia dalla callback che dallo shutdown.
 */
function ocm_cleanup_lock() {
    static $already_cleaned = false;
    if ( $already_cleaned ) {
        return;
    }
    $already_cleaned = true;

    if ( defined( 'OCM_LOCK_FILE' ) && OCM_LOCK_FILE !== '' && file_exists( OCM_LOCK_FILE ) ) {
        @unlink( OCM_LOCK_FILE );
    }

    // Decrementa il contatore globale dei REGEN attivi.
    if ( defined( 'OCM_REGEN_ACTIVE' ) && OCM_REGEN_ACTIVE ) {
        $regen_count_file = OCM_CACHE_DIR . '.regen_count';
        $fh = @fopen( $regen_count_file, 'c+' );
        if ( $fh && flock( $fh, LOCK_EX ) ) {
            $current = max( 0, (int) fread( $fh, 10 ) - 1 );
            fseek( $fh, 0 );
            ftruncate( $fh, 0 );
            fwrite( $fh, (string) $current );
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }
}

ob_start( 'ocm_cache_output_callback' );
