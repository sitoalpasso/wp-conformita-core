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
	 * Core le registra e non le assegna a nessun ruolo: appena registrato, il
	 * tipo non è visibile né modificabile da nessuno. Assegnarle è compito del
	 * componente, che è l'unico a sapere a quali ruoli spetta la sua sezione.
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
