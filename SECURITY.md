# Sicurezza

## Come segnalare una vulnerabilità

**Non aprire una issue pubblica.** Le vulnerabilità si segnalano in privato, in uno di questi
due modi:

1. con la segnalazione privata di GitHub: scheda **Security** del repository, pulsante
   **Report a vulnerability**;
2. per posta elettronica a **andrea@andreaceravolo.it**.

Indica nella segnalazione:

- versione di questo plugin, del componente che lo usa, di WordPress e di PHP;
- il tipo di problema e il suo impatto (che cosa può vedere o fare chi lo sfrutta);
- i passi minimi per riprodurlo, con dati inventati;
- il meccanismo coinvolto, se lo conosci (per esempio il filtro di scadenza o la consegna
  dei documenti).

Non inserire dati personali, contenuti di atti reali o indirizzi di installazioni.

## Tempi di risposta

| Passo | Entro |
|---|---|
| Conferma di ricezione | 3 giorni lavorativi |
| Prima valutazione (vulnerabilità sì o no, gravità) | 10 giorni lavorativi |
| Correzione per i casi gravi (atto scaduto o non pubblico raggiungibile, documento scaricabile quando non dovrebbe, dati personali esposti, scrittura senza permesso) | 30 giorni dalla conferma |

Chi segnala viene tenuto informato fino alla correzione. L'avviso si pubblica quando la
versione corretta è disponibile, con il credito a chi ha segnalato, se lo desidera. Chiediamo
di non divulgare il problema prima di allora.

## Versioni supportate

Il plugin è in sviluppo (versione `0.x`) e non è ancora destinato alla produzione. Le
correzioni di sicurezza si fanno solo sul ramo `main` e sull'ultima versione pubblicata.

| Versione | Supportata |
|---|---|
| ultima versione pubblicata e `main` | sì |
| versioni precedenti | no |

## Che cosa è in ambito

- Il codice di questo repository: filtro di scadenza a ogni lettura, consegna dei documenti
  e cartella protetta, registro delle modifiche, controllo dell'indicizzazione, tipi di
  contenuto e sezioni, controllo della versione dell'interfaccia.
- In particolare: un contenuto scaduto o non pubblico di un componente che usa questo
  plugin che resta raggiungibile su qualunque strada (pagina, ricerca, REST, feed, oEmbed,
  XML-RPC, documenti); un documento scaricabile dall'indirizzo diretto della cartella o dal
  punto di consegna quando non dovrebbe; un meccanismo che parte senza la politica
  dichiarata; una voce del registro modificabile o cancellabile; mancato controllo di nonce
  o capability; iniezione SQL, XSS, CSRF; dati personali esposti dove non servono.

## Che cosa è fuori ambito

- WordPress, PHP, il server e la loro configurazione, salvo che il plugin dichiari di
  verificarla (come la protezione della cartella dei documenti).
- I componenti che usano questo plugin (albo pretorio, trasparenza): si segnalano nei loro
  repository, con la stessa procedura.
- Temi e altri plugin, salvo che il difetto stia nel modo in cui questo plugin li usa.
- I limiti già dichiarati nelle schede di `docs/implementazione/`.
- Problemi che richiedono un account che abbia già il permesso di fare la stessa cosa
  dall'interfaccia.

## I principi di sicurezza del plugin

Il dettaglio è nelle schede di [`docs/implementazione/`](docs/implementazione/). I principi
in breve:

- **Nessuna politica predefinita.** Ogni meccanismo riceve la politica dal componente che
  registra la sezione e rifiuta di partire senza.
- **Nel dubbio il sistema si chiude.** Se un controllo non riesce a stabilire se un contenuto
  è visibile, non lo mostra; se una lettura della banca dati non risponde, l'operazione si
  ferma. Un errore produce un rifiuto, mai un permesso silenzioso.
- **La scadenza è una proprietà del dato letto.** Un contenuto scaduto non compare su nessuna
  strada anche se il compito pianificato non è mai stato eseguito, e il rifiuto non permette
  di distinguere un contenuto scaduto da uno mai esistito.
- **I documenti si servono da un punto di consegna PHP**, che ricontrolla scadenza, stato e
  politica a ogni richiesta, mai dall'indirizzo diretto della cartella. La protezione della
  cartella si verifica, non si presume.
- **Permessi dedicati** controllati con `current_user_can()`, nonce insieme al permesso e mai
  al suo posto, `permission_callback` su ogni rotta.
- **Registro delle modifiche solo in aggiunta**, senza dati personali oltre l'identificativo
  dell'utente.
