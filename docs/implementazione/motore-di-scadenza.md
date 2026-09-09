# Motore di scadenza: scheda di lavorazione

**Repository: `wp-conformita-core`.** Branch `claude/m1-motore-scadenza`, diramato da `main`
al commit `54e4498`. Unità S4 del piano, righe di collaudo C-10..C-24 più le righe nuove
proposte al punto 10.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** L'albo, più
avanti e in un'altra unità, si limiterà a: dichiarare la politica di scadenza della propria
sezione, scrivere il metadato di fine pubblicazione definito qui, e assegnare la capability
di archivio ai propri ruoli. Nessuna logica di scadenza nell'albo, mai: se ci finisse,
esisterebbero due verità sulla stessa cosa.

Scheda scritta prima del codice e non ancora autorizzata. Sostituisce la prima stesura, che
descriveva il comportamento senza specificare il contratto.

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

La ragione è di sostanza e non di gusto. L'obbligo di legge si conta in giorni: un atto si
pubblica per quindici giorni, non per 1.296.000 secondi. E c'è un motivo tecnico che decide
la questione: se si salvasse un istante calcolato al momento della pubblicazione, un cambio
d'ora successivo lo sposterebbe di un'ora rispetto alla mezzanotte civile. Salvando la data e
calcolando l'istante alla lettura, la scadenza cade sempre alla mezzanotte giusta.

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

### 1.4 Perché la data di inizio non sta qui

L'albo ha anche una data di inizio, e non entra in questo meccanismo. Un atto non ancora
iniziato non deve essere pubblico, ma quel controllo lo fa lo **stato** dell'atto, cioè la
macchina a stati ALBO-22: finché non è pubblicato, non è pubblicato.

L'asimmetria è voluta e vale la pena dirla. Se un atto comincia a essere visibile qualche ora
tardi, non succede niente. Se un atto smette di essere invisibile qualche ora tardi, succede
esattamente la cosa che questo componente esiste per impedire. Il rischio è asimmetrico,
quindi il trattamento lo è.

---

## 2. Il contratto delle capability, e un difetto trovato scrivendo questa scheda

La politica `archivio` prevede che il contenuto scaduto resti raggiungibile a chi è
autorizzato. Serve quindi una capability, e la domanda del revisore era come si determina.

**Deriva dall'identificativo della sezione**, perché la politica di scadenza sta sulla
sezione e non sul tipo: `conformita_core_archivio_<sezione>`.

**E qui c'è il difetto.** Gli identificativi di sezione oggi non hanno nessun controllo sui
caratteri ammessi: `registra()` verifica solo che non siano vuoti e che non siano duplicati.
Derivare una capability da una stringa libera riapre **esattamente la collisione della riga
C-86**, quella corretta stamattina sui tipi: due sezioni scritte in modo diverso potrebbero
arrivare alla stessa capability, e chi è autorizzato sull'archivio di una lo sarebbe anche
sull'altra.

**Conseguenza per questa unità**: prima di derivare qualunque capability dalle sezioni, gli
identificativi di sezione ricevono lo stesso vincolo dei tipi, cioè lettere minuscole, cifre e
trattino basso, con la sua riga di collaudo. È lavoro in più rispetto alla scheda precedente,
ma è la condizione perché il resto sia sicuro.

---

## 3. Che cosa la capability consente, e che cosa non consente

Il punto più delicato della revisione, e aveva ragione: la prima stesura diceva che il filtro
non si applica a chi ha la capability. Formulata così sarebbe pericolosa.

**L'esenzione è legata alla superficie, non all'utente.** Un utente autorizzato che naviga il
sito pubblico vede esattamente quello che vede chiunque altro: gli atti scaduti non
ricompaiono nei feed, nella mappa per i motori, nelle anteprime incorporate, negli elenchi,
né in una pagina che potrebbe finire in memoria e poi essere servita ad altri.

Le superfici dove il contenuto scaduto è raggiungibile sono **due, entrambe esplicite**:

| Superficie | Condizione |
|---|---|
| Amministrazione | interrogazione di amministrazione, con la capability del tipo |
| Archivio riservato | superficie dedicata che core contrassegna, autenticata, con intestazioni che ne vietano la memorizzazione pubblica |

Ovunque altro il filtro si applica **a chiunque**, autenticato o no. Nessuna esenzione
generale per il fatto di aver fatto accesso, e nessuna per l'amministratore in quanto tale.

---

## 4. Pubblico e amministrazione si comportano in modo diverso

L'altra correzione del revisore, e anche questa era giusta. Far sparire ovunque un contenuto
con la data rotta significa renderlo irreparabile: nessuno lo trova più per correggerlo.

| Caso | Pubblico | Amministrazione e archivio autorizzato |
|---|---|---|
| Data valida, non scaduta | visibile | visibile |
| Data valida, scaduta | invisibile | visibile secondo la politica |
| **Data mancante** | **invisibile** | **visibile, con segnalazione** |
| **Data non valida o corrotta** | **invisibile** | **visibile, con segnalazione** |

La pubblicazione di un contenuto senza data valida è impedita a monte dal componente, che
valida in scrittura: i due casi anomali qui sopra descrivono un dato già finito nella banca
dati, per migrazione o per scrittura diretta, non un percorso normale.

**La segnalazione è persistente e non chiacchierona.** Un contatore per identificativo di
contenuto e un avviso in amministrazione che elenca i contenuti anomali, non una riga di
registro a ogni richiesta: un contenuto anomalo molto visitato riempirebbe il disco.

---

## 5. Il meccanismo non avviato: la prima stesura diceva una cosa impossibile

Diceva che se il meccanismo non è avviato il contenuto non viene mostrato. Non può funzionare:
se il filtro non è agganciato, non c'è niente che nasconda.

**Comportamento corretto**: la registrazione di una sezione che dichiara una politica di
scadenza **fallisce** se il motore non risulta avviato, con un errore che dice cosa manca.
È lo stesso trattamento della riga C-85 per l'indicizzazione, e per la stessa ragione: un
meccanismo di conformità che non parte deve impedire l'avvio, non fallire in silenzio.

---

## 6. Il contratto delle funzioni pubbliche

Versione dell'interfaccia: **da 1.1.0 a 1.2.0**. L'albo dovrà richiedere `1.2.0` e non il
generico `1`, che accetterebbe anche un core privo di questo motore.

| Funzione | Parametri | Ritorno | Errori |
|---|---|---|---|
| `conformita_core_avvia_motore_scadenza()` | nessuno | `true`, oppure `WP_Error` se già avviato | `conformita_core_motore_gia_avviato` |
| `conformita_core_motore_scadenza_avviato()` | nessuno | `bool` | nessuno |
| `conformita_core_chiave_fine_pubblicazione()` | nessuno | `string`, la chiave del metadato | nessuno |
| `conformita_core_valida_fine_pubblicazione( $valore )` | `string` | `true` oppure `WP_Error` | `conformita_core_data_formato`, `conformita_core_data_inesistente` |
| `conformita_core_imposta_fine_pubblicazione( $post_id, $data )` | `int`, `string` | `true` oppure `WP_Error` | i due sopra, più `conformita_core_tipo_non_gestito` |
| `conformita_core_fine_pubblicazione( $post_id )` | `int` | `string` la data, `''` se assente, `WP_Error` se il tipo non è gestito | `conformita_core_tipo_non_gestito` |
| `conformita_core_istante_scadenza( $post_id )` | `int` | `DateTimeImmutable` nel fuso del sito, oppure `WP_Error` | `conformita_core_data_assente`, `conformita_core_data_non_valida` |
| `conformita_core_scaduto( $post_id, $adesso = null )` | `int`, `DateTimeInterface|null` | `bool`. **Un contenuto con data assente o non valida risulta scaduto**, cioè non pubblicabile | nessuno: la funzione non fallisce, perché è chiamata nel percorso di lettura |
| `conformita_core_capacita_archivio( $sezione )` | `string` | `string` il nome della capability, oppure `WP_Error` | `conformita_core_sezione_non_registrata` |
| `conformita_core_contenuti_anomali()` | nessuno | `array` di identificativi con il motivo | nessuno |

Due scelte da spiegare. **`conformita_core_scaduto()` non restituisce mai un errore**: viene
chiamata dentro il filtro di lettura, e una funzione che può fallire nel percorso di lettura è
una funzione che prima o poi lascia passare qualcosa mentre qualcuno gestisce l'eccezione. Il
parametro `$adesso` esiste solo per i test, e ha un valore predefinito che è l'ora vera.

**`conformita_core_imposta_fine_pubblicazione()` esiste** perché il componente non deve
scrivere il metadato a mano: se lo facesse, la validazione sarebbe facoltativa.

---

## 7. Dove si aggancia, e i limiti dichiarati

**Si aggancia a**: `pre_get_posts` per le interrogazioni, i filtri di `WP_Query` per il
contenuto singolo, la mappa per i motori di ricerca, l'interfaccia informatica sia in
collezione sia sul singolo, le anteprime incorporate, la navigazione fra contenuti adiacenti,
la pagina dell'allegato, i feed.

**I limiti, dichiarati perché la prima stesura prometteva troppo.** La riga C-21 diceva
"query dirette di terzi" e la scheda diceva che il filtro copre ogni interrogazione scritta da
altri. Non è vero, e va scritto dove qualcuno lo leggerà.

| Percorso | Coperto? |
|---|---|
| `WP_Query` sui tipi registrati, filtri normali attivi | **sì**, ed è la formulazione corretta di C-21 |
| `WP_Query` con `suppress_filters` | **no.** Chi lo usa disattiva i filtri di WordPress deliberatamente. Documentato e collaudato come limite noto |
| `get_post()` sul singolo identificativo | **no.** Non passa dalle interrogazioni. Il contenuto è protetto dove viene reso, non dove viene letto in memoria |
| Interrogazione SQL diretta | **no**, e non è copribile da nessun componente |
| Lettura diretta dei metadati | **no** |
| Pagina servita da una memoria che risponde prima di WordPress | **no.** È il rischio della riga ALBO-20, e si chiude nel componente dell'albo, non qui |

Questo cambia anche una frase della prima stesura: **non è vero che il controllo avviene ogni
volta che qualcuno chiede il contenuto**. Avviene ogni volta che la richiesta arriva a
WordPress. Se una memoria di pagina risponde prima, WordPress non viene eseguito.

---

## 8. Che cosa NON faccio in questa unità

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
| `includes/class-conformita-core-scadenza.php` | nuovo, il motore |
| `includes/class-conformita-core-sezioni.php` | modificato: vincolo sui caratteri dell'identificativo, e rifiuto della registrazione a motore non avviato |
| `includes/funzioni-api.php` | modificato: le dieci funzioni del punto 6 |
| `conformita-core.php` | modificato: versione dell'interfaccia a 1.2.0 |
| `tests/scadenza-test.php` | nuovo |
| `tests/sezioni-test.php` | modificato: le due righe nuove sulle sezioni |
| `README.md` | modificato: il contratto del dato, perché è ciò che un altro componente deve sapere |

---

## 10. La matrice dei test, con il conteggio giusto

La prima stesura diceva "quindici prove più tre", che era un doppio conteggio. Il conteggio
vero:

| Righe | Quante | Cosa |
|---|---|---|
| C-10..C-21 | **12** | I dodici percorsi di lettura |
| C-22 | 1 | Contenuto **non** scaduto visibile su tutti e dodici |
| C-23 | 1 | Cambio d'ora, marzo e ottobre |
| C-24 | 1 | Sito con fuso diverso da Roma |
| | **15** | **totale del catalogo esistente** |

**Il cron fermo non è una prova in più: è la condizione in cui girano tutte le dodici** di
C-10..C-21. Nessun test di questa unità pianifica o esegue il compito automatico, perché
l'unità non lo usa.

Righe nuove da aggiungere al catalogo, numerate dopo C-86:

| Riga | Cosa verifica |
|---|---|
| C-87 | Ora corrente **esattamente uguale** all'istante di scadenza: scaduto |
| C-88 | Data mancante e data corrotta: invisibile al pubblico, **visibile in amministrazione**, segnalata |
| C-89 | Bozza e contenuto privato: il filtro non li tratta come scaduti né li rende pubblici |
| C-90 | Tipo di WordPress non registrato in core: **non toccato**, nessun filtro applicato |
| C-91 | Due sezioni con politiche di scadenza opposte nello stesso sito, nei due ordini di caricamento |
| C-92 | Utente con la capability di archivio sulle superfici pubbliche: **non fa ricomparire niente**, su nessuna delle dodici |
| C-93 | `WP_Query` con `suppress_filters`: il contenuto scaduto **passa**, e il test lo fissa come limite noto e documentato |
| C-94 | Sezione con politica di scadenza registrata a motore non avviato: **registrazione rifiutata** |
| C-95 | Identificativo di sezione con il trattino: rifiutato, e due identificativi che differiscono solo per quello non arrivano alla stessa capability |
| C-96 | Contenuto anomalo richiesto molte volte: **una sola segnalazione**, non una per richiesta |
| C-97 | Nessuna contaminazione fra tipi e sezioni: il filtro di una sezione non tocca i contenuti di un'altra |

Totale: **15 esistenti più 11 nuove, 26**.

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

## 11. Che cosa resta da decidere prima di scrivere

Niente di bloccante, ma due cose vanno dette perché Andrea le veda.

**Il vincolo sui caratteri delle sezioni è una modifica a codice già in `main`.** Se un
componente avesse già registrato una sezione con il trattino smetterebbe di funzionare. Oggi
non esiste nessun componente che registri sezioni, quindi il costo è zero, ed è il momento
giusto per farlo.

**La versione dell'interfaccia a 1.2.0 va comunicata**: nessun componente la richiede ancora,
ma quando l'albo partirà dovrà chiedere `1.2.0`. Va scritto nel README, altrimenti si scopre
al primo avvio che fallisce.
