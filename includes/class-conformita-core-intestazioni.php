<?php
/**
 * Emissione delle righe di intestazione della risposta.
 *
 * Un punto solo da cui passano le righe che core manda da se', cioe' il divieto
 * di indicizzazione sulle pagine e le intestazioni dei file consegnati. Esiste
 * perche' quello che conta e' la risposta che esce, non quella che si prepara:
 * una riga composta bene e poi non mandata, o mandata sostituendo le altre, e'
 * un divieto che nessun motore legge. Dentro la suite di test l'uscita e' gia'
 * cominciata e PHP non registra le intestazioni, quindi le prove sostituiscono
 * l'emissione con una registrazione che rispetta le stesse regole di
 * sostituzione di `header()`. Righe C-239 e C-240.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invio delle righe di intestazione, sostituibile dalle prove.
 */
final class Conformita_Core_Intestazioni {

	/**
	 * Emettitore sostituito, usato solo dalle prove.
	 *
	 * @var callable|null
	 */
	private static $emettitore = null;

	/**
	 * Manda una riga di intestazione.
	 *
	 * @param string $riga        Riga completa, nome e valore.
	 * @param bool   $sostituisci Vero per sostituire le righe con lo stesso
	 *                            nome, falso per aggiungerne una accanto.
	 */
	public static function manda( $riga, $sostituisci = true ) {
		if ( null !== self::$emettitore ) {
			call_user_func( self::$emettitore, (string) $riga, (bool) $sostituisci );

			return;
		}

		if ( ! headers_sent() ) {
			header( (string) $riga, (bool) $sostituisci );
		}
	}

	/**
	 * Sostituisce l'emissione delle righe.
	 *
	 * @internal Solo per le prove.
	 *
	 * @param callable $emettitore Funzione che riceve la riga e il modo.
	 */
	public static function fissa_emettitore( $emettitore ) {
		self::$emettitore = is_callable( $emettitore ) ? $emettitore : null;
	}

	/**
	 * Rimette l'emissione vera.
	 *
	 * @internal Solo per le prove.
	 */
	public static function azzera_emettitore() {
		self::$emettitore = null;
	}
}
