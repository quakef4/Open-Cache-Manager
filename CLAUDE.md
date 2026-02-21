# CLAUDE.md - Contesto progetto per Claude Code

## Panoramica

Questo è il plugin **Infobit Page Cache** v1.2.0, un page cache WordPress custom sviluppato per il sito WooCommerce infobitcomputer.it (45.000+ prodotti, 15 fornitori, aggiornamenti bulk fino a 4.000 prodotti/ora).

Il plugin dovrà essere unificato con **Open Redis Manager** (Wp-redis-manager) nel nuovo plugin **WP Open Cache Manager**.

## Struttura file attuale

```
infobit-page-cache/
├── infobit-page-cache.php   # Plugin principale (1095 righe) - Classe Infobit_Page_Cache
├── advanced-cache.php       # Drop-in WordPress (caricato prima di WP quando WP_CACHE=true)
├── uninstall.php            # Pulizia completa all'eliminazione del plugin
├── CHANGELOG.md             # Storico completo delle modifiche
├── README.md                # Documentazione utente
└── CLAUDE.md                # Questo file
```

## Come funziona

### Flusso di una richiesta

```
Browser → Cloudflare → Nginx proxy → advanced-cache.php → [HIT: serve .gz] / [MISS: WordPress → ob_start → salva .gz]
```

1. `advanced-cache.php` viene eseguito PRIMA di WordPress (grazie a `WP_CACHE=true`)
2. Controlla: plugin attivo? (.active), metodo GET?, utente admin?, URL escluso?
3. Se esiste `cache/infobit-pages/XX/hash.gz` valido → serve direttamente, exit
4. Se MISS → avvia `ob_start()`, WordPress genera la pagina, callback salva il gzip

### Sistema di sicurezza a 3 livelli

1. **File `.active`** in `cache/infobit-pages/` - se non esiste, il drop-in non fa nulla
2. **Marker nel file** - `advanced-cache.php` contiene "Infobit Page Cache", viene rimosso/aggiornato solo se il marker è presente
3. **Gestione sicura wp-config.php** - backup → modifica → validazione → ripristino se corrotto

### Invalidazione

- **Singolo prodotto**: invalida pagina prodotto + homepage + shop + categorie prodotto
- **Modalità bulk**: accumula ID in transient, al `bulk_end` se > soglia svuota tutto, altrimenti invalida singoli
- **Globale**: cambio tema, menu, widget → svuota tutto

### File creati dal plugin

| File | Posizione | Scopo |
|------|-----------|-------|
| `advanced-cache.php` | `wp-content/` | Drop-in WordPress |
| `.active` | `wp-content/cache/infobit-pages/` | Flag attivazione |
| `.excluded_urls` | `wp-content/cache/infobit-pages/` | URL esclusi custom per il drop-in |
| `*.gz` | `wp-content/cache/infobit-pages/XX/` | File cache gzip (XX = primi 2 char dell'hash) |
| `wp-config.php.infobit-bak` | `ABSPATH` | Backup temporaneo durante modifiche wp-config |

### Opzioni database

- Chiave: `infobit_page_cache`
- Valori: `ttl`, `excluded_urls`, `debug`, `bulk_threshold`, `preload_urls`

### Transients

- `infobit_cache_notice` - messaggi admin temporanei
- `infobit_cache_bulk_ids` - ID prodotti accumulati durante bulk

### Cron

- `infobit_cache_cleanup` - pulizia giornaliera file scaduti

### WP-CLI

- `wp infobit-cache clear` - svuota cache
- `wp infobit-cache stats` - mostra statistiche

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

## Piano di unificazione con Open Redis Manager

### Nuovo nome: WP Open Cache Manager

### Struttura target
```
wp-open-cache-manager/
├── wp-open-cache-manager.php      # Plugin principale, bootstrap
├── advanced-cache.php             # Drop-in page cache (da questo plugin)
├── object-cache.php               # Drop-in Redis object cache (da Open Redis Manager)
├── includes/
│   ├── class-page-cache.php       # Classe page cache estratta da infobit-page-cache.php
│   ├── class-redis-cache.php      # Classe Redis estratta da Open Redis Manager
│   ├── class-cache-manager.php    # Coordinamento: flush combinato, statistiche
│   └── class-admin-ui.php         # Dashboard admin unificata
├── uninstall.php                  # Pulizia completa (page cache + Redis + opzioni)
├── CHANGELOG.md
└── README.md
```

### Cose da integrare
1. **Flush Redis al deactivate** del page cache per evitare dati inconsistenti (`redis-cli -n 1 FLUSHDB`)
2. **Dashboard unificata**: tab Page Cache + tab Redis + panoramica combinata
3. **WP-CLI unificato**: `wp cache clear --page`, `wp cache clear --redis`, `wp cache clear --all`, `wp cache stats`
4. **Coordinamento invalidazione**: opzione per flush Redis quando si svuota page cache
5. **Auto-aggiornamento**: entrambi i drop-in (advanced-cache.php e object-cache.php)

### Attenzione durante l'unificazione
- Il drop-in `advanced-cache.php` viene eseguito PRIMA di WordPress, non ha accesso a classi/funzioni WP
- Il drop-in `object-cache.php` sostituisce il sistema di cache oggetti nativo di WP
- I due drop-in devono funzionare indipendentemente (se uno fallisce, l'altro continua)
- Le costanti Redis in wp-config.php devono rimanere compatibili con il formato `SRC_REDIS_*` esistente
- L'utente ha anche un altro dominio (mepabit.it) sullo stesso server con lo stesso setup

## Problemi noti e lezioni apprese

1. **`clear_all()` cancellava `.active`**: il file flag era nella stessa directory dei file cache. Risolto con skip esplicito per `.active` e `.excluded_urls`
2. **wp-config.php corrotto**: regex di rimozione inaffidabile. Risolto con approccio riga-per-riga + backup + validazione
3. **Redis cache corrotta dopo disattivazione**: dati inconsistenti bloccavano il sito. Soluzione: flush Redis. Da integrare nel plugin unificato
4. **advanced-cache.php non aggiornato**: non si aggiornava con il plugin. Risolto con `maybe_update_dropin()` su `admin_init`
5. **Esclusioni custom non lette dal drop-in**: il drop-in non ha accesso al DB. Risolto con file `.excluded_urls` sincronizzato
6. **Buffer pool MariaDB crollato dopo riavvio**: valore non esplicito nel .cnf. Reso esplicito in `/etc/mysql/mariadb.conf.d/50-server.cnf`
