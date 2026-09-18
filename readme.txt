=== EMS Local SEO ===
Contributors: edil-milan-stevic
Tags: seo, local seo, schema, localbusiness, construction
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later

SEO locale e tecnico per EDIL MILAN STEVIC.

== Description ==

Funzioni attuali:
* rilevamento dei principali plugin SEO e modalità compatibilità;
* title, meta description, canonical e robots per singola pagina;
* anagrafica aziendale centralizzata;
* JSON-LD WebSite + GeneralContractor/LocalBusiness;
* Service e Article collegati al provider locale;
* BreadcrumbList;
* audit interno di description, duplicati, noindex, immagini ALT, query duplicate e possibili pagine orfane;
* suggerimenti assistiti per link interni, senza modifiche automatiche;
* integrazione nativa nel grafo Schema.org di The SEO Framework 5.x;
* Content Map per copertura tematica, slug sospetti e titoli semanticamente sovrapposti;
* adapter isolato per Search Console tramite Site Kit;
* confronto 28 giorni vs 28 giorni precedenti;
* Opportunity Engine: quick win, CTR debole, cali, crescita e possibile cannibalizzazione basata su query/landing page reali.

Il punteggio dell'audit è una metrica interna diagnostica e non rappresenta un punteggio Google.


== Changelog ==

= 1.1.0 =
* Motore locale adattivo per architettura SEO Trieste.
* Catalogo servizi e intenti P1/P2 senza dipendenza da storico Search Console.
* Classificazione automatica con livelli di affidabilità e override manuale facoltativo.
* Ruoli: pagina servizio, guida, quiz, lavoro reale, azienda e altro.
* Stato dati progressivo: WordPress, HTML pubblico, GSC, mapping manuale e case study.
* Storico compatto dell'architettura; dati assenti non trasformati in zero.
* Link interni guidati dall'architettura con anchor e punto di inserimento suggeriti.
* Service schema collegato solo a classificazioni esplicite, non a semplici euristiche.
* Area predefinita limitata a Trieste e nessuna generazione automatica di pagine territoriali.



= 1.0.1 =
* Baseline tecnica verificata e stato sconosciuto esplicitamente marcato.
* Lettore dei metadata effettivamente pubblicati in HTML.
* Verifica metadata a lotti, riprendibile, con lock e copertura dichiarata.
* Link Health completo a lotti senza limite fisso ai primi 80 URL.
* Orphan detection estesa a menu classici e navigazione a blocchi.
* Search Console: confronto sull'unione delle righe, senza convertire righe assenti in zero.
* Report GSC potenzialmente troncati marcati come tali; payload inattesi bloccano l'analisi.
* Test fixture GSC, parser metadata, permessi/nonce e smoke test WordPress/TSF.


= 1.0.0 =
* Link Health manuale per link interni, redirect e risposte 4xx/5xx.
* Modulo Lavori reali / Case Study con servizio, località, descrizione e audit immagini/ALT.
* I metadati dei case study alimentano la Content Map.
* Workflow GitHub Release con ZIP installabile e checksum SHA-256.
* Consolidamento dashboard e pulizia dati in disinstallazione.


= 0.9.0 =
* Integrazione Search Console tramite Site Kit REST interna.
* Opportunity Engine con confronto periodi e priorità EMS.
* Nessuna modifica automatica a contenuti, redirect o indicizzazione.
