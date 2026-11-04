# Lucchetti della banca dati comuni ai componenti: scheda di lavorazione

**Repository: `wp-conformita-core`.** Righe di collaudo C-311..C-316. Versione
dell'interfaccia da `1.13.0` a `1.14.0`.

**Stato: costruita, righe C-311..C-316 verdi in locale.** Le sezioni dicono quello che il
codice fa.

---

## In tre paragrafi, senza termini tecnici

**Cosa costruiamo.** Albo e trasparenza mettono in fila le operazioni che non devono
sovrapporsi, come due importazioni insieme o due numerazioni dello stesso anno, con i
lucchetti della banca dati: chi arriva per secondo aspetta, o rinuncia. Le banche dati
recenti permettono a una richiesta di tenere più lucchetti insieme. Quelle più vecchie, che
WordPress ammette ancora (MySQL prima della 5.7.5, MariaDB prima della 10.0.2), no: una
richiesta ne tiene uno solo, e prenderne un secondo lascia il primo senza avviso. L'albo ha
già risolto il problema per sé. Ma una richiesta è una sola anche quando lavorano due
componenti: un lucchetto preso dalla trasparenza dentro un'operazione dell'albo lascerebbe
quello dell'albo, e il conto che l'albo tiene per sé non lo vedrebbe. Per questo il
meccanismo passa nel componente comune, che tiene un conto solo per tutti.

**Come lo costruiamo.** È lo stesso meccanismo già in uso nell'albo, spostato qui e reso
disponibile a tutti i componenti con funzioni pubbliche. Il componente comune legge una volta
per richiesta la versione della banca dati. Dove i lucchetti si tengono insieme, non cambia
niente: ogni componente usa i nomi suoi. Dove se ne tiene uno solo, tutti i lucchetti di tutti
i componenti diventano lo stesso lucchetto: lo si chiede alla banca dati alla prima presa, le
prese dentro un'altra si contano senza chiedere niente, e il lucchetto torna libero quando si
lascia l'ultima. Le operazioni vanno allora una alla volta anche fra componenti diversi: è più
lento, non meno sicuro. Una versione che non si riconosce vale come banca dati vecchia; una
versione che non si riesce a leggere non decide niente e nessuna presa riesce.

**Come si prova che funziona, e come si prova che il test è vero.** Una banca dati vecchia in
locale non c'è, quindi le prove la imitano: riscrivono ogni presa di lucchetto in modo che
lasci prima tutte le altre e rispondono alla domanda sulla versione come MariaDB 5.5. La
prova controlla per prima cosa che l'imitazione sia vera: con le istruzioni dirette, senza il
componente comune, il lucchetto di fuori si apre. Poi una prova gira sulla banca dati vera,
qualunque sia, e la verifica continua aggiunge un giro della suite su una MariaDB 5.5 vera. I
guasti introdotti a mano sono nella tabella in fondo.

## 1. File

- `includes/class-conformita-core-lucchetti.php`: il meccanismo, ripreso dall'albo.
- `includes/funzioni-api.php`: le funzioni pubbliche.
- `conformita-core.php`: interfaccia `1.14.0`.
- `tests/class-conformita-core-lucchetto-unico.php`: l'imitazione della banca dati vecchia.
- `tests/lucchetti-test.php`: C-311..C-316.
- `.github/workflows/ci.yml` e `bin/installa-suite-test.sh`: la suite anche su MariaDB 5.5.

## 2. Requisito servito

Nessun requisito nuovo. È la correttezza dei meccanismi che tengono in piedi requisiti già
fatti nei componenti: nell'albo la numerazione unica e le operazioni una alla volta, nella
trasparenza il blocco dei salvataggi.

## 3. Le funzioni pubbliche

| Funzione | Che cosa fa |
|---|---|
| `conformita_core_prendi_lucchetto( $nome, $attesa )` | prende il lucchetto, aspettando al più i secondi dati; vero se preso |
| `conformita_core_lascia_lucchetto( $nome )` | lascia una presa; dove il lucchetto è unico, la banca dati lo libera all'ultima |
| `conformita_core_trattieni_lucchetto( $nome )` | chiude la presa senza liberare il lucchetto: resta fino alla fine della connessione, per chi lavora dentro una transazione aperta da altri |
| `conformita_core_lucchetto_chiuso_senza_rilascio()` | chiude la presa per chi ha liberato il lucchetto con un'istruzione propria |
| `conformita_core_lucchetto_tenuto_qui( $mie )` | se il lucchetto unico lo tiene anche un'altra presa della stessa connessione, contate a parte le `$mie`; sempre falso dove i lucchetti sono distinti |
| `conformita_core_nome_lucchetto( $nome )` | il nome che arriva alla banca dati: quello dato, o quello comune |
| `conformita_core_lucchetto_unico()` | se la connessione tiene un lucchetto solo |
| `conformita_core_versione_a_lucchetto_unico( $versione )` | la regola sulla versione, per le prove dei componenti |

Il nome comune è il prefisso delle tabelle del sito, al più 40 caratteri, seguito da
`conformita_lucchetto`: due siti sulla stessa banca dati non si aspettano a vicenda. Il nome
dato da un componente deve già essere distinto per sito: il componente comune non lo cambia
dove i lucchetti sono distinti.

## 4. Dati letti e scritti

Nessuno. Si legge la versione della banca dati, una volta per connessione.

## 5. Permessi

Nessuno: sono funzioni interne, senza superfici registrate.

## 6. Modi di guasto previsti

- Presa annidata che lascia quella di fuori: C-311.
- Rilascio di una presa annidata che libera il lucchetto: C-311.
- Presa trattenuta liberata da un'operazione successiva: C-312.
- Versione non letta scambiata per una risposta: C-314.
- Soglie delle versioni sbagliate: C-313.

## 7. Prove

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-311 | fatto | Con l'imitazione: le istruzioni dirette aprono il lucchetto di fuori (l'imitazione è vera); con il componente comune, la presa di un secondo componente dentro quella del primo riesce subito, alla banca dati arriva una presa sola, lasciata la seconda nessun'altra richiesta prende né la prima né la seconda, e resta solo la presa di chi chiede; lasciate tutte e due, il lucchetto è libero |
| C-312 | fatto | Con l'imitazione: la presa trattenuta resta chiusa alle altre richieste anche dopo un'altra operazione della stessa richiesta; il nome comune porta il prefisso del sito ed è lo stesso per tutti; la presa chiusa senza rilascio non chiede niente alla banca dati e la presa dopo è di nuovo vera |
| C-313 | fatto | Il tipo dalla versione: MariaDB sotto e sopra 10.0.2, anche con il prefisso `5.5.5-`, MySQL sotto e sopra 5.7.5, versione vuota o sconosciuta come lucchetto unico |
| C-314 | fatto | [guasto] Versione che non si legge: nessuna presa e nessun lucchetto chiesto, la domanda si ripete a ogni chiamata, anche per una presa chiusa o trattenuta; quando la banca dati risponde la presa riesce e la versione non si chiede più |
| C-315 | fatto | Sulla banca dati vera, senza imitazione: la presa annidata non apre quella di fuori; dove i lucchetti sono distinti il nome resta quello del componente e un altro lucchetto è libero per le altre richieste; lasciato tutto, il lucchetto si riprende |
| C-316 | fatto | Interfaccia `1.14.0`, compatibile con chi chiedeva `1.13.0` |

## 8. Cosa non implementa

- **Il passaggio dei componenti.** L'albo e la trasparenza continuano a usare i loro lucchetti
  finché, in modifiche separate, non chiedono l'interfaccia `1.14.0` e passano a queste
  funzioni. Fino ad allora, su una banca dati vecchia, una presa della trasparenza dentro
  un'operazione dell'albo (o il contrario) lascia ancora quella di fuori. Non risulta oggi una
  strada che lo faccia, ma il passaggio di tutti e due lo chiude per costruzione.
- **I nomi dei lucchetti dei componenti.** Restano scelti dai componenti, distinti per sito.

## 9. Come si prova su un'installazione reale

Sulla copia di prova, con la banca dati del server: avviare un'importazione dell'albo e,
mentre lavora, avviarne un'altra da un'altra scheda del browser. La seconda deve rinunciare
con il messaggio dell'operazione in corso. Vale dopo il passaggio dell'albo a queste funzioni.

## Rotture volute

| Guasto introdotto | Visto da |
|---|---|
| Le prese annidate non si contano | C-311 |
| Il nome resta sempre quello del componente | C-311, C-312 |
| Il rilascio ignora le prese ancora aperte | C-311 |
| Il rilascio ignora la presa trattenuta | C-312 |
| La presa trattenuta non si segna | C-312 |
| "Tenuto qui" ignora la presa trattenuta | C-312 |
| La funzione pubblica di "tenuto qui" ignora le prese di chi chiede | C-311 |
| La presa chiusa senza rilascio non si segna | C-312 |
| Versione non letta: la presa si chiede lo stesso | C-314 |
| Versione non letta: lo stato si ricorda | C-314 |
| Soglia di MariaDB a 10.0.1 | C-313 |
| Soglia di MySQL a 5.7.4 | C-313 |
| Versione sconosciuta come lucchetti distinti | C-313 |
| Il prefisso `5.5.5-` di MariaDB ignorato | C-313 |
| Nome comune diverso a ogni presa | C-311, C-312 |
| Nome comune senza il prefisso del sito | C-312 |
| Interfaccia non alzata | C-316 |
