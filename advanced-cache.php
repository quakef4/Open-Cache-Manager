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
 *   X-OCM-Cache: HIT             → pagina servita dalla cache
 *   X-OCM-Cache: MISS            → pagina non in cache, verrà salvata
 *
 * @package Open_Cache_Manager
 * @version 2.0.0
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

// Query string: cacha solo parametri esplicitamente sicuri (whitelist).
// Tutto il resto (filtri WooCommerce, ricerca, ordinamento, ecc.) viene escluso
// perché produce contenuto dinamico che varia per combinazione di parametri.
if ( ! empty( $_GET ) ) {
    // Parametri ammessi: paginazione WordPress e lingua WPML/Polylang
    $allowed_params = array( 'paged', 'page', 'lang', 'PHPSESSID' );
    $query_keys     = array_keys( $_GET );
    $unknown        = array_diff( $query_keys, $allowed_params );
    if ( ! empty( $unknown ) ) {
        header( 'X-OCM-Skip: query-string (' . implode( ',', array_map( function( $k ) { return preg_replace( '/[^a-z0-9_\-]/i', '', $k ); }, $unknown ) ) . ')' );
        return;
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

// HIT: serve il file dalla cache se valido
if ( file_exists( $cache_file ) ) {
    $file_age = time() - filemtime( $cache_file );

    if ( $file_age < OCM_CACHE_TTL ) {
        header( 'X-OCM-Cache: HIT' );
        header( 'X-OCM-Cache-Age: ' . $file_age . 's' );
        header( 'Content-Type: text/html; charset=UTF-8' );

        $accepts_gzip = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
            && strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) !== false;

        if ( $accepts_gzip ) {
            header( 'Content-Encoding: gzip' );
            header( 'Vary: Accept-Encoding' );
            readfile( $cache_file );
        } else {
            $gz = file_get_contents( $cache_file );
            if ( $gz !== false ) {
                echo gzdecode( $gz );
            }
        }
        exit;
    }

    // File scaduto: elimina e rigenera
    @unlink( $cache_file );
}

// MISS: cattura l'output per salvarlo
header( 'X-OCM-Cache: MISS' );

// Passa il path del file alla callback tramite costante (più affidabile di $GLOBALS)
define( 'OCM_CACHE_FILE', $cache_file );

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
        return $html;
    }

    // Non salvare se non sembra HTML valido
    if ( stripos( $html, '<html' ) === false && stripos( $html, '<!DOCTYPE' ) === false ) {
        return $html;
    }

    // Non salvare pagine con messaggi di errore WooCommerce visibili
    if ( strpos( $html, 'woocommerce-error' ) !== false ) {
        return $html;
    }

    // Non salvare pagine esplicitamente noindex
    if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $html ) ) {
        return $html;
    }

    $cache_file = OCM_CACHE_FILE;
    $cache_dir  = dirname( $cache_file );

    // Crea la sottodirectory se non esiste
    if ( ! is_dir( $cache_dir ) ) {
        if ( ! @mkdir( $cache_dir, 0755, true ) ) {
            return $html; // Non si può creare la directory, salta
        }
    }

    // Aggiungi commento debug se abilitato (define OCM_DEBUG true in wp-config.php)
    if ( OCM_CACHE_DEBUG ) {
        $html = rtrim( $html ) . "\n<!-- OCM cached: " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
    }

    // Comprimi e salva con scrittura atomica (tmp → rename)
    if ( function_exists( 'gzencode' ) ) {
        $compressed = gzencode( $html, 6 );
        if ( $compressed !== false ) {
            $tmp = $cache_file . '.tmp.' . getmypid();
            if ( @file_put_contents( $tmp, $compressed, LOCK_EX ) !== false ) {
                @rename( $tmp, $cache_file );
            } else {
                @unlink( $tmp );
            }
        }
    }

    return $html;
}

ob_start( 'ocm_cache_output_callback' );
