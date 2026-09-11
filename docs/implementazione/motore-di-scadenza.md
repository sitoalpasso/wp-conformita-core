# Motore di scadenza: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S4 del piano, righe di collaudo elencate al
punto 10.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** L'albo, più
avanti e in un'altra unità, si limiterà a due cose: dichiarare la politica di scadenza della
propria sezione, che sarà `irraggiungibile`, e scrivere il metadato di fine pubblicazione
definito qui. Nessuna logica di scadenza nell'albo, mai: se ci finisse, esisterebbero due
verità sulla stessa cosa.

*La prima stesura di questa riga diceva che l'albo avrebbe anche assegnato ai propri ruoli la
capability di archivio. Quella capability non esiste: è l'unità S10, e l'albo non la usa
perché dichiara `irraggiungibile`.*

**Stato: approvata e unita in `main`.** Le sezioni operative descrivono ciò che il codice fa
oggi; i punti 11, 12 e 15 sono note storiche e lo dichiarano in apertura.

---

## 1. Il contratto del dato

Tre cose, tutte imposte da core e non negoziabili dal componente, per la stessa ragione per
cui lo sono le capability: il filtro deve poter leggere senza chiedere permesso al componente,
e due componenti non devono potersi pestare i piedi.

### 1.1 Dove sta la data

**Chiave del metadato: `_conformita_core_fine_pubblicazione`.** Una sola, uguale per tutti i
tipi di tutte le sezioni.

Non è configurabile dal componente. Se lo fosse, il filtro dovrebbe costruire la propria
interrogazione a partire dall'elenco dei tipi registrati, e un componente potrebbe indicare
una chiave che scrive anche per altri scopi. Il trattino basso iniziale la tiene fuori
dall'interfaccia dei campi personalizzati: la scrittura passa dal componente, che ha la
propria maschera.

### 1.2 Che cosa contiene

**Una data civile nel formato `Y-m-d`**, per esempio `2026-09-09`. Non un istante, non un
numero di secondi.

La data civile è il dato di dominio appropriato perché il termine di legge è espresso in
giorni: un atto si pubblica per quindici giorni. La scadenza si calcola come mezzanotte
civile del giorno successivo nel fuso del sito, il che evita di trattare quindici giorni come
un numero fisso di secondi: **nei giorni del cambio d'ora un giorno civile dura 23 o 25 ore**,
quindi un calcolo a secondi porterebbe la scadenza alle 23 o all'una invece che a mezzanotte.

**Correzione di una spiegazione sbagliata della stesura precedente**, segnalata in revisione.
Avevo scritto che un istante salvato alla pubblicazione si sposterebbe dopo un cambio d'ora.
È falso: un istante già memorizzato non si sposta. L'errore non sta nel conservare un
istante, sta nel calcolarlo sommando una quantità fissa di secondi a una data.

Il valore è validato in scrittura: formato esatto, data reale (il 31 febbraio è rifiutato).
La validazione la fa core e la espone al componente.

### 1.3 Quando scade, esattamente

**La data di fine è inclusiva.** Un atto con fine `2026-09-09` resta pubblico per tutto il 9
settembre.

**L'istante di scadenza è `2026-09-10 00:00:00` nel fuso del sito**, ottenuto da
`wp_timezone()` e mai da una costante.

**Quando l'ora corrente è esattamente uguale all'istante di scadenza, l'atto è scaduto.** Il
confronto è `adesso >= istante_di_scadenza`. La scelta è deliberata: fra le due direzioni
possibili, questa sbaglia rendendo invisibile un attimo prima invece che visibile un attimo
dopo.

### 1.4 La data di inizio non sta qui

L'albo ha anche una data di inizio, e non entra in questo meccanismo: quando un atto diventa
pubblico lo decide il suo **stato**, cioè la macchina a stati ALBO-22 del componente. Finché
non è pubblicato, non è pubblicato.

---

## 1-bis. Le due politiche di scadenza: che cosa fa questa unità

**Questa unità non legge mai la politica di scadenza.** Il filtro guarda la data e lo stato
del contenuto, non la politica della sezione: con `irraggiungibile` e con `archivio` fa
esattamente la stessa cosa, cioè rende il contenuto scaduto invisibile su tutte le superfici
pubbliche e lo lascia visibile nell'amministrazione.

Le due politiche si dichiarano entrambe e la validazione le accetta entrambe, perché il
registro delle sezioni è dell'unità S1 e non di questa. Ma **la differenza fra le due non
esiste ancora**, e finché non esiste la politica `archivio` non va consumata: un componente
che la dichiara ottiene oggi il comportamento di `irraggiungibile`.

**Dove sta la differenza, quando ci sarà.** In una sola casella: che cosa risponde
l'indirizzo proprio del contenuto, dopo la scadenza, a un utente autenticato che possiede una
capability dedicata all'archivio. Con `irraggiungibile` risponde "non trovato" anche a lui;
con `archivio` risponde a lui e non all'anonimo. Quella capability, quel comportamento e la
riga di collaudo C-91 che li verifica sono l'unità **S10**, che dipende da questa.

**L'albo dichiara `irraggiungibile`**, coerentemente con ALBO-05: l'atto defisso esce dalla
vista e l'archivio è una schermata riservata, non un indirizzo che continua a rispondere.
Quindi l'albo non aspetta S10 per funzionare.

## 2. Le capability: quali esistono e quale no

Le capability dei tipi di contenuto sono dell'unità S2, che le registra e non le assegna:
finché il componente non effettua l'assegnazione, nessun ruolo può gestire il tipo
nell'amministrazione. Sono le capability che governano la **gestione**, non la consultazione
pubblica.

**La capability di archivio non esiste.** Era progettata qui, derivata dall'identificativo
della sezione perché la politica di scadenza sta sulla sezione, ed è stata spostata in S10
insieme al comportamento che dovrebbe governare: una capability senza il comportamento che
apre sarebbe un permesso che non permette niente.

### Il vincolo sui caratteri degli identificativi di sezione

Questo invece è stato fatto, e vale a prescindere da S10. Gli identificativi di sezione
ammettono lettere minuscole, cifre e trattino basso, come quelli dei tipi. È una
**restrizione del contratto decisa prima del rilascio**, non la correzione di un difetto:
senza normalizzazione `sezione-a` e `sezione_a` produrrebbero identificativi diversi, quindi
non collidono.

Il vincolo esiste per tre ragioni. Una regola sola invece di due, perché chi scrive un
componente non deve ricordarne una per i tipi e una per le sezioni. La convenzione interna del
progetto, che per sezioni e capability adotta l'insieme `[a-z0-9_]`. E il fatto che una
trasformazione esplicita del tipo `str_replace( '-', '_', ... )`, introdotta un domani da chi
non conosce questa storia, aprirebbe la collisione della riga C-86: vietare adesso il
carattere che la produrrebbe costa zero.

*Correzione di una motivazione sbagliata scritta qui in prima stesura: `sanitize_key()`
conserva sia il trattino sia il trattino basso, quindi non produce nessuna collisione. Ed è il
progetto ad adottare `[a-z0-9_]` come convenzione, non WordPress a imporlo.*

**È una modifica incompatibile, non un'aggiunta.** L'interfaccia 1.1 accetta identificativi
di sezione con qualunque carattere; questa versione li restringe. Si accetta come correzione
del contratto prima del rilascio, motivata dal fatto che nessun componente consuma ancora la
1.1 e che il repository è in versione alfa. Riga di collaudo C-95.

## 3. L'esenzione dal filtro è della superficie, non dell'utente

Nel filtro non c'è nessun controllo sulle capability. Un utente autorizzato che naviga il
sito pubblico vede esattamente quello che vede chiunque altro: i contenuti scaduti non
ricompaiono nei feed, nella mappa per i motori, nelle anteprime incorporate, negli elenchi,
né in una pagina che potrebbe finire in memoria ed essere poi servita ad altri.

Le superfici dove il contenuto scaduto resta raggiungibile sono **due, entrambe esplicite**:

| Superficie | Condizione |
|---|---|
| Amministrazione | l'interrogazione è di amministrazione, e valgono le capability del tipo |
| Interfaccia informatica con contesto di modifica | `context=edit`, dove WordPress pretende già il permesso di modifica sul contenuto |

Ovunque altro il filtro si applica **a chiunque**, autenticato o no. Nessuna esenzione
generale per aver fatto accesso, e nessuna per l'amministratore in quanto tale. Righe C-92,
C-101 e C-112.

L'archivio riservato, che sarebbe una terza superficie, non esiste: è S10.

---

## 4. Pubblico e amministrazione si comportano in modo diverso

Far sparire ovunque un contenuto con la data rotta significa renderlo irreparabile: nessuno
lo trova più per correggerlo.

| Caso | Pubblico | Amministrazione |
|---|---|---|
| Data valida, non scaduta | visibile | visibile |
| Data valida, scaduta | invisibile | visibile |
| **Data mancante** | **invisibile** | **visibile** |
| **Data non valida o corrotta** | **invisibile** | **visibile** |

### 4-bis. Le anomalie: il percorso di lettura non scrive niente

**Questo vale, ed è implementato.** Guardare la data, decidere, e non lasciare traccia. Un
contenuto anomalo molto visitato costa esattamente come uno sano. Niente contatori, niente
scritture sul percorso pubblico, quindi nessun costo e nessuna condizione di concorrenza.

Le anomalie si intercettano in scrittura, dove la validazione rifiuta un valore malformato
prima che entri nella banca dati. È il motivo per cui un'anomalia può esistere soltanto se il
dato è arrivato per migrazione o per scrittura diretta.

**Quali sono le anomalie.** Data assente, valore che non rispetta il formato, data che
rispetta il formato ma non esiste nel calendario, e **più di un valore per la stessa chiave**.
Tutte e quattro rendono il contenuto scaduto: si sbaglia nella direzione sicura. La quarta è
la riga C-114, decisa perché WordPress ammette più righe di metadato con la stessa chiave e la
lettura normale ne restituisce una sola: con due valori diversi la lettura e una condizione
scritta sulla banca dati potrebbero decidere in modo opposto, e vincerebbe la più permissiva.
La scrittura dall'API ripara, riportando il contenuto a un valore solo, e verifica di esserci
riuscita prima di dichiarare successo.

**La schermata che elenca i contenuti anomali non esiste**, e nemmeno la funzione che li
cerca. Erano progettate qui come `conformita_core_scansiona_anomalie()`, una scansione
amministrativa su richiesta che legge e non persiste; sono l'unità **S10** insieme alla riga
di collaudo C-96. Finché non ci sono, un contenuto con la data rotta si trova aprendo
l'amministrazione, dove resta visibile apposta.

## 5. Come si avvia il motore: interno, automatico, idempotente

La stesura precedente esponeva due funzioni pubbliche per avviare il motore e per chiedere se
fosse avviato. Sbagliato: il motore è infrastruttura di core, e accenderlo non deve essere
responsabilità del componente che lo usa. **Entrambe le funzioni sono eliminate
dall'interfaccia pubblica.**

**Come si avvia davvero.**

| Quando | Chi | Cosa succede |
|---|---|---|
| Caricamento del file di **core** | core | Il motore registra i propri filtri. Aggiungere un filtro non richiede che esista nulla, quindi non dipende dall'ordine di caricamento dei plugin né dall'ordine alfabetico delle cartelle |
| Caricamento del file del **componente**, cioè prima che `plugins_loaded` parta | componente | `add_action( 'conformita_core_pronto', ... )`. **Qui, non dopo**: l'azione viene emessa durante `plugins_loaded`, quindi un aggancio aggiunto a priorità 10 arriverebbe a cose fatte |
| `plugins_loaded`, priorità **5** | core | Completa l'avvio ed emette `conformita_core_pronto` |
| Subito dopo, dentro l'azione | componente | La funzione agganciata registra sezione e tipo |

**Correzione di un errore della stesura precedente**, segnalato in revisione. La tabella
diceva che i componenti si agganciano a `conformita_core_pronto` "a `plugins_loaded` priorità
10 in poi, oppure `init`": è sbagliato, perché a quel punto l'azione è già passata e il
callback non verrebbe mai chiamato. L'aggancio si aggiunge al caricamento del file del
componente.

**Un solo modello, non due.** L'alternativa legittima sarebbe eliminare l'azione e far
lavorare i componenti direttamente su `plugins_loaded` a priorità successiva alla 5. È
altrettanto valida, ma le due non si mescolano: si sceglie l'azione dedicata, perché rende
esplicito il momento in cui core è pronto invece di affidarlo a un numero di priorità che
qualcuno prima o poi cambierà.

**Idempotente**: l'avvio è protetto da una guardia interna. Invocato due volte non aggiunge i
filtri due volte e non produce errori.

**La verifica resta, come guardia difensiva.** La registrazione di una sezione controlla
internamente che il motore sia pronto e, se non lo è, fallisce con un errore che dice cosa
manca. Nella pratica non può accadere, perché il motore si accende al caricamento di core:
la guardia esiste per il caso in cui il file del motore non venga caricato, e perché un
meccanismo di conformità che non parte deve impedire l'avvio invece di fallire in silenzio.
È lo stesso trattamento della riga C-85 per l'indicizzazione.

## 6. Il contratto delle funzioni pubbliche

Versione dell'interfaccia: **da 1.1.0 a 1.2.0**, con la nota del punto 2 sul fatto che
contiene anche una restrizione e non solo aggiunte. L'albo dovrà richiedere `1.2.0` e non il
generico `1`, che accetterebbe anche un core privo di questo motore.

**Le funzioni della scadenza sono sei**, e sono queste.

| Funzione | Parametri | Ritorno | Errori |
|---|---|---|---|
| `conformita_core_chiave_fine_pubblicazione()` | nessuno | `string`, la chiave del metadato | nessuno |
| `conformita_core_valida_fine_pubblicazione( $valore )` | `string` | `true` oppure `WP_Error` | `conformita_core_data_formato`, `conformita_core_data_inesistente` |
| `conformita_core_imposta_fine_pubblicazione( $post_id, $data )` | `int`, `string` | `true` oppure `WP_Error` | i due sopra, più `conformita_core_tipo_non_gestito`, `conformita_core_data_non_scritta` e `conformita_core_dato_non_riparato` |
| `conformita_core_fine_pubblicazione( $post_id )` | `int` | `string` la data, `''` se assente, `WP_Error` altrimenti | `conformita_core_tipo_non_gestito`, `conformita_core_dato_duplicato` |
| `conformita_core_istante_scadenza( $post_id )` | `int` | `DateTimeImmutable` nel fuso del sito, oppure `WP_Error` | `conformita_core_data_assente`, `conformita_core_data_non_valida`, più quelli di sopra |
| `conformita_core_scaduto( $post_id )` | `int` | `bool`. Data assente, non valida o duplicata danno scaduto | nessuno: è chiamata nel percorso di lettura e non deve poter fallire |

**Non esistono** `conformita_core_capacita_archivio()` né
`conformita_core_scansiona_anomalie()`. Erano progettate qui e sono l'unità S10.

Tre precisazioni sul comportamento.

**`conformita_core_scaduto()` non prende il parametro `$adesso`.** L'orologio si inietta
dentro la classe e i test usano quello: un parametro del genere, esposto nell'interfaccia
pubblica, prima o poi verrebbe usato per far sembrare non scaduto qualcosa che lo è.

**`conformita_core_imposta_fine_pubblicazione()` è idempotente e ripara.** Impostare due
volte la stessa data restituisce `true` e non un errore, perché la scrittura di WordPress
restituisce falso sia quando fallisce sia quando il valore era già identico, e i due casi si
distinguono rileggendo. Se il contenuto ha più valori per la chiave, li rimuove e ne scrive
uno; se la rimozione o la scrittura non riescono, **restituisce errore invece di dichiarare
un successo che non c'è stato**, perché altrimenti chi chiama crederebbe di aver riparato un
contenuto che resta anomalo.

**Nessuna funzione pubblica avvia il motore o chiede se è avviato**: vedi il punto 5.

---

## 7. Dove si aggancia, e i limiti dichiarati

Gli agganci, con i nomi veri. Due sono da confermare contro WordPress 6.5 in fase di
implementazione, e sono marcati: la scheda fissa il **comportamento osservabile**, che è
quello che il test verifica, e il nome dell'aggancio è un dettaglio che riporterò a lavoro
fatto.

| Percorso | Aggancio | Righe |
|---|---|---|
| Interrogazioni, elenchi, ricerca, feed | `pre_get_posts` **confermato** | C-11, C-12, C-13 |
| Contenuto singolo dopo l'interrogazione | `the_posts` **confermato** | C-10 |
| Mappa per i motori di ricerca | `wp_sitemaps_posts_query_args` **confermato** | C-14 |
| Interfaccia informatica, collezione | **nessun aggancio dedicato**: la collezione passa da `WP_Query`, quindi la coprono i due strati generali. `rest_{$post_type}_query`, che la scheda indicava, non serve | C-15 |
| Interfaccia informatica, singolo | `rest_request_before_callbacks` **confermato**. L'alternativa `rest_prepare_{$post_type}` è stata **scartata in implementazione**: per un tipo consultabile dal web, WordPress applica quel filtro e poi chiama un metodo sull'oggetto restituito per aggiungere un'intestazione, quindi restituire lì un errore produrrebbe un errore fatale al posto di un 404; e lo stesso filtro prepara ogni elemento della collezione, quindi avrebbe richiesto di distinguere a mano il singolo dall'elenco. Il comportamento fissato dal test è "non trovato", non "vietato" e non "200 con i dati", **tranne con `context=edit`**, che non si filtra perché è il canale dell'editor a blocchi | C-16, C-105, C-112 |
| Anteprime incorporate | `oembed_response_data` **confermato** | C-17 |
| XML-RPC | `xmlrpc_prepare_post` **confermato**, che copre sia la lettura del singolo sia quella degli elenchi perché WordPress prepara ogni contenuto di lì. L'alternativa di disattivare XML-RPC per i tipi gestiti è stata scartata: toglierebbe anche la scrittura, che non c'entra con la scadenza | C-18 |
| Pagina dell'allegato | **nessuno dei due indicati**: basta il secondo strato, che guarda la scadenza del contenuto padre e toglie l'allegato dai risultati. `template_redirect` è stato scartato perché non viene emesso quando la richiesta non arriva a disegnare una pagina, quindi il controllo sarebbe mancato proprio dove serve | C-19 |
| Navigazione adiacente | `get_previous_post_where` e `get_next_post_where` **confermati**. Limite dichiarato: qui agisce il solo confronto sulla banca dati, perché non c'è niente su cui agganciare il secondo strato | C-20 |
| `WP_Query` sui tipi registrati, filtri attivi | `pre_get_posts` | C-21 |

**I limiti, dichiarati perché la prima stesura prometteva troppo.**

| Percorso | Coperto? |
|---|---|
| `WP_Query` sui tipi registrati, filtri normali attivi | **sì**, ed è la formulazione corretta di C-21 |
| `WP_Query` con `suppress_filters` | **no.** Chi lo usa disattiva i filtri deliberatamente. Limite noto e documentato |
| `get_post()` sul singolo identificativo | **no.** Non passa dalle interrogazioni |
| Interrogazione SQL diretta | **no**, e non è copribile da nessun componente |
| Lettura diretta dei metadati | **no** |
| Pagina servita da una memoria che risponde prima di WordPress | **no.** È il rischio della riga ALBO-20, e si chiude nel componente dell'albo |

Ne segue una frase da correggere: **il controllo non avviene ogni volta che qualcuno chiede
il contenuto**, avviene ogni volta che la richiesta arriva a WordPress. Se una memoria di
pagina risponde prima, WordPress non viene eseguito.

## 8. Che cosa NON faccio in questa unità

- **Nessuna capability di archivio, nessuna scansione delle anomalie, nessuna differenza di
  comportamento fra le due politiche di scadenza: sono l'unità S10.** Finché S10 non esiste,
  la politica `archivio` è registrabile ma produce il comportamento di `irraggiungibile`, e
  non va consumata contando su una differenza che non c'è.
- Nessun compito pianificato e nessun battito: sono l'unità S6.
- Nessuna consegna degli allegati e nessuna cartella protetta: sono l'unità S5. Finché S5 non
  esiste, **un allegato di un atto scaduto resta scaricabile dal suo indirizzo diretto**: è la
  falla F1, che questa unità chiude solo per la parte delle pagine.
- Nessun cambio di stato dei contenuti.
- Nessuna interfaccia di configurazione e nessuna maschera di scrittura della data.
- Nessuna gestione della memoria di pagina.
- Nessuna riga di codice nel componente dell'albo pretorio.

---

## 9. I file

| File | Cosa |
|---|---|
| `includes/class-conformita-core-scadenza.php` | nuovo, il contratto del dato |
| `includes/class-conformita-core-filtro-scadenza.php` | nuovo, il filtro sui percorsi di lettura |
| `includes/class-conformita-core-sezioni.php` | modificato: vincolo sui caratteri dell'identificativo, e rifiuto della registrazione a motore non avviato |
| `includes/funzioni-api.php` | modificato: le sei funzioni della scadenza elencate al punto 6 |
| `conformita-core.php` | modificato: versione dell'interfaccia a 1.2.0 |
| `tests/scadenza-test.php` | nuovo |
| `tests/sezioni-test.php` | modificato: le due righe nuove sulle sezioni |
| `README.md` | modificato: il contratto del dato, perché è ciò che un altro componente deve sapere |

---

## 10. Le righe di collaudo di questa unità

Il catalogo di collaudo del cantiere è il contratto. Qui c'è la mappa, non una copia: le
righe si leggono là.

**Righe già a catalogo quando l'unità è partita**: C-10..C-21, i dodici percorsi di lettura;
C-22, il contenuto **non** scaduto visibile su tutti e dodici; C-23, il cambio d'ora in
entrambe le direzioni; C-24, il sito con fuso diverso da Roma. Quindici.

**Il compito pianificato fermo non è una prova in più: è la condizione in cui girano tutte.**
Nessun test di questa unità pianifica o esegue il compito automatico, perché l'unità non lo
usa.

**Righe aggiunte durante il lavoro**, ognuna al catalogo prima del proprio test:

| Riga | Cosa verifica |
|---|---|
| C-87 | Ora corrente uguale all'istante di scadenza, e un istante prima |
| C-88 | Data mancante o corrotta: invisibile al pubblico, visibile in amministrazione |
| C-89 | Bozza e contenuto privato: il filtro non li tratta come scaduti né li rende pubblici |
| C-90 | Tipi non registrati in core: non toccati |
| C-92 | Capability sulle superfici pubbliche: non fa ricomparire niente |
| C-93 | `suppress_filters`: documentazione eseguibile di un limite, non prova di conformità |
| C-94 | Guardia difensiva: sezione registrata a motore non avviato, rifiutata |
| C-95 | Identificativo di sezione con il trattino: rifiutato |
| C-97 | Nessuna contaminazione fra sezioni |
| C-98 | Avvio idempotente, e stato osservabile degli agganci |
| C-99 | Impostazione della stessa data due volte: `true`, non errore |
| C-100 | Valore fuori formato e data inesistente: rifiutati con codici distinti |
| C-101 | Superficie di amministrazione: il contenuto scaduto resta visibile |
| C-102 | `fields => 'ids'`: limite dichiarato, agisce il solo primo strato |
| C-103 | `meta_query` di terzi in `OR`: la clausola di core resta in AND |
| C-104 | Clausola di terzi sulla stessa chiave: si riconosce la clausola intera |
| C-105 | La collezione dell'interfaccia informatica resta ben formata |
| C-106..C-110 | Le cinque prove di non vacuità, una per famiglia di aggancio |
| C-111 | L'aggancio della mappa trasforma davvero gli argomenti |
| C-112 | Contesto di modifica: l'atto scaduto resta correggibile |
| C-113 | Vicino con data corrotta: mai il vicino, e le date valide restano |
| C-114 | Più di un valore per la chiave: anomalia, e la scrittura ripara |

**Righe che NON appartengono a questa unità**: C-91 e C-96, che sono l'unità S10. Il catalogo
le tiene in una sezione a parte proprio perché non si contino qui.

### Che cosa è cambiato in questo elenco durante l'implementazione

Tre righe si sono aggiunte in corso d'opera, e il catalogo di collaudo le contiene già.
Ognuna nasce da un caso che la scheda non aveva previsto e che il codice ha reso visibile.

| Riga | Perché non era prevista |
|---|---|
| C-101 | La scheda dava per scontata l'esenzione dell'amministrazione al punto 4 senza collaudarla. Un contenuto con la data rotta deve restare correggibile, ed è un comportamento che va verificato come gli altri |
| C-102 | `WP_Query` con `fields => 'ids'` restituisce le colonne e torna prima di applicare `the_posts`: è lo stesso limite di C-93 su un percorso diverso, e la scheda non lo nominava |
| C-103 | Una `meta_query` di terzi dichiarata in `OR`. Se la clausola di core si accodasse all'elenco esistente invece di annidarlo come sottogruppo, diventerebbe un'alternativa alle altre condizioni e il filtro sarebbe aggirabile senza volerlo. È il difetto che questo blocco ha evitato per costruzione, e la riga esiste perché resti evitato |
| C-104 | Una clausola di terzi **sulla stessa chiave** della scadenza. Nata da una revisione: il riconoscimento di "clausola già presente" guardava la sola chiave del metadato, quindi una condizione di terzi su quella chiave veniva scambiata per la nostra e core non aggiungeva il confronto con la data. Si collauda con `fields => 'ids'`, dove il secondo strato non gira e il difetto diventa visibile |

Il numero **C-96 resta impegnato** dalla riga di questa scheda sulla scansione delle
anomalie: per questo la verifica sulla `meta_query` in OR ha preso C-103 e non il primo
numero libero.

### Come si legge il conteggio dei test, e come non si legge

Il numero di prove e di asserzioni riportato dalla verifica automatica è un **controllo di
esecuzione**: dice che le prove nuove sono state eseguite e non saltate, il che serve perché
un lavoro verde con un test saltato ha lo stesso colore di uno con il test passato.

**Non è una prova di copertura.** Che le righe del catalogo siano coperte lo dimostrano due
cose diverse: la **tracciabilità**, cioè ogni riga del catalogo con il suo test che la nomina,
e le **prove di non vacuità**, che mostrano il test diventare rosso quando il comportamento
si rompe. Un conteggio che cresce è compatibile con dieci prove che non verificano niente.

Precisazione aggiunta dopo una revisione in cui avevo presentato il conteggio come se
dimostrasse più di quello che dimostra.

### Prove di non vacuità, una per famiglia

La prima stesura ne prometteva una sola, sull'interfaccia informatica. Il revisore ha
osservato che dimostra solo la sensibilità di quel test. Cinque, una per famiglia di
aggancio, ognuna documentata con il test che diventa rosso:

| Famiglia | Cosa rompo di proposito |
|---|---|
| Interrogazioni | tolgo il filtro su `pre_get_posts` |
| Interfaccia informatica | tolgo il controllo sul singolo identificativo |
| Mappa per i motori | tolgo l'esclusione dalla mappa |
| Navigazione adiacente | tolgo il filtro sul vicino |
| Pagina dell'allegato | tolgo il controllo sull'allegato |

---

## 11. Nota storica: le decisioni della revisione del 2026-09-09

**Registro di una revisione precedente, non contratto corrente.** Le righe 1 e 3 descrivono
cose che allora si pensava appartenessero a questa unità e che oggi sono l'unità S10: le
sezioni operative qui sopra dicono il perimetro vero. Il registro resta perché il ragionamento
che portò a quelle decisioni vale ancora, e servirà a chi costruirà S10.

| # | Punto sollevato | Decisione |
|---|---|---|
| 1 | Le due politiche erano indistinguibili | La differenza è **una sola casella**: l'indirizzo proprio del contenuto per l'utente autorizzato. Tabella al punto 1-bis, riga C-91 riscritta di conseguenza. L'albo dichiara `irraggiungibile` |
| 2 | L'avvio del motore era API pubblica | **Eliminate** le due funzioni. Filtri registrati al caricamento di core, azione `conformita_core_pronto` su `plugins_loaded` priorità 5, avvio idempotente con guardia, verifica interna alla registrazione della sezione come guardia difensiva |
| 3 | Le anomalie erano sottospecificate e scrivevano in lettura | **Il percorso di lettura non scrive niente.** Validazione in scrittura più una scansione amministrativa su richiesta, che legge e non persiste. `conformita_core_contenuti_anomali()` eliminata, sostituita da `conformita_core_scansiona_anomalie()` con struttura definita e capability richiesta |
| 4 | Il vincolo sugli identificativi non derivava dalla collisione | **Motivazione riscritta**: non c'è collisione, e dirlo era sbagliato. Il vincolo resta per uniformità con i tipi, convenzione dei nomi di capability e prevenzione di una normalizzazione futura. Dichiarato come **restrizione del contratto prima del rilascio**, non come aggiunta |
| 5 | Mancava XML-RPC | Aggiunto al punto 7, riga C-18, con l'alternativa della disattivazione documentata |
| 6 | Servivano i nomi veri degli agganci | Tabella al punto 7. Due marcati **da confermare** contro WordPress 6.5: la scheda fissa il comportamento osservabile, il nome dell'aggancio lo riporto a lavoro fatto |
| 7 | Impostazione del metadato non idempotente | Rilettura del valore per distinguere "già identico" da "fallito". Riga C-99 |
| 8 | `$adesso` finiva nell'interfaccia pubblica | **Tolto.** Orologio iniettato internamente, i test usano quello |
| 9 | La frase sul ritardo dell'inizio | **Eliminata.** Un ritardo può alterare durata ed effetti della pubblicazione. Resta solo che l'inizio è governato dalla macchina a stati dell'albo |
| 10 | C-87 verificava solo l'istante esatto | Adesso verifica anche l'istante immediatamente precedente |
| 11 | C-93 era classificata come prova di conformità | **Riclassificata** come documentazione eseguibile di un limite noto |

## 12. Nota storica: che cosa restava da sapere prima di scrivere

**Il vincolo sui caratteri delle sezioni tocca codice già in `main`.** Nessun componente
registra ancora sezioni, quindi il costo è zero, ed è il momento giusto.

**La versione 1.2.0 va scritta nel README** con la nota che contiene una restrizione: quando
l'albo partirà dovrà chiedere `1.2.0`, e chi legge deve sapere che la 1.1 accettava
identificativi che la 1.2 rifiuta.

**Ordine di lavoro**: prima il vincolo sulle sezioni con la sua riga, poi i test, poi il
motore. In mezzo, la prova di non vacuità per ognuna delle cinque famiglie.

---

## 13. Stato dell'implementazione

Aggiornato mentre il lavoro procede, perché una scheda che descrive solo le intenzioni non
dice a chi rilegge dove si è arrivati.

**Fatto: il contratto del dato.** Chiave, formato, istante, decisione, validazione,
idempotenza della scrittura. Righe C-23, C-24, C-87, C-88, C-99, C-100.

**Fatto: il filtro sui percorsi che passano da `WP_Query`.** Elenchi, ricerca, feed, mappa
per i motori, contenuto singolo, interrogazioni di terzi. Righe C-10, C-11, C-12, C-13,
C-14, C-21, C-22, più C-89, C-90, C-92, C-93, C-94, C-97, C-98, C-101, C-102, C-103,
C-104.

Il filtro è a **due strati**, e la ragione è che nessuno dei due basta da solo. Il primo
aggiunge una condizione sui metadati all'interrogazione: è veloce e tiene coerente
l'impaginazione, ma confronta stringhe, quindi una data corrotta che ordina alta
(`9999-99-99`) lo supererebbe. Il secondo ricontrolla ogni contenuto restituito con la
stessa funzione che decide la scadenza altrove, ed è lo strato che fa fede.

Il primo strato si aggiunge **solo quando l'interrogazione riguarda esclusivamente tipi
gestiti**. È la precauzione più importante del blocco: aggiungere quella condizione a
un'interrogazione mista cancellerebbe dal sito tutti gli articoli, che quel metadato non ce
l'hanno. Sulle interrogazioni miste lavora il secondo strato, che guarda un contenuto alla
volta.

**Due correzioni entrate dopo la prima revisione del filtro.**

La prima era una falla vera. Il riconoscimento di "clausola già presente", che esiste solo
perché la mappa per i motori passa da due agganci, si accontentava di trovare la chiave del
metadato. Un componente di terzi che interroga la stessa chiave, per esempio con `EXISTS`
per elencare i contenuti che hanno una data di fine, veniva scambiato per la clausola di
core, e core credeva di aver già aggiunto il confronto con la data senza averlo fatto. Nelle
interrogazioni normali il secondo strato copriva il difetto; con `fields => 'ids'` o con
`suppress_filters` il contenuto scaduto sarebbe uscito davvero, il che contraddiceva C-21 e
allargava di molto il limite dichiarato da C-102. Adesso si riconosce solo la clausola
intera: chiave, valore corrente, `>=`, `CHAR`, e lo stesso numero di voci. Riga C-104.

La seconda era una motivazione falsa, non un difetto del codice. La prova C-98 sosteneva che
agganciare due volte lo stesso metodo statico avrebbe fatto girare il filtro due volte:
WordPress identifica ogni aggancio con una chiave univoca, e per un metodo statico quella
chiave è deterministica, quindi la seconda registrazione sostituisce la prima invece di
aggiungersi. Togliendo la guardia da `avvia()`, quella prova sarebbe rimasta verde. La prova
è stata riscritta come verifica dello stato osservabile degli agganci, adesso conta anche
`wp_sitemaps_posts_query_args`, che prima era escluso, e contiene la dimostrazione che il
conteggio sa vedere un doppione. Le ragioni vere della guardia sono scritte accanto ad
`avvia()`: `$avviato` è la condizione che la registrazione della sezione legge per rifiutarsi
a motore spento, e la deduplicazione di WordPress non varrebbe più se uno di questi agganci
diventasse una chiusura.

**Fatto: i percorsi che non passano da `WP_Query`.** Interfaccia informatica sul singolo
identificativo (C-16), anteprime incorporate (C-17), pubblicazione remota (C-18), pagina
dell'allegato (C-19), navigazione adiacente (C-20). Più C-15 e C-105, che passano da
`WP_Query` e non hanno avuto bisogno di nessun aggancio.

**Fatto: le cinque prove di non vacuità.** Righe C-106..C-110. Ognuna spegne un solo
aggancio e pretende che il contenuto scaduto ricompaia: se ricompare, la riga corrispondente
stava misurando il filtro; se non ricompare, stava misurando altro. Prima di spegnere un
aggancio si verifica che ci fosse e dopo che non ci sia più, perché con un nome sbagliato lo
spegnimento non farebbe niente e la prova fallirebbe accusando il filtro.

**Che cosa hanno trovato.** Una cosa, ed è C-108: **l'aggancio dedicato alla mappa per i
motori di ricerca oggi non regge niente.** Spegnendo lui solo il contenuto scaduto resta
fuori dalla mappa, perché la mappa passa comunque da un'interrogazione e a tenerlo fuori sono
i due strati generali. Il commento accanto al codice lo diceva già come ipotesi; adesso è una
prova che gira a ogni verifica. L'aggancio resta come rete di sicurezza per il caso in cui
una versione futura di WordPress costruisse la mappa senza interrogazione, ma va saputo che
oggi non è lui a reggere: un aggancio che nessuna prova esercita può rompersi in silenzio.

**Fatto: le prove sul sito vero.** Righe P-33..P-37 in `collaudo-di-rilascio.md` del repo di
progetto, come prescrive il punto 9 della scheda di lavorazione. Quattro delle cinque si
fanno da fuori senza credenziali, quindi entrano nel controllo automatico del periodo di
chiusura dell'ente.

**Due errori di percorso, tutti e due nelle prove e non nel filtro**, vale la pena
ricordarli perché sono la stessa famiglia. La riga C-17 è andata rossa due volte: la prima
perché l'indirizzo del contenuto non portava a niente, essendo la prova appoggiata a regole
di riscrittura che non si erano formate; la seconda perché passavo i parametri della
richiesta dentro il percorso della rotta, che li rende parte del nome e fa rispondere
"nessuna rotta corrispondente". **Tutti e due si presentavano come un 404**, cioè
indistinguibili dal rifiuto giusto se non si guarda il motivo. Da lì vengono due abitudini
che restano: le prove verificano il motivo del rifiuto e non solo il numero, e verificano il
passaggio intermedio prima di verificare l'esito.

**Da fare, e non è di questo blocco:** l'azione `conformita_core_pronto` del punto 5. Il
motore si accende già al caricamento di core, che è la metà che riguarda la conformità; la
stretta di mano verso i componenti è un'unità sua, e prima le serve la sua riga di catalogo.

---

## 14. La revisione del 2026-09-11 e i tre difetti che ha trovato

Registrati qui perché due dei tre non erano visibili da nessuna prova, e il terzo lo era ma
nessuna prova lo guardava.

**1. L'interfaccia informatica non è solo pubblica.** Il filtro rispondeva "non trovato" a
qualunque richiesta del singolo contenuto scaduto. Ma quel canale è anche quello con cui
l'editor a blocchi apre un contenuto per modificarlo: un atto con la data di fine sbagliata
compariva nell'elenco amministrativo e non si apriva, quindi non era correggibile. È l'esatto
contrario di quello che la riga C-101 pretende, ed era scritto nero su bianco in un test che
lo dichiarava come comportamento voluto. La regola corretta lega l'esenzione al **contesto**
e non all'utente: `context=edit` passa, ogni altro contesto no. Non apre nulla a nessuno,
perché per quel contesto WordPress pretende già il permesso di modifica. Riga C-112.

**2. Un percorso dichiarato non può avere il limite di un percorso esterno.** La navigazione
adiacente confrontava le date come stringhe, quindi un valore corrotto che ordina alto
(`9999-99-99`) restava raggiungibile come vicino. L'avevo classificato come limite
dichiarato, insieme a `suppress_filters` e a `fields => 'ids'`. Era una classificazione
sbagliata: quei due li sceglie chi chiama, mentre la navigazione adiacente è uno dei percorsi
che questo componente dichiara di coprire, e la riga C-88 dice che una data corrotta rende il
contenuto scaduto. Un percorso dichiarato che la mostra contraddice il contratto. La
condizione adesso ammette soltanto date che esistono davvero. Riga C-113.

**Una conseguenza da valutare più avanti.** La stessa condizione, applicata al primo strato,
chiuderebbe anche il limite di `fields => 'ids'` della riga C-102, che oggi resta aperto per
lo stesso motivo tecnico. Non si fa adesso perché il primo strato usa la forma dichiarativa
delle condizioni sui metadati e passarlo a una condizione scritta a mano cambierebbe anche il
conteggio dei risultati e l'impaginazione. Va soppesato, non dato per scontato.

**3. Lo spegnimento diceva una cosa e ne faceva un'altra.** L'accensione registrava otto
agganci, lo spegnimento ne toglieva tre, e `$avviato` passava comunque a falso: il motore si
dichiarava spento mentre cinque agganci continuavano a girare. Nessun errore possibile,
perché uno spegnimento incompleto non fallisce. Accensione e spegnimento adesso leggono lo
stesso elenco, e una prova verifica dall'esterno che dopo lo spegnimento nessuno degli otto
risponda più.

**Due osservazioni minori, accolte.** La riga C-104 diceva "il contenuto scaduto esce", che
si poteva leggere come "viene restituito": riscritta in "resta fuori dai risultati".
E l'aggancio dedicato alla mappa per i motori, che la prova C-108 dimostra non reggere oggi
nessun comportamento, ha ora una prova diretta che ne verifica la trasformazione degli
argomenti: una rete di sicurezza che nessuno prova può rompersi restando verde. Riga C-111.

---

## 15. Nota storica: il perimetro che questa scheda prometteva in più

**Nota storica, non errata corrige.** Le sezioni operative qui sopra sono state riscritte e
dicono già il perimetro vero: questa nota resta per chi si chiede perché altrove, per esempio
in una richiesta di unione o in un messaggio, possa aver letto qualcosa di diverso.

Segnalato in revisione l'11 settembre. Questa scheda progettava quattro cose che l'unità non
ha costruito e che contava come sue:

| Cosa | Dove era promessa | Stato vero |
|---|---|---|
| `conformita_core_capacita_archivio( $sezione )` | tabella del punto 6 | **non esiste** |
| `conformita_core_scansiona_anomalie( $limite )` | tabella del punto 6 e punto 4-bis | **non esiste** |
| Riga C-91, la differenza fra le due politiche di scadenza | punto 1-bis e punto 10 | **nessun test** |
| Riga C-96, la scansione delle anomalie senza capability | punto 10 | **nessun test** |

Ne seguivano tre affermazioni false. Che il filtro leggesse la politica di scadenza: non la
legge mai. Che `archivio` e `irraggiungibile` si comportassero in modo diverso: oggi fanno
esattamente la stessa cosa. E il conteggio "quindici esistenti più tredici nuove", che
includeva due righe mai scritte.

**La decisione.** Le quattro cose si spostano in una unità nuova, **S10, archivio riservato e
scansione delle anomalie**, che dipende da S4 ed è registrata in `unita-di-lavoro.md` del
cantiere. Non si costruiscono adesso, per due ragioni: non servono all'albo, che dichiara
`irraggiungibile`, e aggiungerle qui gonfierebbe una richiesta di unione che è già lunga.

**Il perimetro di S4 è quindi, per intero e senza altro:** il contratto del dato di fine
pubblicazione, e il filtro che applica la scadenza sui dodici percorsi di lettura.

**Le funzioni pubbliche della scadenza sono sei**, non otto: `chiave_fine_pubblicazione`,
`valida_fine_pubblicazione`, `imposta_fine_pubblicazione`, `fine_pubblicazione`,
`istante_scadenza`, `scaduto`. Le due della tabella del punto 6 che non ci sono arrivano con
S10.

**Conseguenza da dichiarare a chi dipende da core.** La politica `archivio` è registrabile,
la validazione la accetta, ma il suo accesso riservato non esiste: un componente che la
dichiara ottiene oggi lo stesso comportamento di `irraggiungibile`. Non va consumata contando
su una differenza che non c'è. È scritto anche nel README, perché è lì che guarda chi scrive
un componente.

### Il caso limite dei metadati duplicati

Trovato nella stessa revisione. WordPress ammette più righe di metadato con la stessa chiave,
e la lettura normale ne restituisce una sola. La condizione sulla banca dati della navigazione
adiacente, scritta senza raggruppamento, avrebbe accettato il contenuto se *almeno una* riga
fosse stata valida e futura: con un valore corrotto e uno futuro le due strade avrebbero
deciso in modo opposto, e su quel percorso, che non ha un secondo strato, avrebbe vinto la più
permissiva.

**Deciso ora, prima che il caso si presentasse**: un valore solo per chiave è il contratto, i
duplicati sono un'anomalia, e un'anomalia rende scaduto come qualsiasi altra. La lettura dà
errore, la condizione sulla banca dati raggruppa e pretende una riga sola, e la scrittura
dall'API ripara, perché altrimenti un contenuto con due date resterebbe invisibile per sempre.
Riga C-114.

### Che cosa resta scelto e non corretto

Il limite di `fields => 'ids'` della riga C-102 **resta aperto**. Estendere a tutte le
interrogazioni la condizione scritta a mano chiuderebbe anche quello, ma cambierebbe la forma
delle condizioni sui metadati, quindi il conteggio dei risultati e l'impaginazione, e
andrebbe misurata sulle prestazioni. È una unità sua, non una riga di questa.
