# Conformita Core

Plugin WordPress che fornisce i meccanismi comuni ai componenti di conformità per la
pubblica amministrazione italiana. Non contiene politiche proprie: ogni meccanismo
richiede la politica come parametro esplicito e rifiuta di attivarsi senza.

**Stato: in sviluppo.** Il componente non è ancora utilizzabile in esercizio. Le
interfacce pubbliche possono cambiare fino alla prima versione stabile.

Licenza: GPL-3.0-or-later. Versioni minime: WordPress 6.5, PHP 8.1. Multisite non
supportato.

## Cosa fornisce

- **Motore di scadenza a tre strati**: filtro in lettura su tutti i percorsi pubblici,
  compito pianificato per il lavoro pesante, battito di controllo per rilevare uno
  stallo del pianificatore. La scadenza è una proprietà del dato letto, quindi la
  conformità non dipende dall'esecuzione del compito pianificato.
- **Controllo dell'indicizzazione**, con la politica dichiarata dal componente che
  registra la sezione e nessun valore predefinito.
- **Consegna degli allegati** da endpoint PHP, con cartella protetta e verifica della
  scadenza a ogni richiesta.
- **Registro delle modifiche** in sola aggiunta, consultabile con una capability
  dedicata.
- **Verificatore dei collegamenti** per le voci che rinviano ad altre pagine o ad altri
  siti.
- **Scheletro dei tipi di contenuto**, con capability dedicate ed esposizione REST
  dichiarata esplicitamente per ogni tipo registrato.

### Capability: generate qui, assegnate dal componente

Core genera capability dedicate per ciascun tipo, ma **non le assegna automaticamente ad
alcun ruolo**. Finché il componente non effettua l'assegnazione, il tipo non è gestibile
dall'amministrazione WordPress.

Non è una dimenticanza. Fra le due direzioni possibili è quella che sbaglia in sicurezza:
un tipo che nasce senza nessuno autorizzato a scriverci si nota subito, mentre un tipo che
nasce con permessi assegnati a ruoli che non dovrebbero averli non si nota affatto. Chi
conosce i propri ruoli è il componente, non questo plugin.

Ne discendono tre obblighi per chi dipende da core:

1. assegnare, all'attivazione e a ogni aggiornamento, l'insieme minimo di capability
   almeno all'amministratore;
2. configurare da sé gli eventuali ruoli propri, per esempio un responsabile della
   pubblicazione;
3. mostrare un avviso in amministrazione se nessun ruolo risulta avere le capability del
   tipo, così la condizione si vede invece di essere scambiata per un guasto.

Le capability sono derivate dall'identificativo del tipo e non sono riscrivibili dal
componente: è la garanzia che il tipo non finisca governato dai permessi degli articoli.
Per lo stesso motivo l'identificativo ammette lettere minuscole, cifre e trattino basso,
ma non il trattino: la derivazione deve restare iniettiva, altrimenti due tipi che
WordPress distingue arriverebbero alle stesse capability.

## Requisiti normativi di riferimento

Il componente implementa requisiti derivati dalla normativa applicabile ai soggetti
dell'art. 2-bis del d.lgs. 14 marzo 2013, n. 33, indipendentemente dallo specifico ente.
Gli estremi delle fonti che motivano i meccanismi:

- d.lgs. 14 marzo 2013, n. 33, obblighi di pubblicazione e relativa durata;
- legge 18 giugno 2009, n. 69, art. 32, pubblicità legale in forma telematica;
- delibera ANAC n. 495 del 2024;
- Regolamento (UE) 2016/679 e provvedimenti del Garante per la protezione dei dati
  personali in materia di durata della pubblicazione e di indicizzazione dei dati
  personali;
- d.lgs. 7 marzo 2005, n. 82 (Codice dell'amministrazione digitale);
- legge 9 gennaio 2004, n. 4 e norma tecnica EN 301 549 per l'accessibilità.

L'elenco completo dei requisiti, con fonte e criterio di verifica per ciascuno, sarà
pubblicato in `docs/requisiti.md`.

## Componenti che dipendono da questo

Le politiche stanno nei componenti che registrano le sezioni, non qui. I componenti di
pubblicità legale e di obblighi di pubblicazione sono in sviluppo separatamente e
dichiarano politiche di indicizzazione opposte fra loro: è la ragione per cui questo
plugin non ammette valori predefiniti.

## Sviluppo

- Ambiente: `npx wp-env start`, poi `npx wp-env run cli wp plugin activate conformita-core`
- Test: `composer test`
- Standard: `composer lint`

## Segnalazioni e contributi

Vedi `SUPPORT.md`. Sull'uso del nome vedi `TRADEMARK.md`.
