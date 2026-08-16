# Supporto

**Stato del progetto: in sviluppo.** Non esiste ancora una versione destinata
all'esercizio e non è attivo alcun impegno di assistenza. Quanto segue descrive dove si
scrive, non un livello di servizio garantito.

## Prima di scrivere

Verifica che il problema non sia già noto: cerca fra le issue aperte e chiuse del
repository. Leggi il `README.md` per i requisiti minimi dichiarati (WordPress 6.5,
PHP 8.1, sito singolo: il multisite non è supportato).

## Segnalare un difetto

Apri una issue sul repository indicando:

- versione del plugin, di WordPress e di PHP;
- se sono attivi altri componenti che dipendono da questo;
- i passaggi per riprodurre il problema, il risultato osservato e quello atteso;
- eventuali messaggi di errore, con `WP_DEBUG` attivo.

**Non inserire nelle segnalazioni dati personali, contenuti riservati, indirizzi di
installazioni in esercizio o credenziali.** Le issue sono pubbliche e indicizzabili.
Sostituisci i dati reali con esempi equivalenti.

## Proporre una modifica

Le proposte si discutono in una issue prima della pull request, così da non scrivere
codice che verrà scartato per una ragione di impianto. Ogni requisito documentato ha
almeno un test che lo verifica: una modifica che tocca un requisito aggiorna prima il
test. Documentazione e messaggi di commit sono in italiano.

## Vulnerabilità di sicurezza

Le vulnerabilità **non** si segnalano con una issue pubblica. Usa la segnalazione
privata di sicurezza del repository (GitHub Security Advisories), descrivendo l'impatto
e i passaggi per riprodurre. La segnalazione riceve riscontro prima di qualsiasi
divulgazione.

## Cosa non rientra nel supporto

Installazione, configurazione e adattamento su una specifica installazione, migrazione
di dati esistenti, formazione. Sono attività di servizio, distinte dal software, che
questo canale non copre.
