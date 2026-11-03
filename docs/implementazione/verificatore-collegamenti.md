# Verificatore dei collegamenti: scheda di lavorazione

**Repository: `wp-conformita-core`.** Unità S8 del piano, righe di collaudo C-60..C-63,
dettagliate al punto 9. Prima parte: registrazione e verifica di un collegamento, righe
C-301..C-306. Seconda parte, in una PR successiva: il controllo periodico e la schermata dei
collegamenti rotti.

**Nel componente dell'albo pretorio non si implementa niente di tutto questo.** Il primo a usare
il meccanismo è la trasparenza, per i rinvii alle banche dati nazionali (art. 9-bis del d.lgs.
33/2013): un rinvio rotto è un obbligo non assolto, e a occhio non si vede.

**Stato: in costruzione.**

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
  controllo, uguale a se stesso dopo `esc_url_raw()`, verso un indirizzo che
  `wp_http_validate_url()` accetta (niente indirizzi interni).
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
| C-301 | da fare | Registrazione: il collegamento compare con esito `mai`, senza data di verifica, con l'istante di registrazione; [attacco] sezione non registrata, chiave vuota, maiuscola, troppo lunga o non testo, indirizzo senza schema, `ftp`, `javascript`, con spazi o a capo, troppo lungo, verso `127.0.0.1`, intervallo mancante, zero, 91, testo: errore e niente scritto |
| C-302 | da fare | Verifica di un collegamento che risponde: esito `raggiungibile`, codice 200, istante di verifica di adesso; la richiesta è un GET con tempo massimo 10 secondi, al più 5 rinvii, 64 KiB letti e indirizzi interni rifiutati |
| C-303 | da fare | Collegamento rotto: pagina inesistente (404), errore del server (500), rinvii esauriti (301 finale), tempo scaduto, dominio inesistente: esito `rotto` con codice o motivo e istante di verifica |
| C-304 | da fare | Registrare di nuovo: stesso indirizzo, esito che resta; indirizzo diverso, esito `mai`; solo intervallo diverso, esito che resta e intervallo nuovo; due sezioni con la stessa chiave restano distinte; togliere, anche un collegamento che non c'è; verificare un collegamento non registrato: errore |
| C-305 | da fare | [guasto] lettura fallita: errore e non `null`, e la verifica non chiede niente all'altro sito; scrittura fallita: errore; indirizzo cambiato durante il controllo: l'esito si butta |
| C-306 | da fare | La tabella si crea con la chiave unica su sezione e chiave; l'interfaccia è `1.12.0` |
