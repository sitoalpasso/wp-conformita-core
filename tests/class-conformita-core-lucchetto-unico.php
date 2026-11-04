<?php
/**
 * Imitazione di una banca dati che tiene un solo lucchetto per connessione.
 *
 * Le versioni di MySQL prima della 5.7.5 e di MariaDB prima della 10.0.2
 * trattano i lucchetti con nome in un altro modo: una connessione ne tiene uno
 * solo, e prenderne un secondo lascia il primo senza avviso; lo stesso
 * lucchetto preso due volte si lascia con un solo rilascio; `RELEASE_ALL_LOCKS`
 * non esiste. WordPress ammette ancora quelle versioni.
 *
 * L'imitazione riscrive le istruzioni prima che arrivino alla banca dati:
 * ogni `GET_LOCK` lascia prima tutti i lucchetti visti finora, compreso quello
 * che sta per prendere, cosi' una connessione non ne tiene mai piu' di uno e
 * mai due volte lo stesso; `RELEASE_ALL_LOCKS` diventa una funzione che non
 * esiste; `VERSION()` risponde con una versione di quel tipo. Vale per tutte
 * le connessioni del processo, anche quelle aperte dalle prove.
 *
 * Si accende in una prova con `attiva()`. La prova vera resta quella sulla
 * banca dati vecchia, nella CI: questa serve a vedere in locale, e a provare
 * i guasti.
 *
 * @package Conformita_Core
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- imitazione della banca dati nelle prove.

/**
 * Riscrive le istruzioni sui lucchetti come le tratterebbe una banca dati vecchia.
 */
final class Conformita_Core_Lucchetto_Unico {

	/**
	 * La versione con cui risponde l'imitazione.
	 */
	const VERSIONE = '5.5.64-MariaDB';

	/**
	 * Se l'imitazione e' accesa.
	 *
	 * @var bool
	 */
	private static bool $accesa = false;

	/**
	 * I nomi dei lucchetti visti, gia' fra apici.
	 *
	 * @var array<string, true>
	 */
	private static array $visti = array();

	/**
	 * Accende l'imitazione.
	 */
	public static function attiva(): void {
		if ( self::$accesa ) {
			return;
		}

		self::rilascia_tutti( $GLOBALS['wpdb'] );
		self::$accesa = true;
		add_filter( 'query', array( self::class, 'riscrivi' ), PHP_INT_MAX );
		self::dimentica_stato();
	}

	/**
	 * Spegne l'imitazione e lascia i lucchetti presi sotto di lei.
	 */
	public static function disattiva(): void {
		if ( ! self::$accesa ) {
			return;
		}

		self::rilascia_tutti( $GLOBALS['wpdb'] );
		remove_filter( 'query', array( self::class, 'riscrivi' ), PHP_INT_MAX );
		self::$accesa = false;
		self::$visti  = array();
		self::dimentica_stato();
	}

	/**
	 * Se l'imitazione e' accesa.
	 *
	 * @return bool
	 */
	public static function accesa(): bool {
		return self::$accesa;
	}

	/**
	 * Lascia tutti i lucchetti di una connessione, su ogni tipo di banca dati.
	 *
	 * Dove una connessione tiene un solo lucchetto, prenderne uno qualsiasi e
	 * lasciarlo libera quello che c'era. Altrove c'e' `RELEASE_ALL_LOCKS`.
	 *
	 * @param \wpdb $connessione Connessione.
	 */
	public static function rilascia_tutti( \wpdb $connessione ): void {
		$versione = $connessione->get_var( 'SELECT VERSION()' );

		if ( ! is_string( $versione ) || Conformita_Core_Lucchetti::versione_a_lucchetto_unico( $versione ) ) {
			$nome = $connessione->prepare( '%s', substr( $connessione->prefix, 0, 40 ) . 'conformita_rilascio' );

			$connessione->get_var( 'SELECT GET_LOCK( ' . $nome . ', 0 )' );
			$connessione->query( 'SELECT RELEASE_LOCK( ' . $nome . ' )' );
		} else {
			$connessione->query( 'SELECT RELEASE_ALL_LOCKS()' );
		}
	}

	/**
	 * Dimentica quello che il componente comune sa dei lucchetti delle connessioni.
	 */
	public static function dimentica_stato(): void {
		$stato = new \ReflectionProperty( Conformita_Core_Lucchetti::class, 'stato' );
		$stato->setAccessible( true );
		$stato->setValue( null, null );
	}

	/**
	 * Il filtro sulle istruzioni.
	 *
	 * @param mixed $istruzione Istruzione.
	 * @return mixed
	 */
	public static function riscrivi( $istruzione ) {
		if ( ! is_string( $istruzione ) ) {
			return $istruzione;
		}

		if ( 1 === preg_match( '/^\s*SELECT\s+VERSION\(\)\s*$/i', $istruzione ) ) {
			return "SELECT '" . self::VERSIONE . "'";
		}

		$istruzione = str_replace( 'RELEASE_ALL_LOCKS()', 'RELEASE_ALL_LOCKS_ASSENTE()', $istruzione );

		return (string) preg_replace_callback(
			"/GET_LOCK\\(\\s*('(?:[^'\\\\]|\\\\.)*')\\s*,\\s*(\\d+)\\s*\\)/",
			static function ( array $parti ): string {
				self::$visti[ $parti[1] ] = true;

				$lascia = array();

				foreach ( array_keys( self::$visti ) as $nome ) {
					$lascia[] = 'COALESCE( RELEASE_LOCK( ' . $nome . ' ), 0 )';
				}

				return 'IF( ( ' . implode( ' + ', $lascia ) . ' ) >= 0, GET_LOCK( ' . $parti[1] . ', ' . $parti[2] . ' ), NULL )';
			},
			$istruzione
		);
	}
}
