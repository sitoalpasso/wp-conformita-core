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

### 1.4 La data di inizio non sta qui

L'albo ha anche una data di inizio, e non entra in questo meccanismo: quando un atto diventa
pubblico lo decide il suo **stato**, cioè la macchina a stati ALBO-22 del componente. Finché
non è pubblicato, non è pubblicato.

---

## 1-bis. Che cosa distingue le due politiche di scadenza

La stesura precedente descriveva `irraggiungibile` e `archivio` in modo identico, e la riga
C-91 avrebbe collaudato una differenza inesistente. La differenza c'è, ed è **una sola**:
che cosa risponde l'indirizzo proprio del contenuto, dopo la scadenza, a un utente
autenticato che possiede la capability di archivio.

| | Dodici percorsi pubblici | Amministrazione | Indirizzo proprio, utente con capability archivio | Interfaccia informatica pubblica |
|---|---|---|---|---|
| `irraggiungibile` | invisibile | visibile | **non trovato**, anche per l'autorizzato | assente |
| `archivio` | invisibile | visibile | **200, solo per l'autorizzato**, con divieto di indicizzazione e divieto di memorizzazione | assente |

Fuori da quella casella le due politiche fanno la stessa cosa, e nessuna delle due fa
ricomparire niente negli elenchi, nei feed, nella mappa per i motori, nelle anteprime
incorporate o nell'interfaccia informatica pubblica. Con `irraggiungibile` il contenuto
scaduto resta raggiungibile **solo da una schermata di amministrazione dedicata**, che non è
questa unità.

**L'albo dichiarerà `irraggiungibile`**, coerentemente con **ALBO-05** ("atto defisso,
utente anonimo, tutti i percorsi C-10..C-21: irraggiungibile; in archivio solo con capability
dedicata"): l'atto defisso esce dalla vista e l'archivio è una schermata riservata, non un
indirizzo che continua a rispondere. *La stesura precedente citava ALBO-21, che invece
riguarda la scadenza nei giorni dei cambi d'ora: riferimento sbagliato, decisione giusta.*

La riga C-91 collauda esattamente quella casella, nei due sensi: con `irraggiungibile`
l'autorizzato riceve "non trovato"; con `archivio` riceve il contenuto, e un anonimo riceve
"non trovato" in entrambi i casi.

## 2. Il contratto delle capability, e una motivazione che avevo sbagliato

La politica `archivio` prevede che il contenuto scaduto resti raggiungibile a chi è
autorizzato. La capability **deriva dall'identificativo della sezione**, perché la politica
di scadenza sta sulla sezione: `conformita_core_archivio_<sezione>`.

**Correzione della stesura precedente.** Avevo scritto che senza un vincolo sui caratteri si
riaprirebbe la collisione della riga C-86. **Non è vero**, ed è giusto che il revisore lo
abbia contestato: la collisione di C-86 nasceva perché il codice *trasformava* il trattino in
trattino basso. Qui non si trasforma niente, quindi `sezione-a` e `sezione_a` produrrebbero
due capability diverse. Nessuna collisione.

**Il vincolo lo mettiamo lo stesso, per tre ragioni vere.**

1. **Una regola sola invece di due.** Gli identificativi di tipo ammettono lettere minuscole,
   cifre e trattino basso. Farne una diversa per le sezioni obbliga chi scrive un componente
   a ricordarsene due, e la seconda si sbaglia.
2. **I nomi di capability con il trattino sono fuori convenzione in WordPress**, e strumenti
   che le elencano o le filtrano assumono spesso l'insieme `[a-z_]`.
3. **Toglie un piede di porco futuro.** Se un domani qualcuno normalizzasse
   l'identificativo prima di derivarne la capability, per esempio con `sanitize_key`, la
   collisione comparirebbe davvero. Vietare adesso il carattere che la produrrebbe costa
   zero.

**Ed è una modifica incompatibile, non un'aggiunta.** L'interfaccia 1.1 accetta identificativi
di sezione con qualunque carattere; questa versione li restringe. Formalmente sarebbe un
cambio di numero maggiore. Si accetta come **correzione del contratto prima del rilascio**,
motivata dal fatto che nessun componente consuma ancora la 1.1 e che il repository è in
versione alfa. Va scritta così nel README e nelle note di versione, senza far finta che sia
additiva.

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

Far sparire ovunque un contenuto con la data rotta significa renderlo irreparabile: nessuno
lo trova più per correggerlo.

| Caso | Pubblico | Amministrazione e archivio autorizzato |
|---|---|---|
| Data valida, non scaduta | visibile | visibile |
| Data valida, scaduta | invisibile | visibile secondo la politica |
| **Data mancante** | **invisibile** | **visibile** |
| **Data non valida o corrotta** | **invisibile** | **visibile** |

### 4-bis. Le anomalie: nessuna scrittura durante la lettura

La stesura precedente prevedeva un contatore per identificativo, aggiornato mentre si legge.
Era sottospecificato e soprattutto sbagliato: introduceva scritture nella banca dati sul
percorso pubblico, con il costo e le condizioni di concorrenza che ne seguono.

**Il percorso di lettura non scrive niente.** Guarda la data, decide, e non lascia traccia.
Un contenuto anomalo molto visitato costa esattamente come uno sano.

Le anomalie si intercettano in due momenti, entrambi fuori dal percorso pubblico:

1. **In scrittura**, dove la validazione rifiuta un valore malformato prima che entri nella
   banca dati. È il percorso normale, ed è il motivo per cui un'anomalia può esistere solo
   se il dato è arrivato per migrazione o per scrittura diretta.
2. **Con una scansione amministrativa** invocata su richiesta, mai automatica, che legge e
   non scrive.

La funzione `conformita_core_contenuti_anomali()` della stesura precedente è **eliminata** e
sostituita da:

| Funzione | Parametri | Ritorno | Autorizzazione |
|---|---|---|---|
| `conformita_core_scansiona_anomalie( $limite = 200 )` | `int` | array di voci `array( 'post_id' => int, 'tipo' => string, 'sezione' => string, 'motivo' => 'assente'\|'formato'\|'inesistente', 'valore' => string )`, ordinate per `post_id` crescente | richiede `manage_options`, altrimenti `WP_Error` |

Non memorizza esiti, non tiene contatori, non va ripulita quando una data viene corretta:
alla scansione successiva l'anomalia semplicemente non c'è più. Un contenuto cancellato non
lascia niente da ripulire perché non c'è niente di persistito.

La schermata che mostra questi risultati **non è in questa unità**.

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

| Funzione | Parametri | Ritorno | Errori |
|---|---|---|---|
| `conformita_core_chiave_fine_pubblicazione()` | nessuno | `string`, la chiave del metadato | nessuno |
| `conformita_core_valida_fine_pubblicazione( $valore )` | `string` | `true` oppure `WP_Error` | `conformita_core_data_formato`, `conformita_core_data_inesistente` |
| `conformita_core_imposta_fine_pubblicazione( $post_id, $data )` | `int`, `string` | `true` oppure `WP_Error` | i due sopra, più `conformita_core_tipo_non_gestito` |
| `conformita_core_fine_pubblicazione( $post_id )` | `int` | `string` la data, `''` se assente, `WP_Error` se il tipo non è gestito | `conformita_core_tipo_non_gestito` |
| `conformita_core_istante_scadenza( $post_id )` | `int` | `DateTimeImmutable` nel fuso del sito, oppure `WP_Error` | `conformita_core_data_assente`, `conformita_core_data_non_valida` |
| `conformita_core_scaduto( $post_id )` | `int` | `bool`. Un contenuto con data assente o non valida risulta scaduto | nessuno: è chiamata nel percorso di lettura e non deve poter fallire |
| `conformita_core_capacita_archivio( $sezione )` | `string` | `string` il nome della capability, oppure `WP_Error` | `conformita_core_sezione_non_registrata` |
| `conformita_core_scansiona_anomalie( $limite = 200 )` | `int` | array di voci come al punto 4-bis | `conformita_core_permesso_negato` |

Tre precisazioni che la stesura precedente sbagliava o taceva.

**`conformita_core_scaduto()` non prende più il parametro `$adesso`.** Esisteva "solo per i
test" e finiva comunque nell'interfaccia pubblica, dove qualcuno prima o poi lo avrebbe usato
per far sembrare non scaduto qualcosa che lo è. L'orologio si inietta **internamente**: la
classe accetta un orologio sostituibile, e i test usano quello. La funzione pubblica prende
solo l'identificativo.

**`conformita_core_imposta_fine_pubblicazione()` è idempotente.** `update_post_meta()`
restituisce `false` sia quando fallisce sia quando il valore era già identico. Distinguere i
due casi rileggendo il valore è obbligatorio: impostare due volte la stessa data
restituisce `true`, non un errore.

**Non esiste nessuna funzione pubblica per avviare il motore o per chiedere se è avviato**:
vedi il punto 5.

---

## 7. Dove si aggancia, e i limiti dichiarati

Gli agganci, con i nomi veri. Due sono da confermare contro WordPress 6.5 in fase di
implementazione, e sono marcati: la scheda fissa il **comportamento osservabile**, che è
quello che il test verifica, e il nome dell'aggancio è un dettaglio che riporterò a lavoro
fatto.

| Percorso | Aggancio | Righe |
|---|---|---|
| Interrogazioni, elenchi, ricerca, feed | `pre_get_posts` | C-11, C-12, C-13 |
| Contenuto singolo dopo l'interrogazione | `the_posts` | C-10 |
| Mappa per i motori di ricerca | `wp_sitemaps_posts_query_args` | C-14 |
| Interfaccia informatica, collezione | `rest_{$post_type}_query` | C-15 |
| Interfaccia informatica, singolo | **da confermare**: `rest_request_before_callbacks` oppure `rest_prepare_{$post_type}`. Il comportamento fissato dal test è "non trovato", non "vietato" e non "200 con i dati" | C-16 |
| Anteprime incorporate | `oembed_response_data` | C-17 |
| XML-RPC | `xmlrpc_prepare_post`, più il rifiuto della chiamata sul singolo. **Alternativa dichiarata**: disattivare XML-RPC per i tipi gestiti, documentandolo, come la riga C-18 consente | C-18 |
| Pagina dell'allegato | `pre_get_posts` sull'interrogazione dell'allegato, più controllo su `template_redirect` | C-19 |
| Navigazione adiacente | `get_previous_post_where` e `get_next_post_where` | C-20 |
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
| C-87 | Ora corrente **esattamente uguale** all'istante di scadenza: scaduto. E un istante **immediatamente precedente**: non scaduto |
| C-88 | Data mancante e data corrotta: invisibile al pubblico, **visibile in amministrazione** |
| C-89 | Bozza e contenuto privato: il filtro non li tratta come scaduti né li rende pubblici |
| C-90 | Tipo di WordPress non registrato in core: **non toccato**, nessun filtro applicato |
| C-91 | **La differenza fra le due politiche**: con `irraggiungibile` l'indirizzo proprio risponde "non trovato" anche all'utente con la capability di archivio; con `archivio` risponde 200 a lui e "non trovato" all'anonimo. Nei due ordini di caricamento |
| C-92 | Utente con la capability di archivio sulle superfici pubbliche: **non fa ricomparire niente**, su nessuna delle dodici |
| C-93 | `WP_Query` con `suppress_filters`: il contenuto scaduto **passa**. **Non è una prova di conformità: è la documentazione eseguibile di un limite noto**, e il suo nome lo dice |
| C-94 | Guardia difensiva: sezione con politica di scadenza registrata a motore non pronto, registrazione rifiutata |
| C-95 | Identificativo di sezione con il trattino: rifiutato. **Restrizione del contratto prima del rilascio**, non correzione di una collisione |
| C-96 | Scansione delle anomalie senza la capability richiesta: rifiutata. E il percorso di lettura non scrive niente, verificato contando le scritture |
| C-97 | Nessuna contaminazione fra tipi e sezioni: il filtro di una sezione non tocca i contenuti di un'altra |
| C-98 | Avvio idempotente: il motore acceso due volte non aggiunge i filtri due volte e non produce errori |
| C-99 | `conformita_core_imposta_fine_pubblicazione()` chiamata due volte con lo stesso valore: `true`, non errore |

Totale: **15 esistenti più 13 nuove, 28**.

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

## 11. Le decisioni prese in questa revisione

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

## 12. Che cosa resta da sapere prima di scrivere

**Il vincolo sui caratteri delle sezioni tocca codice già in `main`.** Nessun componente
registra ancora sezioni, quindi il costo è zero, ed è il momento giusto.

**La versione 1.2.0 va scritta nel README** con la nota che contiene una restrizione: quando
l'albo partirà dovrà chiedere `1.2.0`, e chi legge deve sapere che la 1.1 accettava
identificativi che la 1.2 rifiuta.

**Ordine di lavoro**: prima il vincolo sulle sezioni con la sua riga, poi i test, poi il
motore. In mezzo, la prova di non vacuità per ognuna delle cinque famiglie.
