<?php
/**
 * Plugin Name: Open Cache Manager
 * Plugin URI:  https://github.com/quakef4/Open-Cache-Manager
 * Description: Cache manager per WordPress/WooCommerce con page cache gzip, ottimizzazione database e invalidazione intelligente per cataloghi di grandi dimensioni.
 * Version:     2.0.0
 * Author:      quakef4
 * Author URI:  https://github.com/quakef4/Open-Cache-Manager
 * License:     GPL-2.0+
 * Text Domain: open-cache-manager
 *
 * @package Open_Cache_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OCM_VERSION', '2.0.0' );
define( 'OCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCM_PLUGIN_FILE', __FILE__ );

class Open_Cache_Manager {

    /**
     * Directory della cache.
     *
     * @var string
     */
    private $cache_dir;

    /**
     * Slug della pagina opzioni.
     *
     * @var string
     */
    private $options_page = 'open-cache-manager';

    /**
     * TTL di default in secondi.
     *
     * @var int
     */
    private $default_ttl = 3600;

    /**
     * Flag per sospensione temporanea della cache durante aggiornamenti bulk.
     *
     * @var bool
     */
    private $bulk_mode = false;

    /**
     * Costruttore.
     */
    public function __construct() {
        $this->cache_dir = WP_CONTENT_DIR . '/cache/ocm-pages/';

        // -------------------------------------------------------
        // Hook di invalidazione WooCommerce
        // -------------------------------------------------------
        add_action( 'woocommerce_update_product',       array( $this, 'on_product_update' ) );
        add_action( 'woocommerce_new_product',          array( $this, 'on_product_update' ) );
        add_action( 'woocommerce_delete_product',       array( $this, 'on_product_delete' ) );
        add_action( 'woocommerce_trash_product',        array( $this, 'on_product_delete' ) );
        add_action( 'woocommerce_variation_set_stock',   array( $this, 'on_variation_stock_change' ) );
        add_action( 'woocommerce_product_set_stock',     array( $this, 'on_product_stock_change' ) );

        // Hook di invalidazione WordPress generici
        add_action( 'save_post',            array( $this, 'on_save_post' ), 10, 2 );
        add_action( 'delete_post',          array( $this, 'on_delete_post' ) );
        add_action( 'switch_theme',         array( $this, 'clear_all' ) );
        add_action( 'customize_save_after', array( $this, 'clear_all' ) );
        add_action( 'update_option_sidebars_widgets', array( $this, 'clear_all' ) );

        // Menu e pagine
        add_action( 'wp_nav_menu_item_updated', array( $this, 'clear_all' ) );
        add_action( 'wp_update_nav_menu',       array( $this, 'clear_all' ) );

        // -------------------------------------------------------
        // Admin
        // -------------------------------------------------------
        add_action( 'admin_bar_menu',  array( $this, 'admin_bar_button' ), 999 );
        add_action( 'admin_init',      array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_menu',      array( $this, 'add_admin_menu' ) );
        add_action( 'admin_notices',   array( $this, 'admin_notices' ) );

        // -------------------------------------------------------
        // Azione custom per invalidazione bulk
        // -------------------------------------------------------
        add_action( 'ocm_cache_invalidate_product', array( $this, 'invalidate_product_cache' ) );
        add_action( 'ocm_cache_invalidate_all',     array( $this, 'clear_all' ) );
        add_action( 'ocm_cache_bulk_start',         array( $this, 'bulk_start' ) );
        add_action( 'ocm_cache_bulk_end',           array( $this, 'bulk_end' ) );

        // -------------------------------------------------------
        // Attivazione / Disattivazione
        // -------------------------------------------------------
        register_activation_hook( __FILE__,   array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // -------------------------------------------------------
        // Cron per pulizia cache scaduta
        // -------------------------------------------------------
        add_action( 'ocm_cache_cleanup', array( $this, 'cleanup_expired' ) );

        // -------------------------------------------------------
        // Auto-aggiornamento advanced-cache.php
        // -------------------------------------------------------
        add_action( 'admin_init', array( $this, 'maybe_update_dropin' ) );

        // -------------------------------------------------------
        // Cache warm-up per navigazione AJAX/PJAX (Woodmart)
        // -------------------------------------------------------
        add_action( 'wp_footer', array( $this, 'maybe_print_warmup_script' ) );
    }

    // =============================================================
    //  ATTIVAZIONE / DISATTIVAZIONE
    // =============================================================

    /**
     * Attivazione del plugin.
     */
    public function activate() {
        // Crea directory cache
        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0755, true );
        }

        // Crea file flag che indica che il plugin è attivo (contiene il path del plugin)
        file_put_contents( $this->cache_dir . '.active', plugin_dir_path( __FILE__ ) );

        // Copia advanced-cache.php
        $source = plugin_dir_path( __FILE__ ) . 'advanced-cache.php';
        $dest   = WP_CONTENT_DIR . '/advanced-cache.php';

        if ( file_exists( $source ) ) {
            copy( $source, $dest );
        }

        // Abilita WP_CACHE in wp-config.php
        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
            $this->safe_set_wp_cache( true );
        }

        // Schedula pulizia giornaliera
        if ( ! wp_next_scheduled( 'ocm_cache_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'ocm_cache_cleanup' );
        }

        // Salva opzioni di default
        $defaults = array(
            'ttl'            => $this->default_ttl,
            'excluded_urls'  => "/cart\n/carrello\n/checkout\n/cassa\n/my-account\n/mio-account\n/wishlist",
            'preload_urls'   => '',
            'debug'          => 0,
            'bulk_threshold' => 50,
        );
        if ( ! get_option( 'open_cache_manager' ) ) {
            add_option( 'open_cache_manager', $defaults );
        }

        // Sincronizza URL esclusi e TTL con i file letti dal drop-in
        $options = get_option( 'open_cache_manager', $defaults );
        $this->sync_excluded_urls( isset( $options['excluded_urls'] ) ? $options['excluded_urls'] : '' );
        $this->sync_ttl( isset( $options['ttl'] ) ? (int) $options['ttl'] : $this->default_ttl );
    }

    /**
     * Disattivazione del plugin.
     */
    public function deactivate() {
        // STEP 1: rimuovi il file flag
        $active_flag = $this->cache_dir . '.active';
        if ( file_exists( $active_flag ) ) {
            @unlink( $active_flag );
        }

        // STEP 2: rimuovi WP_CACHE dal wp-config.php
        $this->safe_set_wp_cache( false );

        // STEP 3: rimuovi advanced-cache.php
        $advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( file_exists( $advanced_cache ) ) {
            $content = @file_get_contents( $advanced_cache );
            if ( $content !== false && strpos( $content, 'Open Cache Manager' ) !== false ) {
                @unlink( $advanced_cache );
            }
        }

        // STEP 4: rimuovi cron
        wp_clear_scheduled_hook( 'ocm_cache_cleanup' );

        // STEP 5: svuota cache
        $this->clear_all();

        // STEP 6: rimuovi directory cache
        $this->remove_cache_directory();
    }

    /**
     * Rimuove ricorsivamente la directory cache.
     */
    private function remove_cache_directory() {
        if ( ! is_dir( $this->cache_dir ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isFile() ) {
                @unlink( $item->getPathname() );
            } elseif ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            }
        }

        @rmdir( $this->cache_dir );

        $parent_cache = WP_CONTENT_DIR . '/cache/';
        if ( is_dir( $parent_cache ) ) {
            $remaining = @scandir( $parent_cache );
            if ( $remaining !== false && count( $remaining ) <= 2 ) {
                @rmdir( $parent_cache );
            }
        }
    }

    /**
     * Verifica e aggiorna il drop-in advanced-cache.php se necessario.
     */
    public function maybe_update_dropin() {
        $source = plugin_dir_path( __FILE__ ) . 'advanced-cache.php';
        $dest   = WP_CONTENT_DIR . '/advanced-cache.php';

        if ( ! file_exists( $source ) ) {
            return;
        }

        if ( ! file_exists( $dest ) ) {
            @copy( $source, $dest );
            return;
        }

        $dest_content = @file_get_contents( $dest );
        if ( $dest_content === false || strpos( $dest_content, 'Open Cache Manager' ) === false ) {
            return;
        }

        $source_hash = md5_file( $source );
        $dest_hash   = md5_file( $dest );
        if ( $source_hash !== $dest_hash ) {
            @copy( $source, $dest );
        }
    }

    // =============================================================
    //  GESTIONE SICURA WP-CONFIG.PHP
    // =============================================================

    /**
     * Aggiunge o rimuove WP_CACHE dal wp-config.php in modo sicuro.
     *
     * @param bool $enable True per aggiungere, false per rimuovere.
     * @return bool
     */
    private function safe_set_wp_cache( $enable ) {
        $config_file  = ABSPATH . 'wp-config.php';
        $backup_file  = ABSPATH . 'wp-config.php.ocm-bak';
        $marker       = "define( 'WP_CACHE', true ); // Open Cache Manager";

        if ( ! file_exists( $config_file ) ) {
            $this->set_admin_notice( 'Errore: wp-config.php non trovato.' );
            return false;
        }

        if ( ! is_writable( $config_file ) ) {
            $this->set_admin_notice(
                'Open Cache Manager: wp-config.php non è scrivibile. Aggiungi/rimuovi manualmente: <code>' . $marker . '</code>'
            );
            return false;
        }

        $original_content = file_get_contents( $config_file );
        if ( $original_content === false || strlen( $original_content ) < 100 ) {
            $this->set_admin_notice( 'Errore: impossibile leggere wp-config.php.' );
            return false;
        }

        if ( ! copy( $config_file, $backup_file ) ) {
            $this->set_admin_notice( 'Errore: impossibile creare il backup di wp-config.php.' );
            return false;
        }

        if ( $enable ) {
            if ( strpos( $original_content, 'Open Cache Manager' ) !== false ) {
                @unlink( $backup_file );
                return true;
            }
            $new_content = preg_replace(
                '/^(<\?php)\s*$/m',
                "<?php\n" . $marker,
                $original_content,
                1,
                $count
            );
            if ( $count === 0 ) {
                $new_content = str_replace(
                    '<?php',
                    "<?php\n" . $marker,
                    $original_content
                );
                $occurrences = substr_count( $new_content, $marker );
                if ( $occurrences > 1 ) {
                    $new_content = str_replace( "\n" . $marker, '', $original_content );
                    $new_content = str_replace( '<?php', "<?php\n" . $marker, $new_content );
                }
            }
        } else {
            if ( strpos( $original_content, 'Open Cache Manager' ) === false ) {
                @unlink( $backup_file );
                return true;
            }
            $lines = explode( "\n", $original_content );
            $new_lines = array();
            foreach ( $lines as $line ) {
                if ( strpos( $line, 'Open Cache Manager' ) === false ) {
                    $new_lines[] = $line;
                }
            }
            $new_content = implode( "\n", $new_lines );
        }

        if ( ! $this->validate_wp_config_content( $new_content ) ) {
            @unlink( $backup_file );
            $this->set_admin_notice( 'Errore: la modifica di wp-config.php produrrebbe un file non valido. Operazione annullata.' );
            return false;
        }

        $result = file_put_contents( $config_file, $new_content, LOCK_EX );
        if ( $result === false ) {
            copy( $backup_file, $config_file );
            @unlink( $backup_file );
            $this->set_admin_notice( 'Errore: impossibile scrivere wp-config.php. File ripristinato dal backup.' );
            return false;
        }

        $written_content = file_get_contents( $config_file );
        if ( ! $this->validate_wp_config_content( $written_content ) ) {
            copy( $backup_file, $config_file );
            @unlink( $backup_file );
            $this->set_admin_notice( 'Errore: wp-config.php corrotto dopo la scrittura. File ripristinato dal backup.' );
            return false;
        }

        @unlink( $backup_file );
        return true;
    }

    /**
     * Valida il contenuto di wp-config.php.
     *
     * @param string $content Il contenuto da validare.
     * @return bool
     */
    private function validate_wp_config_content( $content ) {
        if ( strpos( $content, '<?php' ) === false ) {
            return false;
        }

        $required_strings = array( 'DB_NAME', 'DB_USER', 'DB_HOST', 'ABSPATH', 'wp-settings.php' );
        foreach ( $required_strings as $required ) {
            if ( strpos( $content, $required ) === false ) {
                return false;
            }
        }

        $php_count = substr_count( substr( $content, 0, 100 ), '<?php' );
        if ( $php_count > 1 ) {
            return false;
        }

        if ( strlen( $content ) < 500 ) {
            return false;
        }

        return true;
    }

    /**
     * Imposta un avviso admin tramite transient.
     *
     * @param string $message Il messaggio da mostrare.
     */
    private function set_admin_notice( $message ) {
        set_transient( 'ocm_cache_notice', $message, 60 );
    }

    // =============================================================
    //  INVALIDAZIONE CACHE
    // =============================================================

    /**
     * Invalida la cache di un singolo URL.
     *
     * @param string $url L'URL da invalidare.
     */
    public function invalidate_url( $url ) {
        $parsed = parse_url( $url );
        $host   = isset( $parsed['host'] ) ? $parsed['host'] : $_SERVER['HTTP_HOST'];
        $path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        $query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

        $cache_key  = md5( $host . $path . $query );
        $cache_file = $this->cache_dir . substr( $cache_key, 0, 2 ) . '/' . $cache_key . '.gz';

        if ( file_exists( $cache_file ) ) {
            @unlink( $cache_file );
        }
    }

    /**
     * Invalida la cache relativa a un prodotto.
     *
     * @param int $product_id ID del prodotto.
     */
    public function invalidate_product_cache( $product_id ) {
        if ( $this->bulk_mode ) {
            $pending = get_transient( 'ocm_cache_bulk_ids' );
            if ( ! is_array( $pending ) ) {
                $pending = array();
            }
            $pending[] = $product_id;
            set_transient( 'ocm_cache_bulk_ids', $pending, HOUR_IN_SECONDS );
            return;
        }

        $permalink = get_permalink( $product_id );
        if ( $permalink ) {
            $this->invalidate_url( $permalink );
        }

        $this->invalidate_url( home_url( '/' ) );

        $shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
        if ( $shop_id > 0 ) {
            $this->invalidate_url( get_permalink( $shop_id ) );
        }

        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $this->invalidate_url( get_term_link( $term ) );
            }
        }
    }

    public function on_product_update( $product_id ) {
        $this->invalidate_product_cache( $product_id );
    }

    public function on_product_delete( $product_id ) {
        if ( get_post_type( $product_id ) === 'product' ) {
            $this->invalidate_product_cache( $product_id );
        }
    }

    public function on_variation_stock_change( $product ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) {
            $this->invalidate_product_cache( $parent_id );
        }
    }

    public function on_product_stock_change( $product ) {
        $this->invalidate_product_cache( $product->get_id() );
    }

    public function on_save_post( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( $post->post_type === 'product' ) {
            return;
        }
        if ( in_array( $post->post_status, array( 'publish', 'trash' ), true ) ) {
            $this->invalidate_url( get_permalink( $post_id ) );
            $this->invalidate_url( home_url( '/' ) );
        }
    }

    public function on_delete_post( $post_id ) {
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $this->invalidate_url( $permalink );
        }
        $this->invalidate_url( home_url( '/' ) );
    }

    // =============================================================
    //  MODALITA' BULK
    // =============================================================

    public function bulk_start() {
        $this->bulk_mode = true;
        set_transient( 'ocm_cache_bulk_ids', array(), HOUR_IN_SECONDS );
    }

    public function bulk_end() {
        $this->bulk_mode = false;
        $pending = get_transient( 'ocm_cache_bulk_ids' );

        if ( ! is_array( $pending ) || empty( $pending ) ) {
            return;
        }

        $options   = get_option( 'open_cache_manager', array() );
        $threshold = isset( $options['bulk_threshold'] ) ? (int) $options['bulk_threshold'] : 50;

        $unique_ids = array_unique( $pending );
        if ( count( $unique_ids ) > $threshold ) {
            $this->clear_all();
        } else {
            foreach ( $unique_ids as $product_id ) {
                $this->invalidate_product_cache( $product_id );
            }
        }

        delete_transient( 'ocm_cache_bulk_ids' );
    }

    // =============================================================
    //  SVUOTA CACHE
    // =============================================================

    /**
     * Svuota tutta la cache.
     *
     * @return int Numero di file eliminati.
     */
    public function clear_all() {
        $count = 0;

        if ( ! is_dir( $this->cache_dir ) ) {
            return $count;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isFile() ) {
                $basename = basename( $item->getPathname() );
                if ( $basename === '.active' || $basename === '.excluded_urls' ) {
                    continue;
                }
                @unlink( $item->getPathname() );
                $count++;
            } elseif ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            }
        }

        return $count;
    }

    /**
     * Pulisce i file di cache scaduti (chiamato dal cron).
     */
    public function cleanup_expired() {
        if ( ! is_dir( $this->cache_dir ) ) {
            return;
        }

        $options = get_option( 'open_cache_manager', array() );
        $ttl     = isset( $options['ttl'] ) ? (int) $options['ttl'] : $this->default_ttl;
        $now     = time();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && ( $now - $file->getMTime() ) > $ttl ) {
                $basename = basename( $file->getPathname() );
                if ( $basename === '.active' || $basename === '.excluded_urls' ) {
                    continue;
                }
                @unlink( $file->getPathname() );
            }
        }
    }

    // =============================================================
    //  STATISTICHE
    // =============================================================

    /**
     * Restituisce statistiche sulla cache.
     *
     * @return array
     */
    public function get_stats() {
        $stats = array(
            'files'  => 0,
            'size'   => 0,
            'oldest' => 0,
            'newest' => 0,
        );

        if ( ! is_dir( $this->cache_dir ) ) {
            return $stats;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && $file->getExtension() === 'gz' ) {
                $stats['files']++;
                $stats['size'] += $file->getSize();
                $mtime = $file->getMTime();

                if ( $stats['oldest'] === 0 || $mtime < $stats['oldest'] ) {
                    $stats['oldest'] = $mtime;
                }
                if ( $mtime > $stats['newest'] ) {
                    $stats['newest'] = $mtime;
                }
            }
        }

        return $stats;
    }

    // =============================================================
    //  ADMIN BAR
    // =============================================================

    public function admin_bar_button( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stats = $this->get_stats();

        $wp_admin_bar->add_node( array(
            'id'    => 'ocm-cache',
            'title' => sprintf( 'OCM Cache (%d)', $stats['files'] ),
            'href'  => '#',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'ocm-cache',
            'id'     => 'ocm-cache-clear',
            'title'  => 'Svuota tutta la cache',
            'href'   => wp_nonce_url( admin_url( 'admin.php?action=ocm_clear_cache' ), 'ocm_clear_cache' ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'ocm-cache',
            'id'     => 'ocm-cache-clear-page',
            'title'  => 'Svuota cache di questa pagina',
            'href'   => wp_nonce_url(
                admin_url( 'admin.php?action=ocm_clear_page_cache&url=' . urlencode( $this->get_current_url() ) ),
                'ocm_clear_page_cache'
            ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'ocm-cache',
            'id'     => 'ocm-cache-stats',
            'title'  => sprintf(
                '%d pagine cached (%s)',
                $stats['files'],
                $this->format_bytes( $stats['size'] )
            ),
            'href'   => admin_url( 'admin.php?page=' . $this->options_page ),
        ) );
    }

    /**
     * Gestisce le azioni admin.
     */
    public function handle_admin_actions() {
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'ocm_clear_cache' ) {
            if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ocm_clear_cache' ) ) {
                wp_die( 'Non autorizzato' );
            }
            $count = $this->clear_all();
            set_transient( 'ocm_cache_notice', sprintf( 'Cache svuotata! %d file eliminati.', $count ), 30 );
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
            exit;
        }

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'ocm_clear_page_cache' ) {
            if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ocm_clear_page_cache' ) ) {
                wp_die( 'Non autorizzato' );
            }
            $url = isset( $_GET['url'] ) ? urldecode( $_GET['url'] ) : '';
            if ( $url ) {
                $this->invalidate_url( $url );
                set_transient( 'ocm_cache_notice', 'Cache della pagina svuotata!', 30 );
            }
            wp_safe_redirect( $url ? $url : ( wp_get_referer() ? wp_get_referer() : admin_url() ) );
            exit;
        }

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'ocm_reinstall_dropin' ) {
            if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ocm_reinstall_dropin' ) ) {
                wp_die( 'Non autorizzato' );
            }
            $this->activate();
            set_transient( 'ocm_cache_notice', 'Reinstallazione completata. Controlla lo stato sistema per verificare il risultato.', 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=' . $this->options_page ) );
            exit;
        }
    }

    /**
     * Mostra le notifiche admin.
     */
    public function admin_notices() {
        $notice = get_transient( 'ocm_cache_notice' );
        if ( $notice ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', wp_kses_post( $notice ) );
            delete_transient( 'ocm_cache_notice' );
        }
    }

    // =============================================================
    //  DIAGNOSTICA SISTEMA
    // =============================================================

    /**
     * Verifica lo stato di salute del sistema di cache.
     *
     * @return array Array di check con nome, stato (ok|warning|error) e messaggio.
     */
    public function get_system_health() {
        $checks = array();

        // 1. WP_CACHE in wp-config.php
        if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
            $checks[] = array(
                'label'  => 'WP_CACHE in wp-config.php',
                'status' => 'ok',
                'note'   => 'Definito come <code>true</code>',
            );
        } else {
            $checks[] = array(
                'label'  => 'WP_CACHE in wp-config.php',
                'status' => 'error',
                'note'   => 'Non definito o <code>false</code>. Il drop-in <strong>non viene caricato</strong>. Clicca "Reinstalla" per aggiungerlo.',
            );
        }

        // 2. advanced-cache.php installato
        $dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( file_exists( $dropin_path ) ) {
            $dropin_content = @file_get_contents( $dropin_path );
            if ( $dropin_content !== false && strpos( $dropin_content, 'Open Cache Manager' ) !== false ) {
                $checks[] = array(
                    'label'  => 'Drop-in advanced-cache.php',
                    'status' => 'ok',
                    'note'   => 'Installato correttamente in <code>wp-content/</code>',
                );
            } else {
                $checks[] = array(
                    'label'  => 'Drop-in advanced-cache.php',
                    'status' => 'warning',
                    'note'   => 'Esiste ma appartiene a un altro plugin. Non verrà sovrascritto.',
                );
            }
        } else {
            $checks[] = array(
                'label'  => 'Drop-in advanced-cache.php',
                'status' => 'error',
                'note'   => 'Non trovato in <code>wp-content/</code>. Clicca "Reinstalla".',
            );
        }

        // 3. File .active
        $active_file = $this->cache_dir . '.active';
        if ( file_exists( $active_file ) ) {
            $active_content = trim( @file_get_contents( $active_file ) );
            if ( $active_content && file_exists( $active_content . 'open-cache-manager.php' ) ) {
                $checks[] = array(
                    'label'  => 'Flag attivazione (.active)',
                    'status' => 'ok',
                    'note'   => 'Presente. Path plugin: <code>' . esc_html( $active_content ) . '</code>',
                );
            } else {
                $checks[] = array(
                    'label'  => 'Flag attivazione (.active)',
                    'status' => 'error',
                    'note'   => 'File presente ma il path del plugin non è valido (<code>' . esc_html( $active_content ) . '</code>). Clicca "Reinstalla".',
                );
            }
        } else {
            $checks[] = array(
                'label'  => 'Flag attivazione (.active)',
                'status' => 'error',
                'note'   => 'Non trovato in <code>' . esc_html( $this->cache_dir ) . '</code>. Clicca "Reinstalla".',
            );
        }

        // 4. Directory cache scrivibile
        if ( is_dir( $this->cache_dir ) && is_writable( $this->cache_dir ) ) {
            $checks[] = array(
                'label'  => 'Directory cache',
                'status' => 'ok',
                'note'   => '<code>' . esc_html( $this->cache_dir ) . '</code> — scrivibile',
            );
        } elseif ( is_dir( $this->cache_dir ) ) {
            $checks[] = array(
                'label'  => 'Directory cache',
                'status' => 'error',
                'note'   => '<code>' . esc_html( $this->cache_dir ) . '</code> — <strong>non scrivibile</strong>. I file .gz non possono essere creati.',
            );
        } else {
            $checks[] = array(
                'label'  => 'Directory cache',
                'status' => 'warning',
                'note'   => '<code>' . esc_html( $this->cache_dir ) . '</code> — non esiste ancora. Verrà creata al primo salvataggio.',
            );
        }

        // 5. wp-content scrivibile (per advanced-cache.php)
        if ( is_writable( WP_CONTENT_DIR ) ) {
            $checks[] = array(
                'label'  => 'wp-content/ scrivibile',
                'status' => 'ok',
                'note'   => 'Il drop-in può essere installato/aggiornato automaticamente',
            );
        } else {
            $checks[] = array(
                'label'  => 'wp-content/ scrivibile',
                'status' => 'warning',
                'note'   => '<strong>Non scrivibile.</strong> Il drop-in deve essere copiato manualmente da <code>' . esc_html( OCM_PLUGIN_DIR ) . 'advanced-cache.php</code> a <code>' . esc_html( WP_CONTENT_DIR ) . '/advanced-cache.php</code>',
            );
        }

        // 6. wp-config.php scrivibile
        $config_file = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $config_file ) ) {
            $config_file = dirname( ABSPATH ) . '/wp-config.php';
        }
        if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
            $checks[] = array(
                'label'  => 'wp-config.php scrivibile',
                'status' => 'ok',
                'note'   => 'WP_CACHE può essere aggiunto/rimosso automaticamente',
            );
        } else {
            $checks[] = array(
                'label'  => 'wp-config.php scrivibile',
                'status' => 'warning',
                'note'   => '<strong>Non scrivibile.</strong> Aggiungi manualmente in cima a wp-config.php: <code>define( \'WP_CACHE\', true ); // Open Cache Manager</code>',
            );
        }

        // 7. Cron schedulato
        $next_cron = wp_next_scheduled( 'ocm_cache_cleanup' );
        if ( $next_cron ) {
            $checks[] = array(
                'label'  => 'Cron pulizia automatica',
                'status' => 'ok',
                'note'   => 'Schedulato — prossima esecuzione: ' . esc_html( date( 'd/m/Y H:i:s', $next_cron ) ),
            );
        } else {
            $checks[] = array(
                'label'  => 'Cron pulizia automatica',
                'status' => 'warning',
                'note'   => 'Non schedulato. Clicca "Reinstalla" per ripristinarlo.',
            );
        }

        return $checks;
    }

    // =============================================================
    //  PAGINA IMPOSTAZIONI & MENU ADMIN
    // =============================================================

    /**
     * Aggiunge il menu admin con pagine.
     */
    public function add_admin_menu() {
        // Menu principale
        add_menu_page(
            'Open Cache Manager',
            'Cache Manager',
            'manage_options',
            $this->options_page,
            array( $this, 'render_settings_page' ),
            'dashicons-performance',
            80
        );

        // Sottopagina: Page Cache (stessa del menu principale)
        add_submenu_page(
            $this->options_page,
            'Page Cache - Open Cache Manager',
            'Page Cache',
            'manage_options',
            $this->options_page,
            array( $this, 'render_settings_page' )
        );

        // Sottopagina: Ottimizzazione Database
        add_submenu_page(
            $this->options_page,
            'DB Optimizer - Open Cache Manager',
            'DB Optimizer',
            'manage_options',
            'ocm-db-optimizer',
            array( $this, 'render_db_optimizer_page' )
        );
    }

    /**
     * Renderizza la pagina delle impostazioni Page Cache.
     */
    public function render_settings_page() {
        // Salva impostazioni
        if ( isset( $_POST['ocm_cache_save'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'ocm_cache_settings' ) ) {
            $options = array(
                'ttl'            => max( 60, (int) $_POST['ttl'] ),
                'excluded_urls'  => sanitize_textarea_field( $_POST['excluded_urls'] ),
                'debug'          => isset( $_POST['debug'] ) ? 1 : 0,
                'bulk_threshold' => max( 10, (int) $_POST['bulk_threshold'] ),
            );
            update_option( 'open_cache_manager', $options );
            $this->sync_excluded_urls( $options['excluded_urls'] );
            $this->sync_ttl( $options['ttl'] );
            echo '<div class="notice notice-success"><p>Impostazioni salvate!</p></div>';
        }

        $options = wp_parse_args( get_option( 'open_cache_manager', array() ), array(
            'ttl'            => $this->default_ttl,
            'excluded_urls'  => '',
            'debug'          => 0,
            'bulk_threshold' => 50,
        ) );

        $stats  = $this->get_stats();
        $health = $this->get_system_health();

        // Conta errori per decidere se mostrare l'avviso
        $has_errors = false;
        foreach ( $health as $check ) {
            if ( $check['status'] === 'error' ) {
                $has_errors = true;
                break;
            }
        }
        ?>
        <div class="wrap">
            <h1>Open Cache Manager - Page Cache</h1>
            <p class="description">v<?php echo esc_html( OCM_VERSION ); ?> | Cache file gzip per WordPress/WooCommerce</p>

            <!-- Stato sistema -->
            <div class="card" style="max-width:700px; padding:15px; margin-bottom:20px; <?php echo $has_errors ? 'border-left: 4px solid #d63638;' : 'border-left: 4px solid #00a32a;'; ?>">
                <h2 style="margin-top:0;">Stato sistema</h2>
                <table class="widefat" style="margin-bottom:12px;">
                    <thead>
                        <tr>
                            <th style="width:220px;">Componente</th>
                            <th style="width:60px;">Stato</th>
                            <th>Dettaglio</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $health as $check ) :
                        $icon = $check['status'] === 'ok' ? '&#10003;' : ( $check['status'] === 'error' ? '&#10007;' : '&#9888;' );
                        $color = $check['status'] === 'ok' ? '#00a32a' : ( $check['status'] === 'error' ? '#d63638' : '#dba617' );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $check['label'] ); ?></td>
                            <td style="text-align:center; color:<?php echo $color; ?>; font-size:18px; font-weight:bold;"><?php echo $icon; ?></td>
                            <td><small><?php echo wp_kses( $check['note'], array( 'code' => array(), 'strong' => array() ) ); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?action=ocm_reinstall_dropin' ), 'ocm_reinstall_dropin' ); ?>"
                   class="button <?php echo $has_errors ? 'button-primary' : 'button-secondary'; ?>">
                    &#8635; Reinstalla drop-in e ripristina impostazioni
                </a>
                <p style="margin-top:8px; color:#646970; font-size:12px;">
                    Riesegue tutti i passi di attivazione: copia advanced-cache.php, scrive WP_CACHE in wp-config.php, ricrea il file .active, schedula il cron.
                </p>
            </div>

            <!-- Statistiche -->
            <div class="card" style="max-width:600px; padding:15px; margin-bottom:20px;">
                <h2>Statistiche</h2>
                <table class="widefat" style="max-width:400px;">
                    <tr><th>Pagine in cache</th><td><strong><?php echo (int) $stats['files']; ?></strong></td></tr>
                    <tr><th>Dimensione totale</th><td><strong><?php echo esc_html( $this->format_bytes( $stats['size'] ) ); ?></strong></td></tr>
                    <tr>
                        <th>File più vecchio</th>
                        <td><?php echo $stats['oldest'] ? esc_html( date( 'd/m/Y H:i:s', $stats['oldest'] ) ) : '-'; ?></td>
                    </tr>
                    <tr>
                        <th>File più recente</th>
                        <td><?php echo $stats['newest'] ? esc_html( date( 'd/m/Y H:i:s', $stats['newest'] ) ) : '-'; ?></td>
                    </tr>
                </table>
                <br>
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?action=ocm_clear_cache' ), 'ocm_clear_cache' ); ?>"
                   class="button button-secondary"
                   onclick="return confirm('Svuotare tutta la cache?');">
                    Svuota tutta la cache
                </a>
            </div>

            <!-- Impostazioni -->
            <form method="post">
                <?php wp_nonce_field( 'ocm_cache_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th>Durata cache (TTL)</th>
                        <td>
                            <input type="number" name="ttl" value="<?php echo (int) $options['ttl']; ?>"
                                   min="60" step="60" style="width:100px;"> secondi
                            <p class="description">
                                Quanto tempo una pagina resta in cache prima di essere rigenerata.
                                Consigliato: 3600 (1 ora) per cataloghi con aggiornamenti frequenti.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL esclusi</th>
                        <td>
                            <textarea name="excluded_urls" rows="8" cols="60"><?php
                                echo esc_textarea( $options['excluded_urls'] );
                            ?></textarea>
                            <p class="description">
                                Un percorso per riga. Le pagine che contengono questi percorsi non verranno mai cachate.
                                /cart, /checkout, /my-account sono sempre esclusi automaticamente.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Soglia invalidazione bulk</th>
                        <td>
                            <input type="number" name="bulk_threshold" value="<?php echo (int) $options['bulk_threshold']; ?>"
                                   min="10" step="10" style="width:100px;"> prodotti
                            <p class="description">
                                Se durante un aggiornamento bulk vengono modificati più di questo numero di prodotti,
                                la cache viene svuotata completamente anziché invalidare singole pagine.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Debug</th>
                        <td>
                            <label>
                                <input type="checkbox" name="debug" value="1" <?php checked( $options['debug'], 1 ); ?>>
                                Aggiungi commenti HTML con timestamp di cache
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="ocm_cache_save" class="button button-primary" value="Salva impostazioni">
                </p>
            </form>

            <!-- Guida -->
            <div class="card" style="max-width:600px; padding:15px;">
                <h2>Uso negli aggiornamenti bulk</h2>
                <p>Nei tuoi script di importazione/aggiornamento prodotti, usa questi hook per ottimizzare l'invalidazione:</p>
                <pre style="background:#f0f0f0; padding:10px; overflow-x:auto;">
// Prima dell'aggiornamento bulk
do_action( 'ocm_cache_bulk_start' );

// ... aggiorna i prodotti ...

// Dopo l'aggiornamento bulk
do_action( 'ocm_cache_bulk_end' );
                </pre>
                <h3>Invalidazione manuale da codice</h3>
                <pre style="background:#f0f0f0; padding:10px; overflow-x:auto;">
// Invalida un singolo prodotto
do_action( 'ocm_cache_invalidate_product', $product_id );

// Invalida tutta la cache
do_action( 'ocm_cache_invalidate_all' );
                </pre>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizza la pagina DB Optimizer.
     */
    public function render_db_optimizer_page() {
        require_once OCM_PLUGIN_DIR . 'includes/class-db-optimizer.php';
        $optimizer = new OCM_DB_Optimizer();

        $sections     = $optimizer->get_recommendations();
        $summary      = $optimizer->get_summary();
        $bp_info      = $optimizer->get_buffer_pool_usage();
        $tmp_info     = $optimizer->get_tmp_table_stats();
        $conn_info    = $optimizer->get_connection_stats();
        $config       = $optimizer->generate_config_snippet();
        ?>
        <div class="wrap">
            <h1>Open Cache Manager - DB Optimizer</h1>
            <p class="description">Analisi e ottimizzazione della configurazione <?php echo $optimizer->is_mariadb() ? 'MariaDB' : 'MySQL'; ?> per WooCommerce</p>

            <style>
                .ocm-db-cards { display: flex; gap: 15px; flex-wrap: wrap; margin: 15px 0; }
                .ocm-db-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 15px 20px; min-width: 180px; flex: 1; }
                .ocm-db-card.ok { border-left-color: #00a32a; }
                .ocm-db-card.warning { border-left-color: #dba617; }
                .ocm-db-card.critical { border-left-color: #d63638; }
                .ocm-db-card h3 { margin: 0 0 5px 0; font-size: 13px; color: #50575e; text-transform: uppercase; }
                .ocm-db-card .value { font-size: 28px; font-weight: 600; line-height: 1.2; }
                .ocm-db-param-table { border-collapse: collapse; width: 100%; }
                .ocm-db-param-table th, .ocm-db-param-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }
                .ocm-db-param-table th { background: #f6f7f7; font-weight: 600; }
                .ocm-db-param-table tr:hover td { background: #f9f9f9; }
                .ocm-status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
                .ocm-status.ok { background: #d4edda; color: #155724; }
                .ocm-status.warning { background: #fff3cd; color: #856404; }
                .ocm-status.critical { background: #f8d7da; color: #721c24; }
                .ocm-status.info { background: #d1ecf1; color: #0c5460; }
                .ocm-section { background: #fff; border: 1px solid #c3c4c7; margin: 20px 0; }
                .ocm-section h2 { margin: 0; padding: 12px 15px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7; font-size: 14px; }
                .ocm-section .inside { padding: 0; }
                .ocm-section .description { padding: 8px 15px; margin: 0; color: #646970; font-style: italic; border-bottom: 1px solid #f0f0f0; }
                .ocm-config-box { background: #1d2327; color: #c3c4c7; padding: 15px; font-family: monospace; font-size: 13px; line-height: 1.6; white-space: pre-wrap; overflow-x: auto; max-height: 500px; }
                .ocm-progress-bar { background: #e0e0e0; border-radius: 4px; height: 20px; overflow: hidden; }
                .ocm-progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
            </style>

            <!-- Riepilogo server -->
            <div class="ocm-db-cards">
                <div class="ocm-db-card">
                    <h3>Database</h3>
                    <div class="value" style="font-size:16px;"><?php echo esc_html( $optimizer->get_db_version() ); ?></div>
                    <small><?php echo $optimizer->is_mariadb() ? 'MariaDB' : 'MySQL'; ?></small>
                </div>
                <div class="ocm-db-card">
                    <h3>RAM Server</h3>
                    <div class="value" style="font-size:16px;"><?php echo esc_html( $optimizer->get_total_ram_formatted() ); ?></div>
                </div>
                <div class="ocm-db-card ok">
                    <h3>Parametri OK</h3>
                    <div class="value"><?php echo (int) $summary['ok']; ?></div>
                </div>
                <div class="ocm-db-card <?php echo $summary['critical'] > 0 ? 'critical' : 'ok'; ?>">
                    <h3>Da ottimizzare</h3>
                    <div class="value"><?php echo (int) $summary['critical']; ?></div>
                </div>
                <div class="ocm-db-card <?php echo $summary['warning'] > 0 ? 'warning' : 'ok'; ?>">
                    <h3>Consigliati</h3>
                    <div class="value"><?php echo (int) $summary['warning']; ?></div>
                </div>
            </div>

            <!-- Buffer Pool Usage -->
            <div class="ocm-db-cards">
                <div class="ocm-db-card" style="flex:2;">
                    <h3>InnoDB Buffer Pool</h3>
                    <div style="margin:8px 0;">
                        <strong>Dimensione:</strong> <?php echo esc_html( $bp_info['size_formatted'] ); ?> |
                        <strong>Utilizzo:</strong> <?php echo esc_html( $bp_info['usage_pct'] ); ?>% |
                        <strong>Hit Rate:</strong> <?php echo esc_html( $bp_info['hit_rate'] ); ?>%
                    </div>
                    <div class="ocm-progress-bar">
                        <div class="ocm-progress-fill" style="width:<?php echo esc_attr( min( 100, $bp_info['usage_pct'] ) ); ?>%; background:<?php echo $bp_info['hit_rate'] >= 99 ? '#00a32a' : ( $bp_info['hit_rate'] >= 95 ? '#dba617' : '#d63638' ); ?>;"></div>
                    </div>
                    <small style="color:#646970;">
                        Pagine totali: <?php echo number_format( $bp_info['pages_total'] ); ?> |
                        Pagine dati: <?php echo number_format( $bp_info['pages_data'] ); ?> |
                        Pagine libere: <?php echo number_format( $bp_info['pages_free'] ); ?>
                    </small>
                </div>
                <div class="ocm-db-card">
                    <h3>Tabelle temporanee</h3>
                    <div style="margin:8px 0;">
                        <strong>Su disco:</strong> <?php echo esc_html( $tmp_info['disk_pct'] ); ?>%
                    </div>
                    <div class="ocm-progress-bar">
                        <div class="ocm-progress-fill" style="width:<?php echo esc_attr( min( 100, $tmp_info['disk_pct'] ) ); ?>%; background:<?php echo $tmp_info['disk_pct'] <= 25 ? '#00a32a' : ( $tmp_info['disk_pct'] <= 50 ? '#dba617' : '#d63638' ); ?>;"></div>
                    </div>
                    <small style="color:#646970;">
                        In memoria: <?php echo number_format( $tmp_info['memory_tables'] ); ?> |
                        Su disco: <?php echo number_format( $tmp_info['disk_tables'] ); ?>
                    </small>
                </div>
                <div class="ocm-db-card">
                    <h3>Connessioni</h3>
                    <div style="margin:8px 0;">
                        <strong>Max usate:</strong> <?php echo (int) $conn_info['max_used']; ?>/<?php echo (int) $conn_info['max_connections']; ?> (<?php echo esc_html( $conn_info['usage_pct'] ); ?>%)
                    </div>
                    <div class="ocm-progress-bar">
                        <div class="ocm-progress-fill" style="width:<?php echo esc_attr( min( 100, $conn_info['usage_pct'] ) ); ?>%; background:<?php echo $conn_info['usage_pct'] <= 70 ? '#00a32a' : ( $conn_info['usage_pct'] <= 85 ? '#dba617' : '#d63638' ); ?>;"></div>
                    </div>
                    <small style="color:#646970;">
                        Attive: <?php echo (int) $conn_info['current_connected']; ?> |
                        Running: <?php echo (int) $conn_info['current_running']; ?> |
                        Aborted: <?php echo number_format( $conn_info['aborted'] ); ?>
                    </small>
                </div>
            </div>

            <!-- Sezioni parametri -->
            <?php foreach ( $sections as $section ) : ?>
            <div class="ocm-section">
                <h2><?php echo esc_html( $section['title'] ); ?></h2>
                <?php if ( ! empty( $section['description'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $section['description'] ); ?></p>
                <?php endif; ?>
                <div class="inside">
                    <table class="ocm-db-param-table">
                        <thead>
                            <tr>
                                <th style="width:25%;">Parametro</th>
                                <th style="width:15%;">Valore attuale</th>
                                <th style="width:15%;">Raccomandato</th>
                                <th style="width:10%;">Stato</th>
                                <th style="width:35%;">Descrizione</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $section['params'] as $param ) :
                            $analyzed = $optimizer->analyze_param( $param );
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $analyzed['name'] ); ?></code></td>
                                <td><strong><?php echo esc_html( $optimizer->format_current( $analyzed ) ); ?></strong></td>
                                <td><?php echo esc_html( $optimizer->format_recommended( $analyzed ) ); ?></td>
                                <td><span class="ocm-status <?php echo esc_attr( $analyzed['status'] ); ?>"><?php echo esc_html( $analyzed['status_text'] ); ?></span></td>
                                <td><small><?php echo esc_html( $analyzed['description'] ); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Configurazione generata -->
            <div class="ocm-section">
                <h2>Configurazione raccomandata per my.cnf</h2>
                <p class="description">Copia questa configurazione nel file di configurazione del database (es. /etc/mysql/mariadb.conf.d/50-server.cnf). Riavvia il servizio MySQL/MariaDB dopo la modifica.</p>
                <div class="inside">
                    <div style="padding: 10px 15px;">
                        <button type="button" class="button button-secondary" onclick="
                            var el = document.getElementById('ocm-config-output');
                            if (navigator.clipboard) {
                                navigator.clipboard.writeText(el.textContent).then(function() {
                                    alert('Configurazione copiata negli appunti!');
                                });
                            } else {
                                var range = document.createRange();
                                range.selectNode(el);
                                window.getSelection().removeAllRanges();
                                window.getSelection().addRange(range);
                                document.execCommand('copy');
                                alert('Configurazione copiata negli appunti!');
                            }
                        ">Copia configurazione</button>
                        <span style="margin-left: 10px; color: #646970;">Percorsi tipici: <code>/etc/mysql/mariadb.conf.d/50-server.cnf</code> o <code>/etc/mysql/my.cnf</code></span>
                    </div>
                    <div class="ocm-config-box" id="ocm-config-output"><?php echo esc_html( $config ); ?></div>
                </div>
            </div>

            <!-- Note importanti -->
            <div class="card" style="max-width:800px; padding:15px; margin-top:20px;">
                <h2>Note importanti</h2>
                <ul style="list-style:disc; padding-left:20px;">
                    <li><strong>Backup prima di modificare:</strong> Fai sempre un backup di my.cnf prima di applicare le modifiche.</li>
                    <li><strong>Riavvio necessario:</strong> La maggior parte dei parametri InnoDB richiede il riavvio del servizio MySQL/MariaDB per avere effetto.</li>
                    <li><strong>innodb_log_file_size:</strong> La modifica di questo parametro richiede uno shutdown pulito del database. MariaDB ricrea automaticamente i file di log al riavvio.</li>
                    <li><strong>Buffer pool warmup:</strong> Dopo il riavvio, il buffer pool parte vuoto. Con <code>innodb_buffer_pool_dump_at_shutdown</code> e <code>innodb_buffer_pool_load_at_startup</code> attivi, il warmup è automatico.</li>
                    <li><strong>Valori per-connessione:</strong> Parametri come <code>sort_buffer_size</code>, <code>join_buffer_size</code>, <code>read_buffer_size</code> sono allocati per ogni connessione. Valori troppo alti moltiplicati per max_connections possono esaurire la RAM.</li>
                    <li><strong>Query Cache:</strong> Per WooCommerce con aggiornamenti frequenti (import bulk), la query cache causa più overhead che benefici. Si consiglia di disabilitarla e usare Redis come object cache.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    // =============================================================
    //  CACHE WARM-UP PER NAVIGAZIONE AJAX/PJAX
    // =============================================================

    /**
     * Stampa uno script inline nelle pagine shop/categoria WooCommerce.
     *
     * Quando Woodmart (ajax_shop / PJAX) naviga verso un URL filtrato,
     * il browser cambia URL via pushState ma il drop-in non riceve mai
     * un "document" request — solo XHR parziali che vengono skippati.
     *
     * Questo script intercetta i cambi di URL e invia un fetch() in
     * background senza cookie e senza header XHR. Il drop-in lo vede
     * come una visita anonima normale: MISS → salva .gz.
     * Il prossimo visitatore (o Googlebot) ottiene un HIT immediato.
     */
    public function maybe_print_warmup_script() {
        if ( ! function_exists( 'is_shop' ) ) {
            return;
        }
        if ( ! is_shop() && ! is_product_category() && ! is_product_taxonomy() && ! is_product_tag() ) {
            return;
        }
        ?>
        <script>
        /* OCM Cache Warm-up: pre-cacha pagine filtrate navigate via AJAX/PJAX */
        (function(){
            var timer, lastUrl = location.href;
            var origPushState = history.pushState;

            function warmup() {
                var currentUrl = location.href;
                if ( currentUrl === lastUrl ) return;
                lastUrl = currentUrl;
                clearTimeout( timer );
                timer = setTimeout( function() {
                    fetch( currentUrl, { credentials: 'omit', cache: 'no-store' } );
                }, 2000 );
            }

            history.pushState = function() {
                origPushState.apply( this, arguments );
                warmup();
            };

            window.addEventListener( 'popstate', warmup );
        })();
        </script>
        <?php
    }

    // =============================================================
    //  UTILITY
    // =============================================================

    private function sync_excluded_urls( $excluded_urls ) {
        $file = $this->cache_dir . '.excluded_urls';

        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0755, true );
        }

        if ( empty( trim( $excluded_urls ) ) ) {
            if ( file_exists( $file ) ) {
                @unlink( $file );
            }
            return;
        }

        $lines = array_filter( array_map( 'trim', explode( "\n", $excluded_urls ) ) );
        file_put_contents( $file, implode( "\n", $lines ), LOCK_EX );
    }

    /**
     * Scrive il file .ttl letto dal drop-in (non ha accesso al DB).
     *
     * @param int $ttl Durata in secondi.
     */
    private function sync_ttl( $ttl ) {
        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0755, true );
        }
        file_put_contents( $this->cache_dir . '.ttl', (int) $ttl, LOCK_EX );
    }

    private function get_current_url() {
        if ( is_admin() ) {
            return home_url( '/' );
        }
        $protocol = is_ssl() ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    private function format_bytes( $bytes ) {
        if ( $bytes === 0 ) return '0 B';
        $units = array( 'B', 'KB', 'MB', 'GB' );
        $i     = floor( log( $bytes, 1024 ) );
        return round( $bytes / pow( 1024, $i ), 2 ) . ' ' . $units[ $i ];
    }
}

// Inizializza il plugin
new Open_Cache_Manager();

// =============================================================
//  WP-CLI
// =============================================================
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    class OCM_Cache_CLI {

        /**
         * Svuota tutta la cache.
         *
         * ## EXAMPLES
         *     wp open-cache clear
         */
        public function clear() {
            $plugin = new Open_Cache_Manager();
            $count  = $plugin->clear_all();
            WP_CLI::success( sprintf( 'Cache svuotata. %d file eliminati.', $count ) );
        }

        /**
         * Mostra statistiche della cache.
         *
         * ## EXAMPLES
         *     wp open-cache stats
         */
        public function stats() {
            $plugin = new Open_Cache_Manager();
            $stats  = $plugin->get_stats();

            WP_CLI::line( sprintf( 'Pagine in cache: %d', $stats['files'] ) );
            WP_CLI::line( sprintf( 'Dimensione: %s', $this->format_bytes( $stats['size'] ) ) );

            if ( $stats['oldest'] ) {
                WP_CLI::line( sprintf( 'Più vecchio: %s', date( 'Y-m-d H:i:s', $stats['oldest'] ) ) );
            }
            if ( $stats['newest'] ) {
                WP_CLI::line( sprintf( 'Più recente: %s', date( 'Y-m-d H:i:s', $stats['newest'] ) ) );
            }
        }

        /**
         * Analizza la configurazione del database.
         *
         * ## EXAMPLES
         *     wp open-cache db-check
         */
        public function db_check() {
            require_once OCM_PLUGIN_DIR . 'includes/class-db-optimizer.php';
            $optimizer = new OCM_DB_Optimizer();
            $sections  = $optimizer->get_recommendations();
            $summary   = $optimizer->get_summary();

            WP_CLI::line( '' );
            WP_CLI::line( sprintf( 'Database: %s (%s)', $optimizer->get_db_version(), $optimizer->is_mariadb() ? 'MariaDB' : 'MySQL' ) );
            WP_CLI::line( sprintf( 'RAM Server: %s', $optimizer->get_total_ram_formatted() ) );
            WP_CLI::line( sprintf( 'OK: %d | Da ottimizzare: %d | Consigliati: %d', $summary['ok'], $summary['critical'], $summary['warning'] ) );
            WP_CLI::line( '' );

            foreach ( $sections as $section ) {
                WP_CLI::line( '--- ' . $section['title'] . ' ---' );
                $items = array();
                foreach ( $section['params'] as $param ) {
                    $analyzed = $optimizer->analyze_param( $param );
                    $items[]  = array(
                        'Parametro'    => $analyzed['name'],
                        'Attuale'      => $optimizer->format_current( $analyzed ),
                        'Raccomandato' => $optimizer->format_recommended( $analyzed ),
                        'Stato'        => strtoupper( $analyzed['status_text'] ),
                    );
                }
                WP_CLI\Utils\format_items( 'table', $items, array( 'Parametro', 'Attuale', 'Raccomandato', 'Stato' ) );
                WP_CLI::line( '' );
            }
        }

        private function format_bytes( $bytes ) {
            if ( $bytes === 0 ) return '0 B';
            $units = array( 'B', 'KB', 'MB', 'GB' );
            $i     = floor( log( $bytes, 1024 ) );
            return round( $bytes / pow( 1024, $i ), 2 ) . ' ' . $units[ $i ];
        }
    }

    WP_CLI::add_command( 'open-cache', 'OCM_Cache_CLI' );
}
