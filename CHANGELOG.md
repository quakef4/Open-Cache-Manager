# Changelog - Infobit Page Cache

Tutte le modifiche rilevanti al plugin sono documentate in questo file.

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

### Note di migrazione da v1.1.0
- Dopo l'aggiornamento, svuotare la cache per eliminare i vecchi file `.html`:
  ```bash
  rm -rf wp-content/cache/infobit-pages/[0-9a-f]*
  ```
- L'auto-aggiornamento del drop-in avviene automaticamente al primo accesso admin

---

## [1.1.0] - 2026-02-19

### Nuove funzionalità
- **Gestione sicura wp-config.php**: backup automatico prima di ogni modifica, validazione pre e post scrittura, ripristino automatico se il file risulta corrotto dopo la scrittura
- **File uninstall.php**: pulizia completa quando il plugin viene eliminato (non solo disattivato). Rimuove: opzioni DB, transients, cron, advanced-cache.php, directory cache, WP_CACHE dal wp-config.php, file backup residui
- **Disattivazione completa**: rimuove advanced-cache.php da wp-content e la directory cache con tutte le sottocartelle

### Fix
- **Bug critico**: `clear_all()` eliminava il file `.active` durante lo svuotamento cache, disabilitando silenziosamente il plugin. Ora `.active` è preservato
- `rmdir()` con error suppression per directory non vuote (es. directory root che contiene `.active`)
- Aggiunta `remove_cache_directory()` per pulizia ricorsiva completa alla disattivazione

### Bug risolti durante lo sviluppo
- **wp-config.php corrotto alla disattivazione**: la vecchia regex di rimozione mangiava newline e produceva `<?php/**` senza spazio. Risolto con approccio riga-per-riga (explode/implode)
- **Buffer pool MariaDB crollato dopo riavvio**: il valore non era esplicito nel .cnf ma gestito da Plesk. Risolto aggiungendo il parametro esplicitamente

---

## [1.0.0] - 2026-02-19

### Release iniziale

#### Architettura
- `advanced-cache.php`: drop-in WordPress caricato PRIMA di tutto il codice quando `WP_CACHE=true`. Intercetta richieste GET, controlla se esiste file cached valido, lo serve direttamente (bypass PHP/MySQL). Se non esiste, cattura output con `ob_start()` e salva.
- File salvati in `wp-content/cache/infobit-pages/` con hash MD5 dell'URL, organizzati in subdirectory per i primi 2 caratteri dell'hash
- File flag `.active` come interruttore di sicurezza

#### Funzionalità
- Cache basata su file HTML statici con pre-compressione gzip
- TTL configurabile (default 1 ora)
- Esclusione automatica: utenti loggati, carrello WooCommerce attivo, URL admin/checkout/account, richieste POST, query string dinamiche, risposte non-200, pagine con errori WooCommerce, pagine noindex
- Invalidazione intelligente WooCommerce: quando un prodotto viene aggiornato, invalida pagina prodotto + homepage + shop + categorie del prodotto
- Modalità bulk per import massivi con soglia configurabile (default 50 prodotti)
- Admin bar con contatore pagine cached, svuotamento rapido e svuotamento singola pagina
- Pagina impostazioni con statistiche (file, dimensione, età), TTL, URL esclusi, soglia bulk, debug mode
- WP-CLI: `wp infobit-cache clear` e `wp infobit-cache stats`
- Cron giornaliero per pulizia file scaduti
- Scrittura atomica (tmp + rename) per evitare file corrotti sotto carico

#### Hook disponibili per sviluppatori
```php
do_action( 'infobit_cache_bulk_start' );         // Attiva modalità bulk
do_action( 'infobit_cache_bulk_end' );            // Termina bulk e processa
do_action( 'infobit_cache_invalidate_product', $id ); // Invalida singolo prodotto
do_action( 'infobit_cache_invalidate_all' );      // Svuota tutta la cache
```

---

## Contesto tecnico

### Ambiente di produzione
- **Sito**: infobitcomputer.it (WooCommerce, 45.000+ prodotti, 15 fornitori)
- **Server**: 62GB RAM, NVMe, Nginx+Apache (Plesk), MariaDB 10.6+
- **Stack cache**: Cloudflare CDN (free) → Nginx proxy cache → Infobit Page Cache → Redis Object Cache → MariaDB
- **Risultati**: TTFB da 2.3s a 0.05-0.18s (miglioramento 15-45x)

### Ottimizzazioni server correlate (non nel plugin)
- **MariaDB tuning**: innodb_buffer_pool_size=45GB, innodb_flush_log_at_trx_commit=2, innodb_log_file_size=1GB, innodb_io_capacity=10000, table_open_cache=4000, slow_query_log attivo
- **Nginx**: gzip on, expires headers per immagini (1y), CSS/JS (1M), font (1y)
- **Redis**: object cache su database 1 con socket Unix

### Problemi noti e soluzioni
- **Redis cache corrotta**: dopo disattivazione/riattivazione del plugin, la cache Redis può contenere dati inconsistenti. Soluzione: `redis-cli -n 1 FLUSHDB`
- **Speculation Rules di Cloudflare**: può interferire con la navigazione su Chrome (prefetch al primo click). Disabilitare Speed Brain in Cloudflare o ignorare (è un'impostazione del browser, non del sito)
- **advanced-cache.php non aggiornato**: nelle versioni pre-1.2.0 il drop-in non si aggiornava con il plugin. Dalla v1.2.0 l'auto-update è integrato

---

## Roadmap - WP Open Cache Manager

Questo plugin sarà unificato con **Open Redis Manager** in un unico plugin chiamato **WP Open Cache Manager**.

### Struttura prevista
```
wp-open-cache-manager/
├── wp-open-cache-manager.php      # Plugin principale
├── advanced-cache.php             # Drop-in page cache
├── object-cache.php               # Drop-in Redis object cache
├── includes/
│   ├── class-page-cache.php       # Logica page cache (da Infobit Page Cache)
│   ├── class-redis-cache.php      # Gestione Redis (da Open Redis Manager)
│   ├── class-cache-manager.php    # Coordinamento tra i due livelli
│   └── class-admin-ui.php         # Pagina admin unificata
├── uninstall.php
└── README.md
```

### Funzionalità previste per l'unificazione
- Dashboard unica con statistiche Page Cache + Redis
- Flush Redis automatico alla disattivazione del page cache
- Coordinamento invalidazione: quando si svuota il page cache, opzione per svuotare anche Redis
- WP-CLI unificato: `wp cache clear --page`, `wp cache clear --redis`, `wp cache clear --all`
