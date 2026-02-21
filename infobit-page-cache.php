<?php
/**
 * Plugin Name: Infobit Page Cache
 * Plugin URI:  https://infobitcomputer.it
 * Description: Page cache leggero ottimizzato per WooCommerce con cataloghi di grandi dimensioni. Salva le pagine HTML come file statici, con invalidazione intelligente per aggiornamenti bulk dei prodotti.
 * Version:     1.2.0
 * Author:      Infobit
 * License:     GPL-2.0+
 * Text Domain: infobit-page-cache
 *
 * @package Infobit_Page_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Infobit_Page_Cache {

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
    private $options_page = 'infobit-page-cache';

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
        $this->cache_dir = WP_CONTENT_DIR . '/cache/infobit-pages/';

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
        add_action( 'infobit_cache_invalidate_product', array( $this, 'invalidate_product_cache' ) );
        add_action( 'infobit_cache_invalidate_all',     array( $this, 'clear_all' ) );
        add_action( 'infobit_cache_bulk_start',         array( $this, 'bulk_start' ) );
        add_action( 'infobit_cache_bulk_end',           array( $this, 'bulk_end' ) );

        // -------------------------------------------------------
        // Attivazione / Disattivazione
        // -------------------------------------------------------
        register_activation_hook( __FILE__,   array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // -------------------------------------------------------
        // Cron per pulizia cache scaduta
        // -------------------------------------------------------
        add_action( 'infobit_cache_cleanup', array( $this, 'cleanup_expired' ) );

        // -------------------------------------------------------
        // Auto-aggiornamento advanced-cache.php
        // Quando il plugin viene aggiornato, il drop-in in wp-content
        // potrebbe essere una versione vecchia. Verifica e aggiorna.
        // -------------------------------------------------------
        add_action( 'admin_init', array( $this, 'maybe_update_dropin' ) );
    }

    // =============================================================
    //  ATTIVAZIONE / DISATTIVAZIONE
    // =============================================================

    /**
     * Attivazione del plugin.
     * Installa il file advanced-cache.php e abilita WP_CACHE.
     */
    public function activate() {
        // Crea directory cache
        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0755, true );
        }

        // Crea file flag che indica che il plugin è attivo
        file_put_contents( $this->cache_dir . '.active', time() );

        // Copia advanced-cache.php
        $source = plugin_dir_path( __FILE__ ) . 'advanced-cache.php';
        $dest   = WP_CONTENT_DIR . '/advanced-cache.php';

        if ( file_exists( $source ) ) {
            copy( $source, $dest );
        }

        // Prova ad abilitare WP_CACHE in wp-config.php
        // Se non è presente, lo aggiunge in modo sicuro con backup
        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
            $this->safe_set_wp_cache( true );
        }

        // Schedula pulizia giornaliera
        if ( ! wp_next_scheduled( 'infobit_cache_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'infobit_cache_cleanup' );
        }

        // Salva opzioni di default
        $defaults = array(
            'ttl'            => $this->default_ttl,
            'excluded_urls'  => "/cart\n/carrello\n/checkout\n/cassa\n/my-account\n/mio-account\n/wishlist",
            'preload_urls'   => '',
            'debug'          => 0,
            'bulk_threshold' => 50,
        );
        if ( ! get_option( 'infobit_page_cache' ) ) {
            add_option( 'infobit_page_cache', $defaults );
        }

        // Sincronizza gli URL esclusi su file per advanced-cache.php
        $options = get_option( 'infobit_page_cache', $defaults );
        $this->sync_excluded_urls( isset( $options['excluded_urls'] ) ? $options['excluded_urls'] : '' );
    }

    /**
     * Disattivazione del plugin.
     * Rimuove tutto: flag, WP_CACHE, advanced-cache.php, cron, cache files.
     */
    public function deactivate() {
        // STEP 1: rimuovi il file flag (sicurezza immediata)
        $active_flag = $this->cache_dir . '.active';
        if ( file_exists( $active_flag ) ) {
            @unlink( $active_flag );
        }

        // STEP 2: rimuovi WP_CACHE dal wp-config.php in modo sicuro
        $this->safe_set_wp_cache( false );

        // STEP 3: rimuovi advanced-cache.php da wp-content
        // Solo se è il nostro (controlla il marker nel file)
        $advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( file_exists( $advanced_cache ) ) {
            $content = @file_get_contents( $advanced_cache );
            if ( $content !== false && strpos( $content, 'Infobit Page Cache' ) !== false ) {
                @unlink( $advanced_cache );
            }
        }

        // STEP 4: rimuovi cron
        wp_clear_scheduled_hook( 'infobit_cache_cleanup' );

        // STEP 5: svuota tutti i file cache
        $this->clear_all();

        // STEP 6: rimuovi la directory cache stessa
        $this->remove_cache_directory();
    }

    /**
     * Rimuove ricorsivamente la directory cache e tutte le sottocartelle.
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

        // Rimuovi anche la directory parent /cache/ se è vuota
        $parent_cache = WP_CONTENT_DIR . '/cache/';
        if ( is_dir( $parent_cache ) ) {
            $remaining = @scandir( $parent_cache );
            if ( $remaining !== false && count( $remaining ) <= 2 ) { // solo . e ..
                @rmdir( $parent_cache );
            }
        }
    }

    /**
     * Verifica e aggiorna il drop-in advanced-cache.php se necessario.
     * Confronta il contenuto del file nel plugin con quello in wp-content.
     * Se diversi, aggiorna automaticamente.
     */
    public function maybe_update_dropin() {
        $source = plugin_dir_path( __FILE__ ) . 'advanced-cache.php';
        $dest   = WP_CONTENT_DIR . '/advanced-cache.php';

        if ( ! file_exists( $source ) ) {
            return;
        }

        // Se il drop-in non esiste, copialo
        if ( ! file_exists( $dest ) ) {
            @copy( $source, $dest );
            return;
        }

        // Se il drop-in non è il nostro, non toccarlo
        $dest_content = @file_get_contents( $dest );
        if ( $dest_content === false || strpos( $dest_content, 'Infobit Page Cache' ) === false ) {
            return;
        }

        // Confronta: se diversi, aggiorna
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
     * Procedura:
     * 1. Crea un backup del file originale (.bak)
     * 2. Modifica il file
     * 3. Verifica che il file modificato sia valido (contiene <?php e DB_NAME)
     * 4. Se la verifica fallisce, ripristina il backup
     *
     * @param bool $enable True per aggiungere, false per rimuovere.
     * @return bool True se la modifica è riuscita, false altrimenti.
     */
    private function safe_set_wp_cache( $enable ) {
        $config_file  = ABSPATH . 'wp-config.php';
        $backup_file  = ABSPATH . 'wp-config.php.infobit-bak';
        $marker       = "define( 'WP_CACHE', true ); // Infobit Page Cache";

        // Verifiche preliminari
        if ( ! file_exists( $config_file ) ) {
            $this->set_admin_notice( 'Errore: wp-config.php non trovato.' );
            return false;
        }

        if ( ! is_writable( $config_file ) ) {
            $this->set_admin_notice( 
                'Infobit Page Cache: wp-config.php non è scrivibile. Aggiungi/rimuovi manualmente: <code>' . $marker . '</code>' 
            );
            return false;
        }

        // Leggi il contenuto attuale
        $original_content = file_get_contents( $config_file );
        if ( $original_content === false || strlen( $original_content ) < 100 ) {
            $this->set_admin_notice( 'Errore: impossibile leggere wp-config.php.' );
            return false;
        }

        // STEP 1: Crea backup
        if ( ! copy( $config_file, $backup_file ) ) {
            $this->set_admin_notice( 'Errore: impossibile creare il backup di wp-config.php.' );
            return false;
        }

        // STEP 2: Prepara il nuovo contenuto
        if ( $enable ) {
            // Controlla se è già presente
            if ( strpos( $original_content, 'Infobit Page Cache' ) !== false ) {
                @unlink( $backup_file );
                return true; // Già presente, nulla da fare
            }
            // Aggiungi dopo <?php sulla prima riga
            $new_content = preg_replace(
                '/^(<\?php)\s*$/m',
                "<?php\n" . $marker,
                $original_content,
                1,
                $count
            );
            // Se la regex non ha matchato (<?php non è su una riga da solo), prova alternativa
            if ( $count === 0 ) {
                $new_content = str_replace(
                    '<?php',
                    "<?php\n" . $marker,
                    $original_content
                );
                // Assicurati che sia stato aggiunto una sola volta
                $occurrences = substr_count( $new_content, $marker );
                if ( $occurrences > 1 ) {
                    // Rimuovi tutti e aggiungi solo il primo
                    $new_content = str_replace( "\n" . $marker, '', $original_content );
                    $new_content = str_replace( '<?php', "<?php\n" . $marker, $new_content );
                }
            }
        } else {
            // Rimuovi la riga con il marker
            if ( strpos( $original_content, 'Infobit Page Cache' ) === false ) {
                @unlink( $backup_file );
                return true; // Non presente, nulla da fare
            }
            // Rimuovi l'intera riga che contiene il marker
            $lines = explode( "\n", $original_content );
            $new_lines = array();
            foreach ( $lines as $line ) {
                if ( strpos( $line, 'Infobit Page Cache' ) === false ) {
                    $new_lines[] = $line;
                }
            }
            $new_content = implode( "\n", $new_lines );
        }

        // STEP 3: Verifica che il nuovo contenuto sia valido PRIMA di scriverlo
        if ( ! $this->validate_wp_config_content( $new_content ) ) {
            @unlink( $backup_file );
            $this->set_admin_notice( 'Errore: la modifica di wp-config.php produrrebbe un file non valido. Operazione annullata.' );
            return false;
        }

        // STEP 4: Scrivi il nuovo contenuto
        $result = file_put_contents( $config_file, $new_content, LOCK_EX );
        if ( $result === false ) {
            // Ripristina il backup
            copy( $backup_file, $config_file );
            @unlink( $backup_file );
            $this->set_admin_notice( 'Errore: impossibile scrivere wp-config.php. File ripristinato dal backup.' );
            return false;
        }

        // STEP 5: Verifica il file scritto rileggendolo
        $written_content = file_get_contents( $config_file );
        if ( ! $this->validate_wp_config_content( $written_content ) ) {
            // File corrotto! Ripristina il backup
            copy( $backup_file, $config_file );
            @unlink( $backup_file );
            $this->set_admin_notice( 'Errore: wp-config.php corrotto dopo la scrittura. File ripristinato dal backup.' );
            return false;
        }

        // Tutto ok, rimuovi il backup
        @unlink( $backup_file );
        return true;
    }

    /**
     * Valida che il contenuto di wp-config.php sia strutturalmente corretto.
     *
     * @param string $content Il contenuto da validare.
     * @return bool True se valido, false se corrotto.
     */
    private function validate_wp_config_content( $content ) {
        // Deve contenere <?php come apertura
        if ( strpos( $content, '<?php' ) === false ) {
            return false;
        }

        // Deve contenere le definizioni essenziali di WordPress
        $required_strings = array(
            'DB_NAME',
            'DB_USER',
            'DB_HOST',
            'ABSPATH',
            'wp-settings.php',
        );

        foreach ( $required_strings as $required ) {
            if ( strpos( $content, $required ) === false ) {
                return false;
            }
        }

        // Non deve avere <?php duplicato all'inizio (segno di corruzione)
        $php_count = substr_count( substr( $content, 0, 100 ), '<?php' );
        if ( $php_count > 1 ) {
            return false;
        }

        // Il contenuto non deve essere troppo corto (file troncato)
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
        set_transient( 'infobit_cache_notice', $message, 60 );
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

        // Pulizia legacy: rimuovi anche eventuali vecchi file .html
        $legacy_html = $this->cache_dir . substr( $cache_key, 0, 2 ) . '/' . $cache_key . '.html';
        if ( file_exists( $legacy_html ) ) {
            @unlink( $legacy_html );
        }
        if ( file_exists( $legacy_html . '.gz' ) ) {
            @unlink( $legacy_html . '.gz' );
        }
    }

    /**
     * Invalida la cache relativa a un prodotto.
     *
     * @param int $product_id ID del prodotto.
     */
    public function invalidate_product_cache( $product_id ) {
        // Se siamo in modalità bulk, accumula e invalida dopo
        if ( $this->bulk_mode ) {
            $pending = get_transient( 'infobit_cache_bulk_ids' );
            if ( ! is_array( $pending ) ) {
                $pending = array();
            }
            $pending[] = $product_id;
            set_transient( 'infobit_cache_bulk_ids', $pending, HOUR_IN_SECONDS );
            return;
        }

        // Pagina prodotto
        $permalink = get_permalink( $product_id );
        if ( $permalink ) {
            $this->invalidate_url( $permalink );
        }

        // Homepage
        $this->invalidate_url( home_url( '/' ) );

        // Pagina shop
        $shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
        if ( $shop_id > 0 ) {
            $this->invalidate_url( get_permalink( $shop_id ) );
        }

        // Categorie del prodotto
        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $this->invalidate_url( get_term_link( $term ) );
            }
        }
    }

    /**
     * Invalida cache quando un prodotto viene aggiornato.
     *
     * @param int $product_id ID del prodotto.
     */
    public function on_product_update( $product_id ) {
        $this->invalidate_product_cache( $product_id );
    }

    /**
     * Invalida cache quando un prodotto viene eliminato.
     *
     * @param int $product_id ID del prodotto.
     */
    public function on_product_delete( $product_id ) {
        if ( get_post_type( $product_id ) === 'product' ) {
            $this->invalidate_product_cache( $product_id );
        }
    }

    /**
     * Invalida quando lo stock di una variazione cambia.
     *
     * @param WC_Product $product Oggetto prodotto.
     */
    public function on_variation_stock_change( $product ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) {
            $this->invalidate_product_cache( $parent_id );
        }
    }

    /**
     * Invalida quando lo stock di un prodotto cambia.
     *
     * @param WC_Product $product Oggetto prodotto.
     */
    public function on_product_stock_change( $product ) {
        $this->invalidate_product_cache( $product->get_id() );
    }

    /**
     * Invalida cache per post/pagine generiche.
     *
     * @param int     $post_id ID del post.
     * @param WP_Post $post    Oggetto post.
     */
    public function on_save_post( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( $post->post_type === 'product' ) {
            return; // Gestito da woocommerce_update_product
        }
        if ( in_array( $post->post_status, array( 'publish', 'trash' ), true ) ) {
            $this->invalidate_url( get_permalink( $post_id ) );
            $this->invalidate_url( home_url( '/' ) );
        }
    }

    /**
     * Invalida cache quando un post viene eliminato.
     *
     * @param int $post_id ID del post.
     */
    public function on_delete_post( $post_id ) {
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $this->invalidate_url( $permalink );
        }
        $this->invalidate_url( home_url( '/' ) );
    }

    // =============================================================
    //  MODALITÀ BULK
    // =============================================================

    /**
     * Attiva la modalità bulk.
     * Durante un aggiornamento massivo, le invalidazioni vengono accumulate
     * e processate alla fine per evitare di invalidare la cache migliaia di volte.
     */
    public function bulk_start() {
        $this->bulk_mode = true;
        set_transient( 'infobit_cache_bulk_ids', array(), HOUR_IN_SECONDS );
    }

    /**
     * Termina la modalità bulk e processa le invalidazioni accumulate.
     */
    public function bulk_end() {
        $this->bulk_mode = false;
        $pending = get_transient( 'infobit_cache_bulk_ids' );

        if ( ! is_array( $pending ) || empty( $pending ) ) {
            return;
        }

        $options   = get_option( 'infobit_page_cache', array() );
        $threshold = isset( $options['bulk_threshold'] ) ? (int) $options['bulk_threshold'] : 50;

        // Se troppi prodotti modificati, svuota tutta la cache
        $unique_ids = array_unique( $pending );
        if ( count( $unique_ids ) > $threshold ) {
            $this->clear_all();
        } else {
            foreach ( $unique_ids as $product_id ) {
                $this->invalidate_product_cache( $product_id );
            }
        }

        delete_transient( 'infobit_cache_bulk_ids' );
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
                // Preserva file di configurazione del plugin
                if ( $basename === '.active' || $basename === '.excluded_urls' ) {
                    continue;
                }
                @unlink( $item->getPathname() );
                $count++;
            } elseif ( $item->isDir() ) {
                // rmdir fallisce se la dir non è vuota (es. contiene .active), ok ignorare
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

        $options = get_option( 'infobit_page_cache', array() );
        $ttl     = isset( $options['ttl'] ) ? (int) $options['ttl'] : $this->default_ttl;
        $now     = time();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && ( $now - $file->getMTime() ) > $ttl ) {
                $basename = basename( $file->getPathname() );
                // Preserva file di configurazione del plugin
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
     * @return array Statistiche.
     */
    public function get_stats() {
        $stats = array(
            'files'      => 0,
            'size'       => 0,
            'oldest'     => 0,
            'newest'     => 0,
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

    /**
     * Aggiunge il pulsante nella admin bar.
     *
     * @param WP_Admin_Bar $wp_admin_bar Oggetto admin bar.
     */
    public function admin_bar_button( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stats = $this->get_stats();

        $wp_admin_bar->add_node( array(
            'id'    => 'infobit-page-cache',
            'title' => sprintf( '⚡ Cache (%d)', $stats['files'] ),
            'href'  => '#',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'infobit-page-cache',
            'id'     => 'infobit-cache-clear',
            'title'  => '🗑 Svuota tutta la cache',
            'href'   => wp_nonce_url( admin_url( 'admin.php?action=infobit_clear_cache' ), 'infobit_clear_cache' ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'infobit-page-cache',
            'id'     => 'infobit-cache-clear-page',
            'title'  => '🔄 Svuota cache di questa pagina',
            'href'   => wp_nonce_url(
                admin_url( 'admin.php?action=infobit_clear_page_cache&url=' . urlencode( $this->get_current_url() ) ),
                'infobit_clear_page_cache'
            ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'infobit-page-cache',
            'id'     => 'infobit-cache-stats',
            'title'  => sprintf(
                '📊 %d pagine cached (%s)',
                $stats['files'],
                $this->format_bytes( $stats['size'] )
            ),
            'href'   => admin_url( 'options-general.php?page=' . $this->options_page ),
        ) );
    }

    /**
     * Gestisce le azioni admin (svuota cache, ecc.).
     */
    public function handle_admin_actions() {
        // Svuota tutta la cache
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'infobit_clear_cache' ) {
            if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'infobit_clear_cache' ) ) {
                wp_die( 'Non autorizzato' );
            }
            $count = $this->clear_all();
            set_transient( 'infobit_cache_notice', sprintf( 'Cache svuotata! %d file eliminati.', $count ), 30 );
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
            exit;
        }

        // Svuota cache singola pagina
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'infobit_clear_page_cache' ) {
            if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'infobit_clear_page_cache' ) ) {
                wp_die( 'Non autorizzato' );
            }
            $url = isset( $_GET['url'] ) ? urldecode( $_GET['url'] ) : '';
            if ( $url ) {
                $this->invalidate_url( $url );
                set_transient( 'infobit_cache_notice', 'Cache della pagina svuotata!', 30 );
            }
            wp_safe_redirect( $url ? $url : ( wp_get_referer() ? wp_get_referer() : admin_url() ) );
            exit;
        }
    }

    /**
     * Mostra le notifiche admin.
     */
    public function admin_notices() {
        $notice = get_transient( 'infobit_cache_notice' );
        if ( $notice ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', wp_kses_post( $notice ) );
            delete_transient( 'infobit_cache_notice' );
        }
    }

    // =============================================================
    //  PAGINA IMPOSTAZIONI
    // =============================================================

    /**
     * Aggiunge il menu nelle impostazioni.
     */
    public function add_admin_menu() {
        add_options_page(
            'Infobit Page Cache',
            'Infobit Cache',
            'manage_options',
            $this->options_page,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Renderizza la pagina delle impostazioni.
     */
    public function render_settings_page() {
        // Salva impostazioni
        if ( isset( $_POST['infobit_cache_save'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'infobit_cache_settings' ) ) {
            $options = array(
                'ttl'            => max( 60, (int) $_POST['ttl'] ),
                'excluded_urls'  => sanitize_textarea_field( $_POST['excluded_urls'] ),
                'debug'          => isset( $_POST['debug'] ) ? 1 : 0,
                'bulk_threshold' => max( 10, (int) $_POST['bulk_threshold'] ),
            );
            update_option( 'infobit_page_cache', $options );

            // Scrivi il file .excluded_urls per advanced-cache.php
            $this->sync_excluded_urls( $options['excluded_urls'] );

            echo '<div class="notice notice-success"><p>Impostazioni salvate!</p></div>';
        }

        $options = wp_parse_args( get_option( 'infobit_page_cache', array() ), array(
            'ttl'            => $this->default_ttl,
            'excluded_urls'  => '',
            'debug'          => 0,
            'bulk_threshold' => 50,
        ) );

        $stats = $this->get_stats();
        ?>
        <div class="wrap">
            <h1>⚡ Infobit Page Cache</h1>

            <!-- Statistiche -->
            <div class="card" style="max-width:600px; padding:15px; margin-bottom:20px;">
                <h2>📊 Statistiche</h2>
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
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?action=infobit_clear_cache' ), 'infobit_clear_cache' ); ?>"
                   class="button button-secondary"
                   onclick="return confirm('Svuotare tutta la cache?');">
                    🗑 Svuota tutta la cache
                </a>
            </div>

            <!-- Impostazioni -->
            <form method="post">
                <?php wp_nonce_field( 'infobit_cache_settings' ); ?>

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
                    <input type="submit" name="infobit_cache_save" class="button button-primary" value="Salva impostazioni">
                </p>
            </form>

            <!-- Guida -->
            <div class="card" style="max-width:600px; padding:15px;">
                <h2>📖 Uso negli aggiornamenti bulk</h2>
                <p>Nei tuoi script di importazione/aggiornamento prodotti, usa questi hook per ottimizzare l'invalidazione:</p>
                <pre style="background:#f0f0f0; padding:10px; overflow-x:auto;">
// Prima dell'aggiornamento bulk
do_action( 'infobit_cache_bulk_start' );

// ... aggiorna i prodotti ...

// Dopo l'aggiornamento bulk
do_action( 'infobit_cache_bulk_end' );
                </pre>
                <p>Questo evita di invalidare la cache migliaia di volte durante un import.</p>
                <h3>Invalidazione manuale da codice</h3>
                <pre style="background:#f0f0f0; padding:10px; overflow-x:auto;">
// Invalida un singolo prodotto
do_action( 'infobit_cache_invalidate_product', $product_id );

// Invalida tutta la cache
do_action( 'infobit_cache_invalidate_all' );
                </pre>
            </div>
        </div>
        <?php
    }

    // =============================================================
    //  UTILITY
    // =============================================================

    /**
     * Sincronizza gli URL esclusi su file per advanced-cache.php.
     * advanced-cache.php non ha accesso al database WordPress,
     * quindi legge le esclusioni custom da un file.
     *
     * @param string $excluded_urls Gli URL esclusi, uno per riga.
     */
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

        // Pulisci e scrivi le righe
        $lines = array_filter( array_map( 'trim', explode( "\n", $excluded_urls ) ) );
        file_put_contents( $file, implode( "\n", $lines ), LOCK_EX );
    }

    /**
     * Restituisce l'URL corrente.
     *
     * @return string
     */
    private function get_current_url() {
        if ( is_admin() ) {
            return home_url( '/' );
        }
        $protocol = is_ssl() ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    /**
     * Formatta i byte in formato leggibile.
     *
     * @param int $bytes Numero di byte.
     * @return string
     */
    private function format_bytes( $bytes ) {
        if ( $bytes === 0 ) return '0 B';
        $units = array( 'B', 'KB', 'MB', 'GB' );
        $i     = floor( log( $bytes, 1024 ) );
        return round( $bytes / pow( 1024, $i ), 2 ) . ' ' . $units[ $i ];
    }
}

// Inizializza il plugin
new Infobit_Page_Cache();

// =============================================================
//  WP-CLI
// =============================================================
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    /**
     * Comandi WP-CLI per Infobit Page Cache.
     */
    class Infobit_Cache_CLI {

        /**
         * Svuota tutta la cache.
         *
         * ## EXAMPLES
         *     wp infobit-cache clear
         */
        public function clear() {
            $plugin = new Infobit_Page_Cache();
            $count  = $plugin->clear_all();
            WP_CLI::success( sprintf( 'Cache svuotata. %d file eliminati.', $count ) );
        }

        /**
         * Mostra statistiche della cache.
         *
         * ## EXAMPLES
         *     wp infobit-cache stats
         */
        public function stats() {
            $plugin = new Infobit_Page_Cache();
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

        private function format_bytes( $bytes ) {
            if ( $bytes === 0 ) return '0 B';
            $units = array( 'B', 'KB', 'MB', 'GB' );
            $i     = floor( log( $bytes, 1024 ) );
            return round( $bytes / pow( 1024, $i ), 2 ) . ' ' . $units[ $i ];
        }
    }

    WP_CLI::add_command( 'infobit-cache', 'Infobit_Cache_CLI' );
}
