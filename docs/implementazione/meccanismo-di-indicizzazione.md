# Meccanismo di indicizzazione: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S9 del piano, righe di collaudo C-80..C-85 e
C-225..C-230, elencate al punto 11.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** L'albo
dichiara già la propria politica (`vietata`) quando registra la sua sezione: da questa
versione quella dichiarazione produce effetti. L'albo, in un'altra unità, dovrà soltanto
richiedere la versione `1.4.0` dell'interfaccia, perché è la prima che garantisce il
divieto.

**Stato: costruita, prove verdi in locale, verifica continua in corso.** Le sezioni sono al
passato e dicono quello che il codice fa.

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
file di un allegato risponde per l'atto a cui appartiene. La mappa del sito perde per intero
i tipi di contenuto delle sezioni vietate. Non si usa `robots.txt`, di proposito: un
indirizzo escluso lì non viene visitato, quindi il motore non legge mai l'avviso, e se
qualcuno lo collega da fuori l'indirizzo può finire nell'indice lo stesso.

**Come si prova che funziona, e che la prova è vera.** Le prove registrano nello stesso sito
due sezioni finte con politiche opposte e controllano sempre le due insieme: la pagina
vietata deve avere l'avviso, quella consentita non deve averlo, e le pagine normali del sito
nemmeno. Per dimostrare che le prove non sono di cartone, il codice è stato rotto di
proposito in tredici modi diversi (avviso tolto dalla pagina, avviso messo dappertutto,
mappa non filtrata, feed dimenticati, allegati che non risalgono all'atto, `robots.txt`
toccato, spegnimento a metà, e altri): ogni volta almeno una prova è diventata rossa. La
tabella è al punto 12.

---

## 1. File toccati

| File | Perché |
|---|---|
| `includes/class-conformita-core-indicizzazione.php` | nuovo: il meccanismo |
| `conformita-core.php` | carica e accende il meccanismo; versione dell'interfaccia da `1.3.0` a `1.4.0` |
| `includes/class-conformita-core-sezioni.php` | la registrazione di una sezione rifiuta a meccanismo spento (C-85) |
| `includes/class-conformita-core-consegna.php` | il file di un contenuto vietato esce con `X-Robots-Tag: noindex` (C-228) |
| `tests/indicizzazione-test.php` | nuovo: C-80..C-85, C-225..C-227, C-229, C-230 |
| `tests/consegna-test.php` | C-228 |
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
stessa ragione: non dipende dall'ordine in cui WordPress carica i plugin. Tre agganci, in un
elenco solo letto sia dall'accensione sia dallo spegnimento (C-230):

| Aggancio | Che cosa fa |
|---|---|
| `wp_robots` | aggiunge `noindex` al metatag `robots` nel sorgente, e toglie un eventuale `index` |
| `wp_headers` | aggiunge `noindex` all'intestazione `X-Robots-Tag`, senza cancellare quello che c'era |
| `wp_sitemaps_post_types` | toglie dalla mappa del sito i tipi delle sezioni vietate |

Priorità alta (9999): sui contenuti vietati il divieto è l'ultima parola anche se un altro
componente, prima, ha scritto il contrario. Sugli altri contenuti gli agganci restituiscono
quello che ricevono.

**Quali richieste portano il divieto.** Si decide dopo l'interrogazione principale (da
WordPress 6.1 le intestazioni si preparano dopo di essa; la minima dichiarata è 6.5):

1. la pagina di un contenuto di un tipo vietato, compreso il feed dei commenti
   di quel contenuto (C-80);
2. la pagina di un allegato, se il contenuto a cui appartiene è di un tipo vietato (C-227);
3. l'elenco di un tipo vietato (C-225);
4. il feed di un tipo vietato; un feed che mescola tipi vietati e consentiti porta il divieto,
   perché elenca comunque i titoli dei contenuti vietati (C-226).

**Il file consegnato** esce dal punto di consegna di S5 prima che WordPress prepari le
intestazioni della pagina, quindi il divieto si aggiunge lì, con la stessa funzione
(C-228).

**La mappa** perde il tipo intero, non i singoli contenuti: un tipo appartiene a una sezione
sola, e togliendolo sparisce anche la sua voce nell'indice della mappa (C-82).

## 5. Dati letti e scritti

Legge soltanto il registro delle sezioni e dei tipi, che vive in memoria. Non scrive niente:
nessuna opzione, nessun metadato. `docs/dati.md` non cambia.

## 6. Permessi

Nessuno. Il meccanismo agisce su ogni richiesta pubblica, per chiunque la faccia: il divieto
non dipende da chi guarda la pagina.

## 7. Test

Tutte le prove registrano due sezioni finte con politiche opposte nello stesso sito. Il
sorgente si legge stampando quello che WordPress stampa nella testata della pagina; le
intestazioni si leggono dal filtro con cui WordPress le prepara, dopo aver eseguito la
richiesta.

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

**Tre prove di S4 toccate, e perché.** C-14, C-92 e C-108 controllano che un contenuto
scaduto sparisca dalla mappa del sito, e lo facevano su sezioni dichiarate `vietata`. Da
questa unità una sezione vietata non è nella mappa affatto, quindi quelle prove non vi
trovavano più nemmeno il contenuto valido e sono diventate rosse. Non è un difetto del filtro
di scadenza, che non legge la politica di indicizzazione: è che la scadenza nella mappa conta
solo per le sezioni che nella mappa ci sono. Le due prove ora girano su una sezione
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
| Un altro componente scrive `index` sui contenuti vietati | il meccanismo passa per ultimo e lo toglie |
| Un plugin SEO sostituisce mappa e metatag | resta la busta; la mappa si controlla a mano (punto 9) |
| Un tipo vietato e uno consentito nello stesso elenco | vince il divieto sull'elenco; le pagine consentite restano indicizzabili una per una |

## 11. Le righe di collaudo di questa unità

C-80..C-85 sono le righe del catalogo scritte quando l'unità è stata pianificata. Le sei righe
nuove le ha fatte emergere il lavoro, cercando ogni strada da cui un motore arriva ai
contenuti di una sezione: numerazione continuata dopo C-224, l'ultima del registro delle
modifiche, per non sovrapporsi a un ramo ancora aperto.

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-80 | fatto | Pagina di un contenuto di una sezione `vietata`: `noindex` nell'intestazione e nel sorgente |
| C-81 | fatto | Pagina di un contenuto di una sezione `consentita`: nessun `noindex`, e il metatag c'è (l'assenza del divieto è una scelta, non un silenzio) |
| C-82 | fatto | Mappa del sito: assente il tipo della sezione vietata, presenti tipo e contenuti della consentita; a meccanismo spento il tipo vietato ricompare |
| C-83 | fatto | Due sezioni con politiche opposte, registrate nei due ordini: ciascuna ottiene la propria |
| C-84 | fatto | Articoli, pagine e pagina iniziale: nessun divieto, e restano nella mappa |
| C-85 | fatto | A meccanismo spento la registrazione di una sezione è rifiutata con errore che nomina l'indicizzazione; riacceso, la stessa registrazione riesce |
| C-225 | fatto | Elenco di un tipo vietato: divieto nei due segnali; elenco di un tipo consentito: nessun divieto |
| C-226 | fatto | Feed di un tipo vietato, feed dei commenti di un contenuto vietato, feed misto: divieto nell'intestazione. Feed di un tipo consentito, dei commenti di un contenuto consentito, feed generale: nessun divieto |
| C-227 | fatto | Pagina di un allegato: divieto se il contenuto a cui appartiene è vietato; nessun divieto per l'allegato di un contenuto consentito, di un articolo, o senza contenuto |
| C-228 | fatto | File consegnato dal punto di consegna: `X-Robots-Tag: noindex` se il contenuto è vietato, nessuna intestazione se è consentito; le due consegne riescono tutte e due |
| C-229 | fatto | `robots.txt` identico a meccanismo acceso e spento, senza il tipo vietato |
| C-230 | fatto | Acceso, i tre agganci rispondono alla loro priorità; spento, nessuno risponde e il divieto sparisce; riacceso, torna |

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
| G4. Divieto anche sui contenuti fuori dalle sezioni | C-84, C-226, C-227 |
| G5. Mappa non filtrata | C-82, C-83 |
| G6. Registrazione senza guardia | C-85 |
| G7. Elenchi del tipo dimenticati | C-225 |
| G8. Feed dimenticati | C-226 |
| G9. L'allegato non risale al contenuto | C-227, C-228 |
| G10. File consegnati senza divieto | C-228 |
| G11. Esclusione aggiunta a `robots.txt` | C-229, C-230 |
| G12. Spegnimento che toglie un aggancio solo | C-82, C-230 |
| G13. Feed misto: vince il consentito | C-226 |

## 13. Cosa manca

- La verifica continua sul commit di punta: da riportare quando arriva.
- La revisione indipendente.
- L'albo deve richiedere `1.4.0` (unità A10 del piano, che verifica il divieto sulle pagine
  vere dell'albo).
- Le tre prove del punto 9 vanno aggiunte al collaudo di rilascio.
