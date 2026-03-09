# Open Cache Manager v2.1.3

Plugin WordPress di cache management ottimizzato per WooCommerce con cataloghi di grandi dimensioni (45.000+ prodotti).

## Cosa fa

### Page Cache
Salva le pagine come file gzip statici. Quando un visitatore richiede una pagina già in cache, il file `.gz` viene servito direttamente al browser **senza eseguire PHP né query al database**, riducendo il TTFB da secondi a millisecondi.

### DB Optimizer
Analizza la configurazione corrente di MariaDB/MySQL e fornisce raccomandazioni ottimizzate per WooCommerce, con parametri calcolati automaticamente in base alla RAM del server e al tipo di storage.

## Caratteristiche principali

### Page Cache
- **Cache solo GZIP**: salva solo file `.gz` compressi, dimezza lo spazio disco
- **Invalidazione intelligente WooCommerce**: invalida solo le pagine correlate al prodotto aggiornato (prodotto, shop, homepage, categorie)
- **Modalità bulk**: accumula le invalidazioni durante import massivi, processa alla fine
- **Cache per clienti loggati**: i clienti WooCommerce vedono pagine cached sul catalogo; admin/editor esclusi
- **Esclusione automatica**: carrello, checkout, account, wishlist, compare, utenti admin, carrelli attivi
- **Esclusioni custom**: configurabili da pagina impostazioni, sincronizzate con il drop-in
- **Admin bar**: contatore pagine cached, svuotamento rapido, svuotamento singola pagina
- **Pagina impostazioni**: statistiche, TTL, URL esclusi, soglia bulk, debug mode
- **Pulizia automatica**: cron giornaliero per file scaduti
- **Gestione sicura wp-config.php**: backup, validazione pre/post scrittura, ripristino automatico
- **Auto-aggiornamento drop-in**: `advanced-cache.php` si aggiorna con il plugin
- **Disattivazione/eliminazione pulita**: rimuove tutti i file e opzioni creati

### DB Optimizer
- **Rilevamento automatico**: rileva RAM del server, versione MariaDB/MySQL, tipo di storage
- **30+ parametri analizzati**: InnoDB, connessioni, buffer, query cache, logging
- **Dashboard visuale**: stato con indicatori colorati (OK/Consigliato/Da ottimizzare)
- **Metriche real-time**: utilizzo buffer pool, hit rate, tabelle temporanee su disco, connessioni
- **Configurazione generata**: snippet my.cnf pronto da copiare con valori ottimizzati
- **WP-CLI**: `wp open-cache db-check` per analisi da terminale

## Installazione

1. Carica lo zip da **Plugin → Aggiungi nuovo → Carica plugin**
2. Attiva il plugin

L'attivazione automaticamente:
- Copia `advanced-cache.php` in `wp-content/`
- Aggiunge `define('WP_CACHE', true);` in `wp-config.php`
- Crea la directory cache e il file flag `.active`
- Schedula la pulizia giornaliera

## Configurazione

### Page Cache
**Cache Manager → Page Cache**:
- **TTL**: durata cache in secondi (default: 3600 = 1 ora)
- **URL esclusi**: percorsi aggiuntivi da non cachare (uno per riga)
- **Soglia bulk**: numero prodotti oltre il quale svuota tutta la cache
- **Debug**: commenti HTML con timestamp

### DB Optimizer
**Cache Manager → DB Optimizer**:
- Panoramica server e stato parametri
- Raccomandazioni per InnoDB, connessioni, buffer, query cache, logging
- Configurazione my.cnf generata automaticamente

## WP-CLI

```bash
wp open-cache clear       # Svuota tutta la cache
wp open-cache stats       # Mostra statistiche cache
wp open-cache db-check    # Analizza configurazione database
```

## Uso negli script di importazione

```php
require_once('/path/to/wp-load.php');
do_action( 'ocm_cache_bulk_start' );
// ... aggiorna i prodotti ...
do_action( 'ocm_cache_bulk_end' );
```

## Hook disponibili

```php
do_action( 'ocm_cache_bulk_start' );               // Attiva modalità bulk
do_action( 'ocm_cache_bulk_end' );                  // Termina bulk
do_action( 'ocm_cache_invalidate_product', $id );   // Invalida singolo prodotto
do_action( 'ocm_cache_invalidate_all' );            // Svuota tutta la cache
```

## Compatibilità con Redis

Il plugin funziona insieme a Redis Object Cache:
- **Redis** → object cache (risultati query singole)
- **Open Cache Manager** → page cache (HTML completo)

**Nota**: dopo disattivazione, se il sito è lento: `redis-cli -n 1 FLUSHDB`

## Struttura file

```
Open-Cache-Manager/
├── open-cache-manager.php       # Plugin principale
├── advanced-cache.php           # Drop-in WordPress
├── uninstall.php                # Pulizia completa all'eliminazione
├── includes/
│   └── class-db-optimizer.php   # Engine analisi database
├── CHANGELOG.md                 # Storico completo delle modifiche
├── CLAUDE.md                    # Contesto progetto per Claude Code
└── README.md                    # Questo file
```

## Migrazione da Infobit Page Cache

Se stai migrando dalla versione precedente (Infobit Page Cache):
1. Disattiva "Infobit Page Cache"
2. Attiva "Open Cache Manager"
3. La vecchia cache verrà ignorata; la nuova usa la directory `ocm-pages/`
4. Puoi rimuovere manualmente `wp-content/cache/infobit-pages/` se presente
