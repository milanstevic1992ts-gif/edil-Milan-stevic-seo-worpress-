# EMS Local SEO — Roadmap Trieste

**EDIL MILAN STEVIC · triesteincostruzione.com · 18 settembre 2026**

Questa è la roadmap operativa di riferimento. Una funzione presente nel codice non viene considerata verificata finché non è stata provata nel contesto WordPress previsto.

## Obiettivo
Acquisire richieste pertinenti di lavori edili a Trieste — ristrutturazioni, bagni, posa piastrelle, cartongesso, pavimenti e finiture — misurando visibilità, contatti qualificati e preventivi generati.

Il punteggio EMS è solo diagnostico. Nessuna promessa di primo posto o ranking.

## Priorità geografica
1. Trieste.
2. Muggia, San Dorligo della Valle e altre aree solo dopo conferma della copertura reale e della convenienza commerciale.
3. Quartieri usati nei cantieri quando pertinenti.
4. Nessuna generazione massiva di pagine quasi identiche per zona.

## Priorità editoriali
- P1: impresa edile / ristrutturazioni Trieste.
- P1: ristrutturazione bagno / rifare bagno Trieste.
- P1: guida costi bagno Trieste.
- P1: piastrellista / posa piastrelle Trieste.
- P2: cartongesso / controsoffitti Trieste.
- P2: posa SPC / LVT Trieste.
- P2: pittura / rasatura / imbianchino Trieste.
- P2: preventivo ristrutturazione Trieste.

Ogni intento principale deve avere una pagina di riferimento. Guide, quiz e lavori reali devono sostenerla con collegamenti pertinenti.

## Fase 0 — Misure iniziali e inventario
**Priorità P0. Stato: parzialmente verificato.**

### Verificato
- WordPress.com Atomic.
- 60 pagine, 18 articoli, 401 media al controllo.
- 24 plugin installati, 20 attivi.
- The SEO Framework 5.1.4 attivo.
- Site Kit 1.187.0 attivo.
- Jetpack Boost 4.7.1 attivo.
- Redirection 5.9.0 attivo.
- Backup Jetpack attivo con restore point disponibili.
- Nessuno staging WordPress.com visibile al connettore.

### Da verificare sul runtime live
- Versione WordPress core e PHP effettive.
- Proprietà Search Console e permessi effettivi dentro Site Kit.
- Baseline Search Console 28/90 giorni.
- Segmentazione brand / non-brand.
- Registro richieste, servizi richiesti ed esito.
- HTML pubblico dopo attivazione EMS.

Riferimento: `docs/BASELINE-2026-09-18.md`.

## Fase 1 — v1.0.1: analisi affidabili e stabilità
**Priorità P0. Stato: in sviluppo/test.**

### Implementato nel branch v1.0.1
- Lettore condiviso dei metadata effettivamente pubblicati in HTML.
- Separazione tra valori EMS salvati e HTML pubblico.
- Scansione metadata a lotti, riprendibile, con lock e copertura dichiarata.
- Link Health completo a lotti, senza fermarsi ai primi 80 URL.
- Copertura link da contenuto, menu classici e navigazione a blocchi.
- Rilevazione orphan estesa alla navigazione.
- Confronto GSC sull'unione dei due periodi.
- Riga assente dal campione distinta da traffico zero.
- Report GSC al limite marcato come potenzialmente troncato.
- Payload Site Kit inattesi bloccano l'analisi invece di generare dati vuoti ingannevoli.
- Test fixture GSC, parser HTML e permessi/nonce.
- CI con PHP 8.0/8.2, WordPress reale, TSF attivo, upgrade e rollback.

### Criteri per chiudere la fase
- CI completa verde.
- ZIP installabile verificato.
- Nessun doppio metadata nel smoke test TSF.
- Scansione riprendibile oltre il vecchio limite.
- Test per dati GSC assenti/troncati e payload inatteso.
- Attivazione, aggiornamento e rollback verificati.
- Prima verifica live/staging quando disponibile.

## Fase 2 — v1.1: motore locale e architettura pagine
**Priorità P1. Non iniziare prima della stabilità v1.0.1.**

- Content Map: servizio, intento, area servita, pagina principale, guide, quiz e cantieri.
- Scheda aziendale centrale coerente e verificata.
- Identificatori schema coerenti tra azienda, servizi e pagine.
- Suggerimenti link contestuali con testo e punto di inserimento.
- Sovrapposizioni di intento senza etichettare automaticamente ogni caso come cannibalizzazione.
- Blocco della generazione massiva di pagine territoriali quasi identiche.

## Fase 3 — v1.2: opportunità commerciali e contatti
**Priorità P1.**

- Dashboard “Le 5 azioni di questa settimana”.
- Priorità basate su servizio, visibilità, contatti osservati, gravità tecnica e affidabilità del dato.
- Eventi separati: WhatsApp, telefono, quiz, modulo riuscito, contatto qualificato.
- Rispetto consenso e nessun dato personale nei parametri analytics.
- Provenienza Google Business separata mediante link marcati.
- Filtri servizio/dispositivo, confronti 3/6 mesi e diario modifiche.
- Nessun cron GSC finché il contesto di autenticazione supportato non è verificato.

## Fase 4 — v1.3: cantieri reali, contenuti e reputazione
**Priorità P1 bagno/piastrelle; P2 altri servizi.**

- Problema iniziale, lavorazioni, materiali, tempi, servizio e zona pubblicabile.
- Foto prima/dopo autentiche e fasi reali.
- Immagini generate mai presentate come lavori eseguiti.
- ALT descrittivi con supporto alle immagini decorative.
- Schede cantiere collegate a servizio, guida e richiesta preventivo.
- Guide costi con data, inclusioni, esclusioni e natura indicativa.
- Richieste recensione senza incentivi o selezione dei soli clienti soddisfatti.
- Checklist Google Business Profile.
- Citazioni locali reali, niente acquisto massivo di link.

## Fase 5 — v1.4: prestazioni e manutenzione
**Priorità P2.**

- PageSpeed/Core Web Vitals senza secondo sistema di cache.
- Dati real-user distinti dai test di laboratorio.
- Immagini, layout, slider, leggibilità e CTA mobile.
- Sitemap, canonical, HTTP e noindex pagine prioritarie.
- URL Inspection solo con accesso e quote verificati.
- Scansioni incrementali, storico limitato, diagnostica esportabile e notifiche admin.

## Indicatori
- Contatti qualificati e preventivi per servizio.
- Click organici non-brand verso servizi.
- Trend query locali pertinenti.
- Conversioni pagine servizio.
- Errori tecnici delle pagine prioritarie.
- Completezza dei lavori reali.

Gli obiettivi percentuali verranno definiti soltanto dopo la baseline.

## Regole permanenti
1. Nessun keyword stuffing.
2. Nessuna promessa di ranking.
3. Nessun dato inventato per completare schema o report.
4. Nessuna pagina territoriale massiva quasi duplicata.
5. Un solo proprietario per title/meta/canonical/robots.
6. Le funzioni che modificano contenuti devono mostrare anteprima e conservare la versione precedente.
7. Ogni dato deve avere fonte, periodo e stato.
8. Dato assente non significa zero.
9. Una query presente su due pagine non è automaticamente cannibalizzazione.
10. Plugin changes → test → ZIP versionato → possibilità di rollback.
