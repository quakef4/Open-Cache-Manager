# Infobit Page Cache v1.2.0

Plugin WordPress di page cache leggero, ottimizzato per WooCommerce con cataloghi di grandi dimensioni (45.000+ prodotti).

## Cosa fa

Salva le pagine come file gzip statici. Quando un visitatore richiede una pagina già in cache, il file `.gz` viene servito direttamente al browser **senza eseguire PHP né query al database**, riducendo il TTFB da secondi a millisecondi.

## Caratteristiche principali

- **Cache solo GZIP**: salva solo file `.gz` compressi, dimezza lo spazio disco
- **Invalidazione intelligente WooCommerce**: invalida solo le pagine correlate al prodotto aggiornato (prodotto, shop, homepage, categorie)
- **Modalità bulk**: accumula le invalidazioni durante import massivi, processa alla fine
- **Cache per clienti loggati**: i clienti WooCommerce vedono pagine cached sul catalogo; admin/editor esclusi
- **Esclusione automatica**: carrello, checkout, account, wishlist, compare, utenti admin, carrelli attivi
- **Esclusioni custom**: configurabili da pagina impostazioni, sincronizzate con il drop-in
- **Admin bar**: contatore pagine cached, svuotamento rapido, svuotamento singola pagina
- **Pagina impostazioni**: statistiche, TTL, URL esclusi, soglia bulk, debug mode
- **WP-CLI**: `wp infobit-cache clear` e `wp infobit-cache stats`
- **Pulizia automatica**: cron giornaliero per file scaduti
- **Gestione sicura wp-config.php**: backup, validazione pre/post scrittura, ripristino automatico
- **Auto-aggiornamento drop-in**: `advanced-cache.php` si aggiorna con il plugin
- **Disattivazione/eliminazione pulita**: rimuove tutti i file e opzioni creati

## Installazione

1. Carica lo zip da **Plugin → Aggiungi nuovo → Carica plugin**
2. Attiva il plugin

L'attivazione automaticamente:
- Copia `advanced-cache.php` in `wp-content/`
- Aggiunge `define('WP_CACHE', true);` in `wp-config.php`
- Crea la directory cache e il file flag `.active`
- Schedula la pulizia giornaliera

## Configurazione

**Impostazioni → Infobit Cache**:
- **TTL**: durata cache in secondi (default: 3600 = 1 ora)
- **URL esclusi**: percorsi aggiuntivi da non cachare (uno per riga)
- **Soglia bulk**: numero prodotti oltre il quale svuota tutta la cache
- **Debug**: commenti HTML con timestamp

## Uso negli script di importazione

```php
require_once('/path/to/wp-load.php');
do_action( 'infobit_cache_bulk_start' );
// ... aggiorna i prodotti ...
do_action( 'infobit_cache_bulk_end' );
```

## Hook disponibili

```php
do_action( 'infobit_cache_bulk_start' );               // Attiva modalità bulk
do_action( 'infobit_cache_bulk_end' );                  // Termina bulk
do_action( 'infobit_cache_invalidate_product', $id );   // Invalida singolo prodotto
do_action( 'infobit_cache_invalidate_all' );            // Svuota tutta la cache
```

## Compatibilità con Redis

Il plugin funziona insieme a Redis Object Cache:
- **Redis** → object cache (risultati query singole)
- **Infobit Page Cache** → page cache (HTML completo)

**Nota**: dopo disattivazione, se il sito è lento: `redis-cli -n 1 FLUSHDB`

## Struttura file

```
infobit-page-cache/
├── infobit-page-cache.php   # Plugin principale
├── advanced-cache.php       # Drop-in WordPress
├── uninstall.php            # Pulizia completa all'eliminazione
├── CHANGELOG.md             # Storico completo delle modifiche
├── CLAUDE.md                # Contesto progetto per Claude Code
└── README.md                # Questo file
```
