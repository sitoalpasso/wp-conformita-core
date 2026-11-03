# Verificatore dei collegamenti: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S8 del piano, righe di collaudo C-60..C-63,
dettagliate al punto 9. Prima parte: registrazione e verifica di un collegamento, righe
C-301..C-306. Seconda parte, in una PR successiva: il controllo periodico e la schermata dei
collegamenti rotti.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** Il primo a usare
il meccanismo è la trasparenza, per i rinvii alle banche dati nazionali (art. 9-bis del d.lgs.
33/2013): un rinvio rotto è un obbligo non assolto, e a occhio non si vede.

**Stato: prima parte costruita, righe C-301..C-306 verdi in locale.** Le sezioni dicono quello che il codice fa.

---

## In tre paragrafi, senza termini tecnici

**Cosa costruiamo.** Un posto dove un componente lascia un indirizzo di un altro sito, per
esempio la pagina di una banca dati nazionale dove si trovano i dati dell'ente, perché qualcuno
controlli nel tempo che quella pagina risponda ancora. Per ogni indirizzo si conserva l'esito
dell'ultimo controllo con la data: "mai controllato", "risponde" oppure "rotto", con il motivo.
Ogni componente dice ogni quanti giorni il suo indirizzo va ricontrollato: il meccanismo non ha
un valore suo.

**Come lo costruiamo.** Gli indirizzi stanno in una tabella propria, una riga per indirizzo. Il
controllo chiede la pagina all'altro sito con un tempo massimo di attesa, segue al più pochi
rinvii e legge solo l'inizio della risposta: un sito lento o che rimanda a se stesso non
blocca il nostro. Se nel frattempo il componente ha cambiato indirizzo, l'esito del controllo
vecchio si butta. In questa prima parte il controllo si chiede a mano da codice; il controllo
periodico e la pagina che elenca gli indirizzi rotti vengono nella parte successiva.

**Come si prova che funziona.** Le prove sostituiscono l'altro sito con risposte finte: una
pagina che risponde, una che non esiste, un tempo scaduto, un dominio inesistente, una lettura
della nostra banca dati che non riesce. Per ognuna si guarda l'esito scritto e la data. Ogni
regola si rompe apposta una volta, e la prova deve diventare rossa.

## 1. File toccati

| Parte | File | Cosa cambia |
|---|---|---|
| 1 | `includes/class-conformita-core-collegamenti.php` | Nuovo. Tabella, registrazione, lettura, verifica |
| 1 | `includes/funzioni-api.php` | Quattro funzioni pubbliche |
| 1 | `conformita-core.php` | Caricamento, tabella assicurata all'avvio e all'attivazione, interfaccia `1.12.0` |
| 1 | `tests/collegamenti-test.php` | Nuovo. Righe C-301..C-306 |
| 2 | da definire | Compito pianificato a lotti, schermata dei rotti, verifica a mano |

## 2. Requisito servito

C-60..C-63 del piano: un componente registra un collegamento, il verificatore ne registra
l'esito con la data, segnala quelli rotti, non segue rinvii all'infinito e ha un tempo
massimo. Per la trasparenza è la parte "verifica automatica periodica" di TRASP-25.

## 3. Il contratto verso i componenti

| Funzione | Cosa fa |
|---|---|
| `conformita_core_registra_collegamento( $sezione, $chiave, $indirizzo, $intervallo )` | Registra o aggiorna il collegamento della sezione con quella chiave. `true` o errore |
| `conformita_core_togli_collegamento( $sezione, $chiave )` | Lo toglie. `true` anche se non c'era, errore se non si è potuto togliere |
| `conformita_core_collegamento( $sezione, $chiave )` | Il collegamento con l'esito, `null` se non c'è, errore se la lettura fallisce |
| `conformita_core_verifica_collegamento( $sezione, $chiave )` | Lo controlla adesso e restituisce il collegamento con l'esito nuovo, o errore |

**I parametri.**

- `$sezione`: una sezione registrata. Altrimenti errore.
- `$chiave`: lettere minuscole, cifre, trattino e trattino basso, da 1 a 100 caratteri. La
  sceglie il componente, per esempio l'identificativo della voce.
- `$indirizzo`: `http` o `https`, al più 2048 caratteri, senza spazi né caratteri di
  controllo, uguale a se stesso dopo `esc_url_raw()`, senza un host della rete interna scritto
  come tale (`localhost`, indirizzi privati o riservati). La registrazione non chiede al servizio
  dei nomi: un dominio che non esiste si registra e risulta rotto alla verifica, che con la
  richiesta sicura di WordPress ferma anche un nome che porta alla rete interna.
- `$intervallo`: ogni quanti giorni ricontrollare, intero da 1 a 90. **È la politica e non ha
  un valore predefinito**: senza, la registrazione si rifiuta.

**Il collegamento letto** è un elenco con `indirizzo`, `intervallo`, `esito` (`mai`,
`raggiungibile`, `rotto`), `codice` (il codice della risposta, o `null`), `motivo` (testo,
vuoto se raggiungibile), `verificato` (istante UTC `Y-m-d H:i:s`, vuoto se mai),
`registrato` (istante UTC).

**Registrare di nuovo.** Stesso indirizzo: l'esito resta. Indirizzo diverso: l'esito torna
`mai`, perché l'esito vecchio era di un'altra pagina. Solo l'intervallo diverso: si aggiorna
l'intervallo, l'esito resta.

## 4. Come funziona la verifica

| Domanda | Scelta | Perché |
|---|---|---|
| Come si chiede la pagina | `wp_safe_remote_get()`, metodo GET | il GET è quello che fa un visitatore; alcune banche dati rifiutano HEAD. La variante sicura rifiuta gli indirizzi interni anche dopo un rinvio |
| Tempo massimo | 10 secondi | un sito lento all'altro capo non blocca il nostro. Non dipende dall'ente: è una protezione del meccanismo |
| Rinvii | al più 5 | un indirizzo che rimanda a se stesso finisce in errore invece di girare. Il passaggio da `http` a `https` e il rinvio a una pagina di accesso stanno ampiamente dentro |
| Quanto si legge | i primi 64 KiB | serve sapere che la pagina risponde, non scaricarla |
| Quando è raggiungibile | codice finale da 200 a 299 | un 3xx finale vuol dire rinvii esauriti; 4xx e 5xx sono rotti |
| Il motivo di un rotto | il messaggio dell'errore, o "risposta con codice N", al più 255 caratteri | la schermata lo mostra all'ente |
| La scrittura dell'esito | solo se l'indirizzo è ancora quello controllato | se il componente l'ha cambiato durante il controllo, l'esito vecchio non sporca il nuovo |
| Una lettura della nostra banca dati che fallisce | errore, nessuna richiesta all'altro sito | non vale come "collegamento assente" |

## 5. Dati letti e scritti

Tabella `{prefisso}conformita_core_collegamenti`, versione di schema in un'opzione, creata
all'attivazione e verificata a ogni caricamento come il registro delle modifiche. Chiave unica
su sezione e chiave, in `varbinary` per restare dentro il limite di 767 byte degli indici delle
banche dati più vecchie. L'indirizzo si confronta byte per byte: il percorso di un indirizzo
distingue maiuscole e minuscole.

## 6. Permessi

Nessun permesso in questa parte: le funzioni sono per i componenti, che le chiamano dopo aver
controllato i propri permessi. La schermata della seconda parte avrà una capability dedicata.

## 7. Cosa deliberatamente NON fa

- Non controlla che la pagina contenga il dato giusto: dice solo che risponde. Che porti al dato
  vero lo dichiara l'ente, con l'etichetta del rinvio.
- In questa parte non controlla da solo nel tempo e non ha una schermata: è la parte 2.
- Non segue indirizzi interni o della rete locale, nemmeno attraverso un rinvio.

## 8. Modi di guasto

| Guasto | Comportamento |
|---|---|
| Tabella assente o lettura fallita | errore; nessuna richiesta all'altro sito |
| Scrittura fallita | errore; la registrazione si rilegge prima di dire riuscito |
| Altro sito lento, rinvii infiniti, dominio inesistente | esito `rotto` con il motivo, entro il tempo massimo |
| Indirizzo cambiato durante il controllo | l'esito del controllo si butta, resta `mai` |

## 9. Le righe di collaudo di questa unità

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-301 | fatto | Registrazione: il collegamento compare con esito `mai`, senza data di verifica, con l'istante di registrazione; [attacco] sezione non registrata, chiave vuota, maiuscola, troppo lunga o non testo, indirizzo senza schema, `ftp`, `javascript`, con spazi, a capo, virgolette o parentesi angolari, troppo lungo, verso `127.0.0.1`, intervallo mancante, zero, 91, testo: errore e niente scritto |
| C-302 | fatto | Verifica di un collegamento che risponde: esito `raggiungibile`, codice 200, istante di verifica di adesso; la richiesta è un GET con tempo massimo 10 secondi, al più 5 rinvii, 64 KiB letti e indirizzi interni rifiutati |
| C-303 | fatto | Collegamento rotto: pagina inesistente (404), vietata (403), errore del server (500), rinvii esauriti (301 finale o errore), tempo scaduto, dominio inesistente: esito `rotto` con codice o motivo e istante di verifica; un motivo di 400 caratteri si taglia a 255 anche con la banca dati in modo rigoroso; tornato raggiungibile, il motivo si svuota |
| C-304 | fatto | Registrare di nuovo: stesso indirizzo, esito che resta; indirizzo diverso, esito `mai`; solo intervallo diverso, esito che resta e intervallo nuovo; due sezioni con la stessa chiave restano distinte; togliere, anche un collegamento che non c'è; verificare un collegamento non registrato: errore |
| C-305 | fatto | [guasto] lettura fallita: errore e non `null`, e la verifica non chiede niente all'altro sito; scrittura fallita: errore; indirizzo cambiato durante il controllo: l'esito si butta |
| C-306 | fatto | La tabella si crea con la chiave unica su sezione e chiave, e la versione dello schema si scrive; un doppione scritto direttamente si rifiuta; l'interfaccia è `1.12.0` |

## 10. Rotture volute

Una regola alla volta rotta nel codice, con le prove di `collegamenti-test.php`.

| Rottura | Riga rossa |
|---|---|
| Sezione non controllata | C-301 |
| Chiave senza forma, o un carattere più lunga | C-301 |
| Indirizzo lungo, `esc_url_raw()` non confrontato, host interno ammesso | C-301 |
| Intervallo non intero, a zero o a 91 | C-301 |
| Tempo massimo, rinvii, quantità letta diversi; richiesta non sicura | C-302 |
| Solo il 200 raggiungibile; anche i 3xx raggiungibili | C-302, C-303 |
| Motivo non tagliato | C-303 |
| Esito scritto anche se l'indirizzo è cambiato | C-305 |
| Indirizzo nuovo che tiene l'esito vecchio; stesso indirizzo che lo azzera; intervallo non aggiornato | C-304 |
| Riga riletta non guardata dopo la scrittura | C-305 |
| Lettura fallita trattata come collegamento assente | C-305 |
| Rimozione o scrittura dell'esito fallite che rispondono riuscito | C-305 |
| Chiave unica tolta, o solo sulla chiave | C-306, su una banca dati di prova nuova |
| Versione dell'interfaccia | C-306 |

**Due cose da sapere.**

- La chiave unica si prova su una banca dati di prova appena creata, come in CI: le prove di
  WordPress trasformano `CREATE TABLE` e `DROP TABLE` in tabelle temporanee, e su una banca dati
  che ha già la tabella vera `dbDelta()` non toglie un indice. Le due rotture sono rosse con la
  banca dati nuova.
- Una rottura resta verde, per equivalenza dichiarata: togliere il controllo della forma
  `http(s)://` senza spazi. `esc_url_raw()` con i soli protocolli `http` e `https` cambia ogni
  indirizzo che quel controllo rifiuta (senza schema, `ftp`, `javascript`, spazi, a capo), e il
  confronto con l'originale lo rifiuta lo stesso. Il controllo resta perché dice la regola in
  chiaro.

## 11. Che cosa deve fare la trasparenza

Chiedere l'interfaccia `1.13.0`, registrare ogni rinvio dichiarato con la voce come chiave e il
proprio intervallo, toglierlo quando l'ente lo toglie, e mostrare l'esito e il ritardo
(`in_ritardo`). Il controllo periodico lo fa il core: la trasparenza non programma compiti propri.

## 12. Seconda parte: il controllo periodico e il ritardo letto sul dato

Righe C-307..C-310. Versione dell'interfaccia da `1.12.0` a `1.13.0`.

**In parole semplici.** Una volta all'ora il sito ricontrolla da solo, a piccoli gruppi, i
collegamenti che ne hanno bisogno: prima quelli mai controllati, poi quelli controllati da più
tempo dell'intervallo scelto dal componente. Il controllo periodico fa il lavoro, ma non è lui a
dire la verità: ogni collegamento letto porta anche l'indicazione "in ritardo" quando l'ultimo
controllo è più vecchio di quanto il componente aveva chiesto. Se il controllo periodico si
ferma, i collegamenti risultano in ritardo da soli, senza che nessuno debba accorgersene.

| Domanda | Scelta | Perché |
|---|---|---|
| Quando | un compito pianificato ogni ora, programmato all'avvio se manca, tolto alla disattivazione | l'intervallo minimo è un giorno: un'ora basta e lascia spazio al lotto |
| Quanti per volta | al più 5 | con 10 secondi ciascuno, un giro dura al più un minuto circa |
| Quali | quelli mai controllati, poi quelli con l'ultimo controllo più vecchio dell'intervallo, dal più vecchio | chi aspetta da più tempo passa prima |
| Collegamento di una sezione non registrata in questa richiesta | saltato, resta com'è | il componente che l'ha registrato non è attivo: non si controlla per conto di nessuno |
| Un controllo che fallisce | gli altri del lotto proseguono | un guasto su un collegamento non ferma gli altri |
| Lettura del lotto fallita | nessun controllo, errore | non vale come "niente da controllare" |
| In ritardo | l'ultimo controllo, o la registrazione se non c'è mai stato, è più vecchio dell'intervallo più un giorno; con un indirizzo nuovo la registrazione riparte dal cambio | il giorno in più assorbe l'attesa del compito orario e dei lotti; si calcola a ogni lettura, quindi non dipende dal compito pianificato |

**Il collegamento letto** ha una chiave in più: `in_ritardo`, vero o falso.

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-307 | fatto | Il lotto: con sette collegamenti (due mai controllati, tre scaduti da tempi diversi, due controllati da poco) ne controlla cinque, i mai controllati per primi e poi dal più vecchio; al giro dopo gli ultimi da controllare; quelli controllati da poco mai; un collegamento di una sezione non registrata saltato |
| C-308 | fatto | Il compito orario si programma all'avvio se manca, una volta sola, e chiama il lotto; alla disattivazione si toglie |
| C-309 | fatto | In ritardo, senza che il compito sia mai girato: mai controllato e registrato da più dell'intervallo più un giorno; controllato da più dell'intervallo più un giorno; non in ritardo un attimo prima del limite, né quello controllato da poco; collegamento vecchio con l'indirizzo cambiato: non in ritardo subito, in ritardo passati l'intervallo più un giorno dal cambio, e registrarlo di nuovo uguale non sposta il riferimento; interfaccia `1.13.0` |
| C-310 | fatto | [guasto] lettura del lotto fallita: errore e nessuna richiesta; un collegamento del lotto che non si può scrivere non ferma gli altri |

**Intervallo accorciato.** Se il componente accorcia l'intervallo di un collegamento già
controllato, il collegamento può risultare subito in ritardo secondo la regola nuova: è il
ritardo vero rispetto alla politica in vigore, e il lotto lo riprende al giro successivo.

**Limite dichiarato.** Un collegamento il cui esito non si riesce mai a scrivere resta in testa
alla coda e occupa uno dei cinque posti a ogni giro: gli altri proseguono, più lentamente. Il
guasto si vede comunque, perché quel collegamento risulta in ritardo.

**Rotture volute della seconda parte.** 22 rotture, tutte rosse tranne una equivalenza
dichiarata:

- togliere `verificato IS NOT NULL` dall'ordinamento lascia le prove verdi, perché MariaDB e
  MySQL mettono già i valori nulli per primi in ordine crescente. La clausola resta scritta per
  rendere esplicita la regola "prima i mai controllati".

Tre rotture all'inizio verdi hanno portato prove nuove: programmare il compito a ogni avvio
(il compito già programmato a un'altra ora non deve raddoppiare), contare nel lotto anche i
controlli il cui esito non si è scritto, e togliere il filtro sulle sezioni registrate.
