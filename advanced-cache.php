<?php
/**
 * Infobit Page Cache - Advanced Cache Drop-in
 * 
 * Questo file viene caricato da WordPress PRIMA di qualsiasi altro codice
 * quando WP_CACHE è definito come true in wp-config.php.
 * 
 * Va copiato in: wp-content/advanced-cache.php
 * 
 * @package Infobit_Page_Cache
 * @version 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

// ============================================================
// CONTROLLO DI SICUREZZA
// ============================================================
$plugin_check = WP_CONTENT_DIR . '/plugins/infobit-page-cache/infobit-page-cache.php';
if ( ! file_exists( $plugin_check ) ) {
    return;
}

$active_flag = WP_CONTENT_DIR . '/cache/infobit-pages/.active';
if ( ! file_exists( $active_flag ) ) {
    return;
}

// ============================================================
// CONFIGURAZIONE
// ============================================================
define( 'INFOBIT_CACHE_DIR', WP_CONTENT_DIR . '/cache/infobit-pages/' );
define( 'INFOBIT_CACHE_TTL', 3600 );
define( 'INFOBIT_CACHE_DEBUG', false );

// ============================================================
// CONDIZIONI DI ESCLUSIONE
// ============================================================

// Solo richieste GET
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
    return;
}

// No query string con parametri dinamici WooCommerce
$excluded_params = array( 'add-to-cart', 'remove_item', 'added-to-cart', 'wc-ajax', 'preview' );
if ( ! empty( $_GET ) ) {
    foreach ( $excluded_params as $param ) {
        if ( isset( $_GET[ $param ] ) ) {
            return;
        }
    }
}

// Gestione utenti loggati:
// - Admin/editor/shop_manager (hanno cookie wp-settings-*) → NO cache
// - Clienti (hanno solo wordpress_logged_in_*) → cache OK su catalogo
if ( ! empty( $_COOKIE ) ) {
    $is_logged_in = false;
    $is_admin_user = false;

    foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
        if ( strpos( $cookie_name, 'wordpress_logged_in_' ) === 0 ) {
            $is_logged_in = true;
        }
        // wp-settings-time-{user_id} è impostato solo per utenti con accesso wp-admin
        if ( strpos( $cookie_name, 'wp-settings-time-' ) === 0 ) {
            $is_admin_user = true;
        }
    }

    // Admin/editor → no cache mai
    if ( $is_logged_in && $is_admin_user ) {
        return;
    }

    // Cliente loggato → cache OK, ma le pagine personali sono già escluse sotto
}

// No carrello WooCommerce attivo
if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) && $_COOKIE['woocommerce_items_in_cart'] !== '0' ) {
    return;
}

// No richieste POST
if ( ! empty( $_POST ) ) {
    return;
}

// URL esclusi
$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
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

// Leggi esclusioni custom da file
$custom_excluded_file = WP_CONTENT_DIR . '/cache/infobit-pages/.excluded_urls';
if ( file_exists( $custom_excluded_file ) ) {
    $custom_excluded = file( $custom_excluded_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( is_array( $custom_excluded ) ) {
        $excluded_paths = array_merge( $excluded_paths, $custom_excluded );
    }
}

$uri_path = strtolower( parse_url( $request_uri, PHP_URL_PATH ) );
foreach ( $excluded_paths as $excluded ) {
    if ( strpos( $uri_path, $excluded ) !== false ) {
        return;
    }
}

// ============================================================
// GESTIONE CACHE (SOLO GZIP)
// ============================================================

$cache_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$cache_key  = md5( $cache_host . $request_uri );
$cache_file = INFOBIT_CACHE_DIR . substr( $cache_key, 0, 2 ) . '/' . $cache_key . '.gz';

// Controlla se esiste una versione cached valida
if ( file_exists( $cache_file ) ) {
    $file_age = time() - filemtime( $cache_file );

    if ( $file_age < INFOBIT_CACHE_TTL ) {
        header( 'X-Infobit-Cache: HIT' );
        header( 'X-Infobit-Cache-Age: ' . $file_age );
        header( 'Content-Type: text/html; charset=UTF-8' );

        // Verifica se il client accetta gzip
        $accepts_gzip = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
            && strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) !== false;

        if ( $accepts_gzip ) {
            // Serve il file gzip direttamente (caso normale, tutti i browser moderni)
            header( 'Content-Encoding: gzip' );
            header( 'Vary: Accept-Encoding' );
            readfile( $cache_file );
        } else {
            // Fallback rarissimo: decomprime al volo per client senza gzip
            $gz_content = file_get_contents( $cache_file );
            if ( $gz_content !== false ) {
                echo gzdecode( $gz_content );
            }
        }
        exit;
    }
}

// Cache MISS: cattura l'output e salvalo
header( 'X-Infobit-Cache: MISS' );

/**
 * Callback di output buffering.
 * Salva l'HTML generato nella cache come file gzip.
 *
 * @param string $html L'output HTML della pagina.
 * @return string L'HTML inalterato (al browser va la versione non compressa).
 */
function infobit_cache_output_callback( $html ) {

    // Non cachare pagine troppo corte (errori, redirect)
    if ( strlen( $html ) < 1000 ) {
        return $html;
    }

    // Non cachare risposte non-200
    if ( http_response_code() !== 200 ) {
        return $html;
    }

    // Non cachare pagine con errori WooCommerce
    if ( strpos( $html, 'woocommerce-error' ) !== false ) {
        return $html;
    }

    // Non cachare pagine noindex
    if ( strpos( $html, '<meta name="robots"' ) !== false && strpos( $html, 'noindex' ) !== false ) {
        return $html;
    }

    $cache_file = $GLOBALS['infobit_cache_file'];
    $cache_dir  = dirname( $cache_file );

    if ( ! is_dir( $cache_dir ) ) {
        mkdir( $cache_dir, 0755, true );
    }

    if ( INFOBIT_CACHE_DEBUG ) {
        $html .= "\n<!-- Infobit Page Cache | Cached: " . date( 'Y-m-d H:i:s' ) . " -->";
    }

    // Comprimi e salva solo il gzip
    if ( function_exists( 'gzencode' ) ) {
        $gzip_content = gzencode( $html, 6 );
        if ( $gzip_content !== false ) {
            // Scrittura atomica (tmp + rename)
            $tmp_file = $cache_file . '.tmp.' . getmypid();
            if ( file_put_contents( $tmp_file, $gzip_content, LOCK_EX ) !== false ) {
                rename( $tmp_file, $cache_file );
            } else {
                @unlink( $tmp_file );
            }
        }
    }

    return $html;
}

$GLOBALS['infobit_cache_file'] = $cache_file;
ob_start( 'infobit_cache_output_callback' );
