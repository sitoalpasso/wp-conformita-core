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
