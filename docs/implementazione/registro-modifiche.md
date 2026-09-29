# Registro delle modifiche: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S7 del piano, righe di collaudo C-50..C-52,
dettagliate al punto 10 in C-195..C-224.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** L'albo, in
un'altra unità, si limiterà a tre cose: scrivere le proprie voci con il motivo quando compie
un passaggio che lo prevede, leggere le voci di un atto quando costruirà il referto, e dare a
un proprio ruolo il permesso di consultare il registro. Nessun registro proprio nell'albo:
se ci fosse, esisterebbero due verità su chi ha fatto che cosa.

**Stato: costruita, riletta in proprio prima del primo giro di revisione e corretta dopo il
primo, il secondo, il terzo, il quarto, il quinto e il sesto giro (punto 11, ultime otto parti), con le
righe di collaudo verdi in locale.** Le strade di WordPress che cambiano un contenuto senza
il salvataggio ordinario, e come ciascuna è coperta, sono al punto 13.
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
centoventinove modi diversi, uno per volta, e si è guardato quale prova diventava rossa (punto
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
| `includes/class-conformita-core-contatore.php` | Conteggio e date delle voci mancate, aggiornati in un passo solo; non nomina la tabella del registro |
| `includes/class-conformita-core-registro-schermata.php` | La voce di menu e la pagina di consultazione |
| `includes/funzioni-api.php` | Quattro funzioni pubbliche nuove |
| `includes/class-conformita-core-scadenza.php` | Un metodo interno, `ora()`, che dà al registro lo stesso orologio del filtro |
| `conformita-core.php` | Carica le quattro classi, verifica la tabella a ogni avvio, versione di interfaccia 1.4.0 |
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
aver riletto che la tabella c'è e che la chiave ha il suo vincolo di unicità, per intero e da
sola. Se una scrittura fallisce e la tabella non si trova più, la versione si toglie e l'avvio
successivo reinstalla.

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

Tre opzioni in più, `conformita_core_registro_mancate`, `..._mancate_prima` e
`..._mancate_ultima`, contano le voci automatiche che non si sono potute scrivere e ne tengono
la data della prima e dell'ultima. Ciascuna si aggiorna con una sola istruzione alla banca
dati, che parte dal valore che c'è in quel momento: due richieste che annotano insieme non si
cancellano a vicenda.

**Le voci automatiche**, tutte sui soli tipi registrati attraverso core:

| Operazione | Quando |
|---|---|
| `creazione` | il primo salvataggio vero di un contenuto (non la bozza automatica dell'editor). Una bozza automatica che ha già voci nel registro, perché un componente vi ha rimesso un contenuto esistente, non rinasce |
| `pubblicazione` | l'ingresso nello stato pubblicato, da qualunque stato |
| `rimozione` | l'uscita dallo stato pubblicato, verso qualunque stato, cestino compreso |
| `cambio_stato` | ogni altro cambio di stato, compresi quelli verso gli stati propri di un componente |
| `modifica` | un campo del contenuto cambiato, fuori dalla bozza; si registrano i nomi dei campi, non i valori. Restano fuori solo i cambiamenti che WordPress fa da sé al cambio di stato (punto 8). Comprende i campi che WordPress cambia con un'istruzione diretta, senza salvare il contenuto: l'autore, quando si elimina un utente affidandone i contenuti a un altro; il padre dei figli di un contenuto gerarchico eliminato, che passano al suo padre |
| `modifica_fine_pubblicazione` | ogni cambio della data di fine, con il valore di prima e di dopo, anche quando la scrittura non nomina il contenuto: la cancellazione per chiave su tutti i contenuti, il cambio di chiave di una riga, la chiave scritta con altre maiuscole. Su un contenuto gestito la fine si legge prima e dopo ogni scrittura di metadati, qualunque chiave annunci: due letture per scrittura, sui soli contenuti gestiti |
| `eliminazione` | l'eliminazione definitiva, con lo stato da cui è stato eliminato; bozze comprese, e la bozza automatica che ha una storia. Si scrive dopo che la banca dati ha cancellato la riga del contenuto |
| `cambio_tipo` | il contenuto cambia tipo: una voce nella sezione del tipo di prima, con `verso` uscita, e una in quella del tipo nuovo, con `verso` ingresso, per ciascuna delle due che è gestita. Portano tipi, stati e campi cambiati nello stesso salvataggio |
| `allegato_aggiunto`, `allegato_eliminato` | sul contenuto padre, con il numero dell'allegato: al caricamento e all'eliminazione del file (dopo che la banca dati ha cancellato la riga dell'allegato), quando un allegato cambia padre con il salvataggio di WordPress (tolto da uno, aggiunto all'altro), quando lo si collega o scollega dalla libreria dei media, e quando WordPress, eliminando un contenuto, ne sposta gli allegati sul suo padre: l'aggiunta si registra sul padre che li riceve |

Questi nomi sono riservati: un componente non li può usare per le proprie voci.

## 5. I permessi

| Azione | Chi |
|---|---|
| Scrivere una voce | il codice di un componente, con la funzione pubblica. Non è una superficie esterna: nessuna richiesta dall'esterno arriva a scrivere |
| Scrivere una voce automatica | solo core. Il registro consegna la scrittura con origine automatica una volta sola, all'avvio, alle voci automatiche; gli ascoltatori sono chiusure con metodi privati, e nessun metodo pubblico li chiama o li spegne (punto 8 per il limite) |
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
| I dettagli non si possono codificare senza cambiarli | errore `conformita_core_registro_dettagli_non_fedeli`, e nessuna riga. I numeri decimali si codificano con tutte le cifre, qualunque precisione abbia scelto chi configura PHP; il testo codificato si rilegge prima di scrivere |
| L'installazione non è riuscita | nessuna voce si scrive finché l'avvio successivo non riesce a installare: il componente riceve `conformita_core_registro_non_installato`, la voce automatica diventa una mancata. Il controllo legge la versione, che è già in memoria |
| La tabella non c'è | la scrittura fallisce come sopra; l'opzione della versione non si scrive, o si toglie alla prima scrittura fallita se era scritta, quindi l'avvio successivo riprova a crearla |
| La tabella c'è ma la chiave non ha il suo vincolo | per esempio una tabella preesistente che dbDelta non riesce ad allineare: non è un registro installato, la versione non si scrive e l'avvio riprova |
| Un filtro sconosciuto o malformato | errore, mai un filtro ignorato: un filtro ignorato mostrerebbe tutte le voci come se fossero quelle chieste. Un filtro con valore nullo è malformato, e così la pagina senza la misura della pagina. Nella schermata è malformato anche un filtro che non è un testo o che la ripulitura cambierebbe, e un nome che non è né un filtro né un parametro della schermata: la pagina lo dice e non mostra voci |
| La banca dati rifiuta la cancellazione della riga di un contenuto | il contenuto c'è ancora e la voce `eliminazione` non si scrive. WordPress però ha già cancellato i metadati, data di fine compresa, e quella cancellazione resta senza voce (punto 8). Il tentativo fallito non lascia segni: ciò che segue nella stessa richiesta si registra normalmente |
| Un utente eliminato | la voce resta con il suo numero, e la schermata scrive "utente N, non più presente" |
| Un contenuto eliminato | le sue voci restano; la schermata scrive "N (tipo), non più presente" |

## 7. Le prove

Righe C-195..C-224 al punto 10; file e metodi al punto 1. In locale, su WordPress 6.5 con PHP
8.4, l'intera batteria dà 281 prove verdi, e il controllo di stile è pulito.

## 8. Che cosa deliberatamente non fa

- **Le modifiche delle bozze non si registrano.** WordPress salva da solo una bozza a
  intervalli mentre la si scrive, e una voce per salvataggio renderebbe il registro
  illeggibile. Si registrano la nascita, ogni cambio di stato, l'eliminazione e ogni modifica
  da quando il contenuto non è più una bozza. Stessa regola per la data di fine e per gli
  allegati di una bozza.
- **Quando lo stato cambia, ciò che WordPress riscrive da sé non conta come modifica.** Il
  criterio è chi ha chiesto il valore di dopo, non il valore di prima: prima di ogni altro
  filtro si legge cosa chiede il salvataggio e cosa ne ricava WordPress, e il campo è di
  WordPress solo se nessuno l'ha chiesto e il valore è quello preparato da WordPress. Così
  restano fuori il nome nell'indirizzo generato, svuotato al passaggio in verifica, con il
  suffisso del cestino aggiunto o tolto, e le date fissate o rimesse a oggi quando non erano
  fissate; si registrano un nome o una data scelti da chi pubblica o programma, anche su un
  contenuto che non li aveva, e quelli cambiati da un altro componente nello stesso
  salvataggio. Un annuncio di modifica che non viene da un salvataggio conta ogni campo.
  WordPress aggiunge il suffisso del cestino anche al nome di un contenuto che è già nel
  cestino, con un'istruzione diretta, quando un altro contenuto prende il suo indirizzo: è la
  stessa riscrittura, e non si registra.
  Una data si riconosce come rimessa a oggi da WordPress quando è quella di adesso, entro due
  secondi, con la data in UTC vuota: chi indica da sé proprio l'ora di adesso in quella forma
  chiede ciò che WordPress avrebbe fatto comunque, e la voce non si scrive.
- **Un'eliminazione che fallisce a metà lascia un buco.** WordPress cancella i metadati del
  contenuto prima della sua riga. Se la banca dati rifiuta poi la cancellazione della riga, il
  contenuto resta senza la data di fine e senza la voce che lo dice. Lo stesso per gli
  allegati e i figli che WordPress ha già spostato sul padre: il padre che li riceve ha la sua
  voce, il contenuto rimasto non ha quella dell'allegato tolto. Serve un guasto della banca
  dati nel mezzo di un'eliminazione; accorgersene richiederebbe di rimandare la voce a fine
  richiesta, e nessuna riga del catalogo lo chiede.
- **I valori dei campi non si conservano**, solo i loro nomi. Il titolo e il testo di un atto
  possono contenere dati personali.
- **Collegando dalla libreria dei media un allegato che aveva già un padre**, la voce
  `allegato_aggiunto` va sul padre nuovo ma il padre di prima non riceve la sua: la libreria
  sovrascrive il padre con un'istruzione diretta e annuncia solo quello nuovo. La libreria
  offre il collegamento per i soli allegati liberi, quindi il caso richiede di aggirarla.
- **Il cambio di tipo con l'istruzione diretta di WordPress si vede solo se il contenuto era
  in memoria.** La funzione che cambia solo il tipo scrive nella banca dati e lo annuncia
  togliendo il contenuto dalla memoria: se la memoria aveva la copia, questa dice il tipo di
  prima, in tutti e due i versi; se non l'aveva, il contenuto si rilegge già con il tipo
  nuovo, e il cambio non si vede. Per vederlo nei due versi il tipo si rilegge dalla banca
  dati ogni volta che un contenuto esce dalla memoria fuori da un salvataggio, gestito o no:
  una lettura in più, solo se almeno un tipo è gestito. Vederlo sempre richiederebbe una
  lettura del registro a ogni salvataggio di ogni contenuto del sito.
- **Le voci di due salvataggi annidati seguono l'ordine in cui i salvataggi finiscono.** Se
  un componente salva di nuovo un contenuto mentre un altro salvataggio è in corso, quello
  interno finisce per primo e la sua voce viene prima, anche se ha scritto dopo. Le voci
  dicono quali campi sono cambiati, non i valori, e ciascun salvataggio ha la sua.
- **Un salvataggio che la banca dati rifiuta lascia la sua richiesta.** Il registro legge cosa
  chiede un salvataggio al filtro dei dati e la consuma all'annuncio della modifica; se la
  banca dati rifiuta la scrittura, WordPress si ferma senza annunciare niente, e la richiesta
  resta in pila per il resto della richiesta del sito. Un salvataggio successivo dello stesso
  contenuto mette e toglie la sua, e non ne risente; ne risentono il cambio di tipo con
  l'istruzione diretta sullo stesso contenuto, che non si vede, e un salvataggio che ne
  contenga un altro fallito, che prende la richiesta sbagliata. Serve un guasto della banca
  dati e poi un'altra operazione sullo stesso contenuto nella stessa richiesta; WordPress non
  annuncia il fallimento, e nessuna riga del catalogo lo chiede.
- **Le modifiche ai metadati propri di un componente non si registrano da sole**: core
  conosce solo la data di fine. Il componente scrive la voce quando il suo dato lo richiede.
- **Nessuna garanzia verso il codice che gira nello stesso processo.** I metodi privati
  chiudono le strade ordinarie: nessun componente può chiamare un ascoltatore, scrivere una
  voce con origine automatica o spegnere le voci automatiche con una funzione di core. Ma il
  codice di un altro plugin gira con gli stessi poteri di core: può scrivere nella tabella con
  la banca dati di WordPress, staccare tutti gli ascoltatori di un aggancio o leggere un metodo
  privato con la riflessione. Chiudere anche queste strade non è possibile dall'interno di
  WordPress; il registro serve a sapere cosa è successo attraverso WordPress, non a difendersi
  da un plugin ostile, che è già un problema più grande.
- **Nessuna garanzia verso chi amministra il server.** Chi scrive direttamente nella banca
  dati può cambiare la tabella, e anche togliere il vincolo sulla chiave dopo l'installazione:
  il vincolo si controlla a ogni installazione e aggiornamento dello schema, non a ogni avvio,
  che costerebbe un'interrogazione per ogni pagina del sito. Un vincolo nella banca dati (un innesco che rifiuta le
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
| C-195 | Un contenuto in verifica viene pubblicato, con il sito in un fuso diverso da UTC. Seconda parte: un contenuto programmato pubblicato dalla funzione di WordPress che non salva | una voce e una sola, `pubblicazione`, con utente, contenuto, tipo, sezione, origine automatica, stati di prima e di dopo, e l'istante dell'orologio di core conservato in UTC; seconda parte: una `pubblicazione` dallo stato programmato |
| C-196 | Nascita di una bozza, di un contenuto già pubblicato, di una bozza automatica poi salvata | `creazione`; `creazione` e `pubblicazione`; niente per la bozza automatica e `creazione` al suo primo salvataggio |
| C-197 | Modifica del titolo di un pubblicato e di uno in verifica; salvataggio senza modifiche; modifica di testo e riassunto; un pubblicato reso privato cambiando nello stesso salvataggio indirizzo e data. Seconda parte, da in verifica: pubblicato scegliendo l'indirizzo; programmato indicando la data; pubblicato mentre un altro componente cambia indirizzo e date; senza titolo; un pubblicato di cui un altro componente svuota il nome, che WordPress rigenera uguale; con la sola data locale dalla funzione di base, con la data in UTC nulla, assente o vuota, e senza data in UTC con la data locale di prima; in verifica da giorni e pubblicato con la funzione di aggiornamento; un annuncio di modifica lanciato a mano. Terza parte, senza salvataggio: un utente eliminato affidando a un altro un pubblicato, uno in verifica e una bozza; un utente eliminato senza affidare niente; un contenuto gerarchico eliminato, con un figlio pubblicato e uno in bozza. Quarta parte: cambio di tipo fra due tipi gestiti, verso un tipo non gestito cambiando anche il titolo, verso un tipo non gestito passando in bozza, da un tipo non gestito, con la funzione di WordPress che cambia solo il tipo, in uscita e in ingresso; un salvataggio senza cambio di tipo. Quinta parte: un contenuto pubblicato che diventa un allegato, con lo stesso padre e con un padre diverso; un allegato che diventa un contenuto pubblicato; le stesse due conversioni con la funzione che cambia solo il tipo, con la copia in memoria. Sesta parte: un contenuto pubblicato mentre un altro componente, dentro la pubblicazione, lo salva di nuovo cambiandone il riassunto; un titolo cambiato mentre un altro componente, dentro il salvataggio, lo rimette com'era | una voce `modifica` con i nomi dei campi; nessuna voce; una voce con i due nomi e nessun valore; `rimozione` e `modifica` con indirizzo e date. Seconda parte: `modifica` con il solo indirizzo; `cambio_stato` e `modifica` con le due date; `modifica` con indirizzo e date; nessuna `modifica` per l'indirizzo generato dopo il salvataggio; `modifica` con il solo riassunto; `modifica` con le due date in tutti e tre i casi, nessuna senza la data in UTC e con la data di prima; nessuna `modifica` per la data rimessa a oggi; `modifica` con l'indirizzo. Terza parte: `modifica` con l'autore sul pubblicato e su quello in verifica, niente sulla bozza; nessuna voce; `modifica` con il padre sul figlio pubblicato, poi `eliminazione`, niente sulla bozza. Quarta parte: `cambio_tipo` uscita nella sezione di prima e ingresso in quella nuova; l'uscita con il titolo fra i campi; l'uscita con gli stati; l'ingresso; l'uscita e l'ingresso; solo `modifica`. Quinta parte: `cambio_tipo` in uscita e `allegato_aggiunto` sul padre, in tutti e due i casi; `pubblicazione`, `cambio_tipo` in ingresso e `allegato_eliminato` sul padre; con la funzione diretta, `cambio_tipo` e la voce sul padre allo stesso modo. Sesta parte: `pubblicazione` e una sola `modifica`, con il riassunto, quella del salvataggio interno; niente indirizzo e date di WordPress; due `modifica` con il titolo, una per salvataggio |
| C-198 | Un pubblicato va in bozza, in verifica, privato, nel cestino; un pubblicato e uno in verifica rimessi in bozza automatica da un componente, accanto alla nascita di una bozza automatica; un pubblicato rimesso in bozza automatica e poi ripubblicato, un altro poi eliminato, nella stessa richiesta e in un'altra; una bozza automatica mai nata salvata e una eliminata | una voce `rimozione` ciascuno, e nessuna `modifica` accanto; `rimozione` e `cambio_stato`, e niente per la nascita; `pubblicazione` senza una seconda `creazione`, e `eliminazione`, in tutti e due i casi; `creazione` per la prima e niente per la seconda |
| C-199 | Bozza, verifica, bozza, cestino, ripristino; un pubblicato messo nel cestino e ripreso | quattro `cambio_stato` con gli stati giusti; `rimozione` e `cambio_stato`, nessuna `modifica` per il suffisso del cestino |
| C-200 | Eliminazione definitiva di un pubblicato con data di fine; eliminazione che la banca dati rifiuta, seguita da una data di fine; eliminazione con la copia in memoria buttata appena prima della cancellazione; eliminazione di una bozza automatica; un utente eliminato senza affidare i contenuti, con un contenuto di un tipo con autore e uno di un tipo senza | una sola voce `eliminazione` con lo stato; le voci di prima restano tutte; nessuna voce `eliminazione` per il contenuto che c'è ancora, e la data di fine successiva si registra; una sola `eliminazione` e nessuna voce mancata; nessuna voce; `eliminazione` del primo, niente per il secondo, che resta |
| C-201 | Le stesse tre operazioni (titolo, data di fine, allegato) su una bozza e su un contenuto in verifica; eliminazione di una bozza che era stata pubblicata | nella bozza nessuna voce, in verifica tre; l'eliminazione della bozza si registra |
| C-202 | Data di fine scritta, riscritta uguale, cambiata, tolta; poi due righe duplicate aggiornate con una scrittura. Seconda parte: cancellazione per chiave su tutti i contenuti, la stessa nominando un contenuto senza data di fine, una riga della data che cambia chiave e che la riprende. Terza parte: due elenchi diversi e un oggetto scritti uno dopo l'altro, poi tolti. Quarta parte: la chiave in maiuscolo in aggiornamento, cancellazione, aggiunta, cambio per numero di riga e uscita dalla chiave, cancellazione per chiave su tutti i contenuti con la chiave in maiuscolo e, al contrario, di una riga conservata in maiuscolo; un altro metadato. Quinta parte: un altro metadato aggiornato dentro la scrittura della fine; la fine cambiata dentro la scrittura di un altro metadato; la fine riscritta dentro la sua stessa scrittura; una scrittura annidata che WordPress annuncia e non compie, su un altro contenuto | tre voci con prima e dopo, nessuna per la riscrittura uguale; una sola voce per la scrittura su due righe. Una voce per ogni contenuto pubblicato toccato, contate prima di leggerne i valori, e nessuna per la bozza; due righe dello stesso contenuto tolte insieme fanno una voce con tutti e due i valori; la voce va al contenuto toccato, non a quello nominato; una voce per ciascun cambio di chiave. Terza parte: quattro voci, con i valori nella forma in cui sono conservati, e nessun errore. Quarta parte: una voce per ogni cambiamento, con prima e dopo letti come li legge la banca dati; nessuna voce per l'altro metadato. Quinta parte: una voce con prima e dopo esatti nei primi due casi; due voci in ordine nel terzo; una voce nel quarto |
| C-203 | Allegato aggiunto ed eliminato su un pubblicato; un allegato libero assegnato a un contenuto e poi spostato su un altro; scollegato e ricollegato dalla libreria dei media; eliminazione dell'allegato che la banca dati rifiuta, poi riuscita; eliminazione rifiutata, scollegamento, eliminazione riuscita; un contenuto con padre eliminato con un allegato, con la cancellazione riuscita, rifiutata, e con lo spostamento dell'allegato rifiutato | `allegato_aggiunto` e `allegato_eliminato` sul contenuto padre, con il numero dell'allegato; allo spostamento, tolto dal primo e aggiunto al secondo; lo stesso dalla libreria; nessuna voce finché l'allegato c'è ancora, la voce alla riuscita; la voce dello scollegamento e nessuna all'eliminazione riuscita; `allegato_aggiunto` sul padre che riceve l'allegato anche se la cancellazione fallisce, e nessuna se lo spostamento non avviene |
| C-204 | Le stesse operazioni su un articolo di WordPress | nessuna voce e nessuna voce mancata annotata; controllo positivo sul tipo gestito |
| C-205 | Operazione senza utente, poi con un utente con nome ed email | utente zero, poi il numero; le colonne sono quelle del punto 4 e nella riga non c'è nessun dato della persona |

### Voci dei componenti (C-50)

| # | Caso | Atteso |
|---|---|---|
| C-206 | Voce completa con contenuto, motivazione su due righe, dettagli di ogni tipo ammesso; voce della sola sezione; decimali con molte cifre, un decimale tondo e testi fatti di cifre, scritti con la precisione di PHP ridotta a tre cifre | si rilegge uguale, motivazione ripulita degli spazi ai lati, origine componente, utente e istante di core; nessuna voce automatica accanto; i numeri si rileggono identici, e i testi restano testi |
| C-207 | Quarantotto descrizioni sbagliate, una condizione per volta, compresi i dieci nomi riservati, un nome con a capo finale, `utente`, `istante` e `origine` dichiarati | ciascuna rifiutata con il suo codice, e la tabella identica; controllo positivo sulla forma corretta |
| C-208 | Una decisione presa giorni dopo da un'altra persona, che rimanda alla rimozione | la rimozione resta identica; la decisione porta il rimando, la nuova persona e il nuovo istante; si ritrovano in ordine leggendo il contenuto e cercando per rimando |
| C-209 | Seconda voce con la stessa chiave; chiave che differisce solo per maiuscole | rifiutata con il numero della prima, tabella identica; la chiave in maiuscolo è un'altra chiave; l'indice della chiave è unico, su quella colonna sola e per intero, e una riga ripetuta scritta saltando il codice la rifiuta la banca dati |
| C-210 | Scrittura che fallisce, per un componente e per una pubblicazione | errore al componente; la pubblicazione avviene lo stesso; nessuna riga; una voce mancata annotata con l'istante. Un'altra richiesta che ha già portato il conteggio a 3 nella banca dati: l'annotazione successiva dà 4, la prima resta la prima; un'annotazione con un istante precedente non sposta indietro l'ultima |
| C-211 | Scrittura che riesce ma scrive un testo diverso | errore, non il numero; controllo positivo senza alterazione |

### Solo in aggiunta (C-51)

| # | Caso | Atteso |
|---|---|---|
| C-212 | Lettura del codice | nessuna istruzione di modifica, cancellazione o cambio di struttura nelle classi del registro; nessun altro file nomina la tabella; le funzioni pubbliche del registro sono quattro, e nessun metodo ha un nome da modifica. I metodi pubblici delle due classi sono quelli dell'elenco scritto per esteso nella prova; la scrittura automatica, già consegnata all'avvio, non si consegna più |
| C-213 | Superfici esterne | nessuna azione di amministrazione né chiamata asincrona sul registro, nessuna rotta dell'interfaccia per programmi |
| C-221 | Richiesta di cancellazione di gruppo inviata alla schermata, con le voci che una tabella di WordPress userebbe | tabella identica; nella pagina nessun modulo che scrive, nessuna casella di selezione; il modulo dei filtri è una lettura. Seconda parte: le voci mancate compaiono in testa con conteggio e date nel fuso del sito |

### Consultazione (C-52)

| # | Caso | Atteso |
|---|---|---|
| C-214 | Amministratore, redattore e abbonato senza il permesso; poi un utente con il permesso | la voce di menu chiede il permesso dedicato; i tre sono rifiutati; il quarto legge |
| C-215 | Ruoli dopo l'installazione | nessun ruolo ha il permesso di lettura |
| C-216 | Ogni filtro da solo, due giorni di confine attorno alla mezzanotte del sito, due filtri insieme; filtri sconosciuti o malformati, compresi quelli con valore nullo e la pagina senza misura; nella schermata, con il gettone valido, un filtro elenco, uno con marcatori, uno con spazi ai lati, un nome sconosciuto da solo e accanto a un filtro giusto, poi lo stesso senza gettone e il numero di pagina con il gettone. Seconda parte: il primo e l'ultimo istante di un giorno, e quelli accanto, dove l'ora legale comincia a mezzanotte e nei giorni di 23 e 25 ore | ogni filtro mostra le voci giuste e nasconde le altre; una voce delle 22:30 UTC è del giorno dopo a Roma; un filtro sbagliato è un errore e la pagina non mostra tutto; nella schermata l'errore e nessuna voce, e un filtro vuoto vale come assente; senza gettone la pagina dice che i filtri non sono applicati, e il numero di pagina non è un errore. Seconda parte: il primo e l'ultimo istante sono del giorno, quelli accanto no, e il primo istante del giorno dopo si trova filtrando dal giorno dopo |
| C-217 | Filtri senza gettone o con gettone sbagliato | non applicati, e la pagina lo dice; controllo positivo con il gettone valido |
| C-218 | Due voci a cavallo del cambio d'ora, due fusi del sito | orari giusti in entrambi i casi |
| C-219 | Motivazione, dettagli e titolo con codice | stampati come testo, e presenti nella pagina nella forma neutralizzata; l'a capo della motivazione diventa un a capo della pagina |
| C-220 | Voce del sistema, di una persona che poi cambia nome, di una persona poi eliminata | "sistema"; il nome di adesso; "non più presente" |

### Installazione, contratto, non vacuità

| # | Caso | Atteso |
|---|---|---|
| C-222 | Avvio con la versione giusta; installazione con la tabella che non si trova; installazione su tabella allineata; tabella con l'indice della chiave mancante, su un inizio della chiave, su due colonne, non unico; scrittura fallita con la tabella presente e con la tabella sparita; dopo l'installazione fallita per il vincolo, una voce di un componente e una modifica automatica | nessuna istruzione alla banca dati; nessuna versione scritta; nessun cambio di struttura; nessuna versione scritta in tutti e quattro i casi; la versione resta, poi si toglie e l'avvio reinstalla; errore `conformita_core_registro_non_installato`, nessuna riga, una mancata annotata; dopo l'installazione riuscita la voce si scrive |
| C-223 | Versione di interfaccia; nome del permesso e nomi riservati | 1.4.0, compatibile con chi chiedeva 1.3.0; nome e nomi uguali a quelli scritti per esteso nella prova, non letti dal codice |
| C-224 | Voci automatiche spente, con lo spegnimento privato chiamato dalla prova | nessun ascoltatore del registro rimasto, nessuna voce dalle operazioni di C-195..C-203; riaccese, tornano |

## 11. La tabella dei guasti: quale riga misura che cosa

Ogni guasto è stato introdotto da solo nel codice, con le prove fatte girare e il codice
rimesso a posto subito dopo.

| # | Guasto | Prove rosse |
|---|---|---|
| G01 | nessuna voce di pubblicazione | C-195, C-196, C-204, C-205, C-210 |
| G02 | nessuna voce di creazione | C-196, C-204, C-205 |
| G03 | le modifiche delle bozze si registrano | C-201 |
| G04 | niente di ciò che riscrive WordPress al cambio di stato è suo | C-195, C-197, C-198, C-199, C-204, C-205, C-210 |
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
| G36 | filtro con valore nullo ignorato | C-216 |
| G37 | pagina senza misura accettata | C-216 |
| G38 | indirizzo scelto da chi pubblica preso per quello di WordPress | C-197 |
| G39 | indirizzo cambiato da un altro componente preso per quello di WordPress | C-197 |
| G40 | indirizzo generato dopo il salvataggio non riconosciuto | C-197 |
| G41 | date mai di WordPress | C-195, C-197, C-210 |
| G42 | data chiesta esplicitamente presa per quella di WordPress | C-197 |
| G43 | cambio di chiave di una riga della data di fine non visto | C-202 |
| G44 | righe non lette nella cancellazione per chiave | C-202 |
| G45 | alla cancellazione per chiave si guarda anche il contenuto nominato | **nessuna, voluto**: è un guasto equivalente. Il contenuto nominato, se ha la data di fine, è già fra quelli toccati; se non la ha, prima e dopo sono uguali e la voce non si scrive |
| G46 | date cambiate da un altro componente prese per quelle di WordPress | C-197 |
| G47 | data locale indicata senza quella in UTC presa per quella di WordPress | C-197 |
| G48 | senza sapere cosa è stato chiesto, indirizzo e date di WordPress | C-197 |
| G49 | scrittura automatica consegnata a ogni richiesta | C-212 |
| G50 | un ascoltatore pubblico | C-212 |
| G51 | spegnimento che non stacca gli ascoltatori | C-224 |
| G52 | voce dell'allegato eliminato scritta prima della cancellazione | C-203 |
| G53 | filtro della schermata che non è un testo ignorato | C-216 |
| G54 | filtro della schermata ripulito in silenzio | C-216 |
| G55 | richiesta malformata della schermata non detta | C-216 |
| G56 | installazione senza controllo del vincolo sulla chiave | C-222 |
| G57 | vincolo accettato su un inizio della chiave | C-222 |
| G58 | vincolo accettato su più colonne | C-222 |
| G59 | vincolo accettato non unico | C-222 |
| G60 | tabella sparita non vista alla scrittura fallita | C-222 |
| G61 | versione tolta a ogni scrittura fallita | C-222 |
| G62 | conteggio delle mancate letto e riscritto | C-210 |
| G63 | data della prima mancata riscritta | C-210, C-221 |
| G64 | data dell'ultima mancata riscritta | C-210 |
| G65 | copia in memoria delle opzioni non buttata | C-210, C-221 |
| G66 | indice della chiave su due colonne (banca dati ricreata) | C-209, C-222 |
| G67 | indice della chiave su un inizio (banca dati ricreata) | C-209, C-222 |
| G68 | titolo non stampato | C-219 |
| G69 | dettagli non stampati | C-219 |
| G70 | dettagli senza neutralizzarli | C-219 |
| G71 | valore di prima della data di fine non aggiornato dopo la voce | C-202 |
| G72 | una voce della data di fine per ogni riga toccata | C-202 |
| G73 | voce di eliminazione senza la copia del contenuto | C-197, C-200 |
| G74 | copia del contenuto eliminato ignorata dal registro | C-197, C-200 |
| G75 | data locale senza data in UTC vera presa per quella di WordPress | C-197 |
| G76 | data rimessa a oggi da WordPress contata come modifica | C-197 |
| G77 | ogni data con la data in UTC vuota presa per rimessa a oggi | C-197 |
| G78 | data in UTC assente presa per una richiesta esplicita | C-197 |
| G79 | allegati spostati dall'eliminazione non letti | C-203 |
| G80 | spostamento registrato senza controllare che sia avvenuto | C-203 |
| G81 | figli spostati dall'eliminazione non letti | C-197 |
| G82 | figlio in bozza spostato registrato | C-197 |
| G83 | segno dell'eliminazione tolto solo a eliminazione riuscita | C-200 |
| G84 | padre di un tentativo di eliminazione passato usato di nuovo | C-203 |
| G85 | contenuti affidati dall'utente eliminato non letti | C-197 |
| G86 | cambio di autore di una bozza registrato | C-197 |
| G87 | voce di eliminazione senza l'attesa della riga | C-200 |
| G88 | bozza automatica esclusa anche come destinazione | C-198 |
| G89 | nascita della bozza automatica registrata | C-196, C-198 |
| G90 | valori della data di fine convertiti in testo | C-202 |
| G91 | numeri dei dettagli codificati con la precisione di PHP | C-206 |
| G92 | parte decimale zero persa nella codifica | C-206 |
| G93 | nessuna rilettura dei dettagli codificati | **nessuna, voluto**: con le due correzioni sopra nessun dettaglio ammesso cambia nella codifica, su un sito in UTF-8. La rilettura resta per i casi che la batteria non può creare: un sito con un'altra codifica dei caratteri, dove WordPress converte i testi prima di codificarli, e un PHP dove la precisione non si può cambiare. Tolte insieme la precisione e la rilettura, C-206 diventa rossa |
| G94 | testi fatti di cifre codificati come numeri | C-206 |
| G95 | nomi sconosciuti della schermata ignorati | C-216 |
| G96 | numero di pagina non ammesso fra i nomi della schermata | C-216 |
| G97 | nome sconosciuto ignorato quando è l'unico | C-216 |
| G98 | giorno dopo calcolato dall'orario spostato dal cambio d'ora | C-216 |
| G99 | giorno dopo calcolato aggiungendo 86400 secondi | C-216 |
| G100 | scrittura senza installazione riuscita | C-222 |
| G101 | bozza automatica sempre mai nata | C-198 |
| G102 | eliminazione con il criterio di prima sulla bozza automatica | C-198 |
| G103 | nascita con il criterio di prima sulla bozza automatica | C-198 |
| G104 | bozza automatica sempre nata | C-196, C-198, C-200 |
| G105 | cambio di tipo ignorato | C-197 |
| G106 | uscita scritta senza la copia di prima | C-197 |
| G107 | nessuna voce di ingresso | C-197 |
| G108 | campi del salvataggio non portati nel cambio di tipo | C-197 |
| G109 | tipo cambiato con l'istruzione diretta non visto | C-197 |
| G110 | tipo confrontato anche durante il salvataggio | C-197 |
| G111 | fine non letta prima di un aggiornamento di metadati | C-202 |
| G112 | righe della fine non cercate nella cancellazione su tutti i contenuti | C-202 |
| G113 | lettura di prima non consumata dal confronto | C-202 |
| G114 | righe della fine cercate con il confronto esatto | C-202 |
| G115 | tipo cambiato con l'istruzione diretta visto solo in uscita | C-197 |
| G116 | filtro dei dati degli allegati non ascoltato | C-197 |
| G117 | contenuto diventato allegato senza la voce sul padre | C-197 |
| G118 | allegato diventato contenuto senza la voce sul padre | C-197 |
| G119 | una lettura sola per scrittura, senza pila | C-202 |
| G120 | letture della fine per contenuto e non per scrittura | C-202 |
| G121 | letture aperte non aggiornate dopo una voce della fine | C-202 |
| G122 | aggiunta di metadati non riconosciuta fra i due annunci | C-200, C-201, C-202 |
| G123 | salvataggio interno con la richiesta di quello esterno | C-197 |
| G124 | campo scritto uguale a prima contato come modifica | C-197 |
| G127 | conseguenze sul padre solo dal salvataggio, non dal cambio di tipo diretto | C-197 |
| G128 | contenuto riletto al posto di quello scritto dal salvataggio | C-197 |
| G129 | nome rimasto vuoto dopo i filtri preso dai dati scritti | C-197 |
| G125 | dati finali sulla richiesta sbagliata della pila | C-197 |
| G126 | cambio di tipo guardato dopo il ramo degli allegati | C-197 |

**Guasti che il codice di oggi non ha più.** Dopo il quinto giro la fine della pubblicazione
si legge per scrittura e non per contenuto. G05, G72 e G113 agivano sulla lettura unica per
contenuto, che non c'è più: la stessa difesa ora la misurano G119..G121, e G71 è diventato
G121. G43 e G44 agivano sul confronto del nome della chiave, tolto nel quarto giro: li
misurano G111 e G112. Dopo il sesto giro G124 agiva su una regola compresa nel confronto con
i dati scritti: la misura G128. G03, G22, G33, G73, G74, G83, G110..G112, G117 e G118 sono
stati riapplicati alla forma nuova del codice, con le stesse righe rosse o con qualcuna in
più. Tutti gli altri guasti, da G01, sono stati rifatti sul codice del sesto giro, su una banca
dati pulita e con G27 per ultimo: ciascuno fa diventare rossa almeno una prova, tranne G12,
G45 e G93, equivalenti per le ragioni scritte nelle loro righe.

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

**La rilettura prima del primo giro di revisione.** Prima di mandare l'unità al revisore
esterno il codice è stato riletto cercando tre forme di difetto già trovate su S5: un
controllo fatto su una proprietà vicina a quella che conta, un valore atteso dalle prove
calcolato dal codice che si sta provando, una guardia che tace perché crede che qualcun altro
abbia già risposto. Ne sono usciti cinque difetti, ciascuno con la prova scritta prima della
correzione e vista fallire sul codice vecchio.

1. *Proprietà vicina.* I filtri di lettura guardavano se il valore c'era, non se il filtro era
   stato chiesto: un filtro sul contenuto con valore nullo restituiva le voci di tutti i
   contenuti (G36). Lo stesso per la pagina chiesta senza la misura della pagina (G37).
2. *Proprietà vicina.* Al cambio di stato si escludevano indirizzo e date perché lo stato era
   cambiato, non perché li aveva cambiati WordPress: chi rendeva privato un atto cambiandone
   anche la data non lasciava traccia della data (G38..G42).
3. *Proprietà vicina.* La data di fine si seguiva con il contenuto e la chiave che WordPress
   annuncia, che non sono quelli delle righe toccate in due casi: la cancellazione per chiave
   su tutti i contenuti e il cambio di chiave di una riga (G43, G44).
4. *Guardia che crede a un'altra risposta.* La voce `eliminazione` si scriveva prima della
   cancellazione, e zittiva la cancellazione della data di fine anche quando l'eliminazione
   poi falliva. Ora si scrive dopo che la riga non c'è più (C-200).
5. *Valore atteso dal codice in prova.* C-207 provava i nomi riservati leggendoli
   dall'elenco del codice, e C-223 il nome del permesso dalla costante: un nome sparito
   dall'elenco sarebbe sparito anche dalla prova. Ora sono scritti per esteso nelle prove.

**Il primo giro di revisione.** Il revisore esterno ha trovato nove difetti. Per ciascuno la
prova è stata scritta prima della correzione e vista fallire sul codice vecchio, o, per i tre
difetti delle prove, rinforzata e misurata con un guasto nuovo.

1. *Voci automatiche scrivibili da fuori.* Gli ascoltatori erano metodi pubblici e la
   scrittura con origine automatica pure: un componente poteva attestare un fatto mai avvenuto
   o spegnere le voci. Ora sono privati, la scrittura si consegna una volta sola all'avvio
   (C-212, C-224; G49..G51). Il limite che resta è al punto 8.
2. *Proprietà vicina, di nuovo.* L'indirizzo e le date di un contenuto che non li aveva si
   escludevano al cambio di stato anche quando li sceglieva chi pubblicava o programmava. Ora
   il criterio è chi li ha chiesti (C-197; G38..G42, G46..G48).
3. *Fatto non avvenuto.* La voce `allegato_eliminato` si scriveva all'annuncio, prima della
   cancellazione: ora dopo, come per l'eliminazione (C-203; G52).
4. *Filtri della schermata ripuliti in silenzio.* Un filtro elenco si ignorava, uno con
   marcatori o spazi si ripuliva in un valore diverso: la pagina mostrava una selezione diversa
   da quella chiesta (C-216; G53..G55).
5. *Versione scritta sulla fiducia.* Una tabella senza il vincolo sulla chiave passava per
   installata, e una tabella sparita dopo l'installazione non si accorgeva più (C-222;
   G56..G61).
6. *Conteggio perso.* Le voci mancate si contavano leggendo e riscrivendo l'opzione: due
   richieste insieme ne perdevano una (C-210; G62..G65).
7. *Prova debole.* C-209 cercava l'indice per colonna e non per nome: un indice su due colonne
   passava (G66, G67).
8. *Prova debole.* C-202 leggeva le voci in una mappa per contenuto: una voce doppia spariva
   nella mappa (G72).
9. *Prova debole.* C-219 non verificava che titolo e dettagli fossero stampati: una schermata
   che non li stampa passava (G68..G70).

I guasti G04, G14, G15 e G25 sono stati riscritti sul codice nuovo e rifatti; G38..G42 misurano
ora la regola nuova. G27 ha di nuovo contaminato i guasti successivi, che sono stati rifatti su
una banca dati pulita.

**Il secondo giro di revisione.** Cinque difetti, tutti nelle voci automatiche. Stesso metodo:
prova scritta e vista fallire sul codice vecchio, poi guasti.

1. *La voce di eliminazione dipendeva dalla memoria.* Il registro controllava tipo e sezione
   rileggendo il contenuto, che a riga cancellata esiste solo se ne resta una copia in
   memoria. Ora la voce automatica di eliminazione porta la copia che WordPress passa, letta
   prima della cancellazione; per i componenti il contenuto deve esistere come prima (C-200;
   G73, G74, G87).
2. *La data locale senza data in UTC.* Una data indicata senza la data in UTC, o con la data
   in UTC vuota, si prendeva per quella di WordPress. Ora è chiesta, salvo la forma precisa con
   cui la funzione di aggiornamento rimette la data a oggi (C-197; G75..G78).
3. *Gli allegati spostati dall'eliminazione.* WordPress li sposta sul padre del contenuto
   eliminato con un'istruzione diretta: il padre riceveva allegati senza voce. Lo stesso vale
   per i figli di un tipo gerarchico, che cambiano padre. Ora si leggono prima e si confrontano
   dopo lo spostamento, prima della cancellazione della riga (C-197, C-203; G79..G82).
4. *L'autore affidato a un altro.* Eliminando un utente e affidandone i contenuti a un altro,
   WordPress cambia l'autore con un'istruzione diretta. Ora il cambio si registra, fuori dalla
   bozza (C-197; G85, G86).
5. *Segni rimasti dopo un tentativo fallito.* Il segno che tiene fuori la cancellazione dei
   metadati restava dopo un'eliminazione fallita e zittiva ciò che seguiva; il padre annotato
   per un allegato restava per il tentativo successivo. Ora il primo finisce appena i metadati
   sono cancellati, il secondo si rifà a ogni tentativo (C-200, C-203; G83, G84).

La causa comune dei punti 3 e 4 è la stessa del primo giro, vista da un altro lato: WordPress
cambia i campi di un contenuto anche senza salvarlo. Oltre a queste due, le strade dirette di
WordPress lette in questo giro sono il collegamento dalla libreria dei media, già coperto, e il
suffisso del cestino aggiunto al nome di un contenuto già nel cestino, che resta fuori per
scelta (punto 8).

**Il terzo giro di revisione.** Cinque difetti. Stesso metodo: prova scritta e vista fallire
sul codice vecchio, poi guasti.

1. *La bozza automatica esclusa come destinazione.* Un contenuto pubblicato che un componente
   rimetteva in bozza automatica usciva dalla pubblicazione senza voce. Ora l'esclusione vale
   solo per la bozza automatica appena nata (C-198; G88, G89).
2. *La data di fine convertita in testo prima del confronto.* WordPress accetta elenchi e
   oggetti come valori: due elenchi diversi diventavano la stessa parola, e un oggetto
   interrompeva la richiesta. Ora si confrontano le righe conservate, nella loro forma
   serializzata (C-202; G90).
3. *I numeri dei dettagli arrotondati.* La codifica usava la precisione scelta per PHP: con
   una precisione ridotta un decimale perdeva cifre prima di arrivare alla tabella. Ora si
   codifica con tutte le cifre, si tiene la parte decimale zero, e il testo codificato si
   rilegge prima di scrivere (C-206; G91..G94).
4. *Nomi sconosciuti ignorati dalla schermata.* La schermata controllava solo i filtri che
   conosce: `contenuti` al posto di `contenuto` mostrava tutto il registro. Ora ogni nome
   estraneo rende malformata la richiesta (C-216; G95..G97).
5. *Il limite superiore nei cambi d'ora.* Dove l'ora legale comincia a mezzanotte, l'inizio
   del giorno è l'una, e aggiungere un giorno a quell'orario portava l'una anche nel giorno
   dopo, che entrava per un'ora nel filtro. Ora si passa prima alla data dopo e poi si cerca il
   suo inizio (C-216; G98, G99).

La causa comune dei punti 2 e 3 è una conversione che perde informazione fatta prima del
controllo: il controllo confrontava valori già cambiati. Le altre conversioni del registro
sono state rilette con la stessa domanda: i numeri di contenuto, utente e voce passano da un
controllo che rifiuta ciò che non è un intero, prima di essere convertiti; la motivazione si
ripulisce degli spazi ai lati per regola dichiarata, e la rilettura dopo la scrittura confronta
il testo ripulito con la riga (C-211).

**Il quarto giro di revisione.** Quattro difetti. Stesso metodo: prova scritta e vista fallire
sul codice vecchio, poi guasti.

1. *Scritture dopo un'installazione fallita.* L'installazione senza il vincolo sulla chiave
   non scriveva la versione, ma la scrittura non la guardava: due voci con la stessa chiave
   potevano passare. Ora senza versione non si scrive (C-222; G100). Il guasto ha messo in luce
   anche un difetto delle prove: C-222 cambia la struttura della banca dati, il cambio chiude
   la transazione che la prova annulla, e la versione tolta restava tolta per le prove
   successive. Ora ogni prova finisce rimettendo il registro installato, come fa l'avvio.
2. *La bozza automatica con una storia.* Dopo il terzo giro un contenuto rimesso in bozza
   automatica aveva la sua voce, ma quello che seguiva no: ripubblicato rinasceva, eliminato
   spariva. Ora il criterio è il registro: una bozza automatica senza voci non è mai nata
   (C-198, C-200; G101..G104).
3. *Il cambio di tipo.* Il tipo non era fra i campi confrontati, e il registro guardava solo il
   tipo di dopo: un contenuto che usciva verso un tipo non gestito spariva senza voce, con
   tutto il resto del salvataggio. Ora ci sono le voci `cambio_tipo` (C-197; G105..G110).
4. *La chiave della fine scritta diversa.* La banca dati confronta i nomi dei metadati senza
   badare alle maiuscole, il registro li confrontava in PHP. Ora non guarda il nome annunciato:
   su un contenuto gestito legge la fine prima e dopo ogni scrittura di metadati (C-202;
   G111..G114).

Due strade in più sono state provate per completare la tabella del punto 13, senza difetti: la
pubblicazione di un contenuto programmato fatta da WordPress senza salvataggio (C-195) e
l'utente eliminato senza affidare i contenuti (C-200).

**Il quinto giro di revisione.** Tre difetti. Stesso metodo: prova scritta e vista fallire sul
codice vecchio, poi guasti.

1. *Il tipo cambiato direttamente, in ingresso.* La funzione che cambia solo il tipo si vedeva
   quando un contenuto usciva da un tipo gestito, non quando ci entrava: il controllo partiva
   solo se la copia di prima era di un tipo gestito. Ora il tipo si rilegge in tutti e due i
   versi (C-197; G115).
2. *Il contenuto che diventa un allegato.* Il ramo degli allegati veniva prima del cambio di
   tipo, e il filtro dei dati degli allegati non si ascoltava: il contenuto diventato allegato
   aveva l'uscita, per un'altra strada, ma il padre non aveva la voce dell'allegato aggiunto.
   Ora il cambio di tipo si guarda per primo, e dopo si scrive la voce sul padre, nei due
   versi (C-197; G116..G118, G126).
3. *Scritture annidate sulla fine.* La lettura di prima era una per contenuto: un componente
   che aggiornava un suo metadato dentro la scrittura della fine la consumava, e la fine
   cambiava senza voce. Ora ogni scrittura ha la sua lettura (C-202; G119..G122).

**La rilettura dopo il quinto giro.** Prima di chiudere, il ramo è stato riletto da capo
cercando le tre forme dei difetti trovati nei cinque giri: una guardia messa presto che salta
un controllo; uno stato tenuto in memoria che un'operazione condivide con un'altra; un
confronto fatto in PHP che la banca dati fa in un altro modo. Ne sono usciti quattro casi.

- *Il salvataggio annidato* (seconda forma). Un componente che salva di nuovo lo stesso
  contenuto mentre lo si pubblica consumava la richiesta del salvataggio esterno: quello
  esterno si prendeva l'indirizzo e le date che WordPress fissa alla pubblicazione, e il
  campo cambiato da quello interno. Ora le richieste sono una pila per contenuto, e un campo
  che il salvataggio esterno scrive com'era non è suo (C-197; G123..G125).
- *La stessa riga riscritta dentro la sua scrittura, e la scrittura annidata che non avviene*
  (seconda forma). Coperte dalle letture per scrittura: la prima con una pila per scrittura,
  la seconda con il nome della scrittura, che non si confonde con quella esterna (C-202;
  G119, G120).
- *La richiesta lasciata da un salvataggio rifiutato* (prima e seconda forma). Non si può
  ripulire, perché WordPress non annuncia il fallimento: dichiarata al punto 8.
- *Il tipo scritto con altre maiuscole* (terza forma). Il tipo si confrontava in PHP,
  lettera per lettera, e la banca dati senza badare alle maiuscole: un contenuto con il tipo
  in maiuscolo nella riga non aveva voci, ma le ricerche per tipo lo trovavano. Riguardava
  tutti i meccanismi di core che riconoscono un tipo gestito: corretto in un'unità a parte,
  vedi la nota in fondo al punto 13.

Il resto dello stato in memoria è stato riletto con la stessa domanda. I contenuti in
eliminazione, gli allegati in eliminazione, gli spostamenti e i contenuti affidati sono
chiavi per contenuto o per utente, azzerate all'inizio di ogni tentativo; la riga in
cancellazione resta dopo un fallimento, ma il tentativo successivo la riscrive e un contenuto
che ha voci non torna mai nato. Le letture della banca dati sulle righe spostate
dall'eliminazione usano lo stesso confronto delle istruzioni di WordPress che spostano.

**Il sesto giro di revisione.** Due difetti, dello stesso tipo: una correzione del quinto giro
arrivata a una sola delle strade. Stesso metodo: prova scritta e vista fallire sul codice
vecchio, poi guasti.

1. *Il campo rimesso com'era da un salvataggio annidato.* Il salvataggio esterno cambiava il
   titolo, quello interno lo rimetteva com'era, e il confronto dell'esterno fra la copia di
   prima e quella riletta alla fine non vedeva niente. Ora il salvataggio esterno si giudica
   su ciò che ha mandato alla banca dati, dopo tutti i filtri; fa eccezione il nome rimasto
   vuoto, che WordPress genera dopo aver scritto. La regola del quinto giro sui campi scritti
   uguali a prima è compresa in questa (C-197; G128, G129).
2. *La conversione in allegato con il cambio di tipo diretto.* Le voci sul padre esistevano
   solo sulla strada del salvataggio. Ora stanno nel cambio di tipo, che le due strade
   chiamano entrambe (C-197; G127).

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

## 13. Le strade di WordPress che cambiano un contenuto gestito

WordPress cambia un contenuto anche senza il salvataggio ordinario: con istruzioni dirette
sulla banca dati, o con funzioni che toccano una parte sola. Questa è la mappa delle strade
lette in sei giri di revisione e nella rilettura dopo il quinto, e di come ciascuna è coperta. "Fuori per scelta" vuol dire
dichiarato al punto 8.

| Strada | Che cosa cambia | Come è coperta | Righe |
|---|---|---|---|
| Salvataggio ordinario: editor, interfaccia per programmi, riga di comando, importazione | campi, stato, tipo | voci `modifica`, di stato, `cambio_tipo` | C-195..C-199, C-201 |
| Cambio di stato senza salvataggio: pubblicazione di un programmato | stato | WordPress annuncia il cambio di stato: voce di stato | C-195 |
| Bozza automatica: nascita, uscita, ritorno di un contenuto esistente, eliminazione | stato | nascita esclusa; il ritorno e il seguito hanno le loro voci, riconosciuti dal registro | C-196, C-198, C-200 |
| Cestino e ripristino | stato, nome | passano dal salvataggio; il suffisso del cestino è di WordPress | C-198, C-199 |
| Suffisso del cestino aggiunto a un contenuto già nel cestino | nome | fuori per scelta | punto 8 |
| Cambio di tipo con il salvataggio | tipo | `cambio_tipo` in uscita e in ingresso | C-197 |
| Cambio di tipo con l'istruzione diretta, in uscita e in ingresso | tipo | visto se il contenuto era in memoria, nei due versi; se no, non visto | C-197; punto 8 |
| Contenuto che diventa allegato, allegato che diventa contenuto, con il salvataggio o con l'istruzione diretta | tipo, padre | `cambio_tipo` e la voce dell'allegato aggiunto o tolto sul padre, uguali sulle due strade | C-197 |
| Salvataggio dello stesso contenuto dentro un altro salvataggio, anche che rimette com'era un campo | campi, stato | ciascun salvataggio con la sua richiesta e le sue voci; l'esterno giudicato su ciò che ha scritto | C-197 |
| Salvataggio rifiutato dalla banca dati | niente, ma la richiesta resta | fuori: dichiarato | punto 8 |
| Tipo scritto nella riga con altre maiuscole | tipo | riconosciuto con il criterio della banca dati; il passaggio da una grafia all'altra è un `cambio_tipo` | C-249 |
| Eliminazione definitiva | metadati, allegati e figli spostati sul padre, riga | `eliminazione` dopo la riga; spostamenti registrati; i metadati sono dentro l'eliminazione | C-197, C-200, C-203 |
| Eliminazione a metà, rifiutata dalla banca dati | metadati già cancellati | fuori: buco dichiarato | punto 8 |
| Utente eliminato affidando i contenuti | autore | letto prima e dopo, `modifica` | C-197 |
| Utente eliminato senza affidare | contenuti dei tipi con autore eliminati | come l'eliminazione | C-200 |
| Allegati: caricamento, eliminazione, cambio di padre con il salvataggio | allegati del contenuto | voci degli allegati sul padre | C-203 |
| Libreria dei media: collega e scollega | padre dell'allegato | voce sul padre nuovo; nel collegamento il padre di prima non ha la sua | C-203; punto 8 |
| Data di fine: aggiunta, modifica, cancellazione, per numero di riga, cancellazione per chiave su tutti i contenuti, cambio di chiave, chiave con altre maiuscole, valori non testo | fine della pubblicazione | lettura prima e dopo ogni scrittura di metadati sui contenuti gestiti | C-202 |
| Scritture di metadati annidate: un'altra dentro la fine, la fine dentro un'altra, la fine dentro sé stessa, una annunciata e non compiuta | fine della pubblicazione | una lettura per scrittura, riconosciuta dall'annuncio di dopo | C-202 |
| Metadati propri del componente, tassonomie | dati del componente | fuori per scelta: li registra il componente | punto 8 |
| Contatore dei commenti, data di ultima modifica, blocco di modifica | campi tecnici di WordPress | fuori: non sono operazioni sul contenuto | nessuna |
| Revisioni | copie del contenuto, di un tipo proprio | fuori: il salvataggio del contenuto ha già la sua voce | nessuna |
| Pulizie automatiche: bozze automatiche vecchie, cestino svuotato | contenuti eliminati | come l'eliminazione; la bozza automatica mai nata non ha voce | C-200 |
| Scrittura diretta nella banca dati da un altro plugin | qualunque | fuori: stessi poteri di core | punto 8 |

**Corretto in un'unità a parte: un solo criterio, quello della banca dati.** Leggendo la
chiave in maiuscolo era emerso che core leggeva la data di fine con due criteri, la memoria
di WordPress per la scadenza e la banca dati per il filtro e per questo registro, e che
riconosceva un tipo gestito lettera per lettera mentre le ricerche di WordPress lo trovano
senza badare alle maiuscole. Ora la data di fine si legge in un posto solo,
`Conformita_Core_Scadenza::righe()`, che questo registro usa, e il tipo si riconosce con il
criterio della banca dati in `Conformita_Core_Tipi::canonico()`. Una correzione a quanto
scritto qui prima: WordPress riduce il tipo con `sanitize_key` al salvataggio, quindi un tipo
in maiuscolo arriva solo da una scrittura diretta nella banca dati o da un componente che
toglie quella riduzione. Scheda del motore di scadenza, punto 16; righe C-248..C-255.
