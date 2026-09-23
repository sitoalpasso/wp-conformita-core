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
	 * Serve il file anche quando il contenuto è scaduto, e pretende la
	 * capability del tipo: è la stessa esenzione che l'amministrazione ha sulle
	 * pagine, non l'archivio riservato, che è un'unità successiva.
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
	 * `decrescente`), `per_pagina` e `pagina`. Un filtro sconosciuto o
	 * malformato è un errore, non un filtro ignorato.
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
