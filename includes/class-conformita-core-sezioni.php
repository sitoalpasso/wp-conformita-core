<?php
/**
 * Registro delle sezioni e delle politiche che le governano.
 *
 * Il registro è per identificativo di sezione, non globale. È la ragione per cui
 * due componenti con politiche opposte possono stare nello stesso sito: non
 * esiste nessun posto in cui la politica sia una sola, quindi non esiste nessun
 * modo in cui il secondo componente caricato cambi la politica del primo.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro in memoria delle sezioni dichiarate dai componenti.
 */
final class Conformita_Core_Sezioni {

	/**
	 * Politiche per identificativo di sezione.
	 *
	 * @var array<string, Conformita_Core_Politica>
	 */
	private static $sezioni = array();

	/**
	 * Registra una sezione con la sua politica.
	 *
	 * L'insieme dei caratteri ammessi nell'identificativo è lo stesso dei tipi di
	 * contenuto: lettere minuscole, cifre e trattino basso. È una **restrizione
	 * decisa prima del rilascio**, non la correzione di un difetto: senza
	 * normalizzazione `sezione-a` e `sezione_a` produrrebbero capability diverse,
	 * quindi non collidono. Il vincolo esiste per tre ragioni. Una regola sola
	 * invece di due, perché chi scrive un componente non deve ricordarne una per
	 * i tipi e una per le sezioni. La convenzione dei nomi di capability in
	 * WordPress, che assume l'insieme `[a-z_]`. E il fatto che una
	 * normalizzazione futura, per esempio con `sanitize_key`, riaprirebbe
	 * davvero la collisione della riga C-86: vietare adesso il carattere che la
	 * produrrebbe costa zero, mentre toglierlo dopo costerebbe una migrazione.
	 *
	 * Da qui deriveranno le capability di archivio della politica di scadenza,
	 * ed è la ragione per cui il vincolo entra adesso e non più avanti. Riga di
	 * collaudo C-95.
	 *
	 * @param string $sezione   Identificativo della sezione.
	 * @param array  $politica  Politiche dichiarate dal componente.
	 * @return true|WP_Error Vero se la sezione è attiva, errore altrimenti.
	 */
	public static function registra( $sezione, array $politica ) {
		$sezione = is_string( $sezione ) ? trim( $sezione ) : '';

		if ( '' === $sezione ) {
			return new WP_Error(
				'conformita_core_sezione_non_valida',
				__( 'Identificativo di sezione mancante: una sezione senza identificativo non è registrabile.', 'conformita-core' )
			);
		}

		if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $sezione ) ) {
			return new WP_Error(
				'conformita_core_sezione_non_valida',
				__( 'Identificativo di sezione non valido: da uno a sessantaquattro caratteri fra lettere minuscole, cifre e trattino basso.', 'conformita-core' )
			);
		}

		if ( isset( self::$sezioni[ $sezione ] ) ) {
			return new WP_Error(
				'conformita_core_sezione_duplicata',
				sprintf(
					/* translators: %s: identificativo della sezione. */
					__( 'Sezione %s già registrata: la politica di una sezione registrata non si sovrascrive.', 'conformita-core' ),
					$sezione
				)
			);
		}

		$valore = Conformita_Core_Politica::crea( $politica );

		if ( is_wp_error( $valore ) ) {
			return $valore;
		}

		self::$sezioni[ $sezione ] = $valore;

		return true;
	}

	/**
	 * Politica di una sezione registrata.
	 *
	 * @param string $sezione Identificativo della sezione.
	 * @return Conformita_Core_Politica|WP_Error Politica dichiarata, oppure errore
	 *                                           se la sezione non è registrata.
	 */
	public static function politica( $sezione ) {
		$sezione = is_string( $sezione ) ? trim( $sezione ) : '';

		if ( ! isset( self::$sezioni[ $sezione ] ) ) {
			return new WP_Error(
				'conformita_core_sezione_sconosciuta',
				sprintf(
					/* translators: %s: identificativo della sezione. */
					__( 'Sezione %s non registrata: nessuna politica da applicare.', 'conformita-core' ),
					$sezione
				)
			);
		}

		return self::$sezioni[ $sezione ];
	}

	/**
	 * La sezione è registrata con una politica valida.
	 *
	 * @param string $sezione Identificativo della sezione.
	 * @return bool
	 */
	public static function registrata( $sezione ) {
		$sezione = is_string( $sezione ) ? trim( $sezione ) : '';

		return isset( self::$sezioni[ $sezione ] );
	}

	/**
	 * Identificativi delle sezioni registrate, nell'ordine di registrazione.
	 *
	 * @return array<int, string>
	 */
	public static function identificativi() {
		return array_keys( self::$sezioni );
	}

	/**
	 * Svuota il registro.
	 *
	 * Serve alla suite di test, dove tutte le registrazioni girano nello stesso
	 * processo e una sezione lasciata dietro renderebbe verde o rossa la prova
	 * successiva per motivi che non c'entrano con quello che prova.
	 */
	public static function azzera() {
		self::$sezioni = array();
	}
}
