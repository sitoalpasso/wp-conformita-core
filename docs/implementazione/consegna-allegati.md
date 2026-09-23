# Consegna degli allegati: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S5 del piano, righe di collaudo elencate al
punto 11.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo**, e in questa
unità non si tocca una riga di quel repository. L'albo, più avanti e in un'altra unità, si
limiterà a tre cose: chiamare la funzione di deposito quando il redattore carica il
documento principale o un allegato ulteriore, chiedere l'indirizzo di consegna quando deve
stamparlo in una pagina, e leggere l'impronta quando costruirà il referto. Nessuna logica di
protezione nell'albo, mai: se ci finisse, esisterebbero due verità su dove sta un file e su
chi può scaricarlo.

**Stato: costruita, con le righe di collaudo verdi.** Le sezioni sono al passato: dicono
quello che il codice fa, non quello che si pensava di fare. Il punto 12 racconta come è
andata e che cosa il lavoro ha trovato che la scheda non prevedeva; il punto 13 è la tabella
dei guasti.

*Approvata con due modifiche, entrambe recepite e segnate dove cadono. La prima: la
protezione della cartella non si presume, si **verifica** con una richiesta all'esca, e lo
stato `presunta` non esiste (punto 1.2). La seconda: i tipi che il browser non esegue, cioè i
PDF e le immagini raster di un elenco chiuso, escono dentro la pagina e non come scaricamento, con
una politica di sicurezza dei contenuti che li isola (punto 1.5). La prima stesura diceva che
questa unità non fa nessuna richiesta di rete: era una lettura troppo larga della regola, che
vieta i servizi esterni e non una richiesta verso il sito stesso.*

**Che cosa chiude.** La falla **F1**, dichiarata aperta al punto 8 della scheda di S4 con
queste parole: *"Finché S5 non esiste, un allegato di un atto scaduto resta scaricabile dal
suo indirizzo diretto"*. S4 ha tolto la **pagina** dell'allegato dai risultati (riga C-19);
il **file** è rimasto raggiungibile dal suo percorso dentro la cartella dei caricamenti.
Questa unità chiude l'altra metà.

---

## 1. Le sette domande, chiuse prima del codice

Ogni risposta dichiara la scelta, l'alternativa scartata e il motivo. Dove non c'è una
risposta sicura il limite è dichiarato invece che nascosto.

### 1.1 Gli allegati sono contenuti WordPress, sì o no

**Scelta: sì. Restano contenuti di tipo `attachment`, figli del contenuto gestito.** Quello
che cambia è dove stanno i byte e quale indirizzo WordPress pubblica per loro.

**L'alternativa scartata** era rinunciare del tutto alla libreria dei media: un registro
proprio, in metadati o in una tabella dedicata, che lega un file al contenuto padre senza
creare nessun contenuto WordPress. Ha un vantaggio vero e non piccolo: **non esisterebbe
nessun indirizzo pubblico dell'allegato da governare**, perché non esisterebbe l'allegato
come contenuto. Chiuderebbe per costruzione una classe intera di percorsi.

**Perché è stata scartata, in tre ragioni, la prima delle quali da sola basta.**

La prima è che **S4 ha già deciso**, e ha deciso nell'altro senso. La riga C-19 è verde oggi
perché il secondo strato del filtro riconosce `attachment`, guarda la scadenza del **padre**
e toglie l'allegato dai risultati: è scritto in `filtra_risultati()` e collaudato. Scegliere
il registro proprio significherebbe smontare quella riga e riscrivere un pezzo di un'unità
già unita in `main`, non per un difetto ma per un ripensamento. Un ripensamento si può fare,
ma va pagato, e qui non compra abbastanza.

La seconda è che si perderebbe tutto quello che WordPress dà sui media e che serve davvero a
un albo: la verifica del tipo di file in ingresso di `wp_check_filetype_and_ext()`, la
gestione dei nomi che collidono, il legame padre-figlio che la cancellazione del contenuto
rispetta, e la schermata dei media da cui l'amministrazione ritrova un documento. Riscrivere
quella roba è un lavoro vero e sarebbe un lavoro peggiore.

La terza è che il registro proprio sposta il problema invece di risolverlo: l'indirizzo
pubblico sparirebbe, ma resterebbe la cartella e resterebbe il percorso del file. **La
cartella va protetta comunque**, in tutti e due i progetti, e la protezione della cartella è
la parte difficile. Il registro proprio comprerebbe un percorso in meno al prezzo di tutto
il resto.

**Che cosa comporta la scelta, e va detto perché non è gratis.** Un allegato è un contenuto
con un indirizzo pubblico suo, e il filtro di scadenza governa i tipi registrati attraverso
il core, non `attachment`. Quindi questa unità deve governare esplicitamente tre indirizzi:

| Indirizzo | Chi lo governa | Stato |
|---|---|---|
| La **pagina** dell'allegato (`?attachment_id=N` e il suo permalink) | S4, secondo strato, sul padre | già coperto, riga C-19 |
| L'**indirizzo del file** che WordPress pubblica (`wp_get_attachment_url()`) | S5: si filtra, e restituisce l'indirizzo di consegna | da fare, riga C-127 |
| Il **percorso diretto** del file dentro i caricamenti | S5: la cartella protetta | da fare, righe C-115..C-120 |

**Il limite sulle dimensioni intermedie si è ristretto strada facendo.** La scheda lo
dichiarava largo: WordPress genera dimensioni intermedie e anteprime (per i PDF un'immagine
di copertina) e i loro indirizzi si costruiscono in `image_downsize()` e in
`wp_calculate_image_srcset()`, che questa unità non filtra. In implementazione si è visto che
quei file nascono solo se qualcuno chiama `wp_generate_attachment_metadata()`, e il deposito
**non la chiama**: per un documento le dimensioni intermedie non servono, e ogni file
derivato sarebbe un indirizzo in più da governare. Quindi oggi derivati non ne esistono.

**Quello che resta del limite**, e va detto: se un altro componente rigenera i metadati di un
allegato depositato, i derivati nascono dentro la cartella protetta, quindi coperti dalla
protezione, ma con indirizzi che questa unità non filtra. Non è una porta aperta finché la
cartella è protetta; è una porta in più che dipende solo da quella protezione invece che da
due cose.

### 1.2 Dove sta la cartella, come si impedisce l'accesso diretto, e come lo si verifica

**Scelta: una sola cartella, dentro i caricamenti, con nome fisso**, e sotto di essa la
solita struttura per anno e mese che WordPress usa già:

```
wp-content/uploads/conformita-core-protetto/2026/09/atto.pdf
```

Dentro i caricamenti e non fuori dalla radice del sito, perché la cartella dei caricamenti è
**l'unica cartella che WordPress garantisce scrivibile** e che sa dove sta: qualunque
percorso fuori da lì sarebbe un valore da configurare, e su molte installazioni in
condivisione non esiste nessun posto scrivibile fuori dalla radice del sito. Il nome è fisso
e non configurabile: è lo slug del componente, non un valore che dipende dall'amministrazione.

**L'alternativa scartata** era un nome di cartella non indovinabile, generato all'attivazione.
Scartata perché è sicurezza per oscurità: l'indirizzo finirebbe comunque in un registro del
server web, in un referrer o nella cronologia di un browser, e nel frattempo darebbe
l'impressione di una protezione che non c'è.

**Come si impedisce l'accesso.** Nella cartella si scrivono quattro file:

| File | A che serve | Server che lo leggono |
|---|---|---|
| `.htaccess` | nega ogni richiesta, nelle due forme di Apache 2.2 e 2.4 | Apache con `AllowOverride` sufficiente, LiteSpeed |
| `web.config` | nega ogni richiesta | IIS |
| `index.php` | un file muto, contro l'elenco della cartella | tutti |
| `prova-accesso-diretto.txt` | l'**esca generale**: contiene un gettone casuale, generato quando il file viene scritto e conservato accanto all'esito della verifica | è il bersaglio della verifica |
| `prova-accesso-diretto.<estensione>` | l'**esca di un ambito**, scritta nella sottocartella dove i file di quell'estensione finiscono davvero | è il bersaglio della verifica per quell'ambito |

**La copertura, per server, detta come sta.**

| Server | Coperto dai file di regole |
|---|---|
| Apache con `AllowOverride` che comprende `Limit`/`AuthConfig` | sì |
| Apache con `AllowOverride None` | **no**: il file c'è ed è inerte |
| LiteSpeed, OpenLiteSpeed | sì, legge `.htaccess` |
| IIS | sì, con `web.config` |
| nginx, Caddy, e ogni altro server che non legge quei file | **no** |

Dove non basta serve una regola nella configurazione del server. Per nginx è una `location`
che nega il percorso della cartella; il testo esatto va nella documentazione del componente e
nel collaudo di rilascio, non nel codice, perché dipende da come è scritto il blocco del sito.

**Come il componente si accorge di essere su un server dove la protezione non è attiva. Si
chiede all'esca, e si guarda che cosa risponde.** La tabella qui sopra dice che cosa *dovrebbe*
succedere; l'unica cosa che dice che cosa succede davvero è una richiesta HTTP all'indirizzo
dell'esca, perché nessuna delle condizioni che rompono la protezione (`AllowOverride None`,
un blocco `location` che precede, un proxy inverso che serve i file statici da sé) si vede da
dentro PHP.

È una richiesta **verso il sito stesso**, non verso un servizio esterno: la stessa cosa che
WordPress fa nel proprio controllo di integrità. `wp_remote_get()` sull'indirizzo dell'esca, e
poi la lettura della risposta:

L'ordine in cui si guarda conta, ed è questo:

| Ordine | Risposta | Esito | Perché |
|---|---|---|---|
| 1 | Nel corpo c'è il gettone dell'esca, **con qualunque stato** | **`non_coperta`** | i byte del nostro file sono usciti dalla cartella: con quale stato siano usciti non cambia niente |
| 2 | Lo stato è **403** o **404** | **`verificata`** | sono i due modi in cui un server nega un percorso, e sono un elenco chiuso |
| 3 | Lo stato è 2xx e il gettone non c'è | **`ignota`** | è tornato qualcosa che non è il nostro file: una pagina di accesso, un catch-all, un proxy che sostituisce. Non è una prova di rifiuto |
| 4 | Ogni altro stato: 3xx, 401, 429, 5xx | **`ignota`** | nessuno di questi parla delle regole della cartella. Vedi i due riquadri qui sotto |
| 5 | La richiesta non riesce (`WP_Error`) | **`ignota`** | giro su se stessi bloccato, DNS interno diverso, certificato non riconosciuto |

**Il reindirizzamento non è un rifiuto, e questa riga è arrivata in revisione.** La prima
stesura del codice leggeva come rifiuto qualunque stato fuori dall'intervallo 2xx, quindi anche
un 301 e un 302. Il caso comune che rompe quella lettura è un sito il cui indirizzo dei
caricamenti è in `http` mentre il server manda tutto su `https`: l'esca risponde 301, la
verifica direbbe `verificata`, e il deposito partirebbe su una cartella che un passo più in là
potrebbe servire i file senza problemi. Stessa cosa con un firewall applicativo che manda a una
pagina di sfida. È la riga C-163.

**Il diniego è un elenco chiuso, e anche questo è arrivato in revisione, al giro dopo.** La
correzione del reindirizzamento aveva lasciato in piedi la stessa famiglia di difetto con un
confine diverso: trattava come diniego qualunque stato dal 400 in su. Una revisione
indipendente ha fatto notare che così un 503 o un 429, che li dice un proxy che in quel momento
non sta servendo niente, autorizzano il deposito su una cartella che nessuno ha provato; quando
il proxy torna a servire, i file sono lì. Adesso il diniego è `403` e `404` e basta, dichiarati
in una costante. Riga C-164.

**Il 401 è fuori dall'elenco di proposito, e la revisione suggeriva di tenerlo dentro.** Un 401
lo risponde un sito messo per intero dietro un'autenticazione, cioè tipicamente
un'installazione in costruzione. Non è un giudizio sulla cartella: il giorno che
l'autenticazione si toglie, l'esito conservato direbbe `verificata` su una cartella mai
provata, ed è proprio la sequenza che capita, perché i file si depositano mentre il sito è
ancora chiuso. Un'installazione che deve lavorare in quella condizione dichiara lo
scavalcamento, che esiste per questo e sposta la responsabilità su chi lo dichiara. Il costo di
questa scelta è dichiarato: dietro autenticazione, senza scavalcamento, non si deposita.

**Il gettone si guarda prima dello stato, e anche questo è un rilievo della revisione.** Se il
contenuto dell'esca torna indietro, la cartella è aperta, e con quale stato sia tornato non
cambia niente. Guardare lo stato per primo lasciava passare il server che serve il file
accompagnandolo con uno stato di errore, che è quello che fa una rete di distribuzione mal
configurata davanti all'origine. Riga C-165.

Il gettone casuale serve anche a distinguere "il server ha negato" da "è tornata una pagina
qualsiasi con stato 200", che sono due cose diverse e che senza gettone si confonderebbero.

**Un diniego prova un percorso, non una cartella, e questo è il rilievo del terzo giro di
revisione.** L'esca è un `.txt` nella radice della cartella protetta; i documenti sono PDF e
immagini dentro `2026/09`. Fra le due cose c'è una distanza che su un server vero si vede: su
nginx una `location` con espressione regolare per i PDF **vince** su una `location` di
prefisso per la cartella, e su Apache un `FilesMatch` fa lo stesso. Un'installazione così nega
l'esca `.txt` e serve i PDF, e non serve che nessuno cambi niente dopo: è la configurazione
sbagliata dal primo giorno. La verifica diceva `verificata` e il deposito partiva.

**Quindi l'esito si conserva per ambito, dove un ambito è la coppia sottocartella più
estensione**, cioè esattamente quello che un deposito produce e quello che una regola di server
può trattare in modo diverso dal resto. Prima di scrivere i byte, il deposito guarda l'ambito
suo: se non risulta provato, scrive l'esca con quell'estensione in quella sottocartella e la
chiede al server, con la stessa lettura della tabella qui sopra. Righe C-169 e C-170.

**L'ambito si prova com'è scritto, e quello che non si sa chiedere non si deposita.** Il
percorso che si chiede al server deve essere esattamente quello in cui i byte finiscono, e
questo vale per tutte e due le metà dell'ambito. Un nome di sottocartella o un'estensione che
si riducono per costruire l'indirizzo farebbero provare un percorso e scriverne un altro, che
è il difetto della C-169 per un'altra via. Quindi non si riduce niente in silenzio: si
convalida, e quello che non passa la convalida fa rifiutare il deposito con un codice suo.
L'estensione, per la stessa ragione, non si ricostruisce a mano ma si chiede alla stessa
funzione di WordPress che deciderà il nome del file, perché è lei che abbassa le maiuscole e
che per le immagini può cambiare formato. Righe C-172 e C-174.

**E resta una previsione, quindi non è l'ultima parola.** Dentro `wp_handle_sideload()` c'è
un aggancio con cui un altro componente può cambiare il nome del file, estensione compresa,
dopo quel calcolo. Perciò una guardia gira sul percorso **definitivo**, sull'ultimo aggancio
prima che i byte si muovano: legge sottocartella ed estensione dal percorso vero, pretende
che quell'ambito risulti provato, e se non lo è ferma lo spostamento. Righe C-177 e C-178.
La guardia pretende anche tre cose che la previsione non può vedere: che il file abbia
un'estensione, perché un file senza non appartiene all'ambito dei `.txt`; che la cartella
vera sia esattamente quella scritta, senza collegamenti simbolici in mezzo, perché lo stesso
file raggiungibile da due indirizzi è provato su uno solo; e, per un caricamento, che il file
che sta per essere copiato venga ancora da un caricamento HTTP, perché fra l'ingresso e lo
spostamento un aggancio può sostituirlo. Righe C-182, C-183 e C-184.

Due conseguenze, e tutte e due sono scelte:

- **Una verifica generale azzera gli ambiti già provati.** Se si rifà quella, o le regole sono
  cambiate o qualcuno l'ha chiesta: in tutti e due i casi le prove vecchie parlano di una
  configurazione che potrebbe non esserci più.
- **Un esito `non_coperta` di qualunque ambito diventa anche quello generale.** Il gettone è
  uscito, quindi la cartella serve i suoi file, e non è un fatto della sola estensione.

*Quello che resta scoperto, e va detto: l'ambito è provato per la sottocartella del mese in
cui si deposita. Il mese dopo è un ambito nuovo e si prova da capo, il che è giusto, ma una
regola che dipendesse dal nome del singolo file, e non dalla sua estensione o dalla sua
cartella, continuerebbe a non essere vista. Un'esca per file significherebbe una richiesta HTTP
per ogni deposito, che è il costo che questo meccanismo esiste per non pagare.*

**Lo stato `presunta` non esiste.** Era nella stesura precedente di questa scheda e significava
"il file di regole c'è e il server dice di leggerlo": con una verifica vera non serve più, ed
era proprio il valore che avrebbe lasciato passare Apache con `AllowOverride None`, cioè il
caso che si voleva prendere. Gli esiti sono tre: `verificata`, `non_coperta`, `ignota`.

**Quando si fa la richiesta, e quando no.** Non a ogni deposito: un meccanismo che fa una
richiesta HTTP per ogni file caricato paga un costo a ogni scrittura e si ferma quando il giro
su se stessi è lento. Si fa in quattro momenti:

1. **all'attivazione** del plugin;
2. **ogni volta che i file di regole vengono scritti o riscritti**, quindi al primo deposito e
   a ogni deposito successivo che trovi un file di regole mancante o diverso da quello atteso;
3. **su richiesta esplicita**, con `conformita_core_verifica_protezione_allegati()`;
4. **la prima volta che si deposita in un ambito non ancora provato**, cioè la prima volta che
   si scrive un'estensione in una sottocartella. Una sola richiesta per ambito: il secondo file
   con la stessa estensione nello stesso mese non ne fa nessuna.

L'esito si conserva in un'opzione insieme al proprio istante, allo stato HTTP osservato e al
gettone, con una voce per ogni ambito provato, e si rilegge. Un deposito in un ambito già
provato legge l'opzione e non fa nessuna richiesta.

**Il deposito si rifiuta su `non_coperta` e su `ignota`, e procede su `verificata`.** Il motivo
è la regola di questo progetto sul dubbio: fra depositare un file che non si è in grado di
proteggere e rifiutarsi di depositarlo, si rifiuta. Un file già scritto in una cartella aperta
non si può richiamare indietro; un deposito rifiutato è un messaggio in faccia a chi sta
installando, nel momento in cui può ancora rimediare. Con la verifica vera il rifiuto è raro e
si risolve da sé appena l'installazione sistema il server: basta rifare la verifica.

**La costante `CONFORMITA_CORE_PROTEZIONE_CONFERMATA` resta, e cambia ruolo.** Non è più la
strada normale per installare su nginx, che adesso è configurare il server e far dire
`verificata` alla verifica. È uno scavalcamento per chi sa quello che fa: un ambiente dove il
giro su se stessi non funziona per costruzione, e dove chi installa ha verificato a mano da
fuori. Chi la definisce si prende la responsabilità che la verifica non ha potuto prendersi, e
lo stato continua a riportare l'esito vero accanto allo scavalcamento, senza mascherarlo.

**I limiti, che restano e vanno detti.**

- **L'esito invecchia.** La verifica fotografa il momento in cui gira. Una modifica alla
  configurazione del server fatta dopo non viene notata finché qualcuno non rifà la verifica.
  Per questo lo stato porta il suo istante: chi lo legge può vedere quanto è vecchio. Programmare
  una riverifica periodica non è di questa unità, perché il lavoro pianificato è S6.
- **La richiesta parte dal server e non da Internet.** Se davanti al sito c'è un proxy, una rete
  di distribuzione o un firewall applicativo, il giro su se stessi può prendere una strada
  diversa da quella di un visitatore vero. La verifica copre il server; quello che sta davanti
  lo copre solo la prova esterna del punto 10.

### 1.3 Il punto di consegna è una superficie pubblica o di amministrazione

I documenti dell'albo chiedono due cose insieme: dopo la scadenza il file **non è più
scaricabile dal pubblico da nessuno, nemmeno da chi ha permessi** (`dati.md`, e ALBO-05 che
pretende la prova anche da utente autorizzato), e **resta raggiungibile
dall'amministrazione** (`dati.md`).

**Scelta: due punti distinti, non un punto che si comporta in due modi.**

| Punto | Dove | Chi | Scadenza |
|---|---|---|---|
| Consegna pubblica | indirizzo del sito, front-end | chiunque, anche anonimo | **si applica a tutti** |
| Consegna amministrativa | `admin-post.php` | capability del tipo, più nonce | **non si applica**, come l'esenzione di S4 |

**Perché due e non uno, e la ragione è tecnica prima che di stile.** La funzione
`superficie_pubblica()` di S4 dice `! ( is_admin() && ! wp_doing_ajax() )`. E `admin-post.php`
**sta dentro `wp-admin`**: durante una sua richiesta `is_admin()` è vero, anche per un
visitatore anonimo. Se il punto pubblico fosse lì, si troverebbe su una superficie che il
resto del componente considera esente dal filtro di scadenza: non per una svista, ma per la
definizione che S4 ha scritto e collaudato. Sarebbe un'esenzione ottenuta per l'indirizzo
scelto, che è il modo peggiore di ottenerne una.

La seconda ragione è di forma del codice. Un punto solo con due comportamenti concentra la
decisione più pericolosa del meccanismo, cioè esentare o no dalla scadenza, in un ramo `if`
valutato su un indirizzo pubblico. Con due punti, **sull'indirizzo pubblico quel ramo non
esiste**: l'esenzione non è esprimibile. È la stessa forma di ragionamento per cui S4 ha
messo accensione e spegnimento a leggere un elenco solo.

La terza è che i due punti hanno bisogno di cose diverse e le direbbero male insieme:
autenticazione sì e no, nonce sì e no, e due vocabolari di rifiuto diversi (vedi 1.7).

**Che cosa NON è la consegna amministrativa.** Non è l'archivio riservato, che è l'unità S10
e non esiste. È la stessa esenzione che S4 dà all'amministrazione, applicata al file invece
che alla pagina: una superficie di gestione, dove il contenuto scaduto resta visibile perché
resti correggibile. Quando S10 arriverà, la capability di archivio si aggiungerà lì, e la
differenza fra `irraggiungibile` e `archivio` cadrà su questo punto e non su quello pubblico.

**Senza questo secondo punto, S5 romperebbe qualcosa.** Proteggendo la cartella, il file
smetterebbe di essere raggiungibile anche dall'amministrazione, che è una cosa che i
documenti dell'albo chiedono espressamente. Il secondo punto non è un'aggiunta: è la parte
che impedisce a questa unità di togliere una funzione che c'era.

### 1.4 Come si registra, e come si rispetta la regola su nonce e permessi

**Scelta: variabili di interrogazione sull'indirizzo del sito, servite su `parse_request`.
Nessuna regola di riscrittura.** L'indirizzo è:

```
https://sito/?conformita_core_atto=12&conformita_core_allegato=34
```

**Le alternative, e perché sono state scartate.**

| Alternativa | Perché no |
|---|---|
| **Regola di riscrittura** con indirizzo leggibile | Una regola di riscrittura vive in un'opzione che va rigenerata: se la rigenerazione non è avvenuta, o un altro componente la sovrascrive, o cambia la struttura dei permalink, la consegna diventa silenziosamente "non trovato". È la stessa famiglia di errore del compito pianificato che non gira: un meccanismo di conformità non può dipendere da uno stato memorizzato che qualcun altro può perdere. In più con la struttura dei permalink vuota non funzionerebbe affatto |
| **`admin-post.php`** | `is_admin()` vero: vedi 1.3. Sull'indirizzo pubblico è da escludere; resta la scelta giusta per il punto amministrativo |
| **Rotta per programmi (REST)** | Il `permission_callback` verrebbe gratis, ma REST è pensato per risposte strutturate, non per riversare byte: la risposta va costruita a mano scavalcando il server delle rotte, e nel frattempo si finisce sotto `rest_request_before_callbacks`, cioè sotto un aggancio che questa unità dovrebbe poi distinguere dal proprio. Più pezzi mobili per la stessa cosa |

`parse_request` è l'aggancio giusto perché scatta su ogni richiesta del front-end **prima**
dell'interrogazione principale: la consegna serve i byte ed esce, senza che WordPress
costruisca una pagina e senza incrociare i filtri di S4. `template_redirect` sarebbe arrivato
tardi e, come S4 ha già annotato, non scatta quando la richiesta non disegna una pagina.

**Costo dichiarato:** l'indirizzo è brutto e non è leggibile. Per un file che si scarica,
non per una pagina che si cita, è un costo che si accetta. Un alias leggibile sarà una unità
sua, e in quel caso l'indirizzo con le variabili resterà come forma che funziona sempre.

**La regola del repository: nonce e `permission_callback` su ogni superficie registrata. Come
si rispetta su un indirizzo che deve funzionare per un visitatore anonimo.**

Non aggiungendo un nonce dove non significa niente, e scrivendo per esteso la decisione di
accesso che c'è al suo posto.

- **Sul punto pubblico non c'è nonce, ed è deliberato.** Un nonce difende dal CSRF, cioè dal
  far compiere a un utente autenticato un'azione che non voleva. Qui la richiesta è una
  lettura senza effetti, e la risorsa è per definizione pubblica finché l'atto è valido: un
  nonce proverebbe soltanto che chi chiede ha una sessione, che non è ciò che governa
  l'accesso. Metterlo renderebbe l'indirizzo incollabile e non chiuderebbe niente.
- **Al suo posto c'è la catena di controlli, ed è il `permission_callback` di questa
  superficie.** Sta in una funzione sola, si chiama da un punto solo, decide in un senso
  solo, e ogni suo anello ha la sua riga di collaudo (1.7). La regola del repository pretende
  che nessuna superficie registrata resti senza una decisione di accesso esplicita: qui la
  decisione è esplicita, scritta, e collaudata anello per anello.
- **Sul punto amministrativo ci sono tutti e due**, capability del tipo **e** nonce legato
  all'identificativo dell'allegato. Proprio perché è la superficie dove la scadenza non si
  applica, è quella dove far scaricare un file a un amministratore a sua insaputa avrebbe un
  effetto, e quindi il nonce ce l'ha.

**Quale capability sul punto amministrativo.** La capability di tipo dell'insieme
(`edit_posts` derivata dalle radici del tipo, quella che `Conformita_Core_Tipi::capacita()`
restituisce), non una capability sul singolo contenuto. La ragione: è la stessa che regge
l'elenco amministrativo dove S4 lascia visibile il contenuto scaduto, quindi le due esenzioni
hanno la stessa porta. Una capability sul singolo, per esempio `edit_post`, sarebbe fragile
qui: l'albo blocca la modifica dell'atto pubblicato (ALBO-09), e una futura mappatura che
rendesse falso `edit_post` su un atto pubblicato chiuderebbe la consegna amministrativa senza
che nessuno avesse deciso di chiuderla.

### 1.5 La memoria di pagina, le reti di distribuzione, e come esce il file

Un indirizzo che oggi consegna un file e domani deve rifiutarlo non può essere conservato da
nessuno.

**Che cosa manda il punto pubblico**, su ogni risposta, sia quando consegna sia quando
rifiuta:

```
Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0
Pragma: no-cache
Expires: <una data nel passato>
X-Content-Type-Options: nosniff
Accept-Ranges: none
```

`no-store` è quella che conta: `no-cache` da solo autorizza a conservare una copia e a
rivalidarla, `no-store` dice di non conservarla. `private` esclude le memorie condivise.
Nessun `ETag` e nessun `Last-Modified`, per non offrire un appiglio alla rivalidazione.

**Come esce il file: elenco chiuso di tipi serviti dentro la pagina, tutto il resto scaricato.**

Il rischio che giustifica `Content-Disposition: attachment` è l'esecuzione di codice
nell'origine del sito, e riguarda i tipi che il browser interpreta come documento attivo, cioè
un SVG o un HTML, non un PDF e non un'immagine raster. Servire come allegato *tutto* sarebbe una
conclusione più larga della sua premessa, e in un albo pretorio costerebbe una regressione
d'uso vera: chi consulta un atto se lo troverebbe scaricato invece che aperto.

| Tipo | Come esce |
|---|---|
| `application/pdf` | `inline` |
| `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif` | `inline` |
| ogni altro tipo, compresi `image/svg+xml` e qualunque cosa il browser interpreti | `attachment` |

**L'elenco sta nel codice, non in configurazione**, e non è filtrabile: è una decisione di
sicurezza, e una decisione di sicurezza che un'installazione può allargare non è una decisione.

**Il tipo con cui si decide non è quello dichiarato e basta.** Si deriva il tipo
dall'estensione del file con `wp_check_filetype()` e lo si confronta con quello memorizzato
sull'allegato: se coincidono, decide l'elenco qui sopra; se non coincidono, si esce
`attachment` con `application/octet-stream`, perché una discordanza fra i due è esattamente la
condizione in cui non si sa che cosa si sta servendo. Riga C-160.

**Sui tipi serviti dentro la pagina si aggiunge una politica di sicurezza dei contenuti** che
li isola, insieme al `nosniff` che c'è già su tutto:

```
Content-Security-Policy: default-src 'none'; img-src 'self' data:; object-src 'none';
  script-src 'none'; style-src 'none'; base-uri 'none'; form-action 'none';
  frame-ancestors 'self'
```

`default-src 'none'` chiude tutto e le altre direttive riaprono il minimo: un'immagine può
disegnarsi, niente script, niente plugin, niente moduli, nessuna base di indirizzi da
riscrivere. `frame-ancestors 'self'` lascia che sia la pagina dell'atto a incorporare il
documento e impedisce a un altro sito di incorniciarlo.

**La direttiva `sandbox` è deliberatamente fuori**, e va detto perché è quella che un elenco
di direttive "più sicure possibile" conterrebbe. I visualizzatori di PDF incorporati nei
browser sono documenti a loro volta, e `sandbox` senza permessi li rompe: metterla
significherebbe riportare il PDF a scaricarsi, cioè disfare con una intestazione la decisione
appena presa. Le direttive qui sopra vietano già script, plugin e moduli, che sono le vie per
cui un documento servito dall'origine del sito farebbe danno.

**Su tutte le risposte** resta il nome del file depositato, in `Content-Disposition`, nelle
due forme `filename` e `filename*` per i nomi non ASCII: `inline` o `attachment` cambia il
verbo, non il nome.

**Che cosa resta fuori dal nostro controllo, detto senza attenuanti.**

| Fuori controllo | Perché |
|---|---|
| Una rete di distribuzione configurata per conservare per estensione | Molte ignorano `Cache-Control` sulle estensioni di file e conservano lo stesso |
| Un proxy inverso davanti al sito | Vale lo stesso |
| Un componente di memoria di pagina dentro WordPress | Se risponde prima, il nostro codice non gira. È lo stesso limite che S4 dichiara, ed è la riga ALBO-20, che si chiude nel componente dell'albo |
| Il file già scaricato | Una copia sul disco di chi l'ha scaricata non la richiama indietro nessuno. Nessun meccanismo può prometterlo, e prometterlo sarebbe la bugia peggiore di questa scheda |
| Il tasto "indietro" del browser | Con `no-store` la ripresentazione dovrebbe rifare la richiesta, ma il comportamento varia fra i browser |

**Non si usa `X-Sendfile` né `X-Accel-Redirect`.** Scaricherebbero la consegna sul server web,
più efficiente su file grandi, ma sposterebbero la risposta fuori dal nostro controllo
proprio sulle intestazioni che qui contano, e il nome dell'intestazione dipende dal server,
cioè sarebbe un valore da configurare per installazione. Si riversa da PHP. **Limite
dichiarato: su file molto grandi la consegna occupa un processo PHP per tutta la durata.**

### 1.6 Che cosa succede al file alla scadenza, e l'impronta

**Alla scadenza non succede niente al file.** Non si cancella, non si sposta, non si rinomina.
Resta dove sta, e smette di essere consegnato dal punto pubblico perché la catena di
controlli lo rifiuta a ogni richiesta. È il comportamento che `dati.md` dell'albo descrive.

**L'impronta la calcola questo meccanismo, al deposito.** Non alla defissione.

Il motivo è che alla defissione si potrebbe soltanto calcolare l'impronta di quello che sta
sul disco **in quel momento**, che non dimostra niente su quello che è stato pubblicato:
proverebbe la fotografia del momento in cui si scatta, non di quello che il referto deve
attestare. Al deposito, invece, l'impronta e l'atto del pubblicare coincidono. In più il
deposito è un percorso di **scrittura**: calcolarla lì non costa niente sul percorso di
lettura, che per la regola 4-bis di S4 non deve scrivere e non deve pagare.

**In quale forma**, tre metadati sull'allegato:

| Chiave | Contenuto |
|---|---|
| `_conformita_core_impronta` | `sha256:<64 cifre esadecimali>` |
| `_conformita_core_dimensione` | numero di byte, come intero |
| `_conformita_core_deposito` | istante del deposito nel fuso del sito, ISO 8601 con lo scostamento |

L'algoritmo è **dentro il valore** e non implicito: il giorno in cui sha-256 non basterà più,
i valori vecchi continueranno a dire da soli con che cosa sono stati calcolati, invece di
essere reinterpretati in silenzio con l'algoritmo nuovo. L'istante si costruisce con
`wp_timezone()`, mai con un fuso cablato.

**Che cosa questa unità NON fa con l'impronta.** Non la verifica alla consegna: sarebbe una
lettura dell'intero file a ogni richiesta, cioè un costo sul percorso di lettura, e un modo
di guasto nuovo (rifiutare un file pubblico perché non torna un confronto). Non costruisce il
referto, che è dell'albo. E non si accorge se qualcuno sostituisce il file sul disco: è la
riga C-125, che è documentazione eseguibile di un limite e non prova di conformità, come la
C-93 di S4.

### 1.7 I casi limite: che cosa risponde il punto di consegna

**Il principio, e regge tutta la tabella: "non trovato" e "vietato" dicono due cose diverse a
chi guarda. Un file che esiste ma è scaduto non deve confessare di esistere.** La politica
dell'albo è `irraggiungibile`: dopo la defissione quell'indirizzo non risponde più a nessuno,
e un `403` direbbe "c'è, ma non per te", cioè esattamente quello che la politica non vuole
dire. Quindi **tutti** i rifiuti del punto pubblico sono lo stesso rifiuto.

| Caso | Punto pubblico | Riga |
|---|---|---|
| Contenuto inesistente | non trovato | C-133 |
| Allegato inesistente | non trovato | C-133 |
| Allegato che non appartiene al contenuto dichiarato | non trovato | C-134 |
| Contenuto di un tipo non registrato attraverso il core | non trovato | C-135 |
| Contenuto non pubblicato (bozza, privato, in revisione) | non trovato | C-136 |
| Contenuto pubblicato ma protetto da password, chiesto senza la password | non trovato | C-167 |
| Allegato cestinato, contenuto padre ancora pubblicato | non trovato | C-168 |
| Allegato con una password propria, chiesto senza la password | non trovato | C-171 |
| Contenuto scaduto | non trovato | C-131 |
| Contenuto scaduto e richiedente con tutte le capability | non trovato | C-132 |
| Sezione senza politica valida | non trovato | vedi sotto |
| File mancante sul disco | non trovato | C-137 |
| Allegato non depositato attraverso il core | non trovato | C-128 |
| Richiesta parziale (`Range`) | risposta **intera**, stato 200, `Accept-Ranges: none` | C-139 |

**I rifiuti sono indistinguibili fra loro**: stesso stato, stesso corpo, stesse
intestazioni, e nessun dettaglio del percorso sul disco nel corpo. La riga C-138 li confronta
uno con l'altro. Nove dei dieci casi della tabella sono costruibili e vengono confrontati; il
decimo, la sezione senza politica valida, attraverso l'API non è raggiungibile, perché un tipo
si registra solo dentro una sezione che ha già dichiarato la politica, ed è una guardia
difensiva, come quella della riga C-94 di S4.

*In implementazione si è visto che l'uniformità è una proprietà della forma del codice e non
delle prove: il rifiuto lo costruisce una funzione senza argomenti, quindi non ha niente da
cui variare. La riga C-138 serve per il giorno in cui qualcuno le dà un argomento, ed è
quello che il guasto G5 della tabella del punto 13 simula.*

**Il contenuto protetto da password.** Un contenuto con la password è pubblicato, quindi
l'anello sullo stato non lo ferma, ma il suo corpo non si legge senza la password. Consegnare
il suo allegato lo farebbe uscire lo stesso, per l'indirizzo di consegna invece che per la
pagina: chi conosce i due numeri si porterebbe via il documento che la password doveva
chiudere. La password è del contenuto padre e vale per tutto ciò che gli appartiene, quindi
l'anello nuovo chiede `post_password_required()` sul contenuto e rifiuta come tutti gli altri
rifiuti. Con la password giusta la consegna riprende, e la scadenza continua a valere: la
password non è un lasciapassare, è una condizione in più.

Sul punto amministrativo non si applica, ed è deliberato: lì si entra con il nonce e la
capability del tipo, cioè con un'autorizzazione più forte di una password di lettura, e quel
punto serve il file anche dopo la scadenza per lo stesso motivo.

**Limite dichiarato, e non è chiuso:** resta un canale per differenza di tempo. Un rifiuto che
arriva dopo aver letto due contenuti e un metadato impiega più di un rifiuto immediato, e in
teoria la differenza si misura. Questa unità non la livella: costruire risposte a tempo
costante in PHP su un percorso che tocca la banca dati non è realistico, e prometterlo
sarebbe peggio che dichiararlo.

**Sulla politica della sezione, una precisazione che evita di promettere troppo.** La catena
controlla che il tipo appartenga a una sezione registrata con una politica valida, e rifiuta
se non è così: un file governato da nessuna politica non si consegna. **Non** distingue
`irraggiungibile` da `archivio`, esattamente come S4 non le distingue, perché oggi la
differenza non esiste: è l'unità S10. Un componente che dichiara `archivio` ottiene qui il
comportamento di `irraggiungibile`. Quando S10 arriverà, la differenza cadrà sulla consegna
amministrativa, non su quella pubblica.

*Nota sulla costruibilità del caso "sezione senza politica valida": attraverso l'API non è
raggiungibile, perché un tipo si registra solo dentro una sezione che ha già dichiarato la
politica. Il controllo resta come guardia difensiva, con la stessa logica della riga C-94.*

**Sulla richiesta parziale, che è la scelta che costa di più.** Il punto pubblico **ignora
l'intestazione `Range` e risponde sempre con il file intero**, dichiarando `Accept-Ranges:
none`. Il motivo è che una gestione corretta degli intervalli di byte è un analizzatore
sintattico con una storia di vulnerabilità propria (intervalli multipli, intervalli
sovrapposti, estremi che vanno in overflow), e la combinazione fra risposte parziali e
memorie intermedie è precisamente dove i proxy si inventano le cose. **Il costo è reale e va
detto:** un PDF grande non si può riprendere dopo un'interruzione, e i visualizzatori che
leggono un PDF a pezzi dovranno scaricarlo tutto. Se un giorno servirà, l'intervallo singolo
si può aggiungere: è una unità sua, non una riga di questa.

---

## 2. Il contratto del dato

### 2.1 I metadati sull'allegato

| Chiave | Su chi | Contenuto |
|---|---|---|
| `_conformita_core_allegato` | l'allegato | `1`. È la marca che dice "depositato attraverso il core" |
| `_conformita_core_impronta` | l'allegato | `sha256:<esadecimale>` |
| `_conformita_core_dimensione` | l'allegato | byte, intero |
| `_conformita_core_deposito` | l'allegato | istante ISO 8601 nel fuso del sito |

La marca è la verità, il percorso è solo il modo di trovare il file: riconoscere un allegato
protetto guardando se il percorso comincia per `conformita-core-protetto/` funzionerebbe fino
al giorno in cui qualcuno sposta la cartella dei caricamenti.

Il trattino basso iniziale li tiene fuori dall'interfaccia dei campi personalizzati, come per
la data di fine: si scrivono dal deposito e da nessun'altra parte.

### 2.2 Dove va il percorso del file

`_wp_attached_file` conterrà **`conformita-core-protetto/2026/09/atto.pdf`**, cioè un percorso
relativo alla cartella normale dei caricamenti che include il segmento protetto.

Questo è un dettaglio che sembra piccolo e non lo è. Il filtro `upload_dir` che dirotta i byte
resta attivo **solo per la durata dello spostamento** e viene tolto prima che WordPress
registri l'allegato. Se restasse attivo, `_wp_relative_upload_path()` calcolerebbe il percorso
rispetto alla cartella protetta e memorizzerebbe `2026/09/atto.pdf`: un percorso che, riletto
a filtro spento, punterebbe a `uploads/2026/09/atto.pdf`, cioè **a un file pubblico con lo
stesso nome, se esiste**. Sarebbe una collisione che consegna il documento sbagliato, e non
produrrebbe nessun errore.

Con il percorso memorizzato per intero, l'indirizzo che WordPress costruirebbe da solo punta
dentro la cartella protetta, cioè su un indirizzo negato; e `wp_get_attachment_url()` viene
comunque filtrato per restituire l'indirizzo di consegna.

### 2.3 L'opzione che conserva l'esito della verifica

Una sola, `conformita_core_protezione_allegati`, con dentro l'esito della verifica, il suo
istante, lo stato HTTP osservato, il motivo e il gettone dell'esca. È l'unico stato persistente
di questa unità fuori dai metadati, e la ragione per cui esiste è che la verifica costa una
richiesta HTTP e non si può rifare a ogni deposito. La scrive la verifica, la legge il
deposito, e **il percorso di consegna non la tocca**.

---

## 3. Il contratto delle funzioni pubbliche

Versione dell'interfaccia: **da 1.2.0 a 1.3.0**. Solo aggiunte: nessuna funzione esistente
cambia firma o comportamento, e nessun vincolo si restringe. L'albo dovrà richiedere `1.3.0`.

| Funzione | Parametri | Ritorno | Errori |
|---|---|---|---|
| `conformita_core_deposita_allegato( $post_id, $file, $opzioni )` | `int`, `array` nella forma di una voce di `$_FILES`, `array` con `origine` obbligatoria | `int` identificativo dell'allegato, oppure `WP_Error` | `conformita_core_tipo_non_gestito`, `conformita_core_origine_non_dichiarata`, `conformita_core_origine_incoerente`, `conformita_core_tipo_file_non_ammesso`, `conformita_core_protezione_non_verificata`, `conformita_core_destinazione_non_provabile`, `conformita_core_cartella_non_protetta`, `conformita_core_deposito_fallito` |
| `conformita_core_indirizzo_consegna( $allegato_id )` | `int` | `string`, oppure `WP_Error` | `conformita_core_allegato_non_gestito` |
| `conformita_core_indirizzo_consegna_amministrativa( $allegato_id )` | `int` | `string` con il nonce, oppure `WP_Error` | come sopra |
| `conformita_core_allegato_protetto( $allegato_id )` | `int` | `bool` | nessuno: è chiamata anche nel percorso di lettura |
| `conformita_core_impronta_allegato( $allegato_id )` | `int` | `array` con `algoritmo`, `valore`, `dimensione`, `deposito`, oppure `WP_Error` | `conformita_core_allegato_non_gestito`, `conformita_core_impronta_assente` |
| `conformita_core_stato_protezione_allegati()` | nessuno | `array`: legge l'esito conservato, **non fa nessuna richiesta** | nessuno |
| `conformita_core_verifica_protezione_allegati()` | nessuno | `array`: **rifà la richiesta all'esca generale**, azzera gli ambiti già provati, conserva il nuovo esito e lo restituisce | nessuno: un fallimento della richiesta è l'esito `ignota`, non un errore |

Le due funzioni sullo stato sono separate apposta: leggere non deve costare una richiesta
HTTP, e rifare la verifica deve essere una cosa che si chiede, non che capita. Lo stato
restituito da entrambe ha la stessa forma:

| Chiave | Contenuto |
|---|---|
| `copertura` | `verificata`, `non_coperta` oppure `ignota` |
| `istante` | quando la verifica è stata fatta, ISO 8601 nel fuso del sito, oppure vuoto se non è mai stata fatta |
| `stato_http` | lo stato osservato, oppure `0` se la richiesta non è riuscita |
| `motivo` | perché l'esito è quello, in una riga leggibile |
| `cartella` | il percorso della cartella protetta |
| `regole` | quali file di regole ci sono |
| `ambiti` | l'esito conservato per ogni ambito già provato, con la chiave che unisce sottocartella ed estensione |
| `scavalcata` | vero se `CONFORMITA_CORE_PROTEZIONE_CONFERMATA` è definita |

`copertura` riporta sempre l'esito vero della verifica, anche quando lo scavalcamento è
attivo: la costante cambia che cosa il deposito **fa**, non che cosa lo stato **dice**.

**`origine` è obbligatoria e non ha valore predefinito.** Vale `caricamento`, e allora il
deposito pretende che il file sia davvero arrivato da un caricamento HTTP e lo verifica, o
`percorso_locale`, e allora chi chiama sta dichiarando di sapere che i byte sono già sul
disco. Un valore predefinito qui sarebbe sbagliato in tutte e due le direzioni: dare per
scontato `caricamento` renderebbe impossibile l'importazione, dare per scontato
`percorso_locale` trasformerebbe un percorso ricevuto dall'esterno in una lettura di file
arbitrari. È la stessa regola per cui `show_in_rest` va dichiarata.

**`conformita_core_allegato_protetto()` non restituisce mai un errore**, per la stessa ragione
per cui non lo fa `conformita_core_scaduto()`: è chiamata nel percorso di lettura.

**Non esistono** una funzione che avvia la consegna, una che chiede se è avviata, e una che
verifica l'impronta ricalcolandola. Le prime due per la ragione del punto 5 di S4: il
meccanismo è infrastruttura di core e si accende al caricamento del file di core. La terza
perché la verifica appartiene al referto, che è dell'albo.

## 4. Come si avvia

Come S4, e per gli stessi motivi: al caricamento del file di core, con una guardia che rende
l'accensione idempotente, e senza che nessuna funzione pubblica la esponga. Gli agganci stanno
in un elenco solo, letto sia dall'accensione sia dallo spegnimento, perché il disallineamento
fra i due non sia esprimibile: è la lezione del punto 14.3 di S4.

| Aggancio | Metodo | A che serve |
|---|---|---|
| `parse_request` | consegna pubblica | serve i byte ed esce |
| `query_vars` | dichiara le due variabili | senza, non arrivano da `$_GET` |
| `admin_post_conformita_core_allegato` | consegna amministrativa | |
| `admin_post_nopriv_conformita_core_allegato` | rifiuto uguale a tutti gli altri | perché l'anonimo veda "non trovato" e non una pagina vuota |
| `wp_get_attachment_url` | sostituisce l'indirizzo del file | |

**All'attivazione del plugin**, con `register_activation_hook`, si fanno tre cose in fila:
si crea la cartella protetta, si scrivono i quattro file, e si fa la verifica. È l'unico
momento in cui questa unità lavora fuori da una richiesta di consegna o di deposito, e c'è
perché l'esito della verifica sia già disponibile prima del primo deposito invece che al
primo deposito.

**La registrazione di una sezione non dipende dall'avvio della consegna.** È la decisione che
`architettura.md` dell'albo rinviava con queste parole: *"quando arriveranno, se il loro
mancato avvio dovrà bloccare la registrazione sarà una decisione da prendere allora"*. La
prendo qui, e la risposta è no.

Il motivo è che i due meccanismi sbagliano in direzioni opposte. Il motore di scadenza spento
**apre**: i contenuti restano pubblici oltre il termine, e nessuno se ne accorge. La consegna
spenta **chiude**: i file non si scaricano da nessuna parte. Un meccanismo che si guasta
chiudendo non ha bisogno di una guardia che chiuda al posto suo. La guardia che serve davvero
sta dove c'è qualcosa da perdere, cioè sul **deposito**, che si rifiuta di scrivere un file
che non sa proteggere (1.2). Riga C-147.

---

## 5. La catena di controlli della consegna pubblica

In un posto solo, in quest'ordine, e nessun aggancio la salta.

1. L'allegato esiste ed è un contenuto di tipo `attachment`.
2. L'allegato porta la marca del deposito attraverso il core.
3. Il contenuto dichiarato nell'indirizzo esiste.
4. L'allegato ha quel contenuto come padre. **Se non coincidono, si rifiuta.**
5. Il tipo del contenuto è registrato attraverso il core.
6. La sezione del tipo ha una politica valida.
7. Lo stato del contenuto è `publish`.
8. Né il contenuto né l'allegato chiedono una password che la richiesta non porta,
   secondo `post_password_required()`: si guardano tutti e due, perché quella funzione non
   risale dal file al contenuto padre.
9. L'allegato stesso è in stato `inherit`, cioè quello che il deposito produce: un
   allegato cestinato non si consegna, anche se il suo contenuto padre è a posto.
10. Il contenuto non è scaduto, secondo `Conformita_Core_Scadenza::scaduto()`, cioè la stessa
    funzione che decide la scadenza in ogni altro punto del componente.
11. Il file esiste sul disco, dentro la cartella protetta, e il percorso normalizzato con
    `realpath()` sta ancora dentro quella cartella.

Al primo anello che non regge, il rifiuto. Nessun anello scrive niente.

**Perché l'anello 4 esiste, dato che il padre si potrebbe leggere dall'allegato.** Perché un
indirizzo di consegna vale per la coppia e non per il file: se un domani un allegato venisse
riagganciato a un altro contenuto, gli indirizzi vecchi smetterebbero di funzionare invece di
cominciare in silenzio a obbedire alla scadenza di un atto diverso. È il caso limite che la
domanda 7 nomina, e con un solo identificativo nell'indirizzo non sarebbe nemmeno esprimibile.

**Perché l'anello 11 controlla il percorso normalizzato.** Il percorso non arriva mai
dall'esterno, perché l'indirizzo porta due numeri e niente altro, come prescrive la checklist
di sicurezza dell'albo, ma `_wp_attached_file` è un dato memorizzato, e un dato memorizzato può
essere stato scritto da una migrazione. Fra fidarsi e controllare, si controlla.

**Che cosa NON c'è nella catena, e va detto:** nessun controllo sulle capability. Un utente
autorizzato che chiede il file dall'indirizzo pubblico ottiene quello che otterrebbe chiunque
altro. L'esenzione è della superficie, non dell'utente: è la regola del punto 3 di S4, e la
riga C-132 la verifica qui.

---

## 6. Pubblico e amministrazione

| Caso | Punto pubblico | Punto amministrativo |
|---|---|---|
| Atto pubblicato, non scaduto | consegna | consegna |
| Atto pubblicato, scaduto | **non trovato** | consegna |
| Atto scaduto, richiedente con capability | **non trovato** | consegna |
| Atto in bozza | **non trovato** | consegna |
| Atto protetto da password, chiesto senza la password | **non trovato** | consegna |
| Richiedente anonimo | secondo le righe sopra | **non trovato** |
| Richiedente senza la capability del tipo | secondo le righe sopra | **non trovato** |
| Nonce mancante o di un altro allegato | non pertinente | **rifiutato** |

---

## 7. I file

| File | Cosa |
|---|---|
| `includes/class-conformita-core-allegati.php` | nuovo: la cartella protetta, i file di regole, lo stato della copertura, il deposito, l'impronta, la guardia sulla destinazione |
| `includes/class-conformita-core-deposito-fermato.php` | nuovo: l'eccezione con cui la guardia esce dall'aggancio di WordPress, che non ha un modo di dire «rifiuta» |
| `includes/class-conformita-core-consegna.php` | nuovo: i due punti di consegna, la catena di controlli, le intestazioni |
| `includes/funzioni-api.php` | modificato: le sette funzioni del punto 3 |
| `conformita-core.php` | modificato: versione dell'interfaccia a 1.3.0, caricamento dei tre file nuovi, accensione della consegna, aggancio di attivazione |
| `tests/allegati-test.php` | nuovo: cartella, verifica, deposito, impronta, contratto |
| `tests/consegna-test.php` | nuovo: indirizzi, catena di controlli, intestazioni, punto amministrativo |
| `tests/consegna-non-vacuita-test.php` | nuovo: le due prove di non vacuità che si fanno da fuori |
| `README.md` | modificato: la cartella protetta, la copertura per server, e il fatto che il deposito si rifiuta dove la protezione non è dimostrabile |
| `docs/implementazione/consegna-allegati.md` | questa scheda |

Nessun file del repository dell'albo.

---

## 8. Che cosa questa unità non fa

- **Nessuna differenza fra le due politiche di scadenza.** `archivio` e `irraggiungibile`
  producono lo stesso comportamento anche qui, esattamente come in S4. È l'unità S10, e
  quando arriverà cadrà sulla consegna amministrativa.
- **Nessuna capability di archivio.** Il punto amministrativo usa la capability del tipo, che
  esiste già. S10.
- **Nessun compito pianificato e nessun battito**: S6. Alla scadenza il file non viene toccato
  da nessuno, ed è il comportamento voluto. Ne discende che **la riverifica della protezione
  non è programmata**: si fa all'attivazione, alla riscrittura delle regole e su richiesta.
- **Nessuna chiamata a servizi esterni.** L'unica richiesta HTTP di questa unità è quella
  verso l'esca, cioè verso il sito stesso, e si fa nei tre momenti del punto 1.2.
- **Nessun registro delle modifiche**: il deposito non scrive nessuna voce, perché il registro
  è un'altra unità. Quando ci sarà, il deposito sarà uno dei suoi chiamanti.
- **Nessuna verifica dell'impronta alla consegna**, e nessuna costruzione del referto.
- **Nessun controllo antivirus e nessuna ispezione del contenuto del file.** Il deposito
  verifica il tipo con gli strumenti di WordPress e non guarda dentro.
- **Nessun filtro sugli indirizzi delle dimensioni intermedie e delle anteprime**: solo
  `wp_get_attachment_url()`. Limite dichiarato in 1.1.
- **Nessuna gestione degli intervalli di byte**: 1.7.
- **Nessun alias leggibile dell'indirizzo di consegna, e nessuna regola di riscrittura**: 1.4.
- **Nessuna interfaccia di amministrazione, nessun avviso in bacheca.** Core espone lo stato
  della protezione e la funzione per rifare la verifica; mostrarli è del componente, per la
  stessa ragione per cui core genera le capability e non le assegna.
- **Nessun elenco di tipi serviti dentro la pagina configurabile o filtrabile**: 1.5.
- **`wp_get_attachment_url()` restituisce sempre l'indirizzo pubblico, anche in
  amministrazione.** Ne discende che nella libreria dei media il collegamento all'allegato di
  un atto scaduto non porta al file. Non si è fatto restituire l'indirizzo amministrativo
  quando `is_admin()` è vero, e la ragione è che quell'indirizzo porta un nonce legato
  all'utente e all'allegato: un nonce si genera dove lo si usa, non dentro un indirizzo
  generico che qualunque cosa può conservare o mettere in memoria. L'amministrazione arriva
  al file da `conformita_core_indirizzo_consegna_amministrativa()`, che il componente chiama
  dove costruisce le proprie schermate. Limite dichiarato, non difetto nascosto.
- **Nessuna cancellazione di file, mai**, né alla scadenza né altrove.
- **Nessuna riga di codice nel repository dell'albo pretorio.**

---

## 9. I modi di guasto previsti

| Guasto | Che cosa succede | È la direzione sicura? |
|---|---|---|
| Cartella non creabile | il deposito fallisce con errore; nessun file scritto | sì |
| File di regole non scrivibili | il deposito fallisce; nessun file scritto | sì |
| Copertura `non_coperta` o `ignota` | il deposito fallisce, e l'errore riporta esito, istante e stato osservato | sì |
| Regole cancellate a mano dopo il deposito | il deposito successivo trova il file mancante, lo riscrive, **rifà la verifica** e decide con l'esito nuovo | sì |
| Configurazione del server cambiata dopo la verifica | non viene notata finché qualcuno non rifà la verifica. **Limite dichiarato**: l'esito porta il suo istante apposta | **no** |
| Giro su se stessi bloccato | la verifica dà `ignota`, il deposito si rifiuta finché non si sistema o non si dichiara lo scavalcamento | sì |
| Il server reindirizza il percorso dell'esca | la verifica dà `ignota` e non `verificata`, perché dove porta il reindirizzamento non si sa; il deposito si rifiuta | sì |
| Il server risponde all'esca con un'indisponibilità temporanea (429, 5xx) | la verifica dà `ignota`, perché quello stato parla del momento e non delle regole della cartella; il deposito si rifiuta | sì |
| Il sito è per intero dietro un'autenticazione (401 all'esca) | la verifica dà `ignota`, e senza scavalcamento non si deposita finché il sito non è aperto | sì, al costo di un deposito rifiutato su un'installazione in costruzione |
| Il server serve l'esca accompagnandola con uno stato di errore | vale `non_coperta`, perché il gettone si guarda prima dello stato | sì |
| Un aggancio di un altro componente solleva un'eccezione durante lo spostamento dei byte | il dirottamento dei caricamenti si spegne comunque, perché la rimozione del filtro sta in un `finally` | sì |
| Scavalcamento dichiarato su un server davvero scoperto | i file si depositano in una cartella aperta | **no**, ed è il senso di uno scavalcamento: la responsabilità passa a chi lo dichiara |
| Il server nega la cartella ma serve i file di una certa estensione | l'esca di quell'ambito torna con il gettone, quindi vale `non_coperta`: il primo deposito di quell'estensione si rifiuta, e l'esito generale diventa `non_coperta` | sì |
| Un aggancio di un altro componente cambia la sottocartella dei caricamenti in un nome che non si sa chiedere al server com'è | il deposito si rifiuta con un codice suo: si proverebbe un percorso e se ne scriverebbe un altro | sì |
| L'installazione ammette un'estensione che il server tratta in modo diverso da come la si scriverebbe ridotta | l'ambito si prova con l'estensione che il file avrà davvero, quindi un server che serve quella e nega la forma ridotta fa rifiutare il deposito | sì |
| La riscrittura delle regole riesce su un file e fallisce sul successivo | gli esiti si dimenticano al primo file scritto davvero, quindi il ripristino a mano del file rimasto indietro non fa riusare giudizi vecchi | sì |
| Un tipo gestito viene dichiarato non consultabile dal pubblico, anche da un altro componente | i suoi allegati non escono dal punto pubblico, come non escono le sue pagine | sì |
| Un altro componente cambia il nome del file, estensione compresa, dentro lo spostamento | la guardia sul percorso definitivo prova l'ambito vero e ferma lo spostamento se risulta scoperto: nessun byte entra | sì |
| Un aggancio di un altro componente porta la destinazione fuori dalla cartella protetta, anche attraverso un collegamento simbolico | la guardia confronta il percorso vero e ferma lo spostamento: un file fuori da lì non sarebbe protetto da niente | sì |
| Un altro componente sposta i file per conto proprio e lo dice a WordPress prima che la guardia parli | la guardia decide lo stesso, e il file che trova già al suo posto in un ambito non provato lo toglie | sì |
| Un aggancio di un altro componente toglie l'estensione al nome del file | il deposito si rifiuta: un file senza estensione non si sa provare, e trattarlo come un `.txt` proverebbe un'altra regola del server | sì |
| Una sottocartella della cartella protetta è un collegamento simbolico verso un'altra sottocartella interna, o al posto del file c'è già un collegamento | la guardia pretende che la cartella vera sia quella scritta, e che il file non sia un collegamento: lo stesso file raggiungibile da due indirizzi è provato su uno solo | sì |
| Un aggancio di un altro componente sostituisce il file caricato con un file locale dopo il controllo all'ingresso | la guardia rifà il controllo sul file che sta per essere copiato e ferma lo spostamento, senza toccare la sorgente | sì |
| Un documento arriva con il nome dell'esca | si rifiuta con un codice suo: se prendesse il posto dell'esca, la verifica successiva lo sovrascriverebbe con la propria riga di prova | sì |
| Le regole vengono riscritte durante la verifica di un ambito | si dimentica tutto quello che si sapeva, generale compreso, e il generale si rimisura subito | sì |
| Una regola del server dipende dal nome del singolo file e non dall'estensione | non viene vista: l'esca prova l'estensione e la cartella, non il nome. **Limite dichiarato** | **no** |
| Allegato cestinato con il padre ancora pubblicato | il punto pubblico non trova niente; l'amministrazione lo vede ancora, perche' resti ripristinabile | sì |
| Contenuto pubblicato con una password, allegato chiesto senza | la consegna pubblica rifiuta come per ogni altro motivo; quella amministrativa, che pretende nonce e capability, consegna | sì |
| File cancellato dal disco | la consegna risponde "non trovato" | sì |
| Metadato dell'impronta perso | la consegna funziona, l'impronta non è leggibile e il referto se ne accorge | sì |
| `_wp_attached_file` che punta fuori dalla cartella protetta | la consegna rifiuta | sì |
| Tipo dichiarato e tipo dedotto dall'estensione discordi | si esce `attachment` con tipo generico, mai dentro la pagina | sì |
| Consegna spenta (file del meccanismo non caricato) | nessun file si scarica da nessuna parte | sì |

**Che cosa è cambiato rispetto alla stesura precedente di questa scheda.** La riga "regole
cancellate a mano" era l'unico guasto che sbagliava aprendo, e la scheda lo dichiarava come
non chiudibile da PHP. Con la verifica vera si chiude: la riscrittura delle regole è uno dei
tre momenti in cui la verifica si rifà, quindi il deposito successivo alla cancellazione
decide con un esito appena misurato e non con uno vecchio. Restano due righe che sbagliano
aprendo, e sono di natura diversa: una è l'invecchiamento dell'esito, che è un limite; l'altra
è lo scavalcamento dichiarato, che è una scelta di chi installa.

---

## 10. Come si prova su un'installazione reale

Tre prove, da aggiungere al collaudo di rilascio del cantiere con i numeri che si assegnano
là. Servono **anche** dove la verifica automatica dice `verificata`, perché quella richiesta
parte dal server e non da Internet: se davanti al sito c'è un proxy, una rete di distribuzione
o un firewall applicativo, il percorso è un altro.

1. **Accesso diretto al file esca, da fuori.** Si chiede
   `/wp-content/uploads/conformita-core-protetto/prova-accesso-diretto.txt` da una rete
   esterna. Deve rispondere con un rifiuto. È la prova che copre anche quello che sta davanti
   al server, che la verifica interna non vede.
2. **Accesso diretto a un allegato vero.** Si pubblica un atto con un allegato, si legge il
   percorso del file dall'amministrazione, lo si chiede da fuori. Deve rispondere con un
   rifiuto, e l'indirizzo di consegna deve invece funzionare.
3. **Scadenza.** Si porta la data di fine a ieri, senza eseguire nessun compito pianificato, e
   si richiede l'indirizzo di consegna da fuori: non trovato. Poi lo si richiede da un utente
   autorizzato, sempre dal front-end: non trovato lo stesso.

Le prime due si fanno senza credenziali, quindi entrano nel controllo automatico del periodo
di chiusura dell'ente.

---

## 11. Le righe di collaudo di questa unità

Numerazione continuata da C-114, che è l'ultima di S4. Prefisso `C-`, come prescrive la
convenzione di questo repository. **Settanta righe, da C-115 a C-184.**

Stato **fatto** dove la riga è una prova verde nella verifica continua. Cinque righe hanno
stato **fatto (tabella dei guasti)**: sono le prove di non vacuità della catena di controlli,
che non girano a ogni push e la cui verifica è la tabella del punto 13. Il motivo della
distinzione è scritto in fondo a questa sezione, e il costo è dichiarato.

### Deposito e cartella protetta

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-115 | fatto | Il file depositato non sta nella cartella pubblica dei caricamenti, e `_wp_attached_file` porta il segmento protetto per intero |
| C-116 | fatto | La cartella nasce con i tre file di regole e con l'esca, il loro contenuto è quello atteso, e l'esca contiene il gettone conservato accanto all'esito |
| C-117 | fatto | Regole cancellate a mano: il deposito successivo le riscrive **prima** di muovere i byte |
| C-118 | fatto | Cartella non creabile: il deposito fallisce con `conformita_core_cartella_non_protetta` e non lascia dietro né file né allegato |
| C-119 | fatto | Copertura `non_coperta`: deposito rifiutato, e l'errore riporta esito, istante e stato osservato |
| C-120 | fatto | Copertura `ignota`: deposito rifiutato. Con lo scavalcamento dichiarato il deposito procede, e lo stato continua a riportare `ignota` con `scavalcata` vero |
| C-121 | fatto | Deposito su un contenuto di tipo non registrato attraverso il core: rifiutato |
| C-122 | fatto | `origine` non dichiarata o inventata: rifiutato. Dichiarata `caricamento` senza un vero caricamento: rifiutato, con un codice suo |
| C-123 | fatto | Tipo di file non ammesso dall'installazione: rifiutato |
| C-124 | fatto | Impronta al deposito: `sha256:` più il valore vero del file, con dimensione e istante nel fuso del sito |
| C-125 | fatto | File sostituito sul disco dopo il deposito: la consegna non se ne accorge. **Documentazione eseguibile di un limite**, non prova di conformità |
| C-166 | fatto | Un'eccezione sollevata da un aggancio altrui durante lo spostamento dei byte non lascia acceso il dirottamento dei caricamenti |
| C-184 | fatto | Origine dichiarata `caricamento`: la guardia rifà la domanda `is_uploaded_file()` sul file che sta per essere copiato, e un file locale messo al suo posto dopo l'ingresso si ferma senza toccare né sorgente né destinazione. Dentro un deposito vero la guardia conosce l'origine dichiarata, e finito il deposito l'origine non resta in giro |

### Verifica della protezione

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-146 | fatto | Lo stato conservato si rilegge con il suo istante, e leggerlo non fa nessuna richiesta |
| C-154 | fatto | La richiesta all'esca riceve un diniego, 403 oppure 404: esito `verificata`, con l'istante e lo stato osservato |
| C-155 | fatto | La richiesta all'esca riceve 200 con dentro il gettone: esito `non_coperta` |
| C-156 | fatto | La richiesta fallisce, oppure riceve 200 senza il gettone: esito `ignota` in tutti e due i casi, con motivi distinti |
| C-157 | fatto | La verifica si fa su richiesta esplicita, quando le regole si riscrivono, e la prima volta che si deposita in un ambito non ancora provato. **Un deposito in un ambito già provato non fa nessuna richiesta**, e la riga lo verifica contandole |
| C-158 | fatto | Regole cancellate a mano: il deposito successivo le riscrive **e rifà la verifica**, e decide con l'esito nuovo e non con quello conservato |
| C-163 | fatto | La richiesta all'esca riceve un reindirizzamento: esito `ignota` e non `verificata`, e il deposito si rifiuta |
| C-164 | fatto | Uno stato di errore che non è un diniego (500, 503, 429, 401) vale `ignota` e non `verificata`, e il deposito si rifiuta |
| C-165 | fatto | Il gettone vince sullo stato: l'esca servita con uno stato di errore vale `non_coperta` |
| C-169 | fatto | Esca `.txt` negata nella radice ma file dell'estensione servito nella sottocartella di destinazione: il deposito si rifiuta, e l'esito generale diventa `non_coperta` perché il gettone è uscito |
| C-172 | fatto | La sottocartella di destinazione si prova com'è scritta, punto compreso; una che non si sa chiedere al server, come un nome con uno spazio, fa rifiutare il deposito con un codice suo, senza nessuna richiesta e senza muovere nessun file |
| C-174 | fatto | L'estensione provata è quella che il file avrà sul disco: `.PDF` si prova minuscolo perché WordPress lo scriverà minuscolo, e un'estensione con un trattino si prova com'è, quindi un server che nega la forma ridotta e serve quella vera fa rifiutare il deposito |
| C-175 | fatto | Riscrittura delle regole riuscita sul primo file e fallita sul secondo: gli esiti conservati si dimenticano lo stesso, e il ripristino a mano del file rimasto indietro non fa tornare validi i giudizi vecchi |
| C-177 | fatto | Una sottocartella che finisce con un a capo non passa la convalida: le ancore dell'espressione regolare sono `\A` e `\z`, e non `^` e `$`, che accetterebbero quella posizione |
| C-178 | fatto | Un componente cambia il nome del file, estensione compresa, dentro lo spostamento: la guardia sul percorso definitivo prova l'ambito vero e, se risulta scoperto, ferma lo spostamento. Nessun byte entra nell'ambito aperto |
| C-179 | fatto | Un componente risponde per primo all'aggancio dello spostamento e copia il file da sé: la guardia decide lo stesso, in tutti e due gli ordini di aggancio, e il file che trova già al suo posto in un ambito non provato lo toglie |
| C-180 | fatto | La sottocartella di destinazione è un collegamento simbolico verso fuori: la guardia confronta il percorso vero con `realpath()` e ferma lo spostamento. Nessun byte esce dalla cartella protetta |
| C-181 | fatto | Un documento con il nome dell'esca, con l'ambito già provato e l'esca tolta: il deposito si rifiuta con un codice suo, prima di chiedere niente al server, e nessun file prende il posto dell'esca |
| C-182 | fatto | Un aggancio toglie l'estensione al nome del file, prima nel nome previsto e poi solo durante lo spostamento: in tutti e due i casi il deposito si rifiuta, senza provare l'esca `.txt` al posto di un ambito che non ha estensione, e nessun byte entra |
| C-183 | fatto | La sottocartella del mese è un collegamento verso un'altra sottocartella interna, che il server serve: la guardia pretende che la cartella vera sia quella scritta e ferma lo spostamento. Lo stesso per un collegamento che non punta a niente messo al posto del file: nessun byte passa attraverso |
| C-173 | fatto | Regole riscritte durante la verifica di un ambito: gli esiti conservati si dimenticano tutti, il generale si rimisura, e il deposito successivo riprova il proprio ambito |
| C-170 | fatto | L'esito di un ambito si conserva con la sua chiave: il secondo deposito della stessa estensione nella stessa sottocartella non fa nessuna richiesta, e un'estensione nuova ne fa una |

### Indirizzi

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-126 | fatto | L'indirizzo di consegna ha la stessa forma e funziona sia con la struttura dei permalink vuota sia con una struttura attiva |
| C-127 | fatto | `wp_get_attachment_url()` di un allegato depositato restituisce l'indirizzo di consegna, non il percorso del file |
| C-128 | fatto | Un allegato non depositato attraverso il core non si consegna, **in due casi**: fuori dalla cartella protetta, dove a rifiutare è il controllo sul percorso, e **dentro** la cartella protetta senza la marca, dove a rifiutare deve essere la marca. Il secondo caso è stato aggiunto dopo la prova con i guasti: vedi il punto 13 |

### Consegna pubblica, il caso in cui il file deve arrivare

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-129 | fatto | Atto pubblicato e non scaduto: il file arriva intero, con tipo, lunghezza e nome depositato. **Controllo positivo**: senza, tutte le righe sui rifiuti sarebbero soddisfatte da un punto di consegna che non consegna mai |
| C-130 | fatto | Intestazioni comuni, su consegna e su rifiuto: `no-store`, `private`, `no-cache`, `Expires` nel passato, `nosniff`, `Accept-Ranges: none`, nessun `ETag`, nessun `Last-Modified` |
| C-159 | fatto | Un PDF e un'immagine dell'elenco chiuso escono `inline` con la politica di sicurezza dei contenuti, e senza la direttiva `sandbox`; un SVG esce `attachment` e senza politica. Il nome del file c'è in tutti i casi |
| C-160 | fatto | Tipo dichiarato sull'allegato e tipo dedotto dall'estensione discordi: si esce `attachment` con `application/octet-stream`, mai dentro la pagina |

### Consegna pubblica, i rifiuti

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-131 | fatto | Atto scaduto: non trovato, con il compito pianificato mai eseguito |
| C-132 | fatto | Atto scaduto e richiedente con tutte le capability del tipo, e poi amministratore: non trovato in tutti e due i casi |
| C-133 | fatto | Contenuto inesistente, e allegato inesistente: non trovato |
| C-134 | fatto | Allegato che non appartiene al contenuto dichiarato: non trovato, mentre l'accoppiata giusta consegna |
| C-135 | fatto | Contenuto di un tipo non registrato attraverso il core: non trovato |
| C-136 | fatto | Contenuto in bozza, privato o in attesa di revisione: non trovato |
| C-176 | fatto | Contenuto pubblicato di un tipo che il pubblico non può consultare: il punto pubblico non trova niente, quello amministrativo consegna |
| C-171 | fatto | Allegato con una password propria, diversa da quella del padre: il punto pubblico non trova niente; con la password giusta consegna, e la scadenza continua a valere |
| C-168 | fatto | Allegato cestinato, con il contenuto padre ancora pubblicato e non scaduto: il punto pubblico non trova niente, quello amministrativo consegna |
| C-167 | fatto | Contenuto pubblicato e protetto da password: senza la password non trovato, con la password giusta consegna, e da scaduto non trovato nemmeno con la password |
| C-137 | fatto | File mancante sul disco: non trovato, e nel corpo della risposta non c'è né il percorso né il nome della cartella |
| C-138 | fatto | I nove rifiuti costruibili sono indistinguibili fra loro: stesso stato, stesso corpo, stesse intestazioni |
| C-139 | fatto | Richiesta con `Range`: risposta intera, stato 200, `Accept-Ranges: none`, nessun `Content-Range` |
| C-140 | fatto | Il percorso di consegna non scrive niente e non chiede niente: righe di metadati e di opzioni invariate, stato della protezione intatto, zero richieste HTTP, sia quando consegna sia quando rifiuta |

### Consegna amministrativa

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-141 | fatto | Utente con la capability del tipo: scarica anche l'allegato di un atto scaduto |
| C-142 | fatto | Senza nonce, con nonce inventato, o con il nonce di un altro allegato: rifiutato. Con il proprio: consegna |
| C-143 | fatto | Utente autenticato senza la capability del tipo, e visitatore anonimo: non trovato |
| C-144 | fatto | Durante la consegna pubblica `is_admin()` è falso e la superficie amministrativa non risulta in corso. È la riga che impedisce di spostare un domani il punto pubblico su `admin-post.php` senza accorgersene |

### Scadenza, avvio, contratto

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-145 | fatto | Alla scadenza il file resta sul disco e l'impronta resta leggibile e identica |
| C-147 | fatto | La registrazione della sezione riesce anche con la consegna spenta. **Verde fin dallo scheletro**, e va bene così: verifica l'assenza di una guardia |
| C-153 | fatto | La costante della versione dell'interfaccia vale `1.3.0`, e le sette funzioni nuove esistono tutte |

### Prove di non vacuità

Due girano nella verifica continua, cinque sono righe la cui verifica è la tabella dei
guasti. **La ragione della divisione, e il suo costo, sono al punto 13.** In breve: in S4 ogni
percorso era un aggancio separato, che si spegne da fuori; qui i controlli sono anelli di una
catena dentro una funzione sola, e spegnerne uno da una prova richiederebbe un interruttore
nel codice di produzione. Non è stato messo, per la stessa ragione per cui S4 ha rifiutato il
parametro `$adesso`.

| Riga | Stato | Che cosa si rompe |
|---|---|---|
| C-150 | fatto | Tolto il filtro sull'indirizzo dell'allegato: ricompare il percorso diretto del file |
| C-162 | fatto | Tolto l'aggancio del punto pubblico: la richiesta non produce più nessuna risposta. È la riga che dimostra che tutte le altre passavano davvero dall'aggancio |
| C-148 | fatto (tabella dei guasti) | Guasto G1: tolto il controllo di scadenza dalla catena |
| C-149 | fatto (tabella dei guasti) | Guasto G2: tolto il controllo di appartenenza |
| C-151 | fatto (tabella dei guasti) | Guasto G9: non riscritto il file di regole quando manca |
| C-152 | fatto (tabella dei guasti) | Guasto G3: tolto il controllo sullo stato del contenuto |
| C-161 | fatto (tabella dei guasti) | Guasto G7: ignorato l'esito della verifica nel deposito |

### Che cosa è cambiato in questo elenco fra l'approvazione e la fine

| Riga | Cosa |
|---|---|
| C-118 | Verificava una cartella **non scrivibile**, e lo faceva togliendo i permessi. Le prove girano spesso come amministratore di sistema, dove i permessi non fermano niente: adesso verifica una cartella **non creabile**, e controlla anche il codice dell'errore |
| C-128 | Copriva un caso solo, e quel caso non misurava il controllo della marca ma quello sul percorso. Adesso ne copre due |
| C-138 | Da "i nove rifiuti" a "gli otto rifiuti costruibili": il nono è una guardia difensiva che l'API non permette di raggiungere, e contarlo fra i collaudati sarebbe stato contare una prova che non esiste |
| C-148, C-149, C-151, C-152, C-161 | Da prove della suite a righe verificate dalla tabella dei guasti, con il costo dichiarato |
| C-162 | Nuova. Non era prevista: serve a dimostrare che le prove sui rifiuti passano davvero dall'aggancio del punto pubblico, e non sono verdi perché la richiesta non arriva da nessuna parte |
| C-163 | Nuova, e arrivata dopo: la rilettura del ramo ha trovato che un reindirizzamento all'esca veniva letto come un rifiuto. La riga è stata scritta prima della correzione e vista rossa sul comportamento, con `verificata` al posto di `ignota` |
| C-164, C-165 | Nuove, dal primo giro di revisione indipendente. La correzione della C-163 aveva lasciato in piedi la stessa famiglia con un confine diverso: ogni stato dal 400 in su valeva diniego, e il gettone si guardava dopo lo stato. Tutte e due viste rosse sul comportamento prima della correzione |
| C-167 | Nuova, dal secondo giro di revisione indipendente. Lo stato `publish` non dice che il contenuto si legga: con una password sopra, l'allegato usciva lo stesso dall'indirizzo di consegna. La riga è stata scritta prima della correzione e vista rossa sul comportamento |
| C-138 | Da otto rifiuti confrontati a nove, perché il caso della password è costruibile e va confrontato con gli altri |
| C-171, C-172, C-173 | Nuove, dal quarto giro di revisione indipendente. Sono tre crepe nelle correzioni dei giri precedenti: la password guardata solo sul padre, la sottocartella provata che poteva non essere quella scritta, e la dimenticanza degli esiti che guardava solo la verifica generale. Tutte e tre viste rosse spegnendo il controllo e rieseguendo la suite |
| C-168, C-169, C-170 | Nuove, dal terzo giro di revisione indipendente. La C-168 chiude l'allegato cestinato che continuava a uscire; le altre due chiudono il fatto che il diniego dell'esca `.txt` nella radice veniva letto come una prova su tutta la cartella. Tutte viste rosse prima della correzione, la C-169 con il deposito che riusciva |
| C-157 | Da "un deposito che non riscrive niente non fa nessuna richiesta" a "un deposito in un ambito già provato non fa nessuna richiesta": il primo deposito di ogni estensione nuova adesso ne fa una, ed è il prezzo dichiarato della C-169 |
| C-158 | Il conteggio delle richieste passa da due a tre, per la stessa ragione |
| C-174, C-175, C-176 | Nuove, dal quinto giro di revisione indipendente. Tre crepe nelle correzioni del quarto: l'estensione poteva essere ridotta come lo era la sottocartella, la dimenticanza degli esiti non copriva la riscrittura fallita a metà, e la consegna pubblica guardava lo stato del contenuto ma non la consultabilità del suo tipo. Tutte e tre viste rosse spegnendo il controllo e rieseguendo la suite |
| C-172 | Riscritta nello stesso giro. Verificava che un nome da ridurre facesse rifiutare il deposito, ma calcolava l'indirizzo atteso con la stessa funzione che stava provando, quindi la riduzione le sfuggiva. Adesso scrive gli indirizzi per esteso e verifica tutte e due le direzioni: il nome che si sa chiedere si prova com'è, quello che non si sa chiedere fa rifiutare |
| C-177, C-178 | Nuove, dal sesto giro di revisione indipendente. Due modi diversi di scrivere in un ambito diverso da quello provato: un carattere che l'ancora dell'espressione regolare lasciava passare, e il fatto che il nome calcolato in anticipo era una previsione e non un vincolo. Tutte e due viste rosse spegnendo il controllo e rieseguendo la suite, e la C-178 con il deposito che riusciva |
| C-179, C-180, C-181 | Nuove, dalla rilettura fatta in proprio prima del settimo giro, cercando la forma dei rilievi precedenti nel codice scritto per ultimo. La guardia taceva se un altro componente aveva già risposto all'aggancio; confrontava la destinazione come stringa e non come percorso vero; e il nome dell'esca poteva essere quello di un documento, che la verifica successiva avrebbe sovrascritto. Tutte e tre viste rosse spegnendo il controllo, le prime due con il deposito che riusciva |
| C-182, C-183, C-184 | Nuove, dal settimo giro di revisione indipendente. L'estensione vuota diventava `txt` quando si cercava l'ambito; un collegamento interno alla cartella rendeva lo stesso file raggiungibile da un indirizzo mai provato; e l'origine «caricamento» si controllava all'ingresso ma non sul file che si spostava davvero. Tutte e tre viste rosse spegnendo il controllo, le prime due con il deposito che riusciva |
| C-166 | Nuova, dallo stesso giro. La rimozione del filtro sui caricamenti non stava in un `finally`, quindi un'eccezione sollevata da un aggancio altrui lo lasciava acceso per il resto della richiesta |

### Come si legge il conteggio, e come non si legge

La verifica riporta **219 prove e 1272 asserzioni**, di cui 65 prove nuove. È un **controllo
di esecuzione**: dice che le prove nuove sono state eseguite e non saltate, il che serve
perché un lavoro verde con una prova saltata ha lo stesso colore di uno con la prova passata.
Non è una prova di copertura: che le righe siano coperte lo dimostrano la tracciabilità, cioè
ogni riga con il test che la nomina, e la tabella dei guasti, che mostra le righe diventare
rosse quando il comportamento si rompe.

## 12. Stato dell'implementazione, e che cosa il lavoro ha trovato

**Fatto per intero.** Il perimetro del punto 1 è costruito, le cinquantadue righe di collaudo
sono verdi nella verifica continua, e la tabella dei guasti del punto 13 dice quali righe
misurano davvero che cosa.

Verifica locale sulla versione minima dichiarata, WordPress 6.5: **201 prove e 1099
asserzioni verdi**, PHPCS senza errori e senza avvisi. Le prove nuove sono 47, le altre 154
sono quelle che c'erano.

*La verifica continua esegue tutti e due i rami della matrice, WordPress 6.5 con PHP 8.1 e
l'ultima con PHP 8.3, e per questa unità il secondo ramo non è una formalità: poggia su
`wp_handle_sideload()`, su `_wp_relative_upload_path()` e sul filtro `upload_dir`, cioè su
tre punti di WordPress dove un cambiamento fra una versione e l'altra non produrrebbe un
errore ma un percorso diverso.*

*Il conteggio è un **controllo di esecuzione**, non una prova di copertura: dice che le prove
nuove sono state eseguite e non saltate. Che le righe siano coperte lo dimostrano la
tracciabilità e la tabella dei guasti.*

### Le prove sono andate rosse due volte, e la prima non contava

La prima esecuzione, con le prove scritte e nessuna classe, ha dato 43 errori tutti uguali:
`Class "Conformita_Core_Consegna" not found`. È rosso, e non dimostra niente: un rosso da
classe mancante è compatibile con prove che, esistendo la classe, non verificherebbero nulla.

Sono stati scritti gli **scheletri** delle due classi, metodi presenti e corpi che non fanno
niente, e la seconda esecuzione ha dato 42 rossi **sul comportamento**: 9 errori su chiavi
mancanti nello stato e 33 asserzioni fallite. È il rosso che conta, e solo dopo è stato
scritto il codice vero.

**Una prova era verde fin dallo scheletro, ed è giusto così**: la C-147, che verifica che la
registrazione di una sezione **non** dipenda dall'avvio della consegna. Verifica l'assenza di
una guardia, quindi è verde finché quella guardia non c'è. Detto qui perché una riga verde
prima del codice, se non se ne dice il motivo, sembra una riga che non misura niente.

### Tre cose che il codice ha reso visibili e la scheda non prevedeva

**1. Il percorso memorizzato poteva puntare al file sbagliato, e in silenzio.** La scheda lo
aveva già previsto al punto 2.2, ma solo scrivendo il codice si è visto quanto è stretto il
margine: `_wp_relative_upload_path()` calcola il percorso rispetto alla cartella dei
caricamenti *come la vede in quel momento*. Il filtro che dirotta i byte va spento fra lo
spostamento e la registrazione dell'allegato, e se restasse acceso per due righe in più il
percorso memorizzato sarebbe `2026/09/atto.pdf`, che riletto punta a un file pubblico con lo
stesso nome. Il guasto G10 della tabella mostra che cosa succede: quindici righe rosse.

**2. La verifica non poteva partire prima dell'esca.** Nella prima stesura del codice
`verifica()` faceva la richiesta e basta. Se l'esca non c'era, il server rispondeva 404,
perché il file non esiste, e la verifica leggeva quel 404 come un rifiuto, cioè dichiarava
`verificata` una cartella che non era nemmeno stata creata. Adesso `verifica()` scrive prima
e chiede dopo, e per farlo senza rientrare in sé stessa la scrittura delle regole è un passo
separato dalla preparazione.

**3. Due prove nelle prove, non nel codice.** `wp_upload_dir()` tiene una memoria interna dei
percorsi che ha già creato: la pulizia fra una prova e l'altra cancellava le cartelle, e
quella memoria faceva saltare la ricreazione, così lo spostamento dei byte falliva per un
motivo che non riguardava la consegna. Adesso la pulizia toglie i file e lascia le cartelle.
E la riga C-118, che verifica il rifiuto quando la cartella non si può creare, lo faceva
togliendo i permessi di scrittura: le prove girano spesso come amministratore di sistema, e i
permessi non lo fermano, quindi la riga sarebbe stata verde per il motivo sbagliato. Adesso
sposta i caricamenti sotto un percorso che nessuno può creare.

*È la stessa famiglia dei due errori di percorso registrati al punto 13 della scheda di S4:
prove che diventano rosse, o verdi, per motivi che non c'entrano con quello che misurano.*

---

## 13. La tabella dei guasti: quale riga misura che cosa

Trentuno guasti, introdotti **uno alla volta** in una copia del repository presa fuori dal
controllo di versione, con la suite eseguita per intero dopo ognuno. Un guasto che non fa
diventare rossa nessuna riga è una riga di collaudo che stava misurando qualcos'altro.

*I primi undici sono della passata originale. Le correzioni arrivate dopo il primo giro di
revisione non l'hanno fatta rifare, perché nessuna tocca la catena di controlli della
consegna: cambiano la lettura della risposta dentro `verifica()` e la forma di `deposita()`, e
ognuna ha la sua riga verde nella suite, vista rossa prima della correzione. I guasti dal G12 al G31 sono invece gli
anelli e i controlli nati dai giri di revisione, e sono stati misurati uno per uno: il G12 e
il G13 come stato in cui il ramo si trovava prima della correzione, con le righe viste rosse
lì; dal G14 al G31 spegnendo davvero il controllo e rieseguendo la suite. Il G14 ha dato
quattro righe rosse, fra cui la C-169 con il deposito che riusciva; il G16 la C-172, anche lì
con il deposito che riusciva. Dal G18 al G26 ognuno ha fatto diventare rossa una riga sola, e
solo la sua; il G23, il G24, il G25 e il G26 con il deposito che riusciva, e nel G25 con il
file che usciva dalla cartella protetta attraverso il collegamento. Dal G27 al G31 ognuno è
stato eseguito sulla sua riga: tutti e cinque l'hanno fatta diventare rossa, il G27, il G28 e
il G29 con il deposito che riusciva. La suite intera, con quei cinque, non è stata rieseguita
guasto per guasto. **Gli altri undici non
sono stati rieseguiti**, e il costo è dichiarato: le correzioni aggiungono anelli e non ne
cambiano nessuno, ma che gli undici continuino a misurare quello che misuravano è
un'inferenza, non una cosa vista.*

### Che cosa ha trovato il primo giro di revisione indipendente

Il ramo è stato letto da un revisore indipendente su tre domande: la catena di controlli del
punto pubblico, l'esenzione dell'amministrazione, e la lettura della risposta all'esca. Le
prime due hanno retto: la catena usa `realpath()` con il separatore finale e non si aggira con
un prefisso simile o un collegamento simbolico; il punto amministrativo pretende nonce e
capability, e quello pubblico applica la scadenza qualunque cosa dica `is_admin()`.

La terza ha prodotto due rilievi, tutti e due accolti, e sono le righe C-164, C-165 e C-166.
Vale la pena dire che cosa hanno in comune con il difetto del reindirizzamento trovato
rileggendo il ramo: **è sempre la stessa famiglia**, cioè una risposta che non dimostra niente
letta come se dimostrasse che la cartella è protetta. Correggere un caso della famiglia non
chiude la famiglia, ed è il motivo per cui il confine adesso è un elenco chiuso di due stati e
non un intervallo: un elenco si allarga solo di proposito, un intervallo si allarga da sé ogni
volta che il mondo inventa uno stato nuovo.

Un suggerimento del revisore non è stato seguito: teneva il `401` fra i dinieghi. Il motivo
per cui è fuori è nel riquadro del punto 1.2, e il costo della scelta è dichiarato lì.

| Guasto | Che cosa ho rotto | Righe diventate rosse |
|---|---|---|
| G1 | Tolto il controllo di scadenza dalla catena | C-131, C-132, C-138, C-145 |
| G2 | Tolto il controllo di appartenenza fra allegato e contenuto | C-134, C-138 |
| G3 | Tolto il controllo sullo stato del contenuto | C-136, C-138 |
| G4 | Tolto il controllo della marca di deposito | C-128 |
| G5 | Reso il rifiuto diverso quando il contenuto esiste | C-138 |
| G6 | Servito ogni tipo dentro la pagina | C-159, C-160 |
| G7 | Ignorato l'esito della verifica nel deposito | C-119, C-120, C-158 |
| G8 | Ignorato il gettone nella lettura della risposta | C-119, C-155, C-158 |
| G9 | Non riscritto il file di regole quando manca | C-116, C-117, C-158 |
| G10 | Lasciato acceso il filtro sui caricamenti durante la registrazione | C-115, C-117, C-126, C-129, C-131, C-134, C-139, C-140, C-141, C-142, C-157, C-158, C-159, C-160, C-162 |
| G11 | Tolto il filtro sull'indirizzo dell'allegato | C-127, C-150 |
| G12 | Tolto il controllo sulla password del contenuto | C-138, C-167 |
| G13 | Tolto il controllo dello stato dell'allegato | C-168 |
| G14 | Tolto il controllo dell'ambito nel deposito | C-157, C-158, C-169, C-170 |
| G15 | Tolto il controllo della password propria dell'allegato | C-171 |
| G16 | Tolto il controllo sulla provabilità della sottocartella | C-172 |
| G17 | Tolta la dimenticanza degli esiti alla riscrittura delle regole | C-173 |
| G18 | L'estensione dell'ambito ricostruita a mano invece di chiederla a chi deciderà il nome del file | C-174 |
| G19 | La dimenticanza degli esiti rimessa in chi chiama, cioè dopo l'uscita per errore | C-175 |
| G20 | Tolto il controllo sulla consultabilità pubblica del tipo | C-176 |
| G21 | Rimessa la riduzione distruttiva del nome della sottocartella, con la convalida spenta | C-172 |
| G22 | Rimesse le ancore `^` e `$` al posto di `\A` e `\z` nella convalida | C-177 |
| G23 | Tolta la guardia sul percorso definitivo dello spostamento | C-178 |
| G24 | La guardia rimessa a priorità normale, e zitta quando trova una risposta pronta | C-179 |
| G25 | Il confronto della destinazione rimesso sulle stringhe, senza `realpath()` | C-180 |
| G26 | Tolto il controllo sul nome riservato, nel deposito e nella guardia | C-181 |
| G27 | L'estensione vuota rimessa a `txt` quando si cerca l'ambito | C-182 |
| G28 | Tolto il confronto fra la cartella vera e quella scritta | C-183 |
| G29 | Tolto il controllo sul collegamento al posto del file | C-183 |
| G30 | Tolta la domanda `is_uploaded_file()` nella guardia | C-184 |
| G31 | Il deposito non dice alla guardia quale origine ha dichiarato | C-184 |

### Che cosa ha trovato il secondo giro di revisione indipendente

Il secondo giro ha confermato che le correzioni del primo chiudono i rilievi, e che
l'esclusione del `401` regge: il revisore l'ha letta come coerente con la scelta di rifiutare
nel dubbio. Ha però trovato un difetto nuovo, ed è nel primo anello della catena di controlli,
cioè nella parte che il primo giro aveva dichiarato solida.

**Lo stato `publish` non dice che il contenuto si legga.** Un contenuto con una password è
pubblicato, quindi l'anello sullo stato lo lascia passare, ma la sua pagina non mostra niente
a chi la password non ce l'ha. L'allegato, invece, usciva: l'indirizzo di consegna chiede due
numeri e nessuna password. È la riga C-167, scritta prima della correzione e vista rossa
insieme alla C-138, che qui fa da secondo testimone perché il caso è un rifiuto costruibile e
va confrontato con gli altri.

Vale la pena notare dove il difetto stava, perché è diverso dai tre di prima. Quelli erano
tutti nella verifica della protezione, ed erano la stessa famiglia: una risposta che non
dimostra niente letta come prova. Questo sta nella catena della consegna, ed è un'altra
famiglia: **un anello che controlla una proprietà vicina a quella che serve.** `publish`
risponde a "è pubblicato?", la domanda era "è leggibile da chiunque?", e le due coincidono in
tutti i casi tranne uno. Il guasto G12 della tabella misura proprio questo anello.

### Che cosa ha trovato il terzo giro di revisione indipendente

Due rilievi, tutti e due accolti, e tutti e due in punti che i giri precedenti avevano
guardato senza trovare niente.

**L'allegato cestinato continuava a uscire.** La catena controllava lo stato del contenuto
padre e non quello dell'allegato. Con `MEDIA_TRASH` dichiarata, cestinare un allegato non
cancella niente: restano il file, la marca del deposito e il padre, e cambia soltanto lo stato
dell'allegato. Un indirizzo di consegna vecchio serviva un documento ritirato mentre il suo
atto era ancora pubblicato e non scaduto. È la riga C-168, e l'anello nuovo ammette `inherit`
invece di elencare gli stati da rifiutare, per la stessa ragione per cui i dinieghi dell'esca
sono un elenco chiuso: un elenco di ammessi non si allarga da sé quando WordPress inventa uno
stato nuovo.

**Il diniego dell'esca provava meno di quello che autorizzava.** È il rilievo che vale il
giro, ed è descritto per esteso al punto 1.2. In breve: l'esca è un `.txt` nella radice, i
documenti sono PDF dentro `2026/09`, e un server può benissimo negare il primo percorso e
servire i secondi. Adesso l'esito si conserva per ambito, e il deposito prova il proprio prima
di scrivere.

Vale la pena notare la forma che questi due rilievi hanno in comune con quello del giro
precedente, perché è la terza volta di fila: **un controllo che guarda una proprietà vicina a
quella che serve.** `publish` sul padre al posto di "questo allegato è consegnabile"; il
diniego di un percorso al posto del diniego della cartella. Non sono errori di scrittura, sono
errori di domanda, e non è un caso che a trovarli sia stato un lettore esterno: chi ha scritto
il codice sa che cosa intendeva chiedere, e rilegge la riga come se lo chiedesse davvero.

### Che cosa ha trovato il quarto giro di revisione indipendente

Tre rilievi, accolti tutti e tre. Nessuno è una scoperta nuova sul piano del disegno: sono tre
crepe nelle correzioni dei giri precedenti, e questo di per sé dice qualcosa su quanto sia
facile chiudere male un buco che si è appena capito.

**La password si guardava solo sul padre.** `post_password_required()` risponde sul contenuto
che le si passa e non risale dal file all'atto, quindi la domanda sul padre non rispondeva per
l'allegato, che una password propria può averla. Riga C-171, e adesso si guardano tutti e due.

**La sottocartella provata poteva non essere quella scritta.** La riduzione del nome serve a
costruire un percorso e un indirizzo senza sorprese, ma chi sposta i byte usa il nome vero: se
un aggancio di un altro componente mette nella sottocartella un carattere che la riduzione
toglie, si prova un percorso e se ne scrive un altro. È lo stesso difetto della C-169 per
un'altra via. Adesso il deposito si rifiuta, con un codice suo, quando i due nomi non
coincidono; e l'istante si fissa una volta sola e si passa a `wp_handle_sideload()`, così la
sottocartella provata è quella scritta anche la notte del primo del mese. Riga C-172.

**La dimenticanza degli esiti guardava solo la verifica generale.** Se a riscrivere le regole
era la verifica di un ambito, gli esiti vecchi sopravvivevano, incluso quello generale, e il
deposito successivo trovava le regole a posto e riusava un giudizio che parlava di una
configurazione cambiata due volte. Adesso qualunque riscrittura fa dimenticare tutto e
rimisurare subito il generale. Riga C-173.

### Che cosa ha trovato il quinto giro di revisione indipendente

Tre rilievi, accolti tutti e tre. Di nuovo crepe nelle correzioni del giro prima, e di nuovo
della stessa famiglia: un controllo che guarda una proprietà vicina a quella che serve.

**L'estensione si riduceva come si riduceva la sottocartella.** La correzione del quarto giro
aveva tolto la riduzione dal nome della cartella e l'aveva lasciata sull'estensione: un file
`atto.pdf-x`, su un'installazione che ammette quel tipo, veniva provato come `.pdfx` e scritto
come `.pdf-x`. Un server che nega la prima forma e serve la seconda avrebbe quindi ricevuto il
documento in un percorso aperto, con l'ambito che risultava verificato. Adesso l'estensione non
si ricostruisce: si chiede a `wp_unique_filename()`, che è la stessa funzione che deciderà il
nome del file pochi istanti dopo. Da lì è venuto fuori anche il contrario di quello che la
scheda dava per buono: WordPress **abbassa** le maiuscole dell'estensione, quindi provare
`.PDF` perché così si chiama il file in arrivo sarebbe stato sbagliato allo stesso modo. Riga
C-174.

**La dimenticanza degli esiti non copriva la riscrittura fallita a metà.** I file di regole si
scrivono uno dopo l'altro: se il primo viene riscritto e il secondo non si riesce a scrivere,
chi ha chiamato vede solo l'errore. Con la dimenticanza in chi chiama, gli esiti conservati
restavano validi su una cartella riscritta a metà, e bastava che qualcuno rimettesse a mano il
file mancante perché il deposito successivo non trovasse più differenze e riusasse un giudizio
vecchio. Adesso la dimenticanza sta al primo file scritto davvero, dentro la funzione che
scrive. Riga C-175.

**La consegna pubblica guardava lo stato del contenuto e non la consultabilità del suo tipo.**
Essere registrato attraverso il core e stare in `publish` non vuol dire che le pagine di quel
tipo si aprano: un tipo può nascere non consultabile, e un altro componente può renderlo tale
con `register_post_type_args`. In quel caso le pagine non si aprivano e gli allegati sì. Adesso
il ramo pubblico chiede anche `is_post_publicly_viewable()`, e il controllo su `publish` resta
dove stava, perché due anelli che guardano cose diverse restano due anelli. Riga C-176.

**Una cosa che il giro ha fatto vedere sulle prove, non sul codice.** La C-172, scritta al giro
prima, calcolava l'indirizzo che si aspettava chiamando la stessa funzione che stava provando:
con la riduzione rimessa al suo posto, l'indirizzo atteso si riduceva insieme a quello vero e
la prova restava verde. Era una prova che non misurava niente, e se ne è accorto il guasto G21,
non la lettura. Adesso gli indirizzi attesi si scrivono per esteso. Vale come regola: **una
prova non chiede al codice sotto esame di dirle che cosa aspettarsi.**

### Che cosa ha trovato il sesto giro di revisione indipendente

Due rilievi, accolti tutti e due. Tutti e due nella terza domanda, e tutti e due lo stesso
difetto per due vie diverse: **scrivere in un ambito diverso da quello provato.**

**L'ancora dell'espressione regolare accettava un a capo finale.** In PCRE il dollaro non
vuol dire "fine della stringa" ma "fine della stringa, oppure appena prima di un a capo
finale". Una sottocartella che finisse con un a capo passava quindi la convalida: il disco
quel carattere lo conserva, l'indirizzo chiesto al server lo perde per strada, e si torna a
provare un percorso e a scriverne un altro. Adesso le ancore sono `\A` e `\z`. Riga C-177.

**Il nome calcolato in anticipo era una previsione, non un vincolo.** La correzione del
quinto giro chiedeva il nome definitivo alla stessa funzione che lo decide, il che è la
previsione più fedele che si possa fare da fuori, ma resta una previsione: dentro
`wp_handle_sideload()` c'è un aggancio con cui un altro componente può cambiare il nome, e
l'estensione con lui, **dopo** quel calcolo. Un componente che rinomina `atto.pdf` in
`atto.pdf-x`, su un server che nega i primi e serve i secondi, faceva provare un ambito
negato e scrivere in uno aperto. Adesso l'ultima parola ce l'ha una guardia che gira sul
percorso definitivo, sull'ultimo aggancio prima che i byte si muovano, e che ferma lo
spostamento quando l'ambito vero non risulta provato. Riga C-178.

**Una nota su come si ferma.** L'aggancio da cui la guardia decide non ha un modo di dire
«rifiuta»: restituire un valore diverso da `null` fa saltare la copia ma non ferma la
sequenza, che poi prova a dare i permessi a un file che non esiste. Si esce con
un'eccezione di un tipo dedicato, che `deposita()` raccoglie e converte nell'errore con il
motivo vero. Il tipo dedicato non è pedanteria: serve a distinguere «l'ho fermata io» da
un'eccezione sollevata dall'aggancio di qualcun altro, che è il caso della riga C-166 e va
lasciata passare.

### Che cosa ha trovato la rilettura in proprio prima del settimo giro

Dopo sei giri in cui otto rilievi su tredici erano caduti nel codice scritto per ultimo, la
domanda ovvia era se convenisse rileggere quel codice da soli, con la stessa forma di
domanda del revisore, prima di pagare un settimo giro. Tre cose, tutte nella guardia sulla
destinazione, cioè nel pezzo scritto per ultimo.

**La guardia taceva se qualcun altro aveva già risposto.** L'aggancio su cui sta serve anche
ai componenti che spostano i file per conto proprio, per esempio verso un deposito esterno:
rispondono con un valore e WordPress non copia più niente. La guardia, trovando una risposta
pronta, la lasciava passare senza guardare la destinazione. Adesso si aggancia per prima,
alla priorità più bassa che esiste, e decide comunque; e se trova il file già al suo posto,
messo lì da chi ha risposto prima di lei, lo toglie. Riga C-179.

**La destinazione si confrontava come stringa.** Il percorso cominciava per quello della
cartella protetta, e questo bastava. Non basta: una sottocartella può essere un collegamento
simbolico verso un'altra parte del disco, e il nome resta lo stesso. La consegna fa il
confronto con `realpath()` prima di leggere; la guardia adesso lo fa prima di scrivere, sulla
cartella di destinazione, che esiste già mentre il file no. Riga C-180.

**Il nome dell'esca poteva essere quello di un documento.** Con l'ambito già provato nessuno
riscrive l'esca prima di muovere i byte; se nel frattempo l'esca era stata tolta, un documento
con il suo nome prendeva il suo posto, e la verifica successiva, trovando un contenuto
diverso da quello atteso, lo avrebbe sovrascritto con la propria riga di prova. Non è un
file che esce: è un atto che sparisce senza errore. Il nome è riservato, e si rifiuta nel
deposito e nella guardia. Riga C-181. La sovrascrittura non è stata vista, è dedotta da
`scrivi_esca()`: la prova verifica il rifiuto, non il danno.

Le prime due hanno la forma di sempre: una proprietà vicina a quella che serve (una
risposta pronta al posto di una destinazione provata; un nome che comincia bene al posto di
un percorso che sta davvero dentro). La terza è di un'altra famiglia, un nome condiviso fra
due cose che non devono condividerlo.

### Che cosa ha trovato il settimo giro di revisione indipendente

Tre rilievi, accolti tutti e tre. Tutti e tre nel deposito, e due dentro le correzioni del
giro prima o della rilettura in proprio. Nessun aggiramento nuovo della catena pubblica, del
nonce o della capability amministrativa.

**L'estensione vuota diventava `txt`.** Una funzione di servizio trasformava l'estensione
vuota in `txt`, comoda per indicare l'esca generale. Ma un aggancio sul nome deciso da
WordPress può togliere l'estensione dopo che il tipo del file è stato controllato, e allora
il file `atto` veniva giudicato con l'esca `.txt`: su un server che nega i `.txt` e serve i
file senza estensione, il deposito riusciva e il documento era pubblico. Adesso l'estensione
vuota resta vuota, non si sa provare, e il deposito si rifiuta, nel nome previsto e nella
guardia. `txt` resta solo come valore predefinito esplicito della verifica. Riga C-182.

**Un collegamento interno apriva un secondo indirizzo.** La riga C-180 controllava che la
cartella vera stesse dentro quella protetta, ma ricavava l'ambito dal percorso scritto. Se la
sottocartella del mese è un collegamento a un'altra sottocartella interna, il controllo passa,
l'esca chiesta dal percorso scritto torna negata, e lo stesso file si scarica dall'altro
percorso, che nessuno ha provato. Adesso la cartella vera deve essere esattamente la radice
vera più la sottocartella scritta. **Trovato rileggendo lo stesso punto**, e chiuso nella
stessa riga: un collegamento che non punta a niente, messo al posto del file, fa sembrare
libero il nome, e la copia scriverebbe dove punta. Anche quello si ferma. Riga C-183.

**L'origine si controllava all'ingresso, non sul file che si spostava.** Fra il controllo
`is_uploaded_file()` e lo spostamento c'è un aggancio con cui un altro componente può
sostituire il file caricato con un file locale qualunque, e la guardia guardava solo la
destinazione. Adesso il deposito dice alla guardia quale origine ha dichiarato, e per un
caricamento la guardia rifà la domanda sul file che WordPress sta per copiare, prima di
guardare la destinazione. Riga C-184.

*Due cose dette apertamente su questa riga.* La prima: da riga di comando un caricamento vero
non si può creare, quindi un deposito con origine `caricamento` si ferma sempre all'ingresso e
non arriva mai alla guardia. La prova fa due cose separate, e lo dice: dentro un deposito vero
controlla che la guardia conosca l'origine dichiarata, e poi chiama la guardia direttamente,
con l'origine `caricamento` e un file locale, che è esattamente quello che vedrebbe dopo la
sostituzione. La seconda: il revisore suggeriva anche di passare ai caricamenti HTTP la
funzione di WordPress pensata per loro, che sposta invece di copiare. Non è stato fatto: quella
strada, per la stessa ragione, nessuna prova la potrebbe mai percorrere, e un ramo di codice
che nessuna prova percorre è quello in cui il giro successivo troverebbe il difetto. La
guardia chiude il rilievo da sola, perché vede il file che verrà copiato e nessun aggancio
viene dopo di lei a cambiarlo.

La forma è ancora quella di sempre: una proprietà vicina a quella che serve. «Nessuna
estensione» letto come «l'estensione predefinita»; una cartella che sta dentro al posto di
una cartella che è quella scritta; il file controllato all'ingresso al posto di quello che si
sposta. Quattordici rilievi su diciannove, contando la rilettura in proprio.

### Due guasti su undici non hanno fatto diventare rossa nessuna riga, alla prima passata

Sono il motivo per cui questa tabella esiste, e vanno raccontati.

**G4, il controllo della marca: nessuna riga rossa.** La riga C-128 doveva coprirlo, e non lo
copriva. L'allegato estraneo che usava era un allegato creato dalla suite con un file
in `uploads/estraneo.pdf`, cioè **fuori** dalla cartella protetta: a rifiutarlo era l'ultimo
anello della catena, quello sul percorso normalizzato, e il controllo della marca non
entrava mai in gioco. La prova era verde per il motivo sbagliato.

Il caso che conta è un altro: un allegato che punta **dentro** la cartella protetta ma non
porta la marca, cioè un file arrivato lì da una migrazione o da un altro componente. Lì il
controllo sul percorso non basta. La riga C-128 è stata riscritta con tutti e due i casi, e
con il secondo il guasto G4 la fa diventare rossa. **Non è stato corretto il codice: è stata
corretta la prova**, che misurava meno di quello che diceva.

**G5, i rifiuti distinguibili: nessuna riga rossa alla prima passata.** Qui il difetto era nel
guasto, non nella prova. Il primo tentativo aggiungeva al corpo del rifiuto una traccia di
esecuzione, che per tutti i rifiuti risulta identica, perché il percorso di chiamata è lo
stesso: il guasto non rendeva distinguibile niente. Rifatto come un'intestazione diagnostica
aggiunta solo quando il contenuto esiste, che è la scorciatoia che un domani qualcuno
aggiungerebbe davvero per capire perché un file non esce, la riga C-138 diventa rossa.

Vale la pena dirne il motivo, perché è una proprietà della forma del codice e non delle
prove: **il rifiuto è costruito da una funzione senza argomenti**, quindi non ha niente da cui
variare. L'uniformità delle risposte non è una cosa che le prove sorvegliano, è una cosa che
la struttura rende difficile rompere. Le prove servono per il giorno in cui qualcuno le dà
un argomento.

### Le prove di non vacuità che girano nella verifica continua sono due, non sei

La prima stesura di questa scheda ne prometteva sei, una per famiglia di controllo, sul
modello delle cinque di S4. Quel modello qui non si applica per intero, e la ragione è che
in S4 ogni percorso era un **aggancio** separato, che si spegne da fuori con
`remove_filter()`, mentre qui i controlli sono gli anelli di una catena dentro una funzione
sola.

Spegnerne uno da una prova richiederebbe un interruttore nel codice di produzione. **Non è
stato messo, ed è la stessa decisione che la scheda di S4 ha preso sul parametro `$adesso`**:
un interruttore che spegne il controllo di scadenza, per quanto marcato come interno, è una
riga che prima o poi qualcuno chiama per far sembrare non scaduto qualcosa che lo è.

Quindi: le due che si fanno **da fuori**, spegnendo un aggancio vero, sono prove della suite e
girano a ogni verifica continua, e sono C-150 e C-162. Le altre sono righe di catalogo la cui
verifica è la tabella dei guasti qui sopra: C-148 (guasto G1), C-149 (G2), C-151 (G9), C-152
(G3), C-161 (G7).

**Il costo di questa scelta è dichiarato e non è nullo:** quelle cinque righe non girano a
ogni push, quindi un domani qualcuno può indebolire un anello della catena senza che la
verifica continua diventi rossa. Quello che le protegge sono le righe C-131..C-138, che
girano sempre e che i guasti G1, G2, G3 e G7 dimostrano sensibili. La tabella si rifà quando
la catena cambia.

---

## 14. Segnalazioni fuori perimetro

Cose viste lavorando, che **non** si correggono in questa unità perché la decisione non è mia.

**1. `docs/requisiti.md` non esiste in questo repository, e due documenti ci rinviano.**
`CLAUDE.md` dice *"l'elenco completo, con fonte e criterio di verifica per ciascuno, è in
`docs/requisiti.md`"*, al presente. `README.md` è più prudente e dice *"sarà pubblicato in
`docs/requisiti.md`"*, al futuro. Il file non c'è. Ne discende anche che la regola di lavoro
*"ogni requisito di `docs/requisiti.md` ha almeno un test che lo verifica"* oggi non è
applicabile alla lettera, e che il collaudo di questo repository vive per intero nelle schede
di `docs/implementazione/`, come questa. Non creo il file e non tolgo il rinvio: sono due
decisioni diverse (scrivere i requisiti, oppure allineare le due frasi allo stato vero) e
tocca a chi le deve prendere.

**2. L'azione `conformita_core_pronto` continua a non esistere.** Il punto 5 della scheda di
S4 la descrive come il momento in cui i componenti si agganciano, e il punto 13 della stessa
scheda la registra come "da fare, e non è di questo blocco". È ancora da fare. Questa unità
non ne ha bisogno, perché la consegna si accende al caricamento di core come il motore di
scadenza; la segnalo perché il numero di unità che la danno per esistente cresce, e perché
oggi un componente non ha nessun momento dichiarato a cui agganciarsi.

---

## 15. Che cosa l'albo dovrà fare, adesso che questa unità esiste

Non in questa unità e non in questo repository: è scritto qui perché chi legge la scheda sappia
qual è la conseguenza.

- Fissare in `bin/installa-core.sh` e in `.wp-env.json` la revisione di `wp-conformita-core`
  che contiene questa unità.
- Portare `CORE_API_RICHIESTA` da `1.2.0` a `1.3.0`.
- Chiamare `conformita_core_deposita_allegato()` invece di caricare i file per conto proprio,
  e stampare `conformita_core_indirizzo_consegna()` invece dell'indirizzo del file.
- Decidere se e come mostrare in bacheca lo stato della protezione, che core espone e non
  mostra.
- Aggiungere al proprio collaudo la riga C-33, l'indirizzo diretto dell'allegato, che oggi è
  nominata in `collaudo.md` come parte dell'elenco chiuso e non ha ancora un test.
