# Registro delle modifiche: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S7 del piano, righe di collaudo C-50..C-52,
dettagliate al punto 10 in C-195..C-224.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** L'albo, in
un'altra unità, si limiterà a tre cose: scrivere le proprie voci con il motivo quando compie
un passaggio che lo prevede, leggere le voci di un atto quando costruirà il referto, e dare a
un proprio ruolo il permesso di consultare il registro. Nessun registro proprio nell'albo:
se ci fosse, esisterebbero due verità su chi ha fatto che cosa.

**Stato: costruita, con le righe di collaudo verdi in locale; verifica continua in corso.**
Le sezioni sono al passato dove dicono cosa il codice fa.

---

## In tre paragrafi, senza termini tecnici

**Cosa costruiamo.** Un quaderno di bordo dove si può solo aggiungere. Ogni volta che
qualcuno crea, pubblica, modifica, toglie o cancella un contenuto di un componente, il
quaderno scrive da solo una riga: chi, che cosa, quando. Il componente può aggiungere righe
sue con il motivo, per esempio "rimosso in anticipo perché conteneva dati che non si potevano
diffondere". Nessuno può correggere o cancellare una riga, nemmeno l'amministratore del sito.
Una pagina di amministrazione lo mostra e lo filtra, e la vede solo chi ha il permesso
apposito.

**Come lo costruiamo.** Il quaderno è una tabella propria nella banca dati. Il programma ha
un solo modo di scriverci, che aggiunge, e nessun modo di cambiare quello che c'è. Di chi
agisce si salva solo il numero utente, mai il nome: il nome si legge al momento della
consultazione. Il momento si prende dall'orologio del programma e la persona dalla richiesta
in corso, quindi un componente non può scrivere una riga a nome di un altro o con una data a
scelta. Due aggiunte pensate per l'albo: una riga può rimandare a una riga precedente, così
una decisione presa giorni dopo si aggancia al fatto senza riscriverlo; e una riga può avere
una chiave che non si ripete, così un'operazione da fare una volta sola resta una sola anche
se due persone la compiono nello stesso istante.

**Come si prova che funziona, e che la prova è vera.** Ogni comportamento ha una prova
automatica, e ogni prova è stata fatta fallire di proposito: si è rotto il codice in
trentacinque modi diversi, uno per volta, e si è guardato quale prova diventava rossa (punto
11). Per esempio: se il registro si dimentica di scrivere la pubblicazione, cinque prove
diventano rosse; se la pagina mostra il motivo senza neutralizzarne il codice, diventa rossa
la prova C-219; se il permesso di lettura viene dato all'amministratore, diventano rosse
C-214 e C-215.

---

## 1. I file

| File | Perché |
|---|---|
| `includes/class-conformita-core-registro.php` | La tabella, la scrittura in aggiunta, le letture, l'annotazione delle voci mancate |
| `includes/class-conformita-core-registro-automatico.php` | Gli agganci che traducono le operazioni di WordPress in voci |
| `includes/class-conformita-core-registro-schermata.php` | La voce di menu e la pagina di consultazione |
| `includes/funzioni-api.php` | Quattro funzioni pubbliche nuove |
| `includes/class-conformita-core-scadenza.php` | Un metodo interno, `ora()`, che dà al registro lo stesso orologio del filtro |
| `conformita-core.php` | Carica le tre classi, verifica la tabella a ogni avvio, versione di interfaccia 1.4.0 |
| `tests/registro-test.php` | Righe C-195..C-213, C-222..C-224 |
| `tests/registro-schermata-test.php` | Righe C-214..C-221 |
| `tests/allegati-test.php` | C-153 controllava la versione 1.3.0: ora è 1.4.0 |

## 2. Che cosa serve

Il registro delle modifiche del catalogo di core (C-50..C-52, fonte P01), su cui poggiano:

- **ALBO-10** dell'albo, tracciabilità delle operazioni sugli atti, e le righe di ALBO-22 che
  mandano nel registro la motivazione di quattro passaggi: rimando in bozza, annullamento,
  rimozione anticipata con la causa e la decisione dell'amministrazione sull'effetto sul
  periodo, durata propria più corta;
- **ALBO-07**, il referto, che il documento dei requisiti dell'albo vuole costruito sul
  registro e non sulla data di fine memorizzata;
- **C-40** di core, il compito pianificato (S6), che scriverà una voce per ogni contenuto
  defisso;
- **TRASP-19** della trasparenza, quando quel componente si riaprirà.

## 3. Il flusso, per chi usa il sito

1. Un redattore crea un contenuto: una voce `creazione`. Lo salva più volte in bozza: nessuna
   voce (punto 8). Lo manda in verifica: `cambio_stato`. Chi pubblica lo pubblica:
   `pubblicazione`, con il suo numero utente e l'istante.
2. Il componente, se il passaggio lo prevede, aggiunge la voce sua, con motivo e dettagli.
3. Se più tardi qualcuno deve dire qualcosa su quel passaggio, il componente scrive una voce
   nuova che rimanda alla prima. La prima resta com'era.
4. Chi ha il permesso apre **Registro delle modifiche** dal menu di amministrazione, vede le
   voci dalla più recente, e le filtra per sezione, contenuto, operazione, utente e giorni.
   Non ci sono pulsanti che cambiano qualcosa.

## 4. I dati

Una tabella, `{prefisso}conformita_core_registro`, creata all'attivazione e verificata a ogni
avvio (WordPress non chiama l'attivazione quando un plugin si aggiorna). La versione dello
schema installata sta nell'opzione `conformita_core_registro_schema`, e si scrive solo dopo
aver riletto che la tabella c'è.

| Colonna | Contenuto |
|---|---|
| `id` | numero progressivo della voce, che è anche l'ordine di scrittura |
| `istante` | data e ora in UTC, dall'orologio di core. Si mostra e si filtra nel fuso del sito, con `wp_timezone()`. UTC è il formato di conservazione, come `post_date_gmt`: un'ora civile sarebbe ambigua nell'ora che si ripete al ritorno dell'ora solare |
| `utente` | numero dell'utente della richiesta; zero se non c'è nessun utente, per esempio il compito pianificato. **Nient'altro della persona** |
| `sezione` | sezione della voce, obbligatoria |
| `contenuto`, `tipo` | il contenuto e il suo tipo in quel momento; nulli per una voce della sola sezione |
| `azione` | il nome dell'operazione |
| `origine` | `automatica` se l'ha scritta core, `componente` se l'ha scritta un componente |
| `motivazione` | il motivo, testo libero, nullo se assente |
| `dettagli` | coppie di nome e valore semplice, codificate |
| `riferimento` | la voce precedente a cui questa rimanda |
| `chiave` | chiave di unicità, confrontata byte per byte; il vincolo è della banca dati |

Un'opzione in più, `conformita_core_registro_mancate`, conta le voci automatiche che non si
sono potute scrivere, con la data della prima e dell'ultima.

**Le voci automatiche**, tutte sui soli tipi registrati attraverso core:

| Operazione | Quando |
|---|---|
| `creazione` | il primo salvataggio vero di un contenuto (non la bozza automatica dell'editor) |
| `pubblicazione` | l'ingresso nello stato pubblicato, da qualunque stato |
| `rimozione` | l'uscita dallo stato pubblicato, verso qualunque stato, cestino compreso |
| `cambio_stato` | ogni altro cambio di stato, compresi quelli verso gli stati propri di un componente |
| `modifica` | un campo del contenuto cambiato, fuori dalla bozza; si registrano i nomi dei campi, non i valori |
| `modifica_fine_pubblicazione` | ogni cambio della data di fine, con il valore di prima e di dopo |
| `eliminazione` | l'eliminazione definitiva, con lo stato da cui è stato eliminato; bozze comprese |
| `allegato_aggiunto`, `allegato_eliminato` | sul contenuto padre, con il numero dell'allegato: al caricamento e all'eliminazione del file, quando un allegato cambia padre con il salvataggio di WordPress (tolto da uno, aggiunto all'altro), e quando lo si collega o scollega dalla libreria dei media |

Questi nomi sono riservati: un componente non li può usare per le proprie voci.

## 5. I permessi

| Azione | Chi |
|---|---|
| Scrivere una voce | il codice di un componente, con la funzione pubblica. Non è una superficie esterna: nessuna richiesta dall'esterno arriva a scrivere |
| Aprire la schermata | chi ha `conformita_core_leggere_registro`. **Core non la dà a nessun ruolo, nemmeno all'amministratore**, come per le capability dei tipi: la dà il componente |
| Filtrare | chi apre la schermata, con il gettone di sicurezza del modulo; senza gettone valido i filtri non si applicano e la pagina lo dice |
| Modificare o cancellare una voce | **nessuno**: non esiste il percorso, nell'interfaccia, nelle funzioni pubbliche, nelle chiamate asincrone, nell'interfaccia per programmi |

## 6. I modi di guasto

| Guasto | Che cosa succede |
|---|---|
| La scrittura di una voce del componente fallisce | la funzione restituisce errore e non un numero. Il componente decide; per i passaggi con motivazione obbligatoria il consiglio è al punto 12 |
| La scrittura di una voce automatica fallisce | l'operazione è già avvenuta e non si disfa. Si annota il buco, e la schermata lo mostra in testa con il conteggio e le date. Un buco nel registro non si ripara, si può solo sapere che c'è |
| La scrittura riesce ma la riga non dice quello che si voleva | la rilettura se ne accorge e la funzione restituisce errore. La riga resta, perché il registro non cancella |
| Due scritture con la stessa chiave nello stesso istante | una riesce, l'altra riceve l'errore con il numero della voce che esiste già. Lo garantisce il vincolo della banca dati, non solo il controllo che lo precede (guasto G12) |
| La tabella non c'è | la scrittura fallisce come sopra; l'opzione della versione non si scrive, quindi l'avvio successivo riprova a crearla |
| Un filtro sconosciuto o malformato | errore, mai un filtro ignorato: un filtro ignorato mostrerebbe tutte le voci come se fossero quelle chieste |
| Un utente eliminato | la voce resta con il suo numero, e la schermata scrive "utente N, non più presente" |
| Un contenuto eliminato | le sue voci restano; la schermata scrive "N (tipo), non più presente" |

## 7. Le prove

Righe C-195..C-224 al punto 10; file e metodi al punto 1. In locale, su WordPress 6.5 con PHP
8.4, l'intera batteria dà 266 prove verdi, e il controllo di stile è pulito.

## 8. Che cosa deliberatamente non fa

- **Le modifiche delle bozze non si registrano.** WordPress salva da solo una bozza a
  intervalli mentre la si scrive, e una voce per salvataggio renderebbe il registro
  illeggibile. Si registrano la nascita, ogni cambio di stato, l'eliminazione e ogni modifica
  da quando il contenuto non è più una bozza. Stessa regola per la data di fine e per gli
  allegati di una bozza.
- **Quando lo stato cambia, il nome nell'indirizzo e le date non contano come modifica.**
  WordPress li riscrive da sé in quel passaggio (il nome alla pubblicazione e nel cestino, la
  data in UTC alla pubblicazione). Gli altri campi cambiati nello stesso salvataggio si
  registrano.
- **I valori dei campi non si conservano**, solo i loro nomi. Il titolo e il testo di un atto
  possono contenere dati personali.
- **Collegando dalla libreria dei media un allegato che aveva già un padre**, la voce
  `allegato_aggiunto` va sul padre nuovo ma il padre di prima non riceve la sua: la libreria
  sovrascrive il padre con un'istruzione diretta e annuncia solo quello nuovo. La libreria
  offre il collegamento per i soli allegati liberi, quindi il caso richiede di aggirarla.
- **Le modifiche ai metadati propri di un componente non si registrano da sole**: core
  conosce solo la data di fine. Il componente scrive la voce quando il suo dato lo richiede.
- **Nessuna garanzia verso chi amministra il server.** Chi scrive direttamente nella banca
  dati può cambiare la tabella. Un vincolo nella banca dati (un innesco che rifiuta le
  modifiche) richiede permessi che molti servizi di ospitalità non danno, e farebbe fallire
  l'installazione; una catena di impronte che renda visibile una manomissione è possibile, ma
  è una unità a sé e nessuna riga del catalogo la chiede.
- **Nessuna esportazione** del registro, nessuna interfaccia per programmi, nessuna
  cancellazione alla disinstallazione: il registro serve proprio dopo.
- **Un solo permesso di lettura per tutte le sezioni.** Chi lo ha vede le voci di tutti i
  componenti. Separarlo per sezione è possibile e non è chiesto.
- **Nessun limite di lunghezza** su motivazione e dettagli: li scrive il codice di un
  componente, non un visitatore.
- **Il registro non decide niente**: non sa se una motivazione è obbligatoria, quali cause
  sono ammesse, se una decisione si può dare una volta sola. Sono regole del componente; core
  dà la chiave di unicità perché il componente le possa far reggere.

## 9. Come si prova su un'installazione reale

1. Con un componente che assegna il permesso di lettura a un ruolo, un utente di quel ruolo
   apre **Registro delle modifiche** dal menu. **Deve vedere** la pagina con le voci. **Se
   non vede la voce di menu**, il permesso non è stato assegnato: è il comportamento voluto
   finché il componente non lo fa, non un guasto.
2. Pubblica un contenuto di prova del componente e ricarica il registro. **Deve vedere** in
   cima una voce `pubblicazione` con il suo nome, il numero del contenuto e l'ora giusta nel
   fuso del sito. **Se l'ora è sbagliata di una o due ore**, il fuso del sito non è
   impostato in **Impostazioni, Generali**. **Se la voce non c'è**, il registro non sta
   scrivendo: il rilascio si ferma.
3. Da un utente amministratore **senza** il permesso di lettura, apre a mano l'indirizzo
   `wp-admin/admin.php?page=conformita-core-registro`. **Deve vedere** il rifiuto. **Se vede
   il registro**, il permesso è stato dato all'amministratore da qualcuno o da qualcosa: si
   controlla chi.
4. Se in testa alla pagina compare l'avviso di operazioni non registrate, il registro ha un
   buco in quel periodo: si guarda il registro degli errori del server in quelle date.

## 10. Le righe di collaudo

C-50, C-51 e C-52 del catalogo restano le righe di contratto; queste le rendono misurabili.

### Voci automatiche (C-50)

| # | Caso | Atteso |
|---|---|---|
| C-195 | Un contenuto in verifica viene pubblicato, con il sito in un fuso diverso da UTC | una voce e una sola, `pubblicazione`, con utente, contenuto, tipo, sezione, origine automatica, stati di prima e di dopo, e l'istante dell'orologio di core conservato in UTC |
| C-196 | Nascita di una bozza, di un contenuto già pubblicato, di una bozza automatica poi salvata | `creazione`; `creazione` e `pubblicazione`; niente per la bozza automatica e `creazione` al suo primo salvataggio |
| C-197 | Modifica del titolo di un pubblicato e di uno in verifica; salvataggio senza modifiche; modifica di testo e riassunto | una voce `modifica` con i nomi dei campi; nessuna voce; una voce con i due nomi e nessun valore |
| C-198 | Un pubblicato va in bozza, in verifica, privato, nel cestino | una voce `rimozione` ciascuno, e nessuna `modifica` accanto |
| C-199 | Bozza, verifica, bozza, cestino, ripristino | quattro `cambio_stato` con gli stati giusti |
| C-200 | Eliminazione definitiva di un pubblicato con data di fine | una sola voce `eliminazione` con lo stato; le voci di prima restano tutte |
| C-201 | Le stesse tre operazioni (titolo, data di fine, allegato) su una bozza e su un contenuto in verifica; eliminazione di una bozza che era stata pubblicata | nella bozza nessuna voce, in verifica tre; l'eliminazione della bozza si registra |
| C-202 | Data di fine scritta, riscritta uguale, cambiata, tolta; poi due righe duplicate aggiornate con una scrittura | tre voci con prima e dopo, nessuna per la riscrittura uguale; una sola voce per la scrittura su due righe |
| C-203 | Allegato aggiunto ed eliminato su un pubblicato; un allegato libero assegnato a un contenuto e poi spostato su un altro; scollegato e ricollegato dalla libreria dei media | `allegato_aggiunto` e `allegato_eliminato` sul contenuto padre, con il numero dell'allegato; allo spostamento, tolto dal primo e aggiunto al secondo; lo stesso dalla libreria |
| C-204 | Le stesse operazioni su un articolo di WordPress | nessuna voce e nessuna voce mancata annotata; controllo positivo sul tipo gestito |
| C-205 | Operazione senza utente, poi con un utente con nome ed email | utente zero, poi il numero; le colonne sono quelle del punto 4 e nella riga non c'è nessun dato della persona |

### Voci dei componenti (C-50)

| # | Caso | Atteso |
|---|---|---|
| C-206 | Voce completa con contenuto, motivazione su due righe, dettagli di ogni tipo ammesso; voce della sola sezione | si rilegge uguale, motivazione ripulita degli spazi ai lati, origine componente, utente e istante di core; nessuna voce automatica accanto |
| C-207 | Quarantasette descrizioni sbagliate, una condizione per volta, compresi i nove nomi riservati, un nome con a capo finale, `utente`, `istante` e `origine` dichiarati | ciascuna rifiutata con il suo codice, e la tabella identica; controllo positivo sulla forma corretta |
| C-208 | Una decisione presa giorni dopo da un'altra persona, che rimanda alla rimozione | la rimozione resta identica; la decisione porta il rimando, la nuova persona e il nuovo istante; si ritrovano in ordine leggendo il contenuto e cercando per rimando |
| C-209 | Seconda voce con la stessa chiave; chiave che differisce solo per maiuscole | rifiutata con il numero della prima, tabella identica; la chiave in maiuscolo è un'altra chiave; il vincolo di unicità esiste nella banca dati |
| C-210 | Scrittura che fallisce, per un componente e per una pubblicazione | errore al componente; la pubblicazione avviene lo stesso; nessuna riga; una voce mancata annotata con l'istante |
| C-211 | Scrittura che riesce ma scrive un testo diverso | errore, non il numero; controllo positivo senza alterazione |

### Solo in aggiunta (C-51)

| # | Caso | Atteso |
|---|---|---|
| C-212 | Lettura del codice | nessuna istruzione di modifica, cancellazione o cambio di struttura nelle classi del registro; nessun altro file nomina la tabella; le funzioni pubbliche del registro sono quattro, e nessun metodo ha un nome da modifica |
| C-213 | Superfici esterne | nessuna azione di amministrazione né chiamata asincrona sul registro, nessuna rotta dell'interfaccia per programmi |
| C-221 | Richiesta di cancellazione di gruppo inviata alla schermata, con le voci che una tabella di WordPress userebbe | tabella identica; nella pagina nessun modulo che scrive, nessuna casella di selezione; il modulo dei filtri è una lettura. Seconda parte: le voci mancate compaiono in testa con conteggio e date nel fuso del sito |

### Consultazione (C-52)

| # | Caso | Atteso |
|---|---|---|
| C-214 | Amministratore, redattore e abbonato senza il permesso; poi un utente con il permesso | la voce di menu chiede il permesso dedicato; i tre sono rifiutati; il quarto legge |
| C-215 | Ruoli dopo l'installazione | nessun ruolo ha il permesso di lettura |
| C-216 | Ogni filtro da solo, due giorni di confine attorno alla mezzanotte del sito, due filtri insieme; filtri sconosciuti o malformati | ogni filtro mostra le voci giuste e nasconde le altre; una voce delle 22:30 UTC è del giorno dopo a Roma; un filtro sbagliato è un errore e la pagina non mostra tutto |
| C-217 | Filtri senza gettone o con gettone sbagliato | non applicati, e la pagina lo dice; controllo positivo con il gettone valido |
| C-218 | Due voci a cavallo del cambio d'ora, due fusi del sito | orari giusti in entrambi i casi |
| C-219 | Motivazione, dettagli e titolo con codice | stampati come testo; l'a capo della motivazione diventa un a capo della pagina |
| C-220 | Voce del sistema, di una persona che poi cambia nome, di una persona poi eliminata | "sistema"; il nome di adesso; "non più presente" |

### Installazione, contratto, non vacuità

| # | Caso | Atteso |
|---|---|---|
| C-222 | Avvio con la versione giusta; installazione con la tabella che non si trova; installazione su tabella allineata | nessuna istruzione alla banca dati; nessuna versione scritta; nessun cambio di struttura |
| C-223 | Versione di interfaccia | 1.4.0, compatibile con chi chiedeva 1.3.0 |
| C-224 | Voci automatiche spente | nessun aggancio rimasto, nessuna voce dalle operazioni di C-195..C-203; riaccese, tornano |

## 11. La tabella dei guasti: quale riga misura che cosa

Ogni guasto è stato introdotto da solo nel codice, con le prove fatte girare e il codice
rimesso a posto subito dopo.

| # | Guasto | Prove rosse |
|---|---|---|
| G01 | nessuna voce di pubblicazione | C-195, C-196, C-204, C-205, C-210 |
| G02 | nessuna voce di creazione | C-196, C-204, C-205 |
| G03 | le modifiche delle bozze si registrano | C-201 |
| G04 | nome e date contano come modifica al cambio di stato | C-195, C-198, C-204, C-205, C-210 |
| G05 | la seconda segnalazione della stessa scrittura produce una voce | C-202 |
| G06 | la cancellazione dei metadati durante l'eliminazione produce una voce | C-200 |
| G07 | chi agisce si può dichiarare | C-207 |
| G08 | i nomi riservati si possono usare | C-207 |
| G09 | l'ancora finale del nome accetta un a capo | C-207 |
| G10 | motivazione di soli spazi accettata | C-207 |
| G11 | rimando a una voce di un altro contenuto accettato | C-207 |
| G12 | tolto il controllo della chiave prima della scrittura | **nessuna, voluto**: il vincolo della banca dati regge da solo, e l'errore resta lo stesso |
| G13 | tolta la rilettura | C-211 |
| G14 | voce automatica mancata non annotata | C-210 |
| G15 | versione scritta senza verificare la tabella | C-222 |
| G16 | installazione a ogni avvio | C-222 |
| G17 | schermata aperta a chiunque abbia accesso | C-214 |
| G18 | voce di menu con il permesso dell'amministratore | C-214 |
| G19 | filtri applicati senza gettone | C-217 |
| G20 | motivazione stampata senza neutralizzarla | C-219 |
| G21 | orari mostrati in UTC | C-218, C-221 |
| G22 | l'ultimo giorno del filtro escluso | C-216 |
| G23 | giorni dei filtri letti in UTC | C-216 |
| G24 | filtro sconosciuto ignorato | C-216 |
| G25 | voci automatiche tentate anche sui tipi non gestiti | C-204 |
| G26 | voce dell'allegato scritta sull'allegato | C-201, C-203, C-204 |
| G34 | cambio di padre con il salvataggio non ascoltato | C-203 |
| G35 | scollegamento dalla libreria scritto come aggiunta | C-203 |
| G27 | permesso di lettura dato all'amministratore | C-214, C-215 |
| G28 | una funzione che cancella | C-212 |
| G29 | una rotta dell'interfaccia per programmi | C-213 |
| G30 | caselle di selezione nella tabella | C-221 |
| G31 | utente negativo accettato nei filtri | C-216 |
| G32 | istante scritto nel fuso del sito | C-195 |
| G33 | eliminazione di una bozza non registrata | C-201 |

**Due guasti non hanno fatto diventare rossa nessuna riga, alla prima passata**, e le righe
sono state corrette prima di chiudere. G25: una voce automatica su un tipo non gestito non si
scriveva comunque, perché la scrittura rifiuta un tipo senza sezione, ma veniva annotata come
voce mancata, e C-204 non guardava le mancate. G32: le prove giravano con il sito in UTC, dove
ora del sito e UTC coincidono; C-195 ora gira con il sito a Roma.

**Un guasto ne ha contaminati altri.** G27 dava il permesso all'amministratore durante
l'installazione, che nelle prove avviene all'avvio della batteria e fuori dalle transazioni
che le prove annullano: il permesso è rimasto nella banca dati di prova e ha fatto diventare
rosse C-214 e C-215 nei guasti successivi. I guasti da G28 in poi sono stati rifatti su una
banca dati pulita. La lezione vale per chi rifà questa tabella: un guasto che scrive
all'installazione va provato per ultimo, o su una banca dati da buttare.

## 12. Che cosa l'albo dovrà fare, adesso che questa unità esiste

- **Scrivere la voce con il motivo dopo il passaggio, dentro la stessa transazione.** Un
  rimando in bozza, un annullamento, una rimozione anticipata: si apre una transazione, si
  compie il passaggio, si scrive la voce; se la voce restituisce errore si annulla la
  transazione e il passaggio non avviene. Scrivere la voce prima lascerebbe una voce di un
  passaggio mai compiuto se il passaggio fallisce; scriverla dopo senza transazione lascerebbe
  un passaggio senza motivo se è la voce a fallire.
- **Le voci automatiche restano accanto a quelle dell'albo.** Una rimozione anticipata produce
  la `rimozione` (o il `cambio_stato` verso lo stato proprio), la `modifica_fine_pubblicazione`
  con la data di prima e di dopo, e la voce dell'albo con causa e motivazione. Sono tre fatti
  diversi e si leggono insieme.
- **La dichiarazione sull'effetto sul periodo** si scrive come voce nuova con `riferimento`
  alla voce della rimozione anticipata, e con una `chiave` come
  `effetto_sul_periodo:<numero della voce di rimozione>`: la seconda dichiarazione riceve
  l'errore `conformita_core_registro_chiave_esistente`, anche se arrivano insieme.
- **Il referto** legge le voci dell'atto con `conformita_core_voci_registro( array(
  'contenuto' => $id ) )`, in ordine di scrittura, e l'appendice di una risposta arrivata
  dopo è la voce che rimanda alla rimozione, con il suo utente e il suo istante.
- **Il permesso di lettura** si assegna ai ruoli dell'albo con
  `conformita_core_capacita_registro()`, all'attivazione e a ogni aggiornamento, come le
  capability del tipo.
- La richiesta di interfaccia dell'albo può restare 1.2.0 finché non usa il registro; quando
  lo usa, chiede 1.4.0.
