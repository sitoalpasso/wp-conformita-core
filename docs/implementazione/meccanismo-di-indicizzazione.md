# Meccanismo di indicizzazione: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S9 del piano, righe di collaudo C-80..C-85 e
C-225..C-230 e C-232..C-244, elencate al punto 11.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** L'albo
dichiara già la propria politica (`vietata`) quando registra la sua sezione: da questa
versione quella dichiarazione produce effetti. L'albo, in un'altra unità, dovrà soltanto
richiedere la versione `1.4.0` dell'interfaccia, perché è la prima che garantisce il
divieto.

**Stato: costruita, quattro giri di revisione indipendente recepiti (punti 13..16), in attesa
del quinto.** Il punto 17 elenca, selettore per selettore, come la mappa del sito è coperta. Le sezioni sono al passato e dicono quello che il codice fa.

---

## Spiegazione in parole semplici

**Cosa costruiamo.** Quando un componente dichiara che i suoi contenuti non vanno messi nei
motori di ricerca, come fa l'albo pretorio, il sito adesso lo dice davvero ai motori: su
ogni pagina di quei contenuti, sugli elenchi, sui feed e sui file scaricabili c'è l'avviso
"non indicizzare", e quei contenuti spariscono dalla mappa del sito che i motori leggono per
sapere cosa visitare. Quando invece il componente dichiara che i contenuti vanno indicizzati,
come farà la trasparenza, il meccanismo non aggiunge niente. Tutto il resto del sito (pagine,
articoli) resta com'era.

**Come la costruiamo.** Quando WordPress prepara una pagina, prima di mandarla il meccanismo
guarda a quale sezione appartiene quello che la pagina mostra, e ne legge la politica. Se è
"vietata" aggiunge l'avviso in due posti: nella busta della risposta (l'intestazione
`X-Robots-Tag`) e dentro la pagina (il metatag `robots`). I due posti servono perché
ciascuno copre il punto cieco dell'altro: i file e i feed non hanno una "pagina" dove
scrivere, e certe memorie di pagina dei siti conservano la pagina ma perdono la busta. Il
file di un allegato risponde per l'atto a cui appartiene. Un elenco qualsiasi, anche la
pagina iniziale, porta l'avviso se fra quello che mostra c'è un atto. Sulla busta l'avviso
generale viaggia sempre anche su una riga tutta sua, così un altro plugin che riscrive la
busta non riesce a cancellarlo. La mappa del sito perde per intero i tipi di contenuto delle
sezioni vietate, e nessun'altra parte della mappa può elencare un atto, nemmeno se un plugin
prova a leggerli con i filtri spenti: l'ultimo controllo guarda ogni voce che sta per
entrare nella mappa, qualunque sia la strada da cui è arrivata. Non si usa `robots.txt`, di proposito: un
indirizzo escluso lì non viene visitato, quindi il motore non legge mai l'avviso, e se
qualcuno lo collega da fuori l'indirizzo può finire nell'indice lo stesso.

**Come si prova che funziona, e che la prova è vera.** Le prove registrano nello stesso sito
due sezioni finte con politiche opposte e controllano sempre le due insieme: la pagina
vietata deve avere l'avviso, quella consentita non deve averlo, e le pagine normali del sito
nemmeno. Per dimostrare che le prove non sono di cartone, il codice è stato rotto di
proposito in quarantasei modi diversi (avviso tolto dalla pagina, avviso messo dappertutto,
mappa non filtrata, feed dimenticati, allegati che non risalgono all'atto, `robots.txt`
toccato, spegnimento a metà, riga dell'avviso mandata in modo da cancellare le altre, avviso
saltato mentre si manda il file, e altri): ogni volta almeno una prova è diventata rossa.
Le prove sulla busta guardano le righe che escono davvero, non solo quelle preparate. La
tabella è al punto 12.

---

## 1. File toccati

| File | Perché |
|---|---|
| `includes/class-conformita-core-indicizzazione.php` | nuovo: il meccanismo |
| `includes/class-conformita-core-intestazioni.php` | nuovo: il punto da cui escono le righe di intestazione di core, sostituibile nelle prove (C-239, C-240) |
| `conformita-core.php` | carica e accende il meccanismo; versione dell'interfaccia da `1.3.0` a `1.4.0` |
| `includes/class-conformita-core-sezioni.php` | la registrazione di una sezione rifiuta a meccanismo spento (C-85) |
| `includes/class-conformita-core-consegna.php` | il file di un contenuto vietato esce con `X-Robots-Tag: noindex` (C-228); le intestazioni escono dal punto comune anche nelle prove (C-240) |
| `includes/class-conformita-core-mappa-contenuti.php` | nuovo: il fornitore dei contenuti della mappa di WordPress, che scarta le voci svuotate (C-244) |
| `tests/indicizzazione-test.php` | nuovo: C-80..C-85, C-225..C-227, C-229..C-239, C-241..C-244 |
| `tests/consegna-test.php` | C-228, C-240 |
| `tests/class-conformita-core-risposta-registrata.php`, `tests/bootstrap.php` | nuovo: raccoglie le righe emesse con le regole di sostituzione di `header()` |
| `tests/filtro-scadenza-test.php`, `tests/filtro-scadenza-non-vacuita-test.php` | la sezione usata dalle prove sulla scadenza nella mappa diventa `consentita` (punto 7) |
| `tests/allegati-test.php` | C-153 non fissa più la versione esatta, ma "almeno 1.3.0" (punto 3) |

## 2. Requisito servito

Il meccanismo di indicizzazione di core, uno dei meccanismi comuni: applica la politica di
indicizzazione che la sezione ha dichiarato. Per l'albo serve ALBO-06 (le pagine dell'atto
non vengono indicizzate: `noindex` ed esclusione dalla mappa del sito); per la trasparenza,
quando esisterà, serve la regola opposta, cioè che il meccanismo non ostacoli niente.

## 3. Il contratto verso i componenti

**Nessuna funzione pubblica nuova.** Il componente dichiara la politica come faceva già, alla
registrazione della sezione. Cambia che cosa quella dichiarazione produce.

**Versione dell'interfaccia: da 1.3.0 a 1.4.0.** Nessuna firma cambia. La versione sale
perché nasce una garanzia su cui un componente deve poter contare: con `1.4.0` le pagine di
una sezione `vietata` portano il divieto. Un albo che richiede `1.4.0` non si avvia su un
core che non lo garantisce. La prova C-153 di S5 fissava la versione esatta `1.3.0`: adesso
verifica che non sia scesa sotto `1.3.0`, che è ciò che quella riga voleva dire (le funzioni
della consegna esistono da lì).

**Coordinamento con S7.** Il registro delle modifiche, su un ramo non ancora unito, porta
anch'esso la versione a `1.4.0`. Chi arriva in `main` per secondo sale a `1.5.0`.

**Nuovo codice d'errore alla registrazione di una sezione:**
`conformita_core_indicizzazione_non_avviata`, a meccanismo spento. In esercizio non può
accadere, perché il meccanismo si accende al caricamento del file di core.

## 4. Come funziona

Si accende al caricamento del file di core, subito dopo il motore di scadenza e per la
stessa ragione: non dipende dall'ordine in cui WordPress carica i plugin (C-236). Otto
agganci, in un elenco solo letto dall'accensione, dallo spegnimento e dalla domanda "è
acceso?" (C-230):

| Aggancio | Che cosa fa |
|---|---|
| `wp_robots` | aggiunge `noindex` al metatag `robots` nel sorgente, e toglie un eventuale `index` |
| `wp_headers` | compone l'intestazione `X-Robots-Tag` con il divieto generale (C-233) |
| `send_headers` | sulle pagine vietate emette, aggiunte e mai in sostituzione, le righe per un motore specifico messe da parte e una riga generale `X-Robots-Tag: noindex` propria (C-233, C-239) |
| `wp_sitemaps_post_types` | toglie dalla mappa del sito i tipi delle sezioni vietate (C-82) |
| `pre_get_posts` | mentre si costruisce la mappa, toglie i tipi vietati dalle interrogazioni che la leggono, anche con i filtri spenti (C-238) |
| `the_posts` | mentre si costruisce la mappa, toglie i contenuti vietati da quello che le interrogazioni restituiscono: seconda difesa (C-238) |
| `wp_sitemaps_posts_entry` | svuota la voce della mappa di un contenuto vietato, qualunque interrogazione l'abbia portato: ultima difesa (C-244) |
| `wp_sitemaps_add_provider` | mette al posto del fornitore dei contenuti della mappa di WordPress la sua versione che scarta le voci svuotate (C-244) |

**"È acceso" guarda gli agganci, non una variabile.** Se un altro componente ne toglie uno,
il meccanismo non risulta acceso e la registrazione di una sezione è rifiutata; riaccenderlo
rimette quello che manca (C-235). Lo spegnimento esiste per le prove e nessuna funzione
pubblica lo espone: chiamato in esercizio dopo le registrazioni, lascerebbe le pagine senza
divieto, perché la guardia vale al momento della registrazione. È lo stesso limite dello
spegnimento del motore di scadenza.

**Priorità: la più alta che WordPress ammette.** Il divieto passa dopo ogni filtro
registrato a una priorità minore, quindi un componente che prima ha scritto il contrario
viene corretto. **Non è una garanzia assoluta**: un filtro registrato alla stessa priorità
dopo core passa dopo di lui e può togliere il divieto, e nessuna priorità lo impedisce. Per
questo la prova sul sito vero (punto 9) guarda la risposta finale e non il codice. Sugli
altri contenuti gli agganci restituiscono quello che ricevono.

**Come si compone l'intestazione.** Il nome si riconosce senza badare a maiuscole e ne resta
uno solo. Un valore generale già presente si conserva e riceve `noindex` in coda, se non lo
contiene già (anche `none` lo contiene). Un valore che nomina un motore in qualunque punto,
come `googlebot: nofollow` ma anche `nofollow, googlebot: nofollow`, non si tocca: dopo il
nome del motore le direttive valgono per quel motore soltanto, quindi un `noindex` in coda
non varrebbe per gli altri. La riga generale diventa `noindex`, e il valore altrui esce
intero su una riga propria (C-233).

**La riga propria.** WordPress manda le intestazioni una per nome, sostituendo. Un altro
componente che su `send_headers` chiama `header()` con lo stesso nome sostituisce quella
riga, divieto compreso, e nessuna composizione su `wp_headers` lo impedisce. Per questo, sulle
pagine vietate, l'aggancio di `send_headers` manda sempre anche una riga generale
`X-Robots-Tag: noindex` sua, in aggiunta: passa dopo gli agganci ordinari e non toglie niente
a nessuno (C-239). La richiesta si rivaluta lì, senza dipendere dal passaggio da
`wp_headers`. Una riga `noindex` doppia è innocua.

**Da dove escono le righe.** Le righe di core, quelle della pagina e quelle del file
consegnato, passano da un punto solo (`Conformita_Core_Intestazioni::manda()`), che nelle
prove si sostituisce con una registrazione che rispetta le regole di `header()`. È ciò che
permette alle prove di guardare la risposta che esce e non solo quella preparata.

**Quali richieste portano il divieto.** Si decide dopo l'interrogazione principale (da
WordPress 6.1 le intestazioni si preparano dopo di essa; la minima dichiarata è 6.5):

1. la pagina di un contenuto di un tipo vietato, compresi il feed dei commenti e
   l'incorporamento di quel contenuto (C-80, C-237);
2. la pagina di un allegato, se il contenuto a cui appartiene è di un tipo vietato. Un
   allegato il cui contenuto non esiste più non prende la politica di nessuno (C-227, C-234);
3. l'elenco e il feed di un tipo vietato, anche vuoti (C-225, C-226);
4. ogni altra richiesta che mostra anche un solo contenuto vietato: la pagina iniziale, gli
   elenchi per autore o per data, i feed generali, che un tema o un componente possono
   allargare ai tipi vietati. Vince il divieto, perché l'elenco espone comunque titoli e
   riassunti; le pagine dei contenuti consentiti restano indicizzabili una per una (C-226,
   C-232).

**Il file consegnato** esce dal punto di consegna di S5 prima che WordPress prepari le
intestazioni della pagina, quindi il divieto si aggiunge lì, con la stessa funzione
(C-228), dal punto pubblico e da quello amministrativo (C-240).

**La mappa** perde il tipo intero: un tipo appartiene a una sezione sola, e togliendolo
sparisce anche la sua voce nell'indice della mappa (C-82). Se un componente allarga la mappa
di un altro tipo ai tipi vietati, i tipi vietati si tolgono dall'interrogazione prima che
parta, su `pre_get_posts`, che passa anche quando il componente spegne i filtri. Si gestiscono
`any` (e un elenco di tipi assente con una tassonomia), che WordPress allarga a tutti i tipi
ricercabili; gli allegati, escludendo quelli appesi a un contenuto vietato; e l'elenco rimasto
vuoto, che non restituisce niente invece di diventare "gli articoli". Per gli allegati si
corregge il vincolo sul padre che WordPress applica davvero, perché ne applica uno solo: un
padre singolo vietato svuota la lettura, un elenco di padri ammessi perde quelli vietati (e
se resta vuoto la lettura è vuota), e soltanto senza i due si aggiunge l'esclusione (C-241);
nell'elenco dei padri lo zero resta, perché vuol dire "allegati senza contenuto" (C-243). Un
contenuto scelto per identificativo (`p`, `page_id`, `attachment_id`) si guarda direttamente:
se è vietato, la lettura si svuota (C-242). Svuotare vuol dire chiedere un tipo che non
esiste, dopo aver tolto i selettori che WordPress fa prevalere: `post__in` con zero da solo
non basta, perché `p` vince su di lui. I contenuti vietati restituiti comunque si tolgono
ancora su `the_posts`. Tutto soltanto mentre la mappa si costruisce e sulle interrogazioni
secondarie (C-238).

**L'ultima difesa della mappa** non guarda come la lettura è chiesta, ma che cosa sta per
diventare un indirizzo: WordPress passa ogni contenuto letto da `wp_sitemaps_posts_entry`,
anche con i filtri spenti, e lì la voce di un contenuto vietato si svuota. Il fornitore dei
contenuti della mappa, sostituito da core con una sua versione identica tranne che per questo,
scarta le voci vuote. Se un altro componente ha già sostituito il fornitore, core non lo tocca
e nella mappa resta una voce senza indirizzo, che non porta nessun motore da nessuna parte
(C-244). Le difese sull'interrogazione restano: tengono giusti il numero delle pagine della
mappa e l'ordine, e ciascuna ha le sue prove, misurate con l'ultima difesa spenta.

## 5. Dati letti e scritti

Legge soltanto il registro delle sezioni e dei tipi, che vive in memoria. Non scrive niente:
nessuna opzione, nessun metadato. `docs/dati.md` non cambia.

## 6. Permessi

Nessuno. Il meccanismo agisce su ogni richiesta pubblica, per chiunque la faccia: il divieto
non dipende da chi guarda la pagina.

## 7. Test

Tutte le prove registrano due sezioni finte con politiche opposte nello stesso sito. Il
sorgente si legge stampando il metatag, e soltanto se la testata della pagina lo stampa
davvero: se un tema o un componente lo toglie dalla testata, per la prova il divieto nel
sorgente manca, come sulla pagina vera. La testata intera non si stampa perché nell'ambiente
di prova costruito dai sorgenti di WordPress porta con sé il caricatore degli script, che
fallisce per un motivo estraneo. Le intestazioni si leggono in due modi: dal filtro con cui
WordPress le prepara, dopo aver eseguito la richiesta, e, per C-239 e C-240, dalle righe che
escono davvero. Dentro la suite l'uscita è già cominciata e PHP non registra le intestazioni,
quindi le righe si raccolgono con le stesse regole di `header()`: una riga in sostituzione
toglie le precedenti con lo stesso nome, una in aggiunta si mette accanto. Per la pagina si
rifà quello che fa WordPress (una riga per intestazione preparata, poi `send_headers`, con un
componente concorrente dove serve); per il file si passa dall'emissione vera del punto di
consegna, di cui si sostituisce soltanto il riversamento del file e l'uscita.

| Riga | Prova |
|---|---|
| C-80 | `indicizzazione-test.php`, `test_c80_pagina_di_un_contenuto_vietato` |
| C-81 | `test_c81_pagina_di_un_contenuto_consentito` |
| C-82 | `test_c82_mappa_per_i_motori` |
| C-83 | `test_c83_due_sezioni_nei_due_ordini` |
| C-84 | `test_c84_contenuti_estranei_intatti` |
| C-85 | `test_c85_sezione_a_meccanismo_spento` |
| C-225 | `test_c225_elenco_del_tipo` |
| C-226 | `test_c226_feed` |
| C-227 | `test_c227_pagina_di_un_allegato` |
| C-228 | `consegna-test.php`, `test_c228_divieto_di_indicizzazione_sui_file` |
| C-229 | `test_c229_robots_txt_intatto` |
| C-230 | `test_c230_accensione_e_spegnimento` |
| C-225, C-226 (vuoti) | `test_c225_c226_elenco_e_feed_vuoti` |
| C-232 | `test_c232_elenchi_misti` |
| C-233 | `test_c233_composizione_dell_intestazione` |
| C-234 | `test_c234_padre_inesistente` |
| C-235 | `test_c235_aggancio_tolto_da_fuori` |
| C-236 | `test_c236_accensione_al_caricamento` |
| C-237 | `test_c237_percorso_della_pagina` |
| C-238 | `test_c238_mappa_allargata_da_un_componente` |
| C-239 | `test_c239_riga_propria_nella_risposta` |
| C-240 | `consegna-test.php`, `test_c240_divieto_emesso_con_il_file` |
| C-241 | `test_c241_allegati_scelti_per_padre` |

**Tre prove di S4 toccate, e perché.** C-14, C-92 e C-108 controllano che un contenuto
scaduto sparisca dalla mappa del sito, e lo facevano su sezioni dichiarate `vietata`. Da
questa unità una sezione vietata non è nella mappa affatto, quindi quelle prove non vi
trovavano più nemmeno il contenuto valido e sono diventate rosse. Non è un difetto del filtro
di scadenza, che non legge la politica di indicizzazione: è che la scadenza nella mappa conta
solo per le sezioni che nella mappa ci sono. Le tre prove ora girano su una sezione
`consentita`, che è il caso reale (la trasparenza).

## 8. Cosa deliberatamente NON fa

- **Non usa `robots.txt`**, per la ragione scritta sopra. C-229 lo sorveglia.
- **Non tocca le mappe del sito dei plugin SEO.** Alcuni plugin diffusi spengono la mappa di
  WordPress e ne costruiscono una propria: lì il meccanismo non arriva. Le pagine restano
  comunque con il divieto nella busta, e un motore non indicizza una pagina con `noindex`
  anche se la trova in una mappa. Va controllato alla messa in esercizio (punto 9, prova 3).
- **Non toglie divieti messi da altri** sulle sezioni `consentita`, né corregge l'impostazione
  di WordPress "scoraggia i motori di ricerca". Controllare che la trasparenza sia davvero
  indicizzabile è un compito del componente della trasparenza, quando esisterà.
- **Non copre le risposte dell'interfaccia informatica (REST)**: sono dati per programmi, non
  pagine, e i motori non le trattano come pagine. Il divieto nella busta si potrà aggiungere
  lì se un giorno servirà.
- **Non impedisce a un filtro registrato dopo di lui, alla stessa priorità massima, di
  togliere il divieto** (punto 4). La prova sul sito vero lo scopre. Sulla busta il caso è più
  stretto: la riga propria esce per ultima, quindi la può togliere solo chi chiama `header()`
  dopo di lei, cioè un aggancio di `send_headers` alla stessa priorità o codice che gira dopo.
- **Non raggiunge la mappa scritta al posto di WordPress.** Un componente che compone da sé
  l'elenco degli indirizzi di una pagina della mappa (`wp_sitemaps_posts_pre_url_list`), che
  sostituisce il fornitore dei contenuti, o che riscrive la voce dopo l'ultima difesa, alla
  stessa priorità, sta scrivendo lui la mappa. Il punto 17 li elenca.
- **Non copre le tassonomie.** Core non ne registra; quelle dell'albo sono già non pubbliche,
  quindi senza pagine e fuori dalla mappa.
- **I risultati della ricerca interna** li copre già WordPress, che mette `noindex` su ogni
  pagina di ricerca.
- **Non fa sparire dall'indice quello che un motore ha già preso** prima dell'installazione:
  il divieto vale dalla prossima visita del motore. Per le pagine già indicizzate di un albo
  precedente serve la richiesta di rimozione dagli strumenti del motore.

## 9. Come si prova sul sito vero

Tre prove, da aggiungere al collaudo di rilascio.

1. **La pagina di un atto.** Da un browser senza accesso si apre la pagina di un atto
   pubblicato e si guarda il sorgente: il metatag `robots` deve contenere `noindex`.
   Poi si chiede la stessa pagina con `curl -I`: nella risposta deve esserci
   `X-Robots-Tag: noindex`. Se manca il metatag ma c'è l'intestazione, il tema non chiama
   `wp_head()` o un plugin SEO sostituisce il metatag: il divieto regge, ma va annotato. Se
   manca l'intestazione ma c'è il metatag, davanti al sito c'è una memoria di pagina che
   perde le intestazioni: regge lo stesso, e va annotato. **Se mancano tutti e due, il
   rilascio si annulla.**
2. **Il file di un atto.** `curl -I` sull'indirizzo di consegna del documento principale di
   un atto pubblicato: deve esserci `X-Robots-Tag: noindex`. Se manca, i PDF dell'albo
   possono finire nell'indice: il rilascio si annulla.
3. **La mappa del sito.** Si apre `/wp-sitemap.xml`: non deve esserci nessuna voce per il
   tipo degli atti. Se il sito usa la mappa di un plugin SEO, si apre quella e si cerca lo
   stesso tipo; se c'è, si esclude dalla configurazione del plugin prima della messa in
   esercizio. Poi si apre una pagina normale del sito e si controlla che **non** abbia
   `noindex`: se ce l'ha, il divieto sta uscendo dalla sezione.

## 10. Modi di guasto

| Guasto | Come degrada |
|---|---|
| Il file del meccanismo non viene caricato | nessuna sezione si registra (C-85): l'albo non parte, invece di partire con le pagine indicizzabili |
| Il tema non stampa la testata della pagina | resta il divieto nella busta, che i motori rispettano allo stesso modo |
| Una memoria di pagina perde le intestazioni | resta il metatag nel sorgente |
| Un altro componente scrive `index` sui contenuti vietati prima del meccanismo | il meccanismo passa dopo e lo toglie |
| Un altro componente toglie il divieto dopo il meccanismo, alla stessa priorità | il divieto manca: lo scopre solo la prova sul sito vero (punto 9) |
| Un altro componente toglie un aggancio del meccanismo | il meccanismo non risulta acceso e le sezioni nuove non si registrano (C-235) |
| Un altro componente ha già scritto `X-Robots-Tag` per un motore specifico, anche mescolato a direttive generali | la riga generale porta `noindex`, quella altrui esce intera a parte (C-233) |
| Un altro componente sostituisce `X-Robots-Tag` su `send_headers` | la sua riga resta, e accanto esce la riga generale `noindex` propria (C-239) |
| Un componente allarga la mappa di un altro tipo ai tipi vietati, anche con i filtri spenti | i tipi vietati escono dall'interrogazione; con i filtri accesi c'è anche la seconda difesa (C-238) |
| Un componente personalizza la lettura della mappa in un modo che le difese sull'interrogazione non prevedono | l'ultima difesa svuota la voce e il fornitore la scarta (C-244) |
| Un altro componente ha sostituito il fornitore dei contenuti della mappa | la voce del contenuto vietato resta senza indirizzo (C-244) |
| Un allegato punta a un contenuto che non esiste più | nessun divieto preso in prestito da altri contenuti (C-234) |
| Un plugin SEO sostituisce mappa e metatag | resta la busta; la mappa si controlla a mano (punto 9) |
| Un tipo vietato e uno consentito nello stesso elenco | vince il divieto sull'elenco; le pagine consentite restano indicizzabili una per una |

## 11. Le righe di collaudo di questa unità

C-80..C-85 sono le righe del catalogo scritte quando l'unità è stata pianificata. C-225..C-230
le ha fatte emergere il lavoro, cercando ogni strada da cui un motore arriva ai contenuti di
una sezione; C-232..C-238 il primo giro di revisione (punto 13), C-239 e C-240 il secondo
(punto 14), C-241 il terzo (punto 15), C-242..C-244 il quarto (punto 16). Numerazione continuata dopo
C-224, l'ultima del registro delle modifiche, per non sovrapporsi a un ramo ancora aperto. C-231 non è di questa unità: è della correzione della guardia di dipendenza, rinumerata lì
per lo stesso motivo.

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-80 | fatto | Pagina di un contenuto di una sezione `vietata`: `noindex` nell'intestazione e nel sorgente |
| C-81 | fatto | Pagina di un contenuto di una sezione `consentita`: nessun `noindex`, e il metatag c'è (l'assenza del divieto è una scelta, non un silenzio) |
| C-82 | fatto | Mappa del sito: assente il tipo della sezione vietata, presenti tipo e contenuti della consentita; a meccanismo spento il tipo vietato ricompare |
| C-83 | fatto | Due sezioni con politiche opposte, registrate nei due ordini: ciascuna ottiene la propria |
| C-84 | fatto | Articoli, pagine e pagina iniziale: nessun divieto, e restano nella mappa |
| C-85 | fatto | A meccanismo spento la registrazione di una sezione è rifiutata con errore che nomina l'indicizzazione; riacceso, la stessa registrazione riesce |
| C-225 | fatto | Elenco di un tipo vietato, anche vuoto: divieto nei due segnali; elenco di un tipo consentito, anche vuoto: nessun divieto |
| C-226 | fatto | Feed di un tipo vietato (anche vuoto, anche di un tipo senza elenco proprio), feed dei commenti di un contenuto vietato, feed misto: divieto nell'intestazione. Feed di un tipo consentito, dei commenti di un contenuto consentito, feed generale: nessun divieto |
| C-227 | fatto | Pagina di un allegato: divieto se il contenuto a cui appartiene è vietato; nessun divieto per l'allegato di un contenuto consentito, di un articolo, o senza contenuto |
| C-228 | fatto | File consegnato dal punto di consegna: `X-Robots-Tag: noindex` se il contenuto è vietato, nessuna intestazione se è consentito; le due consegne riescono tutte e due |
| C-229 | fatto | `robots.txt` identico a meccanismo acceso e spento, senza il tipo vietato |
| C-230 | fatto | Acceso, gli otto agganci rispondono alla loro priorità; spento, nessuno risponde e il divieto sparisce; riacceso, torna |
| C-232 | fatto | Pagina iniziale ed elenco per autore allargati da un componente ai tipi vietati, feed di tutti i tipi: divieto, dopo aver verificato che il contenuto vietato sia davvero mostrato; gli stessi elenchi senza contenuti vietati: nessun divieto |
| C-233 | fatto | Composizione di `X-Robots-Tag` con un valore già scritto da altri: generale senza e con `noindex`, `none`, direttiva con valore, nome in minuscolo, valore rivolto a un motore con `noindex` e con `nofollow`, valori misti (generale poi motore, con e senza `noindex` in coda, direttiva con valore poi motore). Una sola riga generale con il divieto, e il valore che nomina un motore messo da parte intero; sui contenuti consentiti niente cambia |
| C-234 | fatto | Allegato con un padre che non esiste più, con un contenuto vietato o l'allegato stesso come contenuto globale: nessun divieto preso in prestito; nemmeno con identificativo zero o vuoto |
| C-235 | fatto | Tolto da fuori un aggancio qualsiasi degli otto: il meccanismo non risulta acceso, la registrazione di una sezione è rifiutata, la riaccensione rimette l'aggancio |
| C-236 | fatto | Caricare il file di core accende il meccanismo senza altre chiamate; la versione dell'interfaccia è almeno `1.4.0` e coincide con quella della funzione pubblica |
| C-237 | fatto | Incorporamento di un contenuto vietato: divieto nell'intestazione, di uno consentito: nessuno. Metatag tolto dalla testata: resta l'intestazione |
| C-238 | fatto | Mappa degli articoli allargata da un componente: tipi aggiunti con filtri accesi e spenti, `any` con filtri spenti, tipo sostituito con quello vietato, allegati con lo stato allargato, allargamento dopo la restrizione. Il contenuto vietato (o il suo allegato) non c'è, l'articolo (o il suo allegato) sì, e a meccanismo spento il vietato c'era; col tipo sostituito la lettura è vuota; fuori dalla mappa la stessa interrogazione allargata non si tocca |
| C-239 | fatto | Righe `X-Robots-Tag` che escono da una pagina vietata: un divieto generale c'è sempre, anche quando un altro componente sostituisce l'intestazione su `send_headers` con un valore per un motore, misto o `index, follow`; la sua riga resta com'è; una riga mista preparata esce intera. Da una pagina consentita non esce niente di core |
| C-240 | fatto | Righe che escono con il file consegnato: `noindex` dal punto pubblico e dal punto amministrativo per il contenuto vietato, nessuna riga per il consentito; le intestazioni del file escono davvero |
| C-241 | fatto | Mappa che legge allegati con i filtri spenti, scegliendoli per contenuto padre: padri misti e inclusione accanto a un'esclusione, resta solo l'allegato dell'articolo; solo padri vietati o padre singolo vietato, lettura vuota; padre singolo consentito, l'allegato resta. A meccanismo spento l'allegato del vietato c'era |
| C-242 | fatto | Mappa letta scegliendo un contenuto per identificativo, con i filtri spenti: `p` e `page_id` sul tipo vietato, `p` fra tipi misti, allegato del vietato per `attachment_id`, per `p` con il padre, per `page_id`, per nome. A meccanismo spento il contenuto scelto c'era, acceso la lettura è vuota; un articolo scelto per identificativo resta |
| C-243 | fatto | Allegati senza contenuto inclusi con il padre zero: con `[0, vietato]` resta solo l'orfano, con `[0, vietato, articolo]` restano l'orfano e l'allegato dell'articolo, con il padre singolo zero resta l'orfano |
| C-244 | fatto | Ultima difesa: il fornitore della mappa è quello di core; con le difese sull'interrogazione scavalcate (allargamento dopo la restrizione con i filtri spenti) il vietato arriva alle voci ma non nella mappa, nessuna voce vuota, l'articolo resta; le voci dei contenuti consentiti e gli altri fornitori non si toccano |

"Fatto" vuol dire verde nella suite locale (WordPress 6.5, PHP 8.4). Diventa definitivo con
la verifica continua verde sul commit di punta.

## 12. Le prove di non vacuità

Ogni riga della tabella è una modifica fatta di proposito al codice, in una copia, con la
suite completa eseguita e la modifica poi tolta. Non girano a ogni verifica continua: la
loro verifica è questa tabella.

| Guasto introdotto | Prove diventate rosse |
|---|---|
| G1. Metatag senza divieto | C-80, C-81, C-83, C-84, C-225, C-227, C-230 |
| G2. Intestazione senza divieto | C-80, C-81, C-83, C-84, C-225, C-226, C-227, C-230 |
| G3. Politica ignorata, divieto su ogni tipo gestito | C-81, C-82, C-83, C-225, C-226, C-227, C-228, e le tre di S4 sulla mappa |
| G4. Divieto anche sui contenuti fuori dalle sezioni | C-84, C-226, C-227, C-232, C-238, C-241, C-242, C-243 |
| G5. Mappa non filtrata | C-82, C-83 |
| G6. Registrazione senza guardia | C-85 |
| G7. Elenchi del tipo dimenticati | C-225 |
| G8. Feed dimenticati | C-226 |
| G9. L'allegato non risale al contenuto | C-227, C-228, C-240, C-242 |
| G10. File consegnati senza divieto | C-228, C-240 |
| G11. Esclusione aggiunta a `robots.txt` | C-229, C-230 |
| G12. Spegnimento che toglie un aggancio solo | C-82, C-230, C-238, C-241..C-244 |
| G13. Feed misto: vince il consentito | C-226, C-232 |
| G14. Elenchi misti: i contenuti mostrati non si guardano | C-232 |
| G15. Direttiva per un motore presa per generale | C-233, C-239 |
| G16. Nome dell'intestazione riconosciuto solo con le maiuscole giuste | C-233 |
| G17. Padre inesistente: si ricade sul contenuto globale | C-234 |
| G18. "Acceso" guardando un aggancio solo | C-235 |
| G19. Accensione tolta dal file di core | C-236 e ogni prova che registra una sezione |
| G20. Versione riportata a `1.3.0` | C-236 |
| G21. La riaccensione non rimette l'aggancio mancante | C-235 |
| G22. Mappa allargata non filtrata | C-238 |
| G23. Contenuti filtrati anche fuori dalla mappa | C-238, e tre prove di S4 sulle interrogazioni |
| G24. Riga generale propria non emessa | C-239 (emissione) |
| G25. Riga generale propria mandata in sostituzione | C-239 (emissione) |
| G26. Righe per un motore mandate in sostituzione | C-239 (emissione) |
| G27. Riga mista letta solo nella prima parte | C-233 (composizione), C-239 (emissione) |
| G28. Restrizione della mappa su `pre_get_posts` assente | C-238, C-241, C-242, C-243 |
| G29. `any` non gestito | C-238 |
| G30. Allegati dei contenuti vietati non esclusi | C-238, C-241, C-242, C-243 |
| G31. Elenco dei tipi svuotato e lasciato aperto | C-238 |
| G32. `X-Robots-Tag` saltata mentre si mandano le intestazioni del file | C-240 (emissione) |
| G33. Divieto sui file tolto soltanto dal punto amministrativo | C-240 (emissione) |
| G34. Riga propria emessa solo se `wp_headers` ha messo qualcosa da parte | C-239 (emissione) |
| G35. Restrizione della mappa anche fuori dalla mappa | C-238, tre prove di S4 sulle interrogazioni e cinque di S5 |
| G36. Inclusione dei padri ignorata: solo l'esclusione, che WordPress scarta | C-241, C-243 |
| G37. Padre singolo ignorato | C-241 |
| G38. Inclusione rimasta vuota lasciata aperta | C-241 |
| G39. Selettori per identificativo ignorati | C-242 |
| G40. Lettura svuotata con il solo `post__in` | C-242 |
| G41. Zero tolto dall'elenco dei padri | C-243 |
| G42. Ultima difesa sulle voci assente | C-244 |
| G43. Voci vuote lasciate nella mappa | C-244 |
| G44. Fornitore della mappa non sostituito | C-244 |
| G45. Allegato scelto per nome ignorato | C-242 |
| G46. Ultima difesa che svuota anche le voci consentite | C-82, C-84, C-244, e tre prove di S4 sulla mappa |

Composizione ed emissione sono coperte da prove diverse, ed è voluto: C-233 guarda come si
compone la riga preparata, C-239 e C-240 guardano le righe che escono. G25, G26, G32 e G33
lasciano intatta la risposta preparata, e infatti nessuna prova sulla composizione le
prende: le prendono solo le prove sull'emissione, che il secondo giro ha chiesto per questo.

Dopo le correzioni del quarto giro tutte le righe, G1..G46, sono state rieseguite, tutte
rosse. Dopo le correzioni del terzo giro le righe G1..G38 erano state rieseguite, tutte
rosse. Dopo le correzioni del secondo giro tutte le righe, G1..G35, erano state rieseguite sul codice
nuovo: tutte rosse dove indicato. G31 all'inizio è rimasta verde, perché il caso scelto (la
mappa chiesta per il tipo vietato) si fermava già al filtro dei tipi della mappa; il caso
giusto è la mappa di un altro tipo sostituito con quello vietato, che ora c'è.

Dopo le correzioni del primo giro le righe G1..G13 sono state rieseguite sul codice nuovo.
G7 e G8 all'inizio sono rimaste verdi: la regola nuova sugli elenchi misti copriva anche gli
elenchi e i feed del tipo, finché contenevano un atto. È per questo che esiste la prova
sugli elenchi e i feed vuoti, che adesso le fa diventare rosse.

## 13. Il primo giro di revisione indipendente

Sul commit `0319771`, sette rilievi e la richiesta dei due file della consegna per intero,
che il secondo giro riceve. Come sono stati chiusi:

1. **Elenchi che mostrano atti senza essere l'elenco del tipo** (pagina iniziale, elenchi per
   autore o data allargati da un componente, feed di tutti i tipi). Si guardano ora anche i
   contenuti mostrati dall'interrogazione principale. C-232. Lo stesso caso sulla mappa del
   sito, non segnalato, è chiuso da C-238.
2. **Intestazione composta male con valori per un motore specifico.** Riscritta la
   composizione, con il nome riconosciuto senza maiuscole e le direttive per un motore su una
   riga propria. C-233.
3. **Allegato con un padre che non esiste più**, che prendeva la politica del contenuto
   globale. Niente più ricorsione né ricaduta sul contenuto globale. C-234.
4. **"Acceso" ricordato da una variabile.** Adesso guarda gli agganci e la riaccensione ripara.
   Lo spegnimento dopo le registrazioni resta un limite dichiarato (punto 4). C-235.
5. **"Passa per ultimo" promesso senza poterlo garantire.** Priorità portata alla massima, e
   il limite scritto nei punti 4, 8 e 10.
6. **Accensione al caricamento e versione non sorvegliate.** C-236.
7. **Le prove non attraversavano il percorso vero.** Il sorgente ora pretende che la testata
   stampi il metatag; aggiunti incorporamento e testata senza metatag. C-237.

## 14. Il secondo giro di revisione indipendente

Sul commit `62d1f93`, quattro rilievi. Come sono stati chiusi:

1. **Un altro componente poteva sostituire l'intestazione su `send_headers`** e cancellare il
   divieto. Sulle pagine vietate esce sempre una riga generale `noindex` propria, in
   aggiunta, dopo gli agganci ordinari. C-239.
2. **Valori misti** come `nofollow, googlebot: nofollow` erano presi per generali e ricevevano
   il divieto in coda, dove valeva per un motore solo. Un valore che nomina un motore in
   qualunque punto ora si conserva intero a parte, e il divieto generale lo porta la riga
   propria. C-233, C-239.
3. **La mappa allargata con i filtri spenti** saltava `the_posts`. I tipi vietati escono
   dall'interrogazione su `pre_get_posts`, con `any`, allegati ed elenco vuoto gestiti;
   `the_posts` resta come seconda difesa. C-238.
4. **Le prove guardavano la risposta preparata, non quella emessa.** Le righe di core escono
   ora da un punto solo, sostituibile nelle prove; le prove guardano le righe emesse dalla
   pagina, anche con un concorrente, e dal file, dai due punti di consegna. I tre guasti
   indicati (G25, G32, G33) sono nella tabella del punto 12, con la distinzione fra
   composizione ed emissione. C-239, C-240.

## 15. Il terzo giro di revisione indipendente

Sul commit `54035d1`, un rilievo. **Allegati scelti per contenuto padre.** Un componente che
legge gli allegati della mappa con un elenco di padri (`post_parent__in`) e i filtri spenti
faceva passare l'allegato di un atto: WordPress applica un solo vincolo sul padre, e
l'inclusione vince sull'esclusione aggiunta dalla restrizione. Ora si corregge il vincolo
applicato davvero, compreso il padre singolo, che vince su entrambi. C-241, guasti G36..G38.

## 16. Il quarto giro di revisione indipendente

Sul commit `eefa5ed`, due rilievi, tutti e due sulla restrizione della mappa.

1. **Lettura svuotata scavalcabile.** La restrizione chiedeva "niente" con `post__in` a zero,
   ma WordPress fa vincere `p` (e `page_id`, `attachment_id`) su `post__in`. Ora un contenuto
   scelto per identificativo si guarda direttamente, e svuotare vuol dire togliere i selettori
   che prevalgono e chiedere un tipo che non esiste. C-242.
2. **Allegati senza contenuto persi.** Nell'elenco dei padri lo zero veniva scartato insieme
   ai padri vietati. Ora resta. C-243.

Quattro giri di fila hanno trovato un modo nuovo di personalizzare la lettura della mappa. La
causa comune è che le difese guardavano come la lettura è chiesta, e i modi di chiederla sono
molti. Per questo c'è ora un'ultima difesa che guarda che cosa ne esce: C-244, descritta al
punto 4. Il punto 17 rifà il giro di tutti i selettori.

## 17. La mappa del sito, selettore per selettore

I modi in cui un componente può personalizzare la lettura dei contenuti della mappa di
WordPress, e che cosa li copre. "Ultima difesa" vuol dire che, anche se la difesa
sull'interrogazione mancasse, la voce del contenuto vietato si svuota e non diventa un
indirizzo (C-244).

| Selettore | Che cosa fa in WordPress | Come è coperto | Prove |
|---|---|---|---|
| `post_type` con tipi vietati | legge quei tipi | i tipi vietati si tolgono; se non resta niente la lettura si svuota | C-238 |
| `post_type` = `any`, o vuoto con una tassonomia | legge tutti i tipi ricercabili | si scrive l'elenco esplicito e si tolgono i vietati | C-238 |
| `p` | sceglie un contenuto e vince su `post__in` | se il contenuto scelto è vietato (anche un allegato di un vietato) la lettura si svuota | C-242 |
| `page_id` | sceglie un contenuto e riscrive anche i vincoli sul padre | come `p` | C-242 |
| `attachment_id` (e `subpost_id`, che WordPress converte) | sceglie un allegato e vince su `p` | come `p` | C-242 |
| `attachment` (per nome) | con il tipo vuoto, WordPress cerca un allegato | si applica l'esclusione degli allegati dei vietati | C-242 |
| `name`, `pagename` | scelgono per nome o percorso; un percorso che porta a un allegato fa cambiare tipo a WordPress | esclusione degli allegati dei vietati; il cambio di tipo lo copre l'ultima difesa | C-244 |
| `post__in` | restringe | nessun rischio proprio; quando la lettura si svuota viene riscritto | C-242 |
| `post_parent` | un padre solo, vince sugli altri vincoli sul padre | se è un vietato la lettura si svuota | C-241 |
| `post_parent__in` | elenco dei padri, vince sull'esclusione | si tolgono i vietati, lo zero resta; se non resta niente la lettura si svuota | C-241, C-243 |
| `post_parent__not_in` | esclusione dei padri | si aggiungono i vietati, solo senza i due vincoli sopra | C-238, C-241 |
| `post_status` allargato | fa entrare gli allegati | gli allegati dei vietati si escludono | C-238, C-241 |
| `suppress_filters` | spegne `the_posts` e i filtri sul testo della lettura | non spegne `pre_get_posts` né la voce della mappa | C-238, C-241..C-244 |
| un aggancio di `pre_get_posts` dopo la restrizione, o i filtri sul testo della lettura | cambiano la lettura dopo la restrizione | `the_posts` se i filtri sono accesi, e comunque l'ultima difesa | C-238, C-244 |
| `wp_sitemaps_posts_pre_url_list` | il componente scrive da sé l'elenco degli indirizzi | **non coperto**: la mappa la scrive il componente | punto 8 |
| un altro fornitore dei contenuti al posto di quello di WordPress | il componente costruisce la mappa | l'ultima difesa svuota ancora le voci, ma non le toglie | C-244 |
| un filtro sulla voce dopo l'ultima difesa, alla stessa priorità | riscrive la voce | **non coperto**, stesso limite della priorità massima (punto 4) | punto 8 |

**Conclusione.** Nella mappa di WordPress un contenuto vietato non diventa un indirizzo,
qualunque lettura lo porti. Restano fuori soltanto i casi in cui un componente scrive la mappa
al posto di WordPress, e per questi vale la prova sul sito vero del punto 9 (terza prova),
insieme al divieto sulla pagina, che il motore rispetta anche se trova l'indirizzo in una
mappa.

## 18. Cosa manca

- La verifica continua sul commit di punta: da riportare quando arriva.
- Il quinto giro di revisione indipendente.
- L'albo deve richiedere `1.4.0` (unità A10 del piano, che verifica il divieto sulle pagine
  vere dell'albo).
- Le tre prove del punto 9 vanno aggiunte al collaudo di rilascio.
