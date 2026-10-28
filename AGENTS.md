# Istruzioni per gli agenti che leggono questo repository

Questo file vale per gli strumenti automatici di revisione e di scrittura del codice. Le
regole di sviluppo sono in `CLAUDE.md`: questo file non le ripete, le presuppone. Dove i due
testi sembrano dire cose diverse, vale `CLAUDE.md`, e il dubbio va segnalato.

Scrivi sempre in italiano: commenti di revisione, sintesi, proposte di correzione.

## Che cosa è il repository

Plugin WordPress `conformita-core`: i meccanismi comuni ai componenti di conformità per la
pubblica amministrazione (oggi l'albo pretorio e la trasparenza, in repository a parte).
Versioni minime: WordPress 6.5, PHP 8.1, sito singolo.

Il componente **non ha politiche proprie**. Ogni meccanismo (scadenza, indicizzazione,
consegna dei documenti) riceve la politica come parametro esplicito dal componente che
registra la sezione e rifiuta di attivarsi senza. Albo e trasparenza hanno regole opposte
(l'albo vieta l'indicizzazione, la trasparenza la impone): un valore predefinito qui sarebbe
sbagliato per uno dei due.

I componenti che dipendono da questo verificano all'avvio la versione dell'interfaccia,
`CONFORMITA_CORE_VERSIONE_API` in `conformita-core.php`. Un errore qui si propaga a tutti.

## Dove sono le cose

| Percorso | Contenuto |
|---|---|
| `conformita-core.php` | intestazione, versione dell'interfaccia, avvio |
| `includes/` | una classe per meccanismo; le funzioni pubbliche per gli altri componenti sono in `funzioni-api.php` |
| `tests/` | PHPUnit sulla suite di test di WordPress; un file `*-test.php` per meccanismo, più le prove di non vacuità |
| `docs/implementazione/` | una scheda per meccanismo: contratto del dato, funzioni pubbliche, cosa non fa, modi di guasto, limiti dichiarati e righe di collaudo C-nnn (sezione "Le righe di collaudo") |

Le schede principali: `motore-di-scadenza.md` (filtro a ogni lettura ed elenco chiuso dei
percorsi), `consegna-allegati.md` (cartella protetta e punto di consegna),
`registro-modifiche.md`, `meccanismo-di-indicizzazione.md`.

## Come girano le prove

- `composer install`, poi `composer test` (PHPUnit) e `composer lint` (PHPCS con le
  WordPress Coding Standards).
- Ambiente locale: `npx wp-env start`, poi
  `npx wp-env run cli wp plugin activate conformita-core`.
- La CI (`.github/workflows/ci.yml`) esegue PHPCS e PHPUnit con PHP 8.1 e 8.3.

## Review guidelines

### Prima di tutto: la materia della PR

Leggi la descrizione della pull request. Dice quale unità di lavoro si revisiona, quali
righe di collaudo (C-nnn) tocca, quali rilievi di giri precedenti sono già stati accolti e
quale codice è stato scritto per ultimo. **Rileggi per primo il codice scritto per ultimo**:
le correzioni dei giri precedenti sono il punto dove nascono i difetti nuovi.

Poi leggi la scheda in `docs/implementazione/` che la PR modifica o aggiunge: dice che cosa
il meccanismo deve fare e che cosa **deliberatamente non fa**. Un comportamento dichiarato
come limite o come unità futura non è un difetto di questa PR.

### Che cosa cercare

1. **Nessuna politica predefinita.** Un meccanismo che si avvia, o si comporta in un modo
   preciso, senza che il componente abbia dichiarato la politica è un difetto grave. Lo è
   anche un valore di ripiego "ragionevole" per durata, indicizzazione o consegna.

2. **Scadenza come proprietà del dato letto.** Il filtro a ogni lettura deve coprire tutto
   l'elenco chiuso dei percorsi della scheda del motore di scadenza: pagina singola, elenco,
   ricerca, feed, sitemap, REST per collezione e per ID, oEmbed, XML-RPC, pagina allegato,
   navigazione precedente e successivo, query di terzi, documenti. Nessun percorso dipende
   dal compito pianificato. Un contenuto scaduto o non pubblico risponde come mai esistito
   ("non trovato"), non "non hai il permesso", e il rifiuto non deve permettere di
   distinguere i due casi. Cerca il controllo fatto su una proprietà vicina a quella giusta:
   lo stato `publish` invece di "si legge oggi", lo stato del padre invece di quello
   dell'allegato, un percorso invece della cartella.

3. **Consegna dei documenti.** I file stanno nella cartella protetta e si servono solo dal
   punto di consegna, che rifà a ogni richiesta i controlli di scadenza, stato e politica.
   La protezione della cartella si verifica, non si presume. Nessun percorso di file
   costruito da input esterno; il punto di consegna riceve un ID. Il nome proposto al
   browser passa dal controllo del componente e, se non valido, diventa il nome neutro.
   Intestazioni contro le memorie di pagina corrette anche per il visitatore anonimo.

4. **Valori che decidono la sicurezza.** Un valore che decide visibilità, permesso o
   politica non deve passare da funzioni o filtri che un altro componente può cambiare.
   Una lettura della banca dati che fallisce non vale come "vuoto": deve fermare la
   decisione (controlla `$wpdb->last_error`).

5. **Contratto delle funzioni pubbliche.** Una modifica a `funzioni-api.php` o al
   comportamento promesso da una scheda cambia ciò su cui albo e trasparenza contano.
   Verifica che la scheda sia aggiornata e che la versione dell'interfaccia sia cambiata
   quando il contratto cambia.

6. **Registro delle modifiche.** Solo in aggiunta: nessuna strada modifica o cancella una
   voce. Nessun dato personale oltre l'identificativo dell'utente. Il vincolo di unicità
   della chiave regge anche con richieste concorrenti.

7. **Permessi e nonce.** Capability dedicate con `current_user_can()`, mai il nome di un
   ruolo. Il nonce accompagna la capability, non la sostituisce. Ogni rotta REST ha
   `permission_callback`; ogni tipo registrato dichiara `show_in_rest` esplicitamente.

8. **Date e fuso.** Sempre `wp_timezone()`, mai un fuso cablato e mai UTC per errore.
   Controlla mezzanotte, cambio dell'ora legale, fine anno.

9. **Prove vuote o che non arrivano alla guardia.** Per ogni prova nuova o modificata
   chiediti che cosa dovrebbe rompersi perché diventi rossa. Segnala:
   - il valore atteso calcolato con la funzione sotto esame invece che scritto per esteso;
   - la prova al negativo che non dimostra prima che il caso esiste (precondizione);
   - la prova che aspetta un rifiuto senza dimostrare quale controllo l'ha prodotto;
   - la prova che conta chiamate a un aggancio comprese quelle fatte dalla prova stessa;
   - l'operazione che risponde "riuscito" senza che lo stato risultante venga riletto;
   - la prova che passerebbe anche togliendo la riga che dovrebbe proteggere.

10. **Righe di collaudo coerenti.** Ogni riga C-nnn citata dalla PR esiste nella scheda,
    non si sovrappone a righe già usate in `main`, punta a una prova che esiste e verifica
    ciò che la riga promette. Se il codice cambia un comportamento descritto in `docs/`, il
    documento cambia nella stessa PR.

11. **Sicurezza di base.** Sanitizzazione in ingresso, escaping nel punto in cui il dato
    incontra l'HTML, SQL solo con `$wpdb->prepare()`, blocco dell'accesso diretto in ogni
    file PHP.

### Che cosa non segnalare

- Stile, nomi, lingua dei commenti, formattazione: li controlla PHPCS.
- Rilievi fuori dalla materia della PR, salvo un difetto grave di sicurezza o di scadenza,
  o un difetto sfruttabile senza poteri da amministratore: in quel caso segnalalo una volta,
  dicendo che è fuori materia.
- Il codice di WordPress in sé, e quello dei componenti che usano questo.
- Le scelte già prese e scritte nelle schede.
- I limiti dichiarati come tali nelle schede, e le parti indicate come unità future (per
  esempio il compito pianificato e il battito di controllo, se la scheda li dice non
  costruiti). Eccezione: un limite dichiarato che si rivela sfruttabile senza poteri da
  amministratore si segnala sempre.

### Come scrivere ogni rilievo

Un rilievo per difetto concreto e riproducibile, con il file, la riga, lo scenario che lo fa
emergere e la correzione precisa. Nel testo di ogni rilievo aggiungi tre righe:

- **Realismo:** "configurazione comune" se basta un'installazione o un uso ordinario di
  WordPress; "componente insolito" se serve un altro plugin o un tema che fa qualcosa di non
  comune, e dici quale.
- **Causa comune:** se il difetto ha la stessa causa di un altro rilievo di questa revisione,
  dilla e nomina l'altro rilievo; altrimenti scrivi "isolato".
- **Chi può sfruttarlo:** "visitatore anonimo", "utente con un ruolo basso" oppure "solo chi
  può già installare plugin o scrivere nella banca dati". Un difetto sfruttabile senza
  poteri da amministratore va sempre corretto, per quanto raro sia il caso: non si dichiara
  come limite.

Priorità: un contenuto scaduto o non pubblico raggiungibile, un documento scaricabile quando
non dovrebbe, un meccanismo che parte senza politica, un dato personale esposto o un
permesso aggirato sono sempre la priorità più alta. Una prova che passerebbe con il
comportamento sbagliato su uno di questi punti è alta anche lei.

Se non trovi difetti concreti, dillo in una riga. Non riempire la revisione di suggerimenti.

## Vincoli sui testi del repository

Tutto ciò che entra nel ramo principale diventa pubblico. In nessun file, commento o commit
devono comparire nomi di enti reali, loro domini, o frasi che attribuiscano un requisito a
una singola amministrazione invece che alla norma. I requisiti derivano dalla normativa e
si citano per identificativo ed estremi di legge. Niente trattini lunghi nei testi. Se una
PR viola questi vincoli, segnalalo con priorità alta.
