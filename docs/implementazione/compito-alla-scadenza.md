# Compito alla scadenza e battito: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S6 del piano, righe di collaudo C-40..C-42 e
C-44 del catalogo, dettagliate in C-318..C-324. Versione dell'interfaccia da `1.14.0` a
`1.15.0`. L'avviso al responsabile quando il battito invecchia (C-43) è una lavorazione
successiva.

**Stato: costruita, righe C-318..C-324 verdi in locale, corretta dopo il primo giro di revisione.** Le sezioni dicono quello che il
codice fa.

---

## In tre paragrafi, senza termini tecnici

**Cosa costruiamo.** Quando un atto scade, sparisce subito dalla vista del pubblico: lo fa un
controllo che gira a ogni lettura, e non ha bisogno di nient'altro. Nell'amministrazione però
l'atto risulta ancora "pubblicato", finché qualcuno non lo segna come concluso. Questo lavoro
aggiunge chi lo segna: un compito che gira da solo ogni ora, trova i contenuti scaduti e chiede
al componente di fare il suo passaggio, per esempio "defisso" per l'albo. Ogni volta che il
compito finisce lascia un segno con l'ora, il battito, così si vede se sta girando.

**Come lo costruiamo.** Il componente comune non decide che cosa fare: ogni componente dichiara,
per una sua sezione, da quali stati prendere i contenuti scaduti e quale azione fare su
ciascuno. Senza dichiarazione il compito non tocca niente. Il compito è un compito di WordPress
programmato ogni ora, che parte quando qualcuno chiama `wp-cron.php`. Sul server lo chiama il
cron di sistema, con il cron interno di WordPress spento, perché quello da solo parte solo
quando il sito ha visite. Due esecuzioni insieme non lavorano mai: la seconda trova il lucchetto
preso e rinuncia. Il compito lavora a gruppi di 50 e si ferma a 500 contenuti per volta. Il
resto lo prende l'esecuzione successiva. Un contenuto che dà errore non ferma gli altri.

**Come si prova che funziona, e come si prova che il test è vero.** Le prove fissano l'orologio
e creano contenuti scaduti ieri e anni fa, insieme ad altri che non vanno toccati: quelli che
scadono oggi, quelli futuri, quelli in un altro stato o di un'altra sezione, quelli senza data o
con una data rotta. Controllano che l'azione arrivi solo ai primi, una volta sola anche con due
esecuzioni, e mai mentre un'altra esecuzione tiene il lucchetto. I guasti introdotti a mano
sono nella tabella in fondo.

## 1. File

- `includes/class-conformita-core-compito-scadenza.php`: registrazione, esecuzione, battito.
- `includes/funzioni-api.php`: tre funzioni pubbliche.
- `conformita-core.php`: il compito programmato all'avvio e tolto alla disattivazione,
  interfaccia `1.15.0`.
- `tests/compito-scadenza-test.php`: C-318..C-324.

## 2. Requisito servito

Il meccanismo di ALBO-03 (lo stato dell'atto scaduto aggiornato senza dipendere dal traffico)
e la traccia che serve ad ALBO-19 (il battito). La visibilità al pubblico non dipende da
questo lavoro: resta del filtro di lettura (ALBO-18).

## 3. Le funzioni pubbliche

| Funzione | Che cosa fa |
|---|---|
| `conformita_core_registra_azione_alla_scadenza( $sezione, $stati, $azione )` | dichiara per una sezione registrata gli stati da cui prendere gli scaduti e l'azione, che riceve l'identificativo e restituisce vero o un errore; una sola per sezione, nessun valore predefinito |
| `conformita_core_esegui_compito_scadenza()` | esegue adesso; restituisce `contenuti`, `falliti`, `completo`, oppure un errore se un'altra esecuzione è in corso o la lettura fallisce |
| `conformita_core_battito_compito_scadenza()` | l'ultima esecuzione arrivata in fondo: `istante` (UTC), `contenuti`, `falliti`, `completo`; nulla se non ce n'è stata. Nessun dato dei contenuti |

## 4. Le scelte

| Domanda | Scelta | Perché |
|---|---|---|
| Quali contenuti | dei tipi della sezione, in uno degli stati dichiarati, con data di fine anteriore al giorno corrente nel fuso del sito | è la stessa condizione del filtro di lettura: un contenuto è scaduto il giorno dopo la data di fine |
| Data assente o rotta | non si tocca | il filtro la tratta come scaduta per prudenza; un passaggio di stato su un dato rotto non lo decide nessuno |
| Conferma prima dell'azione | si rilegge lo stato dalla banca dati e la data | fra la lettura del gruppo e l'azione un altro può aver cambiato il contenuto |
| Concorrenza | lucchetto comune, attesa zero | la seconda esecuzione non aspetta: la prossima ora c'è un'altra esecuzione |
| Quanti | gruppi da 50, al più 500 per sezione in un'esecuzione | un'esecuzione resta breve; `completo` falso dice che potrebbero restarne; una sezione non toglie il turno all'altra |
| Da dove riparte | ogni sezione ricorda l'ultimo contenuto provato quando si è fermata al massimo; l'esecuzione dopo riparte da lì e poi torna da capo fino a quel punto; a giro finito si riparte da capo | contenuti che non si risolvono mai, se sono tanti, non tolgono il turno a quelli dopo: in un numero finito di esecuzioni ogni scaduto viene provato |
| Azione che fallisce o si guasta | contata in `falliti`, si passa al contenuto dopo; la riprova l'esecuzione successiva | un contenuto difettoso non ferma gli altri |
| Voce nel registro | la scrive il registro automatico, sul cambio di stato fatto dall'azione con WordPress | non serve una seconda voce, che direbbe la stessa cosa |
| Battito | scritto solo da un'esecuzione arrivata in fondo, anche con azioni fallite | dice che il compito gira; i `falliti` dicono se qualcosa non va |
| Da fuori | compito di WordPress ogni ora; sul server lo chiama il cron di sistema su `wp-cron.php` | funziona anche dove non c'è WP-CLI, e il cron interno spento non lo ferma |

**Quando arriva il compito.** La data di fine è un giorno intero e l'atto scade alla
mezzanotte che lo segue, nel fuso del sito: non c'è una scadenza a metà giornata. La lettura
del gruppo confronta le date come testo (`fine < oggi`), ed è la stessa condizione. L'atto che
scade stanotte lo prende la prima esecuzione dopo la mezzanotte; fino ad allora, e comunque
dalla mezzanotte, il pubblico non lo vede già per il filtro di lettura.

## 5. Dati e permessi

Due opzioni nuove, `conformita_core_battito_scadenza` e
`conformita_core_compito_scadenza_segnaposto`, non caricate a ogni richiesta. Nessun
permesso nuovo: il compito non ha superfici registrate.

## 6. Modi di guasto previsti

- Contenuto toccato prima della scadenza: C-319.
- Contenuto di un'altra sezione, di un altro stato, senza data o con data rotta: C-319.
- Doppia esecuzione o concorrente: C-320.
- Un contenuto difettoso che blocca gli altri: C-321.
- Un contenuto cambiato mentre il compito lavora: C-321.
- Contenuti che non si risolvono mai e tengono fermi quelli dopo, o una sezione che tiene ferma l'altra: C-324.
- Battito scritto da un'esecuzione fallita: C-322.
- Compito non programmato, o non agganciato: C-323.

## 7. Prove

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-318 | fatto | Senza registrazione il compito non tocca niente. Registrazione rifiutata per sezione non registrata, stati vuoti o non in elenco o non validi, azione non chiamabile; la seconda registrazione della stessa sezione non sovrascrive la prima |
| C-319 | fatto | Con l'orologio al 10 ottobre: l'azione arriva solo agli scaduti del 9 ottobre e dell'anno prima; non a quello che scade oggi, al futuro, alla bozza, a quello di un'altra sezione, a quello senza data, a quello con la data rotta, a un articolo di nessuna sezione. Il cambio di stato lascia la voce di rimozione nel registro |
| C-320 | fatto | Con il lucchetto tenuto da un'altra connessione: errore, nessuna azione, nessun battito. Poi un'esecuzione tocca il contenuto, la seconda non trova niente, nessuna voce in più; il lucchetto è lasciato |
| C-321 | fatto | Un'azione che restituisce errore e una che lancia un'eccezione: gli altri sessanta (più di un gruppo) passano, i due contano come falliti e l'esecuzione dopo riprova solo loro. Un contenuto riportato in bozza mentre si lavora su un altro non riceve l'azione. Con 501 scaduti un'esecuzione ne fa 500 e dice di non aver finito, la successiva prende l'ultimo |
| C-322 | fatto | Prima di ogni esecuzione nessun battito; dopo, l'istante dell'orologio e i conteggi. Con la lettura guasta: errore, e il battito resta quello di prima |
| C-323 | fatto | Programmato ogni ora all'avvio, una volta sola; il compito di WordPress, chiamato come lo chiama `wp-cron.php`, fa il lavoro; alla disattivazione si toglie. Interfaccia `1.15.0`, compatibile con chi chiedeva `1.14.0` |
| C-324 | fatto | Una data rotta che come testo viene prima di oggi e 499 contenuti la cui azione fallisce sempre riempiono il massimo; un contenuto buono dopo di loro e uno di un'altra sezione. La prima esecuzione fa quello dell'altra sezione e dice di non aver finito; la seconda riparte da dove si era fermata e fa il buono, poi torna da capo |

## 8. Cosa non implementa

- **L'avviso quando il battito invecchia (C-43, ALBO-19)** e la lettura del battito per un
  controllo esterno (C-44): il valore c'è, la schermata e l'indirizzo per leggerlo vengono dopo.
- **Le azioni dei componenti.** L'albo registra la sua (defisso) in una modifica a parte.
- **Il cron di sistema.** Lo imposta chi gestisce il server: la guida d'installazione dirà come.
- **`completo` con esattamente 500 contenuti.** Se un'esecuzione ne trova esattamente 500 e
  nessun altro, dice comunque di non aver finito: la successiva non trova niente. È la
  lettura prudente.

## 9. Come si prova su un'installazione reale

Sulla copia di prova, con il cron interno di WordPress spento (`DISABLE_WP_CRON`) e un atto con
data di fine passata ancora pubblicato: chiamare `wp-cron.php` dal cron del server, o a mano.
Dopo la chiamata l'atto risulta defisso (con l'albo che registra la sua azione) e il battito
porta l'ora della chiamata.

## Rotture volute

| Guasto introdotto | Visto da |
|---|---|
| Nessuna conferma della data prima dell'azione | C-319 |
| La data letta come "fino a oggi compreso" nella lettura del gruppo | nessuna, perché la conferma della data lo ferma |
| Nessuna conferma dello stato prima dell'azione | C-321 |
| Stati ignorati nella lettura del gruppo | C-319..C-323 |
| Tipi di tutte le sezioni | C-319 |
| Senza lucchetto | C-320 |
| Lucchetto non lasciato | C-320 |
| Eccezione dell'azione non presa | C-321 |
| Contenuti già provati non esclusi dai gruppi successivi | C-319, C-321 |
| Stato letto dalla memoria e non dalla banca dati | C-321 |
| `completo` sempre vero | C-321 |
| Errore dell'azione contato come fatto | C-321 |
| Battito non scritto | C-322, C-323 |
| Lettura fallita presa per "niente da fare" | C-322 |
| Registrazione sovrascritta | C-318 |
| Stati vuoti accettati | C-318 |
| Azione non controllata | C-318 |
| Compito non agganciato | C-323 |
| Compito non programmato | C-323 |
| Compito ogni giorno invece che ogni ora | C-323 |
| Nessun segnaposto: ogni esecuzione riparte da capo | C-324 |
| Massimo condiviso fra le sezioni | C-324 |
| Nessun ritorno da capo dopo il segnaposto | C-324 |
| Segnaposto non azzerato a giro finito | nessuna, ed è equivalente: l'esecuzione dopo riparte da quel punto e torna da capo, quindi prova comunque tutti |

Il massimo tolto del tutto non si prova come guasto: con il gruppo pieno e nessun contenuto
nuovo il ciclo non finirebbe, e la prova non tornerebbe. Lo vede comunque C-321 attraverso
`completo`.

## Una prova vecchia resa robusta

Con le prove nuove, che creano più di cinquecento contenuti, gli identificativi della suite
arrivano a quattro cifre. In C-227 l'allegato senza contenuto prendeva come nome il suo
identificativo, e un nome di quattro cifre nell'indirizzo WordPress lo legge come un anno: la
pagina dell'allegato non si apriva più, per una ragione che con la prova non c'entra. Ora
l'allegato ha un nome che non è un numero. Quello che C-227 verifica non cambia.
