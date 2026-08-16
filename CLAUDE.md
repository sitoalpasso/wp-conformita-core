# Conformita Core - istruzioni di sviluppo

Plugin WordPress per i meccanismi comuni dei componenti di conformità. Implementa i
requisiti derivati dalla normativa applicabile ai soggetti dell'art. 2-bis del d.lgs.
33/2013: l'elenco completo, con fonte e criterio di verifica per ciascuno, è in
`docs/requisiti.md`.

Licenza: GPL-3.0-or-later. Versioni minime: WordPress 6.5, PHP 8.1. Multisite non
supportato.

## Comandi

- Ambiente: `npx wp-env start` (configurazione in `.wp-env.json`)
- Attivazione: `npx wp-env run cli wp plugin activate conformita-core`
- Test: `composer test` (PHPUnit sulla suite di test di WordPress)
- Standard: `composer lint` (PHPCS, WordPress Coding Standards)

La cartella del plugin è montata con `mappings`, che monta senza attivare: dopo
`wp-env start` l'attivazione è un passaggio esplicito. Non aggiungere `plugins`
accanto a `mappings`: monterebbe lo stesso codice in due percorsi e WordPress
vedrebbe due plugin gemelli. Il percorso di montaggio è `conformita-core`, cioè
lo slug, non il nome del repository: c'è un test che lo verifica.

## Regole di lavoro

- Questo plugin non contiene politiche: ogni meccanismo richiede la politica come
  parametro esplicito e rifiuta di attivarsi senza. Nessun valore predefinito, in
  particolare per l'indicizzazione e per la durata della pubblicazione.
- Ogni requisito di `docs/requisiti.md` ha almeno un test che lo verifica: una modifica
  che tocca un requisito aggiorna prima il test. I test sono la specifica eseguibile.
- La scadenza dei contenuti è una proprietà del dato letto, non un evento pianificato:
  nessuna funzione può assumere che il compito pianificato sia stato eseguito.
- Ogni tipo di contenuto dichiara `show_in_rest` esplicitamente, in un senso o
  nell'altro.
- Nessun valore dipendente dalla singola amministrazione cablato nel codice: durate,
  denominazioni e soglie sono configurazione con validazione. I default esistono solo
  dove un default sbagliato è impossibile.
- Fuso orario: sempre `wp_timezone()`, mai un fuso cablato.
- Sicurezza: nonce e `permission_callback` su ogni superficie registrata, sanitizzazione
  in ingresso, escaping in uscita, capability dedicate.
- Documentazione e messaggi di commit in italiano, descrittivi e senza riferimenti a
  installazioni specifiche.
