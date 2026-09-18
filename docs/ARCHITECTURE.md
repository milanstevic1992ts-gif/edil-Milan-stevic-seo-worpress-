# EMS Local SEO — Architettura

## Obiettivo
Plugin WordPress proprietario per SEO locale di EDIL MILAN STEVIC. Controlla metadata essenziali, identità locale, schema e audit, con compatibilità esplicita.

## Moduli
- EMS_Local_SEO_Plugin: bootstrap e asset amministrativi.
- EMS_Local_SEO_Compatibility: rileva i principali plugin SEO e impedisce doppio output dei metadata.
- EMS_Local_SEO_Settings: Business Entity, territorio, profili ufficiali e controllo schema.
- EMS_Local_SEO_Meta: title, meta description, canonical, robots, query primaria e tipo schema per pagina.
- EMS_Local_SEO_Schema: grafo JSON-LD standalone oppure integrazione nativa nel grafo di The SEO Framework 5.x; aggiunge GeneralContractor/Organization, Service e Article senza duplicare WebSite/WebPage/Breadcrumb di TSF.
- EMS_Local_SEO_Audit: controlli interni e cache dei risultati.
- EMS_Local_SEO_Links: suggerimenti euristici di link interni, senza modifiche automatiche.
- EMS_Local_SEO_Content_Map: copertura tematica, slug sospetti e rilevamento di titoli sovrapposti.
- EMS_Local_SEO_Search_Console: adapter isolato verso la REST API interna di Site Kit; usa rest_do_request() e l'utente WordPress già autenticato, senza OAuth aggiuntivo.
- EMS_Local_SEO_Opportunities: aggrega query/landing page, confronta due periodi e genera segnali diagnostici/priorità EMS senza modifiche automatiche.

## Regole di sicurezza SEO
- In modalità automatica, se viene rilevato un altro plugin SEO, EMS non emette metadata e non emette schema.
- Lo schema può essere forzato solo esplicitamente dall'amministratore.
- Nessun dato inventato: indirizzo, coordinate, profili e contatti vengono emessi solo se configurati.
- Nessun markup recensioni self-serving e nessun FAQ schema usato come scorciatoia SEO.
- Il punteggio audit è soltanto una metrica interna.
