<?php
/**
 * Open Cache Manager - Uninstall
 *
 * Questo file viene eseguito da WordPress quando il plugin viene ELIMINATO
 * (non solo disattivato) dal pannello admin.
 *
 * Rimuove completamente:
 * - Opzioni dal database
 * - File advanced-cache.php da wp-content
 * - Directory cache con tutti i file
 * - Costante WP_CACHE dal wp-config.php
 * - Transients
 * - Cron events
 * - File backup
 *
 * @package Open_Cache_Manager
 */

// Sicurezza: esci se non chiamato da WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// =============================================================
//  1. RIMUOVI OPZIONI DAL DATABASE
// =============================================================
delete_option( 'open_cache_manager' );

// =============================================================
//  2. RIMUOVI TRANSIENTS
// =============================================================
delete_transient( 'ocm_cache_notice' );
delete_transient( 'ocm_cache_bulk_ids' );

// =============================================================
//  3. RIMUOVI CRON
// =============================================================
wp_clear_scheduled_hook( 'ocm_cache_cleanup' );

// =============================================================
//  4. RIMUOVI ADVANCED-CACHE.PHP
// =============================================================
$advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $advanced_cache ) ) {
    $content = @file_get_contents( $advanced_cache );
    // Rimuovi solo se è il nostro file
    if ( $content !== false && strpos( $content, 'Open Cache Manager' ) !== false ) {
        @unlink( $advanced_cache );
    }
}

// =============================================================
//  5. RIMUOVI DIRECTORY CACHE E TUTTI I FILE
// =============================================================
$cache_dir = WP_CONTENT_DIR . '/cache/ocm-pages/';

if ( is_dir( $cache_dir ) ) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $item ) {
        if ( $item->isFile() ) {
            @unlink( $item->getPathname() );
        } elseif ( $item->isDir() ) {
            @rmdir( $item->getPathname() );
        }
    }

    @rmdir( $cache_dir );

    // Rimuovi la directory parent /cache/ se è vuota
    $parent_cache = WP_CONTENT_DIR . '/cache/';
    if ( is_dir( $parent_cache ) ) {
        $remaining = @scandir( $parent_cache );
        if ( $remaining !== false && count( $remaining ) <= 2 ) {
            @rmdir( $parent_cache );
        }
    }
}

// =============================================================
//  6. RIMUOVI WP_CACHE DAL WP-CONFIG.PHP
// =============================================================
$config_file = ABSPATH . 'wp-config.php';

if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
    $config_content = file_get_contents( $config_file );

    if ( $config_content !== false && strpos( $config_content, 'Open Cache Manager' ) !== false ) {
        // Crea backup
        $backup_file = ABSPATH . 'wp-config.php.ocm-bak';
        copy( $config_file, $backup_file );

        // Rimuovi la riga riga per riga
        $lines     = explode( "\n", $config_content );
        $new_lines = array();
        foreach ( $lines as $line ) {
            if ( strpos( $line, 'Open Cache Manager' ) === false ) {
                $new_lines[] = $line;
            }
        }
        $new_content = implode( "\n", $new_lines );

        // Valida prima di scrivere
        $is_valid = (
            strpos( $new_content, '<?php' ) !== false &&
            strpos( $new_content, 'DB_NAME' ) !== false &&
            strpos( $new_content, 'ABSPATH' ) !== false &&
            strpos( $new_content, 'wp-settings.php' ) !== false &&
            strlen( $new_content ) > 500
        );

        if ( $is_valid ) {
            $result = file_put_contents( $config_file, $new_content, LOCK_EX );
            if ( $result !== false ) {
                $written = file_get_contents( $config_file );
                $still_valid = (
                    strpos( $written, '<?php' ) !== false &&
                    strpos( $written, 'DB_NAME' ) !== false &&
                    strlen( $written ) > 500
                );
                if ( $still_valid ) {
                    @unlink( $backup_file );
                } else {
                    copy( $backup_file, $config_file );
                    @unlink( $backup_file );
                }
            } else {
                copy( $backup_file, $config_file );
                @unlink( $backup_file );
            }
        } else {
            @unlink( $backup_file );
        }
    }
}

// =============================================================
//  7. RIMUOVI FILE BACKUP RESIDUI
// =============================================================
$backup_file = ABSPATH . 'wp-config.php.ocm-bak';
if ( file_exists( $backup_file ) ) {
    @unlink( $backup_file );
}
