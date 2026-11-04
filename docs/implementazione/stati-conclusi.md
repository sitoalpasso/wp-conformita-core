# Stati conclusi: scheda di lavorazione

**Repository: `wp-conformita-core`.** Righe di collaudo C-327..C-329. Versione
dell'interfaccia da `1.15.0` a `1.16.0`.

**Stato: costruita, righe C-327..C-329 verdi in locale.** Le sezioni dicono quello che il
codice fa.

---

## In tre paragrafi, senza termini tecnici

**Cosa costruiamo.** Il componente comune lascia a chi ha il solo permesso di consultare gli
scaduti aprire i documenti dei contenuti pubblicati, scaduti compresi, e di nessun altro. Con il
compito alla scadenza un componente può spostare un contenuto finito regolarmente in uno stato
suo, per esempio "pubblicazione conclusa". Senza questa modifica, chi consulta perderebbe i
documenti proprio di quei contenuti, cioè degli scaduti che deve poter consultare.

**Come lo costruiamo.** Il componente dichiara, per ogni suo tipo di contenuto, gli stati che
valgono come fine regolare della pubblicazione. Chi consulta apre i documenti dei contenuti in
quegli stati come quelli dei pubblicati. La dichiarazione accetta solo stati che il componente
ha già registrato e che non sono pubblici né privati: uno stato pubblico renderebbe il
contenuto visibile a tutti, fuori dalla scadenza. Gli stati di lavoro di WordPress (bozza, in
attesa, cestinato e simili) sono rifiutati. Una dichiarazione per tipo, che non si
sovrascrive. Non c'è un elenco predefinito: chi non dichiara niente resta come prima.

**Come si prova che funziona, e come si prova che il test è vero.** La prova registra quattro
stati: uno da dichiarare, uno non dichiarato, uno pubblico e uno privato. Controlla prima che,
senza dichiarazione, chi consulta trovi chiuso lo stato concluso, e poi che lo trovi aperto
dopo. Lo stato non dichiarato resta chiuso con il rifiuto di sempre. La dichiarazione di un
altro tipo non apre niente, e dal punto pubblico il contenuto concluso resta chiuso a tutti. I
guasti introdotti a mano sono nella tabella in fondo.

## 1. File

- `includes/class-conformita-core-tipi.php`: la dichiarazione e la lettura.
- `includes/class-conformita-core-consegna.php`: chi consulta apre anche gli stati conclusi.
- `includes/funzioni-api.php`: la funzione pubblica.
- `conformita-core.php`: interfaccia `1.16.0`.
- `tests/consegna-test.php`: C-327..C-329.

## 2. Requisito servito

Nessun requisito nuovo. Tiene in piedi la consultazione riservata degli scaduti quando un
componente segna con uno stato proprio la fine regolare della pubblicazione: nell'albo è il
compito alla scadenza di ALBO-03.

## 3. La funzione pubblica

| Funzione | Che cosa fa |
|---|---|
| `conformita_core_dichiara_stati_conclusi( $tipo, $stati )` | dichiara gli stati di fine regolare del tipo; vero, oppure errore |

Errori: `conformita_core_tipo_sconosciuto` per un tipo non registrato,
`conformita_core_conclusi_stati` per un elenco vuoto o uno stato non accettato,
`conformita_core_conclusi_duplicato` per la seconda dichiarazione dello stesso tipo.

## 4. Dati letti e scritti

Nessuno. La dichiarazione vive per la richiesta, come la registrazione dei tipi: il componente
la ripete a ogni avvio.

## 5. Permessi

Nessun permesso nuovo. Il permesso di consultare gli scaduti apre un insieme di contenuti più
largo, ma solo quello che il componente dichiara. Chi gestisce il tipo non cambia niente. Il
punto pubblico non cambia niente: lì conta solo lo stato pubblicato.

## 6. Modi di guasto previsti

- Stato pubblico dichiarato concluso, che porterebbe fuori il contenuto: C-327.
- Stato di lavoro di WordPress dichiarato concluso, che aprirebbe le bozze a chi consulta: C-327.
- Dichiarazione di un tipo che apre i contenuti di un altro: C-328.
- Dichiarazione sovrascritta: C-327.

## 7. Prove

| Riga | Stato | Cosa verifica |
|---|---|---|
| C-327 | fatto | Rifiutati il tipo sconosciuto, l'elenco vuoto o non elenco, lo stato non testo, pubblicato, bozza, cestinato, lo stato non registrato, lo stato pubblico, quello privato, l'elenco con uno buono e uno no; dopo i rifiuti niente risulta dichiarato. Accettato lo stato registrato non pubblico; la seconda dichiarazione è rifiutata e non si aggiunge |
| C-328 | fatto | Chi consulta: senza dichiarazione lo stato concluso è chiuso; dichiarato da un altro tipo resta chiuso; dichiarato dal suo tipo si apre, con il file giusto; uno stato non dichiarato resta chiuso con il rifiuto di sempre; dal punto pubblico il concluso è chiuso |
| C-329 | fatto | Interfaccia `1.16.0`, compatibile con chi chiedeva `1.15.0` |

## 8. Cosa non implementa

- **Il passaggio dei contenuti allo stato concluso.** Lo fa il componente, con il compito alla
  scadenza.
- **Il filtro di scadenza.** Non cambia: guarda solo i pubblicati, e uno stato concluso non è
  pubblico, quindi WordPress non lo mostra a nessuno.

## 9. Come si prova su un'installazione reale

Dopo il passaggio dell'albo: con un utente che ha solo il permesso di consultare gli scaduti,
aprire dalla schermata degli atti scaduti il documento di un atto concluso. Deve aprirsi.

## Rotture volute

| Guasto introdotto | Visto da |
|---|---|
| Chi consulta apre solo i pubblicati | C-328 |
| Stato pubblico accettato | C-327 |
| Stato privato accettato | C-327 |
| Stati di lavoro di WordPress accettati | C-327 |
| Stato non registrato accettato | C-327 |
| Seconda dichiarazione che sovrascrive | C-327 |
| Stato concluso per qualunque tipo | C-328 |
| Interfaccia non alzata | C-329 |
