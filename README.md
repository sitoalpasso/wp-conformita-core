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
- **Consegna degli allegati**: i file stanno in una cartella che l'accesso diretto non
  raggiunge e si scaricano solo da un punto di consegna che a ogni richiesta rifà tutti i
  controlli. La protezione della cartella non si presume: si verifica.
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

### Scadenza: che cosa il filtro copre, e che cosa no

La scadenza è una proprietà del dato letto: la decisione si prende alla lettura, e non
dipende dall'esecuzione del compito pianificato. Il filtro lavora su due strati. Il primo
aggiunge una condizione all'interrogazione della banca dati, ed è quello che tiene coerente
l'impaginazione. Il secondo ricontrolla ogni contenuto restituito, ed è quello che fa fede:
il primo confronta stringhe, quindi un valore corrotto che ordina alto lo supererebbe.

La condizione della banca dati si aggiunge **solo alle interrogazioni che riguardano
esclusivamente tipi registrati da core**. Su un'interrogazione mista lavora il secondo
strato, che guarda un contenuto alla volta: aggiungere quella condizione a
un'interrogazione mista toglierebbe dai risultati tutti i contenuti che quel metadato non
ce l'hanno.

L'esenzione dal filtro è **della superficie, non dell'utente**. Un utente autorizzato che
naviga il sito pubblico vede quello che vede chiunque altro. Le capability governano la
gestione nell'amministrazione, dove il contenuto scaduto resta visibile perché resti
correggibile.

Un contenuto con **più di una data di fine registrata** è un'anomalia, non una scelta fra
due: risulta scaduto, come qualsiasi altro dato che non si sappia leggere. La scrittura
attraverso l'API riporta il contenuto a un valore solo.

Lo stesso vale per l'interfaccia REST, che non è solo una superficie pubblica ma anche il
canale dell'editor a blocchi: una richiesta con `context=edit` non viene filtrata, perché
altrimenti un contenuto con la data di fine sbagliata non sarebbe apribile per correggerlo.
Non è una scorciatoia: per quel contesto WordPress pretende già il permesso di modifica sul
contenuto e risponde da sé a chi non ce l'ha. Ogni altro contesto, dichiarato o assente,
riceve `404`.

**La politica `archivio` non è ancora consumabile.** Le due politiche di scadenza si
dichiarano entrambe e la validazione le accetta entrambe, ma oggi producono lo stesso
comportamento: il contenuto scaduto è invisibile sulle superfici pubbliche e visibile
nell'amministrazione. L'accesso riservato che distingue `archivio` da `irraggiungibile`, con
la capability derivata dalla sezione, è un'unità successiva. Un componente che dichiara
`archivio` non deve contare su una differenza che ancora non c'è.

Non sono coperti, e vanno considerati limiti noti: `WP_Query` con `suppress_filters` e con
`fields => 'ids'`, che non applicano il secondo strato; `get_post()` sul singolo
identificativo; le interrogazioni SQL dirette; la lettura diretta dei metadati; le pagine
servite da una memoria che risponde prima di WordPress. Il controllo non avviene a ogni
richiesta del contenuto: avviene a ogni richiesta che arriva a WordPress.

### Allegati: dove stanno, e perché un deposito può essere rifiutato

È la parte che un componente dipendente deve sapere prima di scrivere una riga.

**I file non stanno nella cartella pubblica dei caricamenti.** Stanno in
`wp-content/uploads/conformita-core-protetto/`, dove il componente scrive `.htaccess`,
`web.config` e un indice muto. Il percorso memorizzato sull'allegato porta quel segmento per
intero, e `wp_get_attachment_url()` restituisce l'indirizzo di consegna e non il percorso del
file: **il percorso non si pubblica mai**.

**La protezione si verifica, non si presume.** Nella cartella c'è anche un file esca con un
gettone dentro; il componente lo chiede al sito stesso e legge la risposta, in quest'ordine.
Il gettone che torna indietro vale `non_coperta`, con qualunque stato sia tornato: i byte sono
usciti. Un `403` o un `404` valgono `verificata`, e sono un elenco chiuso. Tutto il resto vale
`ignota`, quindi niente deposito: la richiesta fallita, il `200` con qualcosa che non è il
nostro file, il reindirizzamento, e ogni stato che parla del momento e non delle regole della
cartella, come un `503` o il `401` di un sito ancora chiuso al pubblico.

**Un diniego prova un percorso, non una cartella.** Un server può negare la cartella e servire
lo stesso i file di una certa estensione, perché fra due regole non vince sempre quella che
parla della cartella. Perciò l'esito si conserva per **ambito**, cioè per la coppia
sottocartella più estensione, e il primo deposito di un'estensione nuova prova il percorso
vero prima di scrivere i byte. La richiesta si fa all'attivazione, quando i file di regole
vengono scritti o riscritti, su richiesta esplicita con
`conformita_core_verifica_protezione_allegati()`, e la prima volta che si deposita in un
ambito non ancora provato; **non a ogni deposito**. L'esito si conserva con il proprio istante
e si rilegge con `conformita_core_stato_protezione_allegati()`, che non fa nessuna richiesta.

**Dove la protezione non risulta verificata, il deposito si rifiuta.** Non è un avviso: è un
rifiuto, e su un server che non legge i file di regole (nginx, Caddy, Apache con
`AllowOverride None`) nessun file si deposita finché non si aggiunge la regola alla
configurazione del server. Fra scrivere un file che non si è in grado di proteggere e non
scriverlo, non si scrive: un file già finito in una cartella aperta non si richiama indietro.
L'installazione che ha verificato la protezione a mano, e in cui il giro sul sito stesso non
funziona per costruzione, può dichiarare la costante
`CONFORMITA_CORE_PROTEZIONE_CONFERMATA`; lo stato continua a riportare l'esito vero accanto
allo scavalcamento.

**Due punti di consegna, non uno.** Quello pubblico applica la scadenza **a chiunque**,
permessi compresi, e risponde "non trovato" a ogni rifiuto: un file che esiste ma è scaduto
non deve confessare di esistere. Applica anche la password, quando c'è, e la guarda
sia sul contenuto padre sia sull'allegato, perché nessuna delle due domande risponde per
l'altra: lo stato `publish` dice che il contenuto è pubblicato, non che si legga, e senza
quell'anello l'indirizzo di consegna farebbe uscire il documento che la pagina tiene chiuso.
Un allegato messo nel cestino non esce più dal punto pubblico, e continua a vedersi
dall'amministrazione, come il contenuto scaduto. Quello amministrativo sta su `admin-post.php`, pretende il
nonce e la capability del tipo, e serve il file anche dopo la scadenza. Sono due indirizzi e
non un indirizzo con due comportamenti, perché su `admin-post.php` `is_admin()` è vero anche
per un visitatore anonimo: un punto pubblico messo lì risulterebbe esente dal filtro di
scadenza per l'indirizzo scelto.

**Come esce il file.** PDF e immagini raster di un elenco chiuso e non configurabile escono
dentro la pagina, con una politica di sicurezza dei contenuti che li isola; ogni altro tipo si
scarica. Su tutto: nessuna memoria di pagina, nessuna richiesta parziale.

**Limiti dichiarati.** L'esito della verifica invecchia, e una modifica alla configurazione
del server fatta dopo non si nota finché qualcuno non rifà la verifica. La richiesta parte dal
server e non da Internet, quindi non vede quello che sta davanti al sito: un proxy, una rete
di distribuzione, un firewall applicativo. L'impronta si calcola al deposito e non si
ricontrolla, quindi un file sostituito sul disco non si nota. E `wp_get_attachment_url()`
restituisce l'indirizzo pubblico anche in amministrazione, perché l'indirizzo amministrativo
porta un nonce e un nonce si genera dove lo si usa.

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
