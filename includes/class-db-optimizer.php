<?php
/**
 * Open Cache Manager - Database Optimizer
 *
 * Analizza la configurazione corrente di MariaDB/MySQL e fornisce
 * raccomandazioni ottimizzate per WooCommerce con cataloghi di grandi dimensioni.
 *
 * @package Open_Cache_Manager
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OCM_DB_Optimizer {

    /**
     * Variabili correnti del database.
     *
     * @var array
     */
    private $variables = array();

    /**
     * Stato globale del database.
     *
     * @var array
     */
    private $status = array();

    /**
     * RAM totale del server in bytes.
     *
     * @var int
     */
    private $total_ram = 0;

    /**
     * Costruttore: carica variabili e stato.
     */
    public function __construct() {
        $this->load_variables();
        $this->load_status();
        $this->detect_ram();
    }

    /**
     * Carica le variabili di configurazione del database.
     */
    private function load_variables() {
        global $wpdb;
        $results = $wpdb->get_results( 'SHOW VARIABLES', ARRAY_A );
        if ( $results ) {
            foreach ( $results as $row ) {
                $this->variables[ $row['Variable_name'] ] = $row['Value'];
            }
        }
    }

    /**
     * Carica lo stato globale del database.
     */
    private function load_status() {
        global $wpdb;
        $results = $wpdb->get_results( 'SHOW GLOBAL STATUS', ARRAY_A );
        if ( $results ) {
            foreach ( $results as $row ) {
                $this->status[ $row['Variable_name'] ] = $row['Value'];
            }
        }
    }

    /**
     * Rileva la RAM totale del server.
     */
    private function detect_ram() {
        if ( PHP_OS_FAMILY === 'Linux' && is_readable( '/proc/meminfo' ) ) {
            $meminfo = @file_get_contents( '/proc/meminfo' );
            if ( $meminfo && preg_match( '/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m ) ) {
                $this->total_ram = (int) $m[1] * 1024; // Convert kB to bytes
            }
        }
        if ( $this->total_ram === 0 ) {
            // Fallback: prova con shell
            $mem = @shell_exec( 'free -b 2>/dev/null | awk \'/^Mem:/{print $2}\'' );
            if ( $mem ) {
                $this->total_ram = (int) trim( $mem );
            }
        }
    }

    /**
     * Restituisce un valore di variabile del database.
     *
     * @param string $name Nome della variabile.
     * @return string|null
     */
    public function get_var( $name ) {
        return isset( $this->variables[ $name ] ) ? $this->variables[ $name ] : null;
    }

    /**
     * Restituisce un valore di stato del database.
     *
     * @param string $name Nome della variabile di stato.
     * @return string|null
     */
    public function get_status( $name ) {
        return isset( $this->status[ $name ] ) ? $this->status[ $name ] : null;
    }

    /**
     * Restituisce la versione del database.
     *
     * @return string
     */
    public function get_db_version() {
        return $this->get_var( 'version' ) ?: 'N/A';
    }

    /**
     * Controlla se il database è MariaDB.
     *
     * @return bool
     */
    public function is_mariadb() {
        $version = $this->get_var( 'version' );
        $comment = $this->get_var( 'version_comment' );
        return ( $version && stripos( $version, 'mariadb' ) !== false )
            || ( $comment && stripos( $comment, 'mariadb' ) !== false );
    }

    /**
     * Restituisce la RAM totale del server in formato leggibile.
     *
     * @return string
     */
    public function get_total_ram_formatted() {
        if ( $this->total_ram === 0 ) {
            return 'Non rilevabile';
        }
        return $this->format_bytes( $this->total_ram );
    }

    /**
     * Restituisce la RAM totale in bytes.
     *
     * @return int
     */
    public function get_total_ram() {
        return $this->total_ram;
    }

    /**
     * Converte un valore di variabile MySQL in bytes.
     *
     * @param string $value Valore con eventuale suffisso (K, M, G).
     * @return int
     */
    private function to_bytes( $value ) {
        if ( is_null( $value ) || $value === '' ) {
            return 0;
        }
        $value  = strtoupper( trim( $value ) );
        $number = (int) $value;

        if ( strpos( $value, 'G' ) !== false ) {
            return $number * 1024 * 1024 * 1024;
        }
        if ( strpos( $value, 'M' ) !== false ) {
            return $number * 1024 * 1024;
        }
        if ( strpos( $value, 'K' ) !== false ) {
            return $number * 1024;
        }

        return (int) $value;
    }

    /**
     * Restituisce le raccomandazioni per tutti i parametri monitorati.
     *
     * @return array Array di sezioni, ognuna con parametri e raccomandazioni.
     */
    public function get_recommendations() {
        $ram = $this->total_ram;
        // Se non rilevata, usa 64GB come default (dal profilo produzione del progetto)
        if ( $ram === 0 ) {
            $ram = 64 * 1024 * 1024 * 1024;
        }

        $ram_gb = $ram / ( 1024 * 1024 * 1024 );

        $sections = array();

        // =============================================================
        // SEZIONE 1: InnoDB Engine
        // =============================================================
        $innodb = array(
            'title'       => 'InnoDB Engine',
            'description' => 'InnoDB è il motore di storage predefinito. Questi parametri hanno il maggiore impatto sulle prestazioni.',
            'params'      => array(),
        );

        // innodb_buffer_pool_size
        $recommended_bp = (int) ( $ram * 0.70 );
        $innodb['params'][] = array(
            'name'        => 'innodb_buffer_pool_size',
            'current'     => $this->get_var( 'innodb_buffer_pool_size' ),
            'recommended' => $recommended_bp,
            'format'      => 'bytes',
            'description' => 'Memoria allocata per la cache dei dati e degli indici InnoDB. Il parametro più importante per le prestazioni. Raccomandato: 70% della RAM totale per server dedicati al database.',
            'severity'    => 'critical',
            'config_key'  => 'innodb_buffer_pool_size',
        );

        // innodb_buffer_pool_instances
        $bp_gb = $recommended_bp / ( 1024 * 1024 * 1024 );
        $recommended_instances = min( 64, max( 1, (int) $bp_gb ) );
        $innodb['params'][] = array(
            'name'        => 'innodb_buffer_pool_instances',
            'current'     => $this->get_var( 'innodb_buffer_pool_instances' ),
            'recommended' => $recommended_instances,
            'format'      => 'number',
            'description' => 'Numero di istanze del buffer pool. Riduce la contesa dei lock interni. Raccomandato: 1 per ogni GB di buffer pool (max 64).',
            'severity'    => 'warning',
            'config_key'  => 'innodb_buffer_pool_instances',
        );

        // innodb_log_file_size
        $recommended_log = 1024 * 1024 * 1024; // 1GB
        if ( $ram_gb >= 32 ) {
            $recommended_log = 2 * 1024 * 1024 * 1024; // 2GB
        }
        $innodb['params'][] = array(
            'name'        => 'innodb_log_file_size',
            'current'     => $this->get_var( 'innodb_log_file_size' ),
            'recommended' => $recommended_log,
            'format'      => 'bytes',
            'description' => 'Dimensione di ogni file di redo log InnoDB. Log più grandi migliorano le performance in scrittura ma aumentano il tempo di recovery. Raccomandato: 1-2GB per workload WooCommerce.',
            'severity'    => 'critical',
            'config_key'  => 'innodb_log_file_size',
        );

        // innodb_log_buffer_size
        $innodb['params'][] = array(
            'name'        => 'innodb_log_buffer_size',
            'current'     => $this->get_var( 'innodb_log_buffer_size' ),
            'recommended' => 64 * 1024 * 1024, // 64MB
            'format'      => 'bytes',
            'description' => 'Buffer in memoria per il redo log. Valori più alti riducono gli I/O su disco durante le transazioni. Raccomandato: 64MB.',
            'severity'    => 'warning',
            'config_key'  => 'innodb_log_buffer_size',
        );

        // innodb_flush_log_at_trx_commit
        $innodb['params'][] = array(
            'name'        => 'innodb_flush_log_at_trx_commit',
            'current'     => $this->get_var( 'innodb_flush_log_at_trx_commit' ),
            'recommended' => '2',
            'format'      => 'number',
            'description' => 'Controlla quando il log viene scritto su disco. 1 = sicuro (flush a ogni commit), 2 = performante (flush ogni secondo, rischio perdita max 1s di dati). Per WooCommerce il valore 2 offre il miglior compromesso prestazioni/sicurezza.',
            'severity'    => 'critical',
            'config_key'  => 'innodb_flush_log_at_trx_commit',
        );

        // innodb_flush_method
        $innodb['params'][] = array(
            'name'        => 'innodb_flush_method',
            'current'     => $this->get_var( 'innodb_flush_method' ),
            'recommended' => 'O_DIRECT',
            'format'      => 'string',
            'description' => 'Metodo di flush su disco. O_DIRECT evita il double buffering tra InnoDB e il filesystem OS, riducendo l\'uso di RAM e migliorando le performance su Linux.',
            'severity'    => 'warning',
            'config_key'  => 'innodb_flush_method',
        );

        // innodb_io_capacity
        $innodb['params'][] = array(
            'name'        => 'innodb_io_capacity',
            'current'     => $this->get_var( 'innodb_io_capacity' ),
            'recommended' => '10000',
            'format'      => 'number',
            'description' => 'IOPS disponibili per le operazioni di background InnoDB (flush dirty pages, merge change buffer). Per dischi NVMe: 10000+. Per SSD SATA: 2000-5000. Per HDD: 200-400.',
            'severity'    => 'critical',
            'config_key'  => 'innodb_io_capacity',
        );

        // innodb_io_capacity_max
        $innodb['params'][] = array(
            'name'        => 'innodb_io_capacity_max',
            'current'     => $this->get_var( 'innodb_io_capacity_max' ),
            'recommended' => '20000',
            'format'      => 'number',
            'description' => 'IOPS massimi per operazioni InnoDB in situazioni di carico elevato. Per NVMe: 20000+. Dovrebbe essere almeno il doppio di innodb_io_capacity.',
            'severity'    => 'warning',
            'config_key'  => 'innodb_io_capacity_max',
        );

        // innodb_read_io_threads
        $innodb['params'][] = array(
            'name'        => 'innodb_read_io_threads',
            'current'     => $this->get_var( 'innodb_read_io_threads' ),
            'recommended' => '8',
            'format'      => 'number',
            'description' => 'Thread dedicati alle operazioni di lettura I/O. Per server con NVMe e CPU multi-core: 8-16.',
            'severity'    => 'info',
            'config_key'  => 'innodb_read_io_threads',
        );

        // innodb_write_io_threads
        $innodb['params'][] = array(
            'name'        => 'innodb_write_io_threads',
            'current'     => $this->get_var( 'innodb_write_io_threads' ),
            'recommended' => '8',
            'format'      => 'number',
            'description' => 'Thread dedicati alle operazioni di scrittura I/O. Per server con NVMe e CPU multi-core: 8-16.',
            'severity'    => 'info',
            'config_key'  => 'innodb_write_io_threads',
        );

        // innodb_file_per_table
        $innodb['params'][] = array(
            'name'        => 'innodb_file_per_table',
            'current'     => $this->get_var( 'innodb_file_per_table' ),
            'recommended' => 'ON',
            'format'      => 'onoff',
            'description' => 'Ogni tabella ha il proprio file .ibd. Consente il recupero dello spazio con OPTIMIZE TABLE e una migliore gestione dello storage.',
            'severity'    => 'warning',
            'config_key'  => 'innodb_file_per_table',
        );

        // innodb_buffer_pool_dump_at_shutdown
        $innodb['params'][] = array(
            'name'        => 'innodb_buffer_pool_dump_at_shutdown',
            'current'     => $this->get_var( 'innodb_buffer_pool_dump_at_shutdown' ),
            'recommended' => 'ON',
            'format'      => 'onoff',
            'description' => 'Salva lo stato del buffer pool allo shutdown. Combinato con load_at_startup, permette un warmup rapido dopo il riavvio del database, evitando il calo di prestazioni post-reboot.',
            'severity'    => 'warning',
            'config_key'  => 'innodb_buffer_pool_dump_at_shutdown',
        );

        // innodb_buffer_pool_load_at_startup
        $innodb['params'][] = array(
            'name'        => 'innodb_buffer_pool_load_at_startup',
            'current'     => $this->get_var( 'innodb_buffer_pool_load_at_startup' ),
            'recommended' => 'ON',
            'format'      => 'onoff',
            'description' => 'Ricarica lo stato del buffer pool all\'avvio. Essenziale per evitare il problema del buffer pool freddo dopo un riavvio del server o del database.',
            'severity'    => 'warning',
            'config_key'  => 'innodb_buffer_pool_load_at_startup',
        );

        // innodb_open_files
        $innodb['params'][] = array(
            'name'        => 'innodb_open_files',
            'current'     => $this->get_var( 'innodb_open_files' ),
            'recommended' => '4000',
            'format'      => 'number',
            'description' => 'Numero massimo di file .ibd aperti contemporaneamente. Per WooCommerce con molte tabelle (prodotti, ordini, meta): 4000+.',
            'severity'    => 'info',
            'config_key'  => 'innodb_open_files',
        );

        $sections[] = $innodb;

        // =============================================================
        // SEZIONE 2: Connessioni e Thread
        // =============================================================
        $connections = array(
            'title'       => 'Connessioni e Thread',
            'description' => 'Gestione delle connessioni client al database. Parametri importanti per la scalabilità sotto carico.',
            'params'      => array(),
        );

        $connections['params'][] = array(
            'name'        => 'max_connections',
            'current'     => $this->get_var( 'max_connections' ),
            'recommended' => '200',
            'format'      => 'number',
            'description' => 'Numero massimo di connessioni simultanee. Troppo alto spreca RAM (ogni connessione usa ~10MB), troppo basso causa errori "Too many connections". Per WooCommerce: 150-300.',
            'severity'    => 'warning',
            'config_key'  => 'max_connections',
        );

        $connections['params'][] = array(
            'name'        => 'thread_cache_size',
            'current'     => $this->get_var( 'thread_cache_size' ),
            'recommended' => '32',
            'format'      => 'number',
            'description' => 'Thread riciclati per le nuove connessioni. Evita il costo di creazione/distruzione thread. Raccomandato: 16-64.',
            'severity'    => 'info',
            'config_key'  => 'thread_cache_size',
        );

        $connections['params'][] = array(
            'name'        => 'wait_timeout',
            'current'     => $this->get_var( 'wait_timeout' ),
            'recommended' => '300',
            'format'      => 'number',
            'description' => 'Secondi prima che una connessione inattiva venga chiusa. Il default (28800 = 8 ore) è troppo alto. Ridurre a 300s libera connessioni inutilizzate.',
            'severity'    => 'info',
            'config_key'  => 'wait_timeout',
        );

        $connections['params'][] = array(
            'name'        => 'interactive_timeout',
            'current'     => $this->get_var( 'interactive_timeout' ),
            'recommended' => '300',
            'format'      => 'number',
            'description' => 'Come wait_timeout ma per connessioni interattive (mysql CLI). Raccomandato: stesso valore di wait_timeout.',
            'severity'    => 'info',
            'config_key'  => 'interactive_timeout',
        );

        $sections[] = $connections;

        // =============================================================
        // SEZIONE 3: Tabelle e Buffer
        // =============================================================
        $buffers = array(
            'title'       => 'Tabelle e Buffer',
            'description' => 'Configurazione delle cache delle tabelle e dei buffer per le operazioni di sorting e join.',
            'params'      => array(),
        );

        $buffers['params'][] = array(
            'name'        => 'table_open_cache',
            'current'     => $this->get_var( 'table_open_cache' ),
            'recommended' => '4000',
            'format'      => 'number',
            'description' => 'Numero di tabelle aperte cached. WooCommerce usa centinaia di tabelle (prodotti, meta, ordini, tassonomie). Un valore basso causa rallentamenti per riapertura continua.',
            'severity'    => 'critical',
            'config_key'  => 'table_open_cache',
        );

        $buffers['params'][] = array(
            'name'        => 'table_definition_cache',
            'current'     => $this->get_var( 'table_definition_cache' ),
            'recommended' => '2000',
            'format'      => 'number',
            'description' => 'Cache delle definizioni delle tabelle (schema). WooCommerce con plugin aggiuntivi può avere 200+ tabelle. Raccomandato: 2000+.',
            'severity'    => 'warning',
            'config_key'  => 'table_definition_cache',
        );

        $buffers['params'][] = array(
            'name'        => 'tmp_table_size',
            'current'     => $this->get_var( 'tmp_table_size' ),
            'recommended' => 256 * 1024 * 1024, // 256MB
            'format'      => 'bytes',
            'description' => 'Dimensione massima delle tabelle temporanee in memoria. Se superata, MySQL crea tabelle su disco (molto più lento). Per query complesse WooCommerce: 256MB.',
            'severity'    => 'warning',
            'config_key'  => 'tmp_table_size',
        );

        $buffers['params'][] = array(
            'name'        => 'max_heap_table_size',
            'current'     => $this->get_var( 'max_heap_table_size' ),
            'recommended' => 256 * 1024 * 1024, // 256MB
            'format'      => 'bytes',
            'description' => 'Dimensione massima delle tabelle MEMORY. Deve essere uguale a tmp_table_size. Il valore effettivo delle tabelle temporanee è il MINIMO tra i due.',
            'severity'    => 'warning',
            'config_key'  => 'max_heap_table_size',
        );

        $buffers['params'][] = array(
            'name'        => 'join_buffer_size',
            'current'     => $this->get_var( 'join_buffer_size' ),
            'recommended' => 4 * 1024 * 1024, // 4MB
            'format'      => 'bytes',
            'description' => 'Buffer per JOIN senza indici. Allocato per-connessione. Non esagerare: con 200 connessioni, 4MB = 800MB totali.',
            'severity'    => 'info',
            'config_key'  => 'join_buffer_size',
        );

        $buffers['params'][] = array(
            'name'        => 'sort_buffer_size',
            'current'     => $this->get_var( 'sort_buffer_size' ),
            'recommended' => 4 * 1024 * 1024, // 4MB
            'format'      => 'bytes',
            'description' => 'Buffer per operazioni ORDER BY. Allocato per-connessione. Per query di ordinamento WooCommerce (prezzo, popolarità): 4MB.',
            'severity'    => 'info',
            'config_key'  => 'sort_buffer_size',
        );

        $buffers['params'][] = array(
            'name'        => 'read_buffer_size',
            'current'     => $this->get_var( 'read_buffer_size' ),
            'recommended' => 2 * 1024 * 1024, // 2MB
            'format'      => 'bytes',
            'description' => 'Buffer per scansioni sequenziali delle tabelle. Per-connessione. Per cataloghi WooCommerce grandi: 2MB.',
            'severity'    => 'info',
            'config_key'  => 'read_buffer_size',
        );

        $buffers['params'][] = array(
            'name'        => 'read_rnd_buffer_size',
            'current'     => $this->get_var( 'read_rnd_buffer_size' ),
            'recommended' => 4 * 1024 * 1024, // 4MB
            'format'      => 'bytes',
            'description' => 'Buffer per letture ordinate dopo un sort. Migliora le performance delle query con ORDER BY su grandi dataset.',
            'severity'    => 'info',
            'config_key'  => 'read_rnd_buffer_size',
        );

        $sections[] = $buffers;

        // =============================================================
        // SEZIONE 4: Query Cache
        // =============================================================
        $qcache = array(
            'title'       => 'Query Cache',
            'description' => 'La Query Cache memorizza i risultati delle query SELECT. Per WooCommerce con aggiornamenti frequenti (import bulk, ordini) si consiglia di disabilitarla per evitare contesa e invalidazioni continue.',
            'params'      => array(),
        );

        $qcache['params'][] = array(
            'name'        => 'query_cache_type',
            'current'     => $this->get_var( 'query_cache_type' ),
            'recommended' => 'OFF',
            'format'      => 'string',
            'description' => 'OFF = disabilitata (raccomandato per WooCommerce). ON = abilitata. DEMAND = solo con SQL_CACHE hint. Con import bulk di 4000 prodotti/ora, la query cache causa più invalidazioni che benefici.',
            'severity'    => 'warning',
            'config_key'  => 'query_cache_type',
        );

        $qcache['params'][] = array(
            'name'        => 'query_cache_size',
            'current'     => $this->get_var( 'query_cache_size' ),
            'recommended' => '0',
            'format'      => 'number',
            'description' => 'Memoria allocata per la query cache. Se query_cache_type = OFF, impostare a 0 per non sprecare RAM. Anche un valore di 1MB alloca strutture interne inutilmente.',
            'severity'    => 'info',
            'config_key'  => 'query_cache_size',
        );

        $sections[] = $qcache;

        // =============================================================
        // SEZIONE 5: Logging e Monitoraggio
        // =============================================================
        $logging = array(
            'title'       => 'Logging e Monitoraggio',
            'description' => 'Logging delle query lente per identificare e ottimizzare le query problematiche.',
            'params'      => array(),
        );

        $logging['params'][] = array(
            'name'        => 'slow_query_log',
            'current'     => $this->get_var( 'slow_query_log' ),
            'recommended' => 'ON',
            'format'      => 'onoff',
            'description' => 'Abilita il log delle query lente. Essenziale per identificare query WooCommerce problematiche (ricerche prodotti, filtri, report). Impatto sulle prestazioni: trascurabile.',
            'severity'    => 'warning',
            'config_key'  => 'slow_query_log',
        );

        $logging['params'][] = array(
            'name'        => 'long_query_time',
            'current'     => $this->get_var( 'long_query_time' ),
            'recommended' => '1',
            'format'      => 'number',
            'description' => 'Soglia in secondi per considerare una query "lenta". Il default (10s) è troppo permissivo. Per WooCommerce: 1-2 secondi.',
            'severity'    => 'warning',
            'config_key'  => 'long_query_time',
        );

        $logging['params'][] = array(
            'name'        => 'slow_query_log_file',
            'current'     => $this->get_var( 'slow_query_log_file' ),
            'recommended' => null,
            'format'      => 'string',
            'description' => 'Percorso del file di log delle query lente. Solo informativo.',
            'severity'    => 'info',
            'config_key'  => null,
        );

        // log_slow_verbosity (MariaDB only)
        if ( $this->is_mariadb() ) {
            $logging['params'][] = array(
                'name'        => 'log_slow_verbosity',
                'current'     => $this->get_var( 'log_slow_verbosity' ),
                'recommended' => 'query_plan',
                'format'      => 'string',
                'description' => 'Dettaglio del log delle query lente (solo MariaDB). query_plan include l\'EXPLAIN della query, utilissimo per il debug.',
                'severity'    => 'info',
                'config_key'  => 'log_slow_verbosity',
            );
        }

        $sections[] = $logging;

        // =============================================================
        // SEZIONE 6: Performance Schema e Varie
        // =============================================================
        $misc = array(
            'title'       => 'Performance e Varie',
            'description' => 'Impostazioni aggiuntive per il monitoraggio e l\'ottimizzazione generale.',
            'params'      => array(),
        );

        $misc['params'][] = array(
            'name'        => 'performance_schema',
            'current'     => $this->get_var( 'performance_schema' ),
            'recommended' => 'ON',
            'format'      => 'onoff',
            'description' => 'Abilita il Performance Schema per il monitoraggio dettagliato delle operazioni del database. Usa circa 200-400MB di RAM, ma fornisce dati preziosi per l\'ottimizzazione.',
            'severity'    => 'info',
            'config_key'  => 'performance_schema',
        );

        $misc['params'][] = array(
            'name'        => 'skip_name_resolve',
            'current'     => $this->get_var( 'skip_name_resolve' ),
            'recommended' => 'ON',
            'format'      => 'onoff',
            'description' => 'Disabilita la risoluzione DNS per le connessioni client. Velocizza le connessioni e previene problemi con DNS lento. Richiede che i GRANT usino IP invece di hostname.',
            'severity'    => 'info',
            'config_key'  => 'skip_name_resolve',
        );

        $misc['params'][] = array(
            'name'        => 'key_buffer_size',
            'current'     => $this->get_var( 'key_buffer_size' ),
            'recommended' => 32 * 1024 * 1024, // 32MB
            'format'      => 'bytes',
            'description' => 'Buffer per indici MyISAM. Poiché WooCommerce usa InnoDB, un valore basso (32MB) è sufficiente. Serve solo per le tabelle di sistema MySQL/MariaDB.',
            'severity'    => 'info',
            'config_key'  => 'key_buffer_size',
        );

        $sections[] = $misc;

        return $sections;
    }

    /**
     * Analizza un singolo parametro e restituisce lo stato.
     *
     * @param array $param Configurazione del parametro.
     * @return array Parametro arricchito con lo stato.
     */
    public function analyze_param( $param ) {
        $current     = $param['current'];
        $recommended = $param['recommended'];
        $format      = $param['format'];

        if ( is_null( $recommended ) || is_null( $current ) ) {
            $param['status']      = 'info';
            $param['status_text'] = 'Info';
            return $param;
        }

        $is_ok = false;

        switch ( $format ) {
            case 'bytes':
                $current_val     = (int) $current;
                $recommended_val = (int) $recommended;
                // Tolleranza del 10%
                $is_ok = $current_val >= ( $recommended_val * 0.9 );
                break;

            case 'number':
                $current_val     = (int) $current;
                $recommended_val = (int) $recommended;
                // Per i numeri, consideriamo "ok" se >= al raccomandato (o uguale per valori come flush_log_at_trx_commit)
                if ( $param['name'] === 'innodb_flush_log_at_trx_commit' ) {
                    $is_ok = ( $current_val === $recommended_val );
                } elseif ( $param['name'] === 'wait_timeout' || $param['name'] === 'interactive_timeout' ) {
                    $is_ok = ( $current_val <= $recommended_val );
                } elseif ( $param['name'] === 'long_query_time' ) {
                    $is_ok = ( (float) $current <= (float) $recommended );
                } elseif ( $param['name'] === 'query_cache_size' ) {
                    $is_ok = ( $current_val <= (int) $recommended );
                } else {
                    $is_ok = ( $current_val >= $recommended_val * 0.9 );
                }
                break;

            case 'string':
                $is_ok = ( strtoupper( trim( $current ) ) === strtoupper( trim( $recommended ) ) );
                // query_cache_type: "OFF" or "0" entrambi validi
                if ( $param['name'] === 'query_cache_type' ) {
                    $is_ok = ( strtoupper( trim( $current ) ) === 'OFF' || trim( $current ) === '0' );
                }
                break;

            case 'onoff':
                $current_on = in_array( strtoupper( trim( $current ) ), array( 'ON', '1', 'YES' ), true );
                $rec_on     = in_array( strtoupper( trim( $recommended ) ), array( 'ON', '1', 'YES' ), true );
                $is_ok      = ( $current_on === $rec_on );
                break;
        }

        if ( $is_ok ) {
            $param['status']      = 'ok';
            $param['status_text'] = 'OK';
        } else {
            $param['status']      = $param['severity'];
            $param['status_text'] = $param['severity'] === 'critical' ? 'Da ottimizzare' : 'Consigliato';
        }

        return $param;
    }

    /**
     * Genera lo snippet di configurazione per my.cnf / 50-server.cnf.
     *
     * @return string Configurazione raccomandata.
     */
    public function generate_config_snippet() {
        $sections = $this->get_recommendations();
        $lines    = array();
        $lines[]  = '[mysqld]';
        $lines[]  = '# Configurazione ottimizzata generata da Open Cache Manager';
        $lines[]  = '# Server: ' . $this->get_total_ram_formatted() . ' RAM | ' . $this->get_db_version();
        $lines[]  = '# Data: ' . date( 'Y-m-d H:i:s' );
        $lines[]  = '';

        foreach ( $sections as $section ) {
            $lines[] = '# --- ' . $section['title'] . ' ---';
            foreach ( $section['params'] as $param ) {
                if ( is_null( $param['config_key'] ) || is_null( $param['recommended'] ) ) {
                    continue;
                }
                $value = $param['recommended'];
                if ( $param['format'] === 'bytes' ) {
                    $value = $this->format_config_bytes( (int) $value );
                } elseif ( $param['format'] === 'onoff' ) {
                    $value = strtoupper( $value ) === 'ON' ? '1' : '0';
                }
                $lines[] = $param['config_key'] . ' = ' . $value;
            }
            $lines[] = '';
        }

        return implode( "\n", $lines );
    }

    /**
     * Calcola statistiche di stato per riepilogo.
     *
     * @return array Conteggio per stato.
     */
    public function get_summary() {
        $sections = $this->get_recommendations();
        $summary  = array(
            'ok'       => 0,
            'critical' => 0,
            'warning'  => 0,
            'info'     => 0,
            'total'    => 0,
        );

        foreach ( $sections as $section ) {
            foreach ( $section['params'] as $param ) {
                $analyzed = $this->analyze_param( $param );
                $summary[ $analyzed['status'] ]++;
                $summary['total']++;
            }
        }

        return $summary;
    }

    /**
     * Calcola la percentuale di utilizzo del buffer pool.
     *
     * @return array Info buffer pool.
     */
    public function get_buffer_pool_usage() {
        $pool_size     = (int) $this->get_var( 'innodb_buffer_pool_size' );
        $pages_total   = (int) $this->get_status( 'Innodb_buffer_pool_pages_total' );
        $pages_free    = (int) $this->get_status( 'Innodb_buffer_pool_pages_free' );
        $pages_data    = (int) $this->get_status( 'Innodb_buffer_pool_pages_data' );
        $read_requests = (int) $this->get_status( 'Innodb_buffer_pool_read_requests' );
        $reads         = (int) $this->get_status( 'Innodb_buffer_pool_reads' );

        $usage_pct = $pages_total > 0 ? round( ( $pages_data / $pages_total ) * 100, 1 ) : 0;
        $hit_rate  = $read_requests > 0 ? round( ( ( $read_requests - $reads ) / $read_requests ) * 100, 2 ) : 0;

        return array(
            'size_formatted' => $this->format_bytes( $pool_size ),
            'usage_pct'      => $usage_pct,
            'hit_rate'       => $hit_rate,
            'pages_total'    => $pages_total,
            'pages_free'     => $pages_free,
            'pages_data'     => $pages_data,
        );
    }

    /**
     * Restituisce informazioni sulle tabelle temporanee.
     *
     * @return array Info tmp tables.
     */
    public function get_tmp_table_stats() {
        $created_tmp_tables      = (int) $this->get_status( 'Created_tmp_tables' );
        $created_tmp_disk_tables = (int) $this->get_status( 'Created_tmp_disk_tables' );

        $disk_pct = $created_tmp_tables > 0
            ? round( ( $created_tmp_disk_tables / $created_tmp_tables ) * 100, 1 )
            : 0;

        return array(
            'memory_tables' => $created_tmp_tables - $created_tmp_disk_tables,
            'disk_tables'   => $created_tmp_disk_tables,
            'disk_pct'      => $disk_pct,
        );
    }

    /**
     * Restituisce informazioni sulle connessioni.
     *
     * @return array Info connessioni.
     */
    public function get_connection_stats() {
        $max_connections     = (int) $this->get_var( 'max_connections' );
        $max_used            = (int) $this->get_status( 'Max_used_connections' );
        $threads_connected   = (int) $this->get_status( 'Threads_connected' );
        $threads_running     = (int) $this->get_status( 'Threads_running' );
        $aborted_connects    = (int) $this->get_status( 'Aborted_connects' );
        $connections_total   = (int) $this->get_status( 'Connections' );

        $usage_pct = $max_connections > 0 ? round( ( $max_used / $max_connections ) * 100, 1 ) : 0;

        return array(
            'max_connections'   => $max_connections,
            'max_used'          => $max_used,
            'current_connected' => $threads_connected,
            'current_running'   => $threads_running,
            'aborted'           => $aborted_connects,
            'total'             => $connections_total,
            'usage_pct'         => $usage_pct,
        );
    }

    /**
     * Formatta bytes in formato leggibile.
     *
     * @param int $bytes Numero di byte.
     * @return string
     */
    public function format_bytes( $bytes ) {
        if ( $bytes === 0 ) {
            return '0 B';
        }
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $i     = floor( log( $bytes, 1024 ) );
        return round( $bytes / pow( 1024, $i ), 2 ) . ' ' . $units[ (int) $i ];
    }

    /**
     * Formatta un valore in formato per my.cnf (es. 45G, 256M, 4M).
     *
     * @param int $bytes Numero di byte.
     * @return string
     */
    private function format_config_bytes( $bytes ) {
        if ( $bytes >= 1024 * 1024 * 1024 && $bytes % ( 1024 * 1024 * 1024 ) === 0 ) {
            return ( $bytes / ( 1024 * 1024 * 1024 ) ) . 'G';
        }
        if ( $bytes >= 1024 * 1024 && $bytes % ( 1024 * 1024 ) === 0 ) {
            return ( $bytes / ( 1024 * 1024 ) ) . 'M';
        }
        if ( $bytes >= 1024 && $bytes % 1024 === 0 ) {
            return ( $bytes / 1024 ) . 'K';
        }
        return (string) $bytes;
    }

    /**
     * Formatta il valore raccomandato per la visualizzazione.
     *
     * @param array $param Configurazione del parametro.
     * @return string
     */
    public function format_recommended( $param ) {
        if ( is_null( $param['recommended'] ) ) {
            return '-';
        }
        if ( $param['format'] === 'bytes' ) {
            return $this->format_bytes( (int) $param['recommended'] );
        }
        return (string) $param['recommended'];
    }

    /**
     * Formatta il valore corrente per la visualizzazione.
     *
     * @param array $param Configurazione del parametro.
     * @return string
     */
    public function format_current( $param ) {
        if ( is_null( $param['current'] ) ) {
            return 'Non impostato';
        }
        if ( $param['format'] === 'bytes' ) {
            return $this->format_bytes( (int) $param['current'] );
        }
        return (string) $param['current'];
    }
}
