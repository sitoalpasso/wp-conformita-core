<?php
/**
 * Lucchetti della banca dati, anche dove una connessione ne tiene uno solo.
 *
 * I componenti mettono in fila le operazioni che non devono sovrapporsi con i
 * lucchetti con nome della banca dati (`GET_LOCK`): un'importazione, una
 * numerazione, un salvataggio che tocca piu' righe insieme.
 *
 * **Non tutte le banche dati li trattano allo stesso modo.** Da MySQL 5.7.5 e
 * da MariaDB 10.0.2 una connessione tiene piu' lucchetti insieme, e lo stesso
 * lucchetto preso due volte va lasciato due volte. Prima, e WordPress ammette
 * ancora quelle versioni, una connessione ne tiene uno solo: prenderne un
 * secondo lascia il primo senza avviso, e un solo rilascio libera tutto. Un
 * lucchetto preso dentro un altro aprirebbe il primo a un'altra richiesta
 * mentre l'operazione lavora ancora.
 *
 * **Perche' qui e non in ogni componente.** La connessione e' una sola per
 * richiesta e la condividono tutti i componenti: un lucchetto preso da uno,
 * dentro un'operazione dell'altro, lascia quello dell'altro. Il conto delle
 * prese tenuto da ciascun componente per se' non lo vedrebbe. Lo tiene allora
 * questo, uno per connessione, per tutti.
 *
 * **Dove la connessione ne tiene uno solo, se ne usa uno solo.** Ogni
 * lucchetto di ogni componente diventa lo stesso lucchetto, preso una volta
 * sola dalla banca dati e contato qui: le prese annidate non chiedono niente,
 * e il rilascio arriva alla banca dati quando si lascia l'ultima. Le
 * operazioni vanno allora una alla volta anche quando non si toccherebbero:
 * e' piu' lento, ma non lascia passare nessuno in mezzo. Dove la connessione
 * ne tiene piu' d'uno, i lucchetti restano distinti, con il nome scelto dal
 * componente.
 *
 * Il tipo si legge dalla versione della banca dati, una volta per
 * connessione. Una versione letta ma che non si riconosce vale come lucchetto
 * unico: e' la lettura prudente, perche' il lucchetto unico e' corretto anche
 * dove se ne potrebbero tenere di piu', e la stessa banca dati da' la stessa
 * risposta a ogni connessione. Una versione che non si legge non decide
 * niente: le prese si rifiutano e la richiesta dopo chiede di nuovo, perche'
 * una connessione che scegliesse da sola il lucchetto unico non escluderebbe
 * quelle che, lette le versioni, usano i lucchetti distinti.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lucchetti della banca dati, non dati.

/**
 * Prende e lascia i lucchetti dei componenti.
 */
final class Conformita_Core_Lucchetti {

	/**
	 * Lo stato di ogni connessione: se tiene un lucchetto solo, quante prese
	 * annidate sono aperte, e se il lucchetto resta fino alla fine della
	 * connessione.
	 *
	 * @var \WeakMap<\wpdb, array{unico: bool, prese: int, trattenuto: bool, letto: bool}>|null
	 */
	private static ?\WeakMap $stato = null;

	/**
	 * Se la connessione tiene un solo lucchetto alla volta.
	 *
	 * @return bool
	 */
	public static function unico(): bool {
		return self::stato()['unico'];
	}

	/**
	 * Il nome con cui un lucchetto arriva alla banca dati.
	 *
	 * @param string $nome Nome del lucchetto.
	 * @return string
	 */
	public static function nome( string $nome ): string {
		if ( ! self::unico() ) {
			return $nome;
		}

		global $wpdb;

		return substr( $wpdb->prefix, 0, 40 ) . 'conformita_lucchetto';
	}

	/**
	 * Prende un lucchetto, attendendo al massimo i secondi indicati.
	 *
	 * @param string $nome   Nome del lucchetto.
	 * @param int    $attesa Secondi di attesa.
	 * @return bool Vero se preso.
	 */
	public static function prendi( string $nome, int $attesa ): bool {
		global $wpdb;

		$stato = self::stato();

		// Senza sapere che tipo di banca dati e', nessuna presa: chi chiama rinuncia come per un lucchetto occupato.
		if ( ! $stato['letto'] ) {
			return false;
		}

		if ( $stato['unico'] && $stato['prese'] > 0 ) {
			self::scrivi( array( 'prese' => $stato['prese'] + 1 ) + $stato );

			return true;
		}

		$preso = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::nome( $nome ), $attesa ) );

		if ( $preso && $stato['unico'] ) {
			self::scrivi( array( 'prese' => 1 ) + $stato );
		}

		return $preso;
	}

	/**
	 * Lascia un lucchetto preso con `prendi()`.
	 *
	 * @param string $nome Nome del lucchetto.
	 */
	public static function lascia( string $nome ): void {
		global $wpdb;

		$stato = self::stato();

		if ( $stato['unico'] ) {
			$prese = max( 0, $stato['prese'] - 1 );

			self::scrivi( array( 'prese' => $prese ) + $stato );

			if ( $prese > 0 || $stato['trattenuto'] ) {
				return;
			}
		}

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::nome( $nome ) ) );
	}

	/**
	 * Chiude una presa senza lasciare il lucchetto alla banca dati: lo lascia
	 * la banca dati stessa, alla fine della connessione.
	 *
	 * Serve dentro una transazione aperta da altri, dove le scritture diventano
	 * definitive solo quando quella si chiude.
	 *
	 * @param string $nome Nome del lucchetto.
	 */
	public static function trattieni( string $nome ): void {
		unset( $nome );

		$stato = self::stato();

		if ( $stato['unico'] ) {
			self::scrivi(
				array(
					'prese'      => max( 0, $stato['prese'] - 1 ),
					'trattenuto' => true,
				) + $stato
			);
		}
	}

	/**
	 * Se il lucchetto unico e' gia' tenuto da un'altra presa di questa
	 * connessione: un'operazione annidata, o una presa trattenuta.
	 *
	 * Dove i lucchetti sono distinti la risposta e' sempre no.
	 *
	 * @param int $mie Le prese aperte che sono di chi chiede: zero prima di
	 *                 prendere, una prima di lasciare.
	 * @return bool
	 */
	public static function tenuto_da_altri_qui( int $mie = 0 ): bool {
		$stato = self::stato();

		return $stato['unico'] && ( $stato['prese'] > $mie || $stato['trattenuto'] );
	}

	/**
	 * Chiude una presa senza chiedere niente alla banca dati, per chi ha
	 * lasciato il lucchetto con un'istruzione propria.
	 */
	public static function presa_chiusa(): void {
		$stato = self::stato();

		if ( $stato['unico'] ) {
			self::scrivi( array( 'prese' => max( 0, $stato['prese'] - 1 ) ) + $stato );
		}
	}

	/**
	 * Se la versione della banca dati tiene un solo lucchetto per connessione.
	 *
	 * @param string $versione La risposta di `VERSION()`.
	 * @return bool
	 */
	public static function versione_a_lucchetto_unico( string $versione ): bool {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $versione, $parti ) ) {
			return true;
		}

		// Una risposta che comincia con 5.5.5- e poi dice MariaDB porta la versione vera dopo il trattino.
		if ( false !== stripos( $versione, 'mariadb' ) && 1 === preg_match( '/^5\.5\.5-(\d+)\.(\d+)\.(\d+)/', $versione, $vere ) ) {
			$parti = $vere;
		}

		$numero = sprintf( '%d.%d.%d', $parti[1], $parti[2], $parti[3] );
		$minima = false !== stripos( $versione, 'mariadb' ) ? '10.0.2' : '5.7.5';

		return version_compare( $numero, $minima, '<' );
	}

	/**
	 * Lo stato della connessione, letto la prima volta.
	 *
	 * @return array{unico: bool, prese: int, trattenuto: bool, letto: bool}
	 */
	private static function stato(): array {
		global $wpdb;

		if ( null === self::$stato ) {
			self::$stato = new \WeakMap();
		}

		if ( ! isset( self::$stato[ $wpdb ] ) ) {
			$versione = $wpdb->get_var( 'SELECT VERSION()' );

			/*
			 * Senza risposta non si sceglie: questa connessione andrebbe sul
			 * lucchetto unico e un'altra, che la versione l'ha letta, su quelli
			 * distinti, e le due non si escluderebbero. Le prese si rifiutano, e
			 * la volta dopo si chiede di nuovo.
			 */
			if ( ! is_string( $versione ) ) {
				return array(
					'unico'      => true,
					'prese'      => 0,
					'trattenuto' => false,
					'letto'      => false,
				);
			}

			self::$stato[ $wpdb ] = array(
				'unico'      => self::versione_a_lucchetto_unico( $versione ),
				'prese'      => 0,
				'trattenuto' => false,
				'letto'      => true,
			);
		}

		return self::$stato[ $wpdb ];
	}

	/**
	 * Scrive lo stato della connessione.
	 *
	 * @param array{unico: bool, prese: int, trattenuto: bool, letto: bool} $stato Stato.
	 */
	private static function scrivi( array $stato ): void {
		global $wpdb;

		if ( ! $stato['letto'] ) {
			return;
		}

		self::$stato[ $wpdb ] = $stato;
	}
}
