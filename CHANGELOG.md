# Changelog - Open Cache Manager

Tutte le modifiche rilevanti al plugin sono documentate in questo file.

---

## [2.0.0] - 2026-02-21

### Rinominazione e ristrutturazione
- **Nuovo nome**: da "Infobit Page Cache" a **Open Cache Manager**
- **Autore**: Starter Dev Labs
- **File principale**: `open-cache-manager.php` (era `infobit-page-cache.php`)
- **Menu admin**: nuovo menu top-level "Cache Manager" con sottopagine
- **Classe principale**: `Open_Cache_Manager` (era `Infobit_Page_Cache`)
- **Directory cache**: `wp-content/cache/ocm-pages/` (era `infobit-pages/`)
- **Opzione DB**: `open_cache_manager` (era `infobit_page_cache`)
- **Hook pubblici**: prefisso `ocm_cache_` (era `infobit_cache_`)
- **WP-CLI**: `wp open-cache` (era `wp infobit-cache`)
- **Header HTTP**: `X-OCM-Cache` (era `X-Infobit-Cache`)
- **Text domain**: `open-cache-manager`
- **Costanti**: `OCM_VERSION`, `OCM_PLUGIN_DIR`, `OCM_PLUGIN_FILE`

### Nuove funzionalità
- **DB Optimizer**: nuova pagina admin per l'analisi e ottimizzazione della configurazione MariaDB/MySQL
  - Rilevamento automatico RAM server e versione database
  - Analisi di 30+ parametri: InnoDB, connessioni, buffer, query cache, logging
  - Dashboard visuale con indicatori colorati (OK/Consigliato/Da ottimizzare)
  - Metriche real-time: utilizzo buffer pool, hit rate, tabelle temporanee su disco, connessioni
  - Generazione automatica snippet my.cnf con valori ottimizzati
  - Note e best practices per ogni parametro
- **WP-CLI db-check**: `wp open-cache db-check` per analisi database da terminale
- **Menu admin dedicato**: menu top-level "Cache Manager" con icona dashicons-performance
- **Struttura modulare**: directory `includes/` con classi separate
- **Costanti plugin**: `OCM_VERSION`, `OCM_PLUGIN_DIR`, `OCM_PLUGIN_FILE` per accesso globale

### Parametri database analizzati
- **InnoDB Engine**: buffer_pool_size, buffer_pool_instances, log_file_size, log_buffer_size, flush_log_at_trx_commit, flush_method, io_capacity, io_capacity_max, read/write_io_threads, file_per_table, buffer_pool_dump/load, open_files
- **Connessioni**: max_connections, thread_cache_size, wait_timeout, interactive_timeout
- **Buffer e Tabelle**: table_open_cache, table_definition_cache, tmp_table_size, max_heap_table_size, join_buffer_size, sort_buffer_size, read_buffer_size, read_rnd_buffer_size
- **Query Cache**: query_cache_type, query_cache_size (raccomandazione: OFF per WooCommerce)
- **Logging**: slow_query_log, long_query_time, slow_query_log_file, log_slow_verbosity (MariaDB)
- **Varie**: performance_schema, skip_name_resolve, key_buffer_size

### Note di migrazione da v1.2.0 (Infobit Page Cache)
- La vecchia directory `wp-content/cache/infobit-pages/` non viene più utilizzata
- I vecchi hook `infobit_cache_*` non funzionano più; aggiornare a `ocm_cache_*`
- L'opzione DB `infobit_page_cache` non viene più letta; le impostazioni vanno riconfigurate
- Il vecchio WP-CLI `wp infobit-cache` non è più disponibile; usare `wp open-cache`
- Dopo l'aggiornamento, rimuovere manualmente la vecchia cache:
  ```bash
  rm -rf wp-content/cache/infobit-pages/
  ```

---

## [1.2.0] - 2026-02-21

### Nuove funzionalità
- **Solo GZIP**: il plugin salva ora solo file `.gz` (prima salvava sia `.html` che `.gz`). Dimezza lo spazio disco (da ~2.5GB a ~1.2GB per ~9800 pagine). Il browser riceve il file gzip direttamente con `Content-Encoding: gzip`. Fallback con `gzdecode()` per i rarissimi client senza supporto gzip.
- **Cache per clienti loggati**: i clienti WooCommerce (ruolo customer/subscriber) ora vedono pagine cached quando navigano il catalogo. Solo gli utenti con accesso wp-admin (admin, editor, shop_manager) sono esclusi dalla cache. La distinzione avviene tramite cookie: `wp-settings-time-*` è presente solo per utenti admin.
- **Auto-aggiornamento drop-in**: quando il plugin viene aggiornato, `advanced-cache.php` in `wp-content/` si aggiorna automaticamente al primo caricamento admin. Non serve più copiarlo manualmente.
- **Esclusioni custom sincronizzate**: gli URL esclusi configurati nella pagina impostazioni vengono scritti su file `.excluded_urls` che `advanced-cache.php` legge direttamente (non ha accesso al database WordPress).

### Fix
- `cleanup_expired()` ora preserva anche il file `.excluded_urls` oltre a `.active`
- `clear_all()` preserva `.excluded_urls`
- `admin_notices()` usa `wp_kses_post()` per supportare HTML nei messaggi di errore
- `invalidate_url()` pulisce anche eventuali vecchi file `.html` legacy dalla v1.0-1.1
- Aggiunto `/compare` alla lista hardcoded degli URL esclusi
- `get_stats()` conta ora i file `.gz` invece di `.html`

---

## [1.1.0] - 2026-02-19

### Nuove funzionalità
- **Gestione sicura wp-config.php**: backup automatico prima di ogni modifica, validazione pre e post scrittura, ripristino automatico se il file risulta corrotto dopo la scrittura
- **File uninstall.php**: pulizia completa quando il plugin viene eliminato
- **Disattivazione completa**: rimuove advanced-cache.php da wp-content e la directory cache con tutte le sottocartelle

### Fix
- **Bug critico**: `clear_all()` eliminava il file `.active` durante lo svuotamento cache, disabilitando silenziosamente il plugin. Ora `.active` è preservato
- `rmdir()` con error suppression per directory non vuote
- Aggiunta `remove_cache_directory()` per pulizia ricorsiva completa alla disattivazione

---

## [1.0.0] - 2026-02-19

### Release iniziale
- Cache basata su file HTML statici con pre-compressione gzip
- TTL configurabile (default 1 ora)
- Invalidazione intelligente WooCommerce
- Modalità bulk per import massivi
- Admin bar, pagina impostazioni, WP-CLI
- Cron giornaliero per pulizia file scaduti
- Scrittura atomica (tmp + rename)

---

## Contesto tecnico

### Ambiente di produzione
- **Sito**: infobitcomputer.it (WooCommerce, 45.000+ prodotti, 15 fornitori)
- **Server**: 62GB RAM, NVMe, Nginx+Apache (Plesk), MariaDB 10.6+
- **Stack cache**: Cloudflare CDN (free) → Nginx proxy cache → Open Cache Manager → Redis Object Cache → MariaDB
- **Risultati**: TTFB da 2.3s a 0.05-0.18s (miglioramento 15-45x)

### Ottimizzazioni server correlate (ora verificabili dal DB Optimizer)
- **MariaDB tuning**: innodb_buffer_pool_size=45GB, innodb_flush_log_at_trx_commit=2, innodb_log_file_size=1GB, innodb_io_capacity=10000, table_open_cache=4000, slow_query_log attivo
- **Nginx**: gzip on, expires headers per immagini (1y), CSS/JS (1M), font (1y)
- **Redis**: object cache su database 1 con socket Unix
