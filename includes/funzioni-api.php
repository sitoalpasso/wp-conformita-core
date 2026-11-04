<?php
/**
 * API pubblica di core: quello che i componenti dipendenti chiamano.
 *
 * Le funzioni qui dentro sono la superficie stabile, coperta dalla versione di
 * API. Le classi restano il dettaglio di implementazione.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'conformita_core_registra_sezione' ) ) {
	/**
	 * Registra una sezione dichiarandone la politica.
	 *
	 * La politica va dichiarata per intero: senza politica di indicizzazione e
	 * senza politica di scadenza la sezione non si attiva, e la funzione dice
	 * quale delle due manca. Non esistono valori predefiniti, perché il
	 * predefinito ragionevole per un componente è la violazione dell'altro.
	 *
	 * @param string $sezione  Identificativo della sezione.
	 * @param array  $politica Politiche dichiarate: `indicizzazione` e `scadenza`.
	 * @return true|WP_Error Vero se la sezione è attiva, errore altrimenti.
	 */
	function conformita_core_registra_sezione( $sezione, array $politica ) {
		return Conformita_Core_Sezioni::registra( $sezione, $politica );
	}
}

if ( ! function_exists( 'conformita_core_politica_sezione' ) ) {
	/**
	 * Politica di una sezione registrata.
	 *
	 * @param string $sezione Identificativo della sezione.
	 * @return Conformita_Core_Politica|WP_Error Politica dichiarata, oppure errore.
	 */
	function conformita_core_politica_sezione( $sezione ) {
		return Conformita_Core_Sezioni::politica( $sezione );
	}
}

if ( ! function_exists( 'conformita_core_sezione_registrata' ) ) {
	/**
	 * La sezione è registrata con una politica valida.
	 *
	 * @param string $sezione Identificativo della sezione.
	 * @return bool
	 */
	function conformita_core_sezione_registrata( $sezione ) {
		return Conformita_Core_Sezioni::registrata( $sezione );
	}
}

if ( ! function_exists( 'conformita_core_sezioni_registrate' ) ) {
	/**
	 * Identificativi delle sezioni registrate, nell'ordine di registrazione.
	 *
	 * @return array<int, string>
	 */
	function conformita_core_sezioni_registrate() {
		return Conformita_Core_Sezioni::identificativi();
	}
}

if ( ! function_exists( 'conformita_core_registra_tipo' ) ) {
	/**
	 * Registra un tipo di contenuto dentro una sezione già registrata.
	 *
	 * Il tipo si registra sempre da qui e mai direttamente con WordPress: la
	 * politica sta sulla sezione, quindi un tipo registrato fuori da una sezione
	 * sarebbe un contenuto pubblicato che nessuna regola governa. Se la sezione
	 * non risulta registrata la funzione rifiuta, e il tipo non arriva a
	 * WordPress.
	 *
	 * La definizione dichiara la sezione e la scelta su `show_in_rest`, che è
	 * obbligatoria in entrambi i sensi e non ha valore predefinito. Gli altri
	 * argomenti di registrazione si passano in `argomenti` e arrivano a
	 * WordPress come sono, tolti quelli che core impone.
	 *
	 * Due chiavi facoltative: `nome_scaricato`, la funzione che propone il
	 * nome del file consegnato, e, dalla versione 1.9.0 dell'interfaccia,
	 * `allegati_seguono_la_sezione`, vero per un tipo senza pagina propria i
	 * cui contenuti si mostrano dentro una pagina della sezione: i loro file
	 * escono dal punto pubblico quando il contenuto è pubblicato, valido e
	 * senza password, anche se la pagina del contenuto non è consultabile.
	 * Dalla 1.10.0 la chiave accetta anche una funzione, che riceve allegato
	 * e contenuto e viene chiamata per ultima: il file esce solo se risponde
	 * `true`.
	 *
	 * @param string $tipo        Identificativo del tipo.
	 * @param array  $definizione Definizione: `sezione`, `show_in_rest`, `argomenti`.
	 * @return true|WP_Error Vero se il tipo è registrato, errore altrimenti.
	 */
	function conformita_core_registra_tipo( $tipo, array $definizione ) {
		return Conformita_Core_Tipi::registra( $tipo, $definizione );
	}
}

if ( ! function_exists( 'conformita_core_tipo_registrato' ) ) {
	/**
	 * Il tipo è registrato attraverso core, quindi ha una sezione.
	 *
	 * Il nome si riconosce come lo riconosce la banca dati quando WordPress
	 * cerca i contenuti per tipo: di norma senza badare alle maiuscole né agli
	 * spazi in coda. Un contenuto con `PROVA_ATTO` nella riga è quindi di un
	 * tipo gestito se `prova_atto` lo è.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return bool
	 */
	function conformita_core_tipo_registrato( $tipo ) {
		return Conformita_Core_Tipi::registrato( $tipo );
	}
}

if ( ! function_exists( 'conformita_core_sezione_del_tipo' ) ) {
	/**
	 * Sezione che governa un tipo registrato.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return string|WP_Error Identificativo della sezione, oppure errore.
	 */
	function conformita_core_sezione_del_tipo( $tipo ) {
		return Conformita_Core_Tipi::sezione( $tipo );
	}
}

if ( ! function_exists( 'conformita_core_politica_tipo' ) ) {
	/**
	 * Politica che governa un tipo registrato.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return Conformita_Core_Politica|WP_Error Politica dichiarata, oppure errore.
	 */
	function conformita_core_politica_tipo( $tipo ) {
		return Conformita_Core_Tipi::politica( $tipo );
	}
}

if ( ! function_exists( 'conformita_core_capacita_tipo' ) ) {
	/**
	 * Capability dedicate a un tipo registrato.
	 *
	 * Core le registra e non le assegna ad alcun ruolo: finché il componente non
	 * effettua l'assegnazione, nessun ruolo può gestire il tipo
	 * nell'amministrazione WordPress. Assegnarle è compito del componente, che è
	 * l'unico a sapere a quali ruoli spetta la sua sezione.
	 *
	 * Le capability governano la gestione del contenuto e non la sua
	 * consultazione pubblica, che discende dalla politica della sezione e dallo
	 * stato del contenuto.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<string, string>|WP_Error Mappa delle capability, oppure errore.
	 */
	function conformita_core_capacita_tipo( $tipo ) {
		return Conformita_Core_Tipi::capacita( $tipo );
	}
}

if ( ! function_exists( 'conformita_core_capacita_consultazione_scaduti' ) ) {
	/**
	 * Il permesso di consultare gli scaduti di un tipo registrato.
	 *
	 * Apre, dal punto amministrativo di consegna e in sola lettura, i file dei
	 * contenuti pubblicati del tipo, scaduti compresi, e dalla 1.16.0 quelli
	 * negli stati dichiarati conclusi. Non dà nessun permesso
	 * di gestione e non riapre il punto pubblico. Uno per tipo, non compreso
	 * in conformita_core_capacita_tipo(). Core non lo assegna a nessun ruolo:
	 * lo assegna il componente.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return string|WP_Error Nome del permesso, oppure errore.
	 */
	function conformita_core_capacita_consultazione_scaduti( $tipo ) {
		return Conformita_Core_Tipi::capacita_consultazione_scaduti( $tipo );
	}
}

if ( ! function_exists( 'conformita_core_dichiara_stati_conclusi' ) ) {
	/**
	 * Dichiara gli stati in cui un contenuto del tipo arriva quando la sua
	 * pubblicazione e' finita regolarmente.
	 *
	 * Dalla 1.16.0. Chi ha il solo permesso di consultare gli scaduti apre i
	 * file dei contenuti in questi stati come quelli dei pubblicati. Gli stati
	 * vanno gia' registrati in WordPress e non sono pubblici ne' privati; gli
	 * stati di lavoro di WordPress sono rifiutati. Una dichiarazione per tipo,
	 * che non si sovrascrive.
	 *
	 * @param string $tipo  Identificativo del tipo.
	 * @param array  $stati Elenco non vuoto di stati.
	 * @return true|WP_Error
	 */
	function conformita_core_dichiara_stati_conclusi( $tipo, $stati ) {
		return Conformita_Core_Tipi::dichiara_stati_conclusi( $tipo, $stati );
	}
}

if ( ! function_exists( 'conformita_core_tipi_registrati' ) ) {
	/**
	 * Identificativi dei tipi registrati, nell'ordine di registrazione.
	 *
	 * @return array<int, string>
	 */
	function conformita_core_tipi_registrati() {
		return Conformita_Core_Tipi::identificativi();
	}
}

if ( ! function_exists( 'conformita_core_chiave_fine_pubblicazione' ) ) {
	/**
	 * La chiave del metadato che contiene la fine della pubblicazione.
	 *
	 * È una sola per tutti i tipi e non è configurabile dal componente: il
	 * filtro di lettura deve poterla usare senza chiedere permesso a nessuno.
	 *
	 * @return string
	 */
	function conformita_core_chiave_fine_pubblicazione() {
		return Conformita_Core_Scadenza::chiave();
	}
}

if ( ! function_exists( 'conformita_core_valida_fine_pubblicazione' ) ) {
	/**
	 * Il valore è una data valida per la fine della pubblicazione.
	 *
	 * @param mixed $valore Valore da validare.
	 * @return true|WP_Error
	 */
	function conformita_core_valida_fine_pubblicazione( $valore ) {
		return Conformita_Core_Scadenza::valida( $valore );
	}
}

if ( ! function_exists( 'conformita_core_imposta_fine_pubblicazione' ) ) {
	/**
	 * Scrive la fine della pubblicazione su un contenuto di tipo gestito.
	 *
	 * Il componente non scrive il metadato per conto proprio: se lo facesse, la
	 * validazione sarebbe facoltativa. Idempotente: impostare due volte la
	 * stessa data non è un errore.
	 *
	 * @param int    $post_id Identificativo del contenuto.
	 * @param string $data    Data di fine, formato AAAA-MM-GG.
	 * @return true|WP_Error
	 */
	function conformita_core_imposta_fine_pubblicazione( $post_id, $data ) {
		return Conformita_Core_Scadenza::imposta( $post_id, $data );
	}
}

if ( ! function_exists( 'conformita_core_fine_pubblicazione' ) ) {
	/**
	 * La fine della pubblicazione registrata su un contenuto.
	 *
	 * Una lettura fallita nella banca dati non è una data assente: dalla
	 * versione 1.9.0 dell'interfaccia restituisce l'errore
	 * `conformita_core_lettura_fallita`, e non la stringa vuota.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return string|WP_Error La data, stringa vuota se assente.
	 */
	function conformita_core_fine_pubblicazione( $post_id ) {
		return Conformita_Core_Scadenza::fine( $post_id );
	}
}

if ( ! function_exists( 'conformita_core_istante_scadenza' ) ) {
	/**
	 * L'istante in cui il contenuto smette di essere pubblicabile.
	 *
	 * Mezzanotte del giorno successivo alla data di fine, nel fuso del sito: la
	 * data di fine è inclusiva.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return DateTimeImmutable|WP_Error
	 */
	function conformita_core_istante_scadenza( $post_id ) {
		return Conformita_Core_Scadenza::istante( $post_id );
	}
}

if ( ! function_exists( 'conformita_core_scaduto' ) ) {
	/**
	 * Il contenuto è scaduto.
	 *
	 * Non restituisce mai un errore: è chiamata nel percorso di lettura. Data
	 * assente, valore corrotto o tipo non gestito danno tutti scaduto.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return bool
	 */
	function conformita_core_scaduto( $post_id ) {
		return Conformita_Core_Scadenza::scaduto( $post_id );
	}
}

if ( ! function_exists( 'conformita_core_versione_api' ) ) {
	/**
	 * Versione dell'API esposta da core.
	 *
	 * È una cosa diversa dalla versione del plugin: i componenti dipendenti
	 * verificano questa, che cambia solo quando cambia il contratto.
	 *
	 * @return string
	 */
	function conformita_core_versione_api() {
		return Conformita_Core_Dipendenza::versione_api();
	}
}

if ( ! function_exists( 'conformita_core_api_compatibile' ) ) {
	/**
	 * La versione disponibile dell'API soddisfa quella richiesta.
	 *
	 * Entrambe le versioni si passano esplicitamente: la versione disponibile è
	 * un parametro e non un valore letto di nascosto, così la funzione resta
	 * verificabile anche nel caso in cui core non esponga nessuna versione.
	 *
	 * @param string      $richiesta   Versione richiesta dal componente.
	 * @param string|null $disponibile Versione disponibile, di norma conformita_core_versione_api().
	 * @return bool
	 */
	function conformita_core_api_compatibile( $richiesta, $disponibile ) {
		return Conformita_Core_Dipendenza::compatibile( $richiesta, $disponibile );
	}
}

if ( ! function_exists( 'conformita_core_avvia_componente' ) ) {
	/**
	 * Guardia di avvio di un componente dipendente.
	 *
	 * Il componente la chiama all'avvio, dopo essersi assicurato che core sia
	 * caricato. Se la versione di API non è compatibile il componente viene
	 * disattivato con un avviso leggibile in bacheca, senza errore fatale.
	 *
	 * @param string $nome          Nome visibile del componente.
	 * @param string $file          Percorso del componente come lo conosce WordPress.
	 * @param string $api_richiesta Versione di API richiesta dal componente.
	 * @return bool Vero se il componente può proseguire l'avvio.
	 */
	function conformita_core_avvia_componente( $nome, $file, $api_richiesta ) {
		return Conformita_Core_Dipendenza::avvia_componente( $nome, $file, $api_richiesta );
	}
}

if ( ! function_exists( 'conformita_core_deposita_allegato' ) ) {
	/**
	 * Deposita un file su un contenuto di tipo gestito.
	 *
	 * Il componente non sposta i file per conto proprio: se lo facesse, la
	 * cartella protetta sarebbe facoltativa e l'impronta non esisterebbe.
	 *
	 * **`origine` è obbligatoria e non ha valore predefinito.** Vale
	 * `caricamento`, e allora la funzione pretende che il file provenga davvero
	 * da un caricamento HTTP, oppure `percorso_locale`, e allora chi chiama
	 * dichiara di sapere che i byte sono già sul disco. Un predefinito sarebbe
	 * sbagliato in tutte e due le direzioni: `caricamento` renderebbe
	 * impossibile l'importazione, `percorso_locale` trasformerebbe un percorso
	 * ricevuto dall'esterno in una lettura di file arbitrari.
	 *
	 * **Si rifiuta se la protezione della cartella non risulta verificata.** Fra
	 * scrivere un file che non si è in grado di proteggere e non scriverlo, non
	 * si scrive.
	 *
	 * @param int                  $post_id Contenuto padre.
	 * @param array<string, mixed> $file    Voce nella forma di `$_FILES`.
	 * @param array<string, mixed> $opzioni Opzioni: `origine` è obbligatoria.
	 * @return int|WP_Error Identificativo dell'allegato, oppure errore.
	 */
	function conformita_core_deposita_allegato( $post_id, array $file, array $opzioni = array() ) {
		return Conformita_Core_Allegati::deposita( $post_id, $file, $opzioni );
	}
}

if ( ! function_exists( 'conformita_core_indirizzo_consegna' ) ) {
	/**
	 * L'indirizzo pubblico da cui si scarica un allegato depositato.
	 *
	 * È l'unico indirizzo da stampare: il percorso del file non si pubblica mai,
	 * e `wp_get_attachment_url()` restituisce già questo.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return string|WP_Error
	 */
	function conformita_core_indirizzo_consegna( $allegato_id ) {
		return Conformita_Core_Consegna::indirizzo( $allegato_id );
	}
}

if ( ! function_exists( 'conformita_core_indirizzo_consegna_amministrativa' ) ) {
	/**
	 * L'indirizzo amministrativo, con il proprio nonce.
	 *
	 * Serve il file a chi ha il permesso sul contenuto, con la capability del
	 * tipo, oppure a chi consulta gli scaduti del tipo, per i contenuti
	 * pubblicati. Dalla 1.11.0 il file di un contenuto scaduto lo apre solo chi
	 * ha il permesso di consultare gli scaduti, anche se gestisce il tipo.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return string|WP_Error
	 */
	function conformita_core_indirizzo_consegna_amministrativa( $allegato_id ) {
		return Conformita_Core_Consegna::indirizzo_amministrativo( $allegato_id );
	}
}

if ( ! function_exists( 'conformita_core_allegato_protetto' ) ) {
	/**
	 * L'allegato è stato depositato attraverso il core.
	 *
	 * Non restituisce mai un errore: è chiamata nel percorso di lettura.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return bool
	 */
	function conformita_core_allegato_protetto( $allegato_id ) {
		return Conformita_Core_Allegati::protetto( $allegato_id );
	}
}

if ( ! function_exists( 'conformita_core_impronta_allegato' ) ) {
	/**
	 * L'impronta calcolata al momento del deposito.
	 *
	 * Calcolata al deposito e non alla scadenza, perché alla scadenza si potrebbe
	 * solo fotografare quello che sta sul disco in quel momento, che non attesta
	 * ciò che è stato pubblicato. Non si ricalcola alla lettura.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return array<string, mixed>|WP_Error Con `algoritmo`, `valore`, `dimensione`, `deposito`.
	 */
	function conformita_core_impronta_allegato( $allegato_id ) {
		return Conformita_Core_Allegati::impronta( $allegato_id );
	}
}

if ( ! function_exists( 'conformita_core_stato_protezione_allegati' ) ) {
	/**
	 * Lo stato della protezione della cartella, letto e non misurato.
	 *
	 * **Non fa nessuna richiesta.** `copertura` vale `verificata`, `non_coperta`
	 * oppure `ignota`, e riporta sempre l'esito vero anche quando lo
	 * scavalcamento è dichiarato: la costante cambia che cosa il deposito fa,
	 * non che cosa lo stato dice.
	 *
	 * @return array<string, mixed>
	 */
	function conformita_core_stato_protezione_allegati() {
		return Conformita_Core_Allegati::stato();
	}
}

if ( ! function_exists( 'conformita_core_verifica_protezione_allegati' ) ) {
	/**
	 * Rifà la verifica della protezione e conserva il nuovo esito.
	 *
	 * Chiede l'esca al sito stesso e legge la risposta. Costa una richiesta
	 * HTTP, quindi è una cosa che si chiede e non che capita: il deposito la
	 * rifà da sé solo quando ha dovuto riscrivere i file di regole.
	 *
	 * @return array<string, mixed> Lo stato con l'esito appena misurato.
	 */
	function conformita_core_verifica_protezione_allegati() {
		return Conformita_Core_Allegati::verifica();
	}
}

if ( ! function_exists( 'conformita_core_registra_voce' ) ) {
	/**
	 * Aggiunge una voce al registro delle modifiche.
	 *
	 * La voce si aggiunge e non si modifica più. Chi agisce e quando non si
	 * dichiarano: core scrive l'utente della richiesta in corso e l'istante del
	 * proprio orologio.
	 *
	 * Chiavi ammesse, tutte le altre sono un errore:
	 * - `sezione`, obbligatoria: una sezione registrata;
	 * - `azione`, obbligatoria: il nome dell'operazione, in `[a-z0-9_]`, diverso
	 *   da quelli che core registra da sé;
	 * - `contenuto`: il contenuto interessato, di un tipo della stessa sezione;
	 * - `motivazione`: il motivo, un testo non vuoto;
	 * - `dettagli`: coppie di nome e valore semplice, o elenco di valori semplici;
	 * - `riferimento`: una voce precedente dello stesso contenuto e della stessa
	 *   sezione, per aggiungere qualcosa che la riguarda senza riscriverla;
	 * - `chiave`: una chiave che nessun'altra voce può portare, per le
	 *   operazioni che si fanno una volta sola. Due scritture con la stessa
	 *   chiave, anche nello stesso istante, danno una voce e un errore, e
	 *   l'errore porta nei dati il numero della voce che esiste già.
	 *
	 * @param array<string, mixed> $voce Descrizione della voce.
	 * @return int|WP_Error Numero della voce, oppure errore.
	 */
	function conformita_core_registra_voce( $voce ) {
		return Conformita_Core_Registro::registra( $voce );
	}
}

if ( ! function_exists( 'conformita_core_voce_registro' ) ) {
	/**
	 * Una voce del registro.
	 *
	 * @param int $id Numero della voce.
	 * @return array<string, mixed>|null La voce, oppure nullo se non esiste.
	 */
	function conformita_core_voce_registro( $id ) {
		return Conformita_Core_Registro::voce( $id );
	}
}

if ( ! function_exists( 'conformita_core_voci_registro' ) ) {
	/**
	 * Le voci del registro che rispondono ai filtri.
	 *
	 * Filtri ammessi: `sezione`, `contenuto`, `azione`, `utente`, `origine`,
	 * `riferimento`, `dal` e `al` (giorni civili AAAA-MM-GG nel fuso del sito,
	 * inclusi), `ordine` (`crescente`, che è l'ordine di scrittura, oppure
	 * `decrescente`), `per_pagina` e `pagina` (che vale solo insieme a
	 * `per_pagina`). Un filtro sconosciuto o malformato è un errore, non un
	 * filtro ignorato, e lo è anche un filtro con valore nullo: chi cerca le
	 * voci di un contenuto che non ha trovato riceve un errore, non le voci di
	 * tutti.
	 *
	 * La funzione non controlla i permessi: chi la chiama risponde di chi vede
	 * quello che restituisce.
	 *
	 * Ogni voce ha `id`, `istante` (un `DateTimeImmutable` in UTC, da portare
	 * nel fuso del sito con `wp_timezone()` prima di mostrarlo), `utente` (il
	 * numero, zero per il sistema), `sezione`, `contenuto` e `tipo` (nulli per
	 * una voce della sola sezione), `azione`, `origine` (`automatica` o
	 * `componente`), `motivazione` (nulla se assente), `dettagli` (elenco,
	 * vuoto se assenti), `riferimento` e `chiave` (nulli se assenti).
	 *
	 * @param array<string, mixed> $filtri Filtri.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	function conformita_core_voci_registro( $filtri = array() ) {
		return Conformita_Core_Registro::voci( $filtri );
	}
}

if ( ! function_exists( 'conformita_core_capacita_registro' ) ) {
	/**
	 * La capability che apre la schermata di consultazione del registro.
	 *
	 * Core non la assegna a nessun ruolo: la assegna il componente.
	 *
	 * @return string
	 */
	function conformita_core_capacita_registro() {
		return Conformita_Core_Registro::CAPACITA;
	}
}

if ( ! function_exists( 'conformita_core_registra_collegamento' ) ) {
	/**
	 * Registra un collegamento perché il verificatore ne controlli la risposta.
	 *
	 * L'intervallo è la politica del componente e non ha un valore
	 * predefinito: senza, la registrazione si rifiuta. Registrare di nuovo lo
	 * stesso indirizzo lascia l'esito; un indirizzo diverso lo riporta a "mai".
	 *
	 * @param string $sezione    Sezione registrata.
	 * @param string $chiave     Chiave scelta dal componente: minuscole, cifre, trattino e trattino basso.
	 * @param string $indirizzo  Indirizzo http o https.
	 * @param int    $intervallo Ogni quanti giorni ricontrollare, da 1 a 90.
	 * @return true|WP_Error
	 */
	function conformita_core_registra_collegamento( $sezione, $chiave, $indirizzo, $intervallo ) {
		return Conformita_Core_Collegamenti::registra( $sezione, $chiave, $indirizzo, $intervallo );
	}
}

if ( ! function_exists( 'conformita_core_togli_collegamento' ) ) {
	/**
	 * Toglie un collegamento dal verificatore; vero anche se non c'era.
	 *
	 * @param string $sezione Sezione registrata.
	 * @param string $chiave  Chiave.
	 * @return true|WP_Error
	 */
	function conformita_core_togli_collegamento( $sezione, $chiave ) {
		return Conformita_Core_Collegamenti::togli( $sezione, $chiave );
	}
}

if ( ! function_exists( 'conformita_core_collegamento' ) ) {
	/**
	 * Un collegamento con l'esito dell'ultima verifica.
	 *
	 * Chiavi: `indirizzo`, `intervallo`, `esito` (`mai`, `raggiungibile`,
	 * `rotto`), `codice` (intero o nullo), `motivo`, `verificato` e
	 * `registrato` (istanti UTC `Y-m-d H:i:s`, `verificato` vuoto se mai;
	 * dalla 1.13.0 `registrato` riparte quando cambia l'indirizzo),
	 * `in_ritardo` (dalla 1.13.0: vero se l'ultimo controllo, o la
	 * registrazione se mai controllato, e piu vecchio dell'intervallo piu un
	 * giorno; calcolato in lettura).
	 *
	 * @param string $sezione Sezione registrata.
	 * @param string $chiave  Chiave.
	 * @return array<string, mixed>|null|WP_Error Nullo se non c'è; errore se la lettura fallisce.
	 */
	function conformita_core_collegamento( $sezione, $chiave ) {
		return Conformita_Core_Collegamenti::collegamento( $sezione, $chiave );
	}
}

if ( ! function_exists( 'conformita_core_verifica_collegamento' ) ) {
	/**
	 * Controlla adesso un collegamento, con tempo massimo e rinvii limitati, e ne scrive l'esito.
	 *
	 * @param string $sezione Sezione registrata.
	 * @param string $chiave  Chiave.
	 * @return array<string, mixed>|WP_Error Il collegamento con l'esito nuovo.
	 */
	function conformita_core_verifica_collegamento( $sezione, $chiave ) {
		return Conformita_Core_Collegamenti::verifica( $sezione, $chiave );
	}
}

if ( ! function_exists( 'conformita_core_prendi_lucchetto' ) ) {
	/**
	 * Prende un lucchetto della banca dati, attendendo al massimo i secondi indicati.
	 *
	 * Dalla 1.14.0. Dove la banca dati tiene un solo lucchetto per connessione,
	 * tutti i lucchetti di tutti i componenti diventano lo stesso, contato qui:
	 * una presa dentro un'altra della stessa connessione riesce subito, e il
	 * lucchetto torna libero quando si lascia l'ultima. Senza una risposta
	 * sulla versione della banca dati la presa si rifiuta, come un lucchetto
	 * occupato.
	 *
	 * @param string $nome   Nome del lucchetto, gia' distinto per sito dal componente.
	 * @param int    $attesa Secondi di attesa.
	 * @return bool Vero se preso.
	 */
	function conformita_core_prendi_lucchetto( $nome, $attesa ) {
		return Conformita_Core_Lucchetti::prendi( (string) $nome, (int) $attesa );
	}
}

if ( ! function_exists( 'conformita_core_lascia_lucchetto' ) ) {
	/**
	 * Lascia un lucchetto preso con `conformita_core_prendi_lucchetto()`.
	 *
	 * Dalla 1.14.0.
	 *
	 * @param string $nome Nome del lucchetto.
	 */
	function conformita_core_lascia_lucchetto( $nome ) {
		Conformita_Core_Lucchetti::lascia( (string) $nome );
	}
}

if ( ! function_exists( 'conformita_core_trattieni_lucchetto' ) ) {
	/**
	 * Chiude una presa senza lasciare il lucchetto: lo lascia la banca dati alla
	 * fine della connessione.
	 *
	 * Dalla 1.14.0. Serve dentro una transazione aperta da altri, dove le
	 * scritture diventano definitive solo quando quella si chiude.
	 *
	 * @param string $nome Nome del lucchetto.
	 */
	function conformita_core_trattieni_lucchetto( $nome ) {
		Conformita_Core_Lucchetti::trattieni( (string) $nome );
	}
}

if ( ! function_exists( 'conformita_core_lucchetto_chiuso_senza_rilascio' ) ) {
	/**
	 * Chiude una presa senza chiedere niente alla banca dati, per chi ha
	 * lasciato il lucchetto con un'istruzione propria.
	 *
	 * Dalla 1.14.0.
	 */
	function conformita_core_lucchetto_chiuso_senza_rilascio() {
		Conformita_Core_Lucchetti::presa_chiusa();
	}
}

if ( ! function_exists( 'conformita_core_lucchetto_tenuto_qui' ) ) {
	/**
	 * Se il lucchetto unico e' gia' tenuto da un'altra presa di questa
	 * connessione: un'operazione annidata, anche di un altro componente, o una
	 * presa trattenuta. Dove i lucchetti sono distinti, sempre falso.
	 *
	 * Dalla 1.14.0.
	 *
	 * @param int $mie Le prese aperte che sono di chi chiede: zero prima di prendere, una prima di lasciare.
	 * @return bool
	 */
	function conformita_core_lucchetto_tenuto_qui( $mie = 0 ) {
		return Conformita_Core_Lucchetti::tenuto_da_altri_qui( (int) $mie );
	}
}

if ( ! function_exists( 'conformita_core_nome_lucchetto' ) ) {
	/**
	 * Il nome con cui un lucchetto arriva alla banca dati: quello dato, o quello
	 * comune dove la banca dati ne tiene uno solo.
	 *
	 * Dalla 1.14.0. Per chi scrive istruzioni proprie sui lucchetti.
	 *
	 * @param string $nome Nome del lucchetto.
	 * @return string
	 */
	function conformita_core_nome_lucchetto( $nome ) {
		return Conformita_Core_Lucchetti::nome( (string) $nome );
	}
}

if ( ! function_exists( 'conformita_core_lucchetto_unico' ) ) {
	/**
	 * Se la connessione tiene un solo lucchetto alla volta.
	 *
	 * Dalla 1.14.0.
	 *
	 * @return bool
	 */
	function conformita_core_lucchetto_unico() {
		return Conformita_Core_Lucchetti::unico();
	}
}

if ( ! function_exists( 'conformita_core_versione_a_lucchetto_unico' ) ) {
	/**
	 * Se una versione della banca dati, come la dice `VERSION()`, tiene un solo
	 * lucchetto per connessione. Nel dubbio, si'.
	 *
	 * Dalla 1.14.0.
	 *
	 * @param string $versione La risposta di `VERSION()`.
	 * @return bool
	 */
	function conformita_core_versione_a_lucchetto_unico( $versione ) {
		return Conformita_Core_Lucchetti::versione_a_lucchetto_unico( (string) $versione );
	}
}

if ( ! function_exists( 'conformita_core_registra_azione_alla_scadenza' ) ) {
	/**
	 * Registra che cosa fare sui contenuti scaduti di una sezione.
	 *
	 * Dalla 1.15.0. Il compito alla scadenza, a ogni esecuzione, prende i
	 * contenuti dei tipi della sezione che sono in uno degli stati dati e la
	 * cui data di fine e' passata, e chiama l'azione su ciascuno. L'azione e'
	 * del componente: riceve l'identificativo e restituisce vero se ha fatto,
	 * un errore altrimenti; dopo, il contenuto non deve piu' essere in uno
	 * degli stati, o il compito lo riprende. Senza registrazione il compito non
	 * tocca niente; gli stati non hanno un valore predefinito.
	 *
	 * @param string   $sezione Sezione registrata.
	 * @param array    $stati   Gli stati da cui prendere i contenuti scaduti.
	 * @param callable $azione  L'azione su ciascun contenuto.
	 * @return true|WP_Error
	 */
	function conformita_core_registra_azione_alla_scadenza( $sezione, $stati, $azione ) {
		return Conformita_Core_Compito_Scadenza::registra( $sezione, $stati, $azione );
	}
}

if ( ! function_exists( 'conformita_core_esegui_compito_scadenza' ) ) {
	/**
	 * Esegue adesso il compito alla scadenza.
	 *
	 * Dalla 1.15.0. Di norma lo esegue WordPress, ogni ora, quando il cron di
	 * sistema chiama `wp-cron.php`. Una seconda esecuzione mentre gira la prima
	 * rinuncia con un errore.
	 *
	 * @return array{contenuti: int, falliti: int, completo: bool}|WP_Error
	 */
	function conformita_core_esegui_compito_scadenza() {
		return Conformita_Core_Compito_Scadenza::esegui();
	}
}

if ( ! function_exists( 'conformita_core_battito_compito_scadenza' ) ) {
	/**
	 * L'ultima esecuzione del compito arrivata in fondo, o nulla se non ce n'e' stata.
	 *
	 * Dalla 1.15.0. Chiavi: `istante` (UTC, `Y-m-d H:i:s`), `contenuti`
	 * (azioni riuscite), `falliti`, `completo` (falso se l'esecuzione si e'
	 * fermata al massimo di contenuti e ne restano altri). Nessun dato dei
	 * contenuti.
	 *
	 * @return array<string, mixed>|null
	 */
	function conformita_core_battito_compito_scadenza() {
		return Conformita_Core_Compito_Scadenza::battito();
	}
}
