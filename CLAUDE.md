# CLAUDE.md - Contesto progetto per Claude Code

## Panoramica

Questo è il plugin **Open Cache Manager** v2.1.3, un cache manager WordPress con page cache e ottimizzazione database, sviluppato per siti WooCommerce di grandi dimensioni (45.000+ prodotti, 15 fornitori, aggiornamenti bulk fino a 4.000 prodotti/ora).

Precedentemente noto come "Infobit Page Cache", il plugin è stato rinominato e ristrutturato nella v2.0.0 con l'aggiunta del DB Optimizer.

Il plugin potrà essere ulteriormente unificato con **Open Redis Manager** (Wp-redis-manager) nel futuro **WP Open Cache Manager**.

## Struttura file attuale

```
Open-Cache-Manager/
├── open-cache-manager.php       # Plugin principale - Classe Open_Cache_Manager
├── advanced-cache.php           # Drop-in WordPress (caricato prima di WP quando WP_CACHE=true)
├── uninstall.php                # Pulizia completa all'eliminazione del plugin
├── includes/
│   └── class-db-optimizer.php   # Engine analisi e ottimizzazione database (OCM_DB_Optimizer)
├── CHANGELOG.md                 # Storico completo delle modifiche
├── README.md                    # Documentazione utente
└── CLAUDE.md                    # Questo file
```

## Come funziona

### Page Cache - Flusso di una richiesta

```
Browser → Cloudflare → Nginx proxy → advanced-cache.php → [HIT: serve .gz] / [MISS: WordPress → ob_start → salva .gz]
```

1. `advanced-cache.php` viene eseguito PRIMA di WordPress (grazie a `WP_CACHE=true`)
2. Controlla: plugin attivo? (.active), metodo GET?, utente admin?, URL escluso?
3. Se esiste `cache/ocm-pages/XX/hash.gz` valido → serve direttamente, exit
4. Se MISS → avvia `ob_start()`, WordPress genera la pagina, callback salva il gzip

### DB Optimizer - Funzionamento

1. Rileva RAM del server da `/proc/meminfo`
2. Legge variabili correnti con `SHOW VARIABLES` e stato con `SHOW GLOBAL STATUS`
3. Calcola valori raccomandati basandosi su: RAM (70% per buffer pool), tipo storage (NVMe), workload WooCommerce
4. Confronta con i valori attuali e genera report con indicatori colorati
5. Genera snippet my.cnf pronto da copiare

### Sistema di sicurezza a 3 livelli

1. **File `.active`** in `cache/ocm-pages/` - se non esiste, il drop-in non fa nulla
2. **Marker nel file** - `advanced-cache.php` contiene "Open Cache Manager", viene rimosso/aggiornato solo se il marker è presente
3. **Gestione sicura wp-config.php** - backup → modifica → validazione → ripristino se corrotto

### Invalidazione

- **Singolo prodotto**: invalida pagina prodotto + homepage + shop + categorie prodotto
- **Modalità bulk**: accumula ID in transient, al `bulk_end` se > soglia svuota tutto, altrimenti invalida singoli
- **Globale**: cambio tema, menu, widget → svuota tutto

### File creati dal plugin

| File | Posizione | Scopo |
|------|-----------|-------|
| `advanced-cache.php` | `wp-content/` | Drop-in WordPress |
| `.active` | `wp-content/cache/ocm-pages/` | Flag attivazione |
| `.excluded_urls` | `wp-content/cache/ocm-pages/` | URL esclusi custom per il drop-in |
| `*.gz` | `wp-content/cache/ocm-pages/XX/` | File cache gzip (XX = primi 2 char dell'hash) |
| `wp-config.php.ocm-bak` | `ABSPATH` | Backup temporaneo durante modifiche wp-config |

### Opzioni database

- Chiave: `open_cache_manager`
- Valori: `ttl`, `excluded_urls`, `debug`, `bulk_threshold`, `preload_urls`

### Transients

- `ocm_cache_notice` - messaggi admin temporanei
- `ocm_cache_bulk_ids` - ID prodotti accumulati durante bulk

### Cron

- `ocm_cache_cleanup` - pulizia giornaliera file scaduti

### WP-CLI

- `wp open-cache clear` - svuota cache
- `wp open-cache stats` - mostra statistiche
- `wp open-cache db-check` - analizza configurazione database

### Hook pubblici

- `ocm_cache_bulk_start` - attiva modalità bulk
- `ocm_cache_bulk_end` - termina bulk e processa
- `ocm_cache_invalidate_product` - invalida singolo prodotto
- `ocm_cache_invalidate_all` - svuota tutta la cache

## Ambiente di produzione

```
Server: 62GB RAM, NVMe
OS: Ubuntu 24
Web: Nginx (reverse proxy :443) → Apache (:7081)
Panel: Plesk
DB: MariaDB 10.6+ (innodb_buffer_pool_size=45GB)
Cache: Redis (socket Unix, database 1, prefisso 8VbUoIp_)
CDN: Cloudflare (free plan)
PHP: 8.3 (mod_proxy_fcgi)
CMS: WordPress + WooCommerce + tema Woodmart
```

## Configurazione Redis correlata (da wp-config.php)

```php
define( 'SRC_REDIS_HOST', '127.0.0.1' );
define( 'SRC_REDIS_PORT', 6379 );
define( 'SRC_REDIS_SOCKET', '/var/run/redis/redis-server.sock' );
define( 'SRC_REDIS_DATABASE', 1 );
define( 'SRC_REDIS_PREFIX', '8VbUoIp_' );
```

## DB Optimizer - Parametri monitorati

### InnoDB Engine
- `innodb_buffer_pool_size` - 70% RAM (critico)
- `innodb_buffer_pool_instances` - 1 per GB di buffer pool
- `innodb_log_file_size` - 1-2GB per workload WooCommerce
- `innodb_log_buffer_size` - 64MB
- `innodb_flush_log_at_trx_commit` - 2 per WooCommerce (critico)
- `innodb_flush_method` - O_DIRECT su Linux
- `innodb_io_capacity` - 10000+ per NVMe (critico)
- `innodb_io_capacity_max` - 20000+ per NVMe
- `innodb_read_io_threads` / `innodb_write_io_threads` - 8-16
- `innodb_file_per_table` - ON
- `innodb_buffer_pool_dump_at_shutdown` / `innodb_buffer_pool_load_at_startup` - ON (warmup)
- `innodb_open_files` - 4000+

### Connessioni e Thread
- `max_connections` - 150-300
- `thread_cache_size` - 16-64
- `wait_timeout` / `interactive_timeout` - 300s

### Buffer e Tabelle
- `table_open_cache` - 4000+ (critico per WooCommerce)
- `table_definition_cache` - 2000+
- `tmp_table_size` / `max_heap_table_size` - 256MB
- `join_buffer_size` - 4MB (per-connessione)
- `sort_buffer_size` - 4MB (per-connessione)
- `read_buffer_size` - 2MB
- `read_rnd_buffer_size` - 4MB

### Query Cache
- `query_cache_type` - OFF per WooCommerce (troppe invalidazioni con import bulk)
- `query_cache_size` - 0

### Logging
- `slow_query_log` - ON
- `long_query_time` - 1-2s
- `log_slow_verbosity` - query_plan (MariaDB)

## Piano di unificazione con Open Redis Manager

### Struttura target futura
```
wp-open-cache-manager/
├── wp-open-cache-manager.php      # Plugin principale, bootstrap
├── advanced-cache.php             # Drop-in page cache
├── object-cache.php               # Drop-in Redis object cache (da Open Redis Manager)
├── includes/
│   ├── class-page-cache.php       # Classe page cache
│   ├── class-redis-cache.php      # Classe Redis (da Open Redis Manager)
│   ├── class-db-optimizer.php     # Ottimizzazione database (già implementato)
│   ├── class-cache-manager.php    # Coordinamento: flush combinato, statistiche
│   └── class-admin-ui.php         # Dashboard admin unificata
├── uninstall.php
├── CHANGELOG.md
└── README.md
```

### Cose da integrare
1. **Flush Redis al deactivate** del page cache per evitare dati inconsistenti
2. **Dashboard unificata**: tab Page Cache + tab Redis + tab DB Optimizer + panoramica combinata
3. **WP-CLI unificato**: `wp cache clear --page`, `wp cache clear --redis`, `wp cache clear --all`, `wp cache stats`
4. **Coordinamento invalidazione**: opzione per flush Redis quando si svuota page cache
5. **Auto-aggiornamento**: entrambi i drop-in (advanced-cache.php e object-cache.php)

### Attenzione durante l'unificazione
- Il drop-in `advanced-cache.php` viene eseguito PRIMA di WordPress, non ha accesso a classi/funzioni WP
- Il drop-in `object-cache.php` sostituisce il sistema di cache oggetti nativo di WP
- I due drop-in devono funzionare indipendentemente (se uno fallisce, l'altro continua)
- Le costanti Redis in wp-config.php devono rimanere compatibili con il formato `SRC_REDIS_*` esistente

## Problemi noti e lezioni apprese

1. **`clear_all()` cancellava `.active`**: il file flag era nella stessa directory dei file cache. Risolto con skip esplicito per `.active` e `.excluded_urls`
2. **wp-config.php corrotto**: regex di rimozione inaffidabile. Risolto con approccio riga-per-riga + backup + validazione
3. **Redis cache corrotta dopo disattivazione**: dati inconsistenti bloccavano il sito. Soluzione: flush Redis. Da integrare nel plugin unificato
4. **advanced-cache.php non aggiornato**: non si aggiornava con il plugin. Risolto con `maybe_update_dropin()` su `admin_init`
5. **Esclusioni custom non lette dal drop-in**: il drop-in non ha accesso al DB. Risolto con file `.excluded_urls` sincronizzato
6. **Buffer pool MariaDB crollato dopo riavvio**: valore non esplicito nel .cnf. Ora verificabile dal DB Optimizer (parametri dump_at_shutdown / load_at_startup)
