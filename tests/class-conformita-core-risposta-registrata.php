<?php
/**
 * Registrazione delle righe di intestazione emesse, per le prove.
 *
 * Dentro la suite l'uscita e' gia' cominciata e PHP non registra le
 * intestazioni, quindi le prove raccolgono le righe qui. Le regole sono quelle
 * di `header()`: una riga mandata in sostituzione toglie tutte le righe
 * precedenti con lo stesso nome, senza badare a maiuscole e minuscole; una
 * riga mandata in aggiunta si mette accanto. Righe C-239 e C-240.
 *
 * @package Conformita_Core
 */

/**
 * Le righe di una risposta, nell'ordine in cui restano.
 */
class Conformita_Core_Risposta_Registrata {

	/**
	 * Righe rimaste.
	 *
	 * @var array<int, string>
	 */
	private $righe = array();

	/**
	 * Registra una riga come la tratterebbe `header()`.
	 *
	 * @param string $riga        Riga completa.
	 * @param bool   $sostituisci Vero per sostituire le righe con lo stesso nome.
	 */
	public function registra( $riga, $sostituisci = true ) {
		$nome = self::nome( $riga );

		if ( $sostituisci ) {
			$this->righe = array_values(
				array_filter(
					$this->righe,
					function ( $presente ) use ( $nome ) {
						return self::nome( $presente ) !== $nome;
					}
				)
			);
		}

		$this->righe[] = $riga;
	}

	/**
	 * I valori delle righe rimaste con un nome, nell'ordine.
	 *
	 * @param string $nome Nome dell'intestazione.
	 * @return array<int, string>
	 */
	public function valori( $nome ) {
		$valori = array();

		foreach ( $this->righe as $riga ) {
			if ( self::nome( $riga ) === strtolower( $nome ) ) {
				$valori[] = trim( substr( $riga, strpos( $riga, ':' ) + 1 ) );
			}
		}

		return $valori;
	}

	/**
	 * Nome di una riga, in minuscolo.
	 *
	 * @param string $riga Riga completa.
	 * @return string
	 */
	private static function nome( $riga ) {
		return strtolower( trim( (string) strstr( $riga, ':', true ) ) );
	}
}
