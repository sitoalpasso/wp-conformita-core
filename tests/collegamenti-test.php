<?php
/**
 * Verificatore dei collegamenti, prima parte: registrazione e verifica.
 *
 * Righe di collaudo C-301..C-306, che dettagliano C-60..C-63. Scheda:
 * `docs/implementazione/verificatore-collegamenti.md`.
 *
 * L'altro sito non si contatta mai: ogni richiesta passa da
 * `pre_http_request`, che la prova sostituisce con una risposta finta e
 * annota con gli argomenti ricevuti.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul verificatore dei collegamenti.
 */
class Conformita_Core_Collegamenti_Test extends WP_UnitTestCase {

	const SEZIONE       = 'sezione_collegamenti';
	const SEZIONE_ALTRA = 'sezione_collegamenti_altra';
	const INDIRIZZO     = 'https://banca-dati.example.org/ente/dati';

	/**
	 * Richieste arrivate al finto altro sito: indirizzo e argomenti.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private $richieste = array();

	/**
	 * Risposta che il finto altro sito dara.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $risposta = array();

	/**
	 * Due sezioni registrate, tabella presente e vuota, altro sito finto.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();

		foreach ( array( self::SEZIONE, self::SEZIONE_ALTRA ) as $sezione ) {
			$this->assertTrue(
				conformita_core_registra_sezione(
					$sezione,
					array(
						'indicizzazione' => 'consentita',
						'scadenza'       => 'irraggiungibile',
					)
				)
			);
		}

		$this->assertTrue( Conformita_Core_Collegamenti::installa() );

		$this->richieste = array();
		$this->risposta  = self::risposta( 200 );

		add_filter( 'pre_http_request', array( $this, 'altro_sito' ), 10, 3 );
	}

	/**
	 * Guasti tolti.
	 */
	public function tear_down() {
		global $wpdb;

		remove_all_filters( 'query' );
		remove_filter( 'pre_http_request', array( $this, 'altro_sito' ), 10 );
		$wpdb->suppress_errors( false );
		Conformita_Core_Sezioni::azzera();

		parent::tear_down();
	}

	/**
	 * Il finto altro sito: annota la richiesta e risponde.
	 *
	 * @param mixed                $esito     Risposta proposta.
	 * @param array<string, mixed> $argomenti Argomenti della richiesta.
	 * @param string               $indirizzo Indirizzo.
	 * @return array<string, mixed>|WP_Error
	 */
	public function altro_sito( $esito, $argomenti, $indirizzo ) {
		unset( $esito );

		$this->richieste[] = array( $indirizzo, $argomenti );

		return $this->risposta;
	}

	/**
	 * Una risposta finta con il codice dato.
	 *
	 * @param int $codice Codice.
	 * @return array<string, mixed>
	 */
	private static function risposta( $codice ) {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $codice,
				'message' => get_status_header_desc( $codice ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Registra il collegamento di prova.
	 *
	 * @param string $indirizzo  Indirizzo.
	 * @param int    $intervallo Intervallo.
	 * @param string $chiave     Chiave.
	 * @param string $sezione    Sezione.
	 */
	private function registra( $indirizzo = self::INDIRIZZO, $intervallo = 7, $chiave = 'voce_uno', $sezione = self::SEZIONE ) {
		$this->assertTrue( conformita_core_registra_collegamento( $sezione, $chiave, $indirizzo, $intervallo ) );
	}

	/**
	 * Le righe della tabella, lette senza passare dal meccanismo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function righe() {
		global $wpdb;

		$tabella = Conformita_Core_Collegamenti::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- La prova guarda la tabella com'e.
		return $wpdb->get_results( "SELECT * FROM `{$tabella}`", ARRAY_A );
	}

	/**
	 * Vero se l'istante UTC dato e di adesso, con un minuto di margine.
	 *
	 * @param string $istante Istante `Y-m-d H:i:s` in UTC.
	 * @return bool
	 */
	private static function adesso( $istante ) {
		$letto = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $istante, new DateTimeZone( 'UTC' ) );

		return false !== $letto && abs( $letto->getTimestamp() - time() ) <= 60;
	}

	/**
	 * Le letture della tabella falliscono.
	 */
	private function guasta_letture() {
		global $wpdb;

		$tabella = Conformita_Core_Collegamenti::tabella();
		$wpdb->suppress_errors( true );
		add_filter(
			'query',
			static function ( $sql ) use ( $tabella ) {
				return 0 === strpos( ltrim( (string) $sql ), 'SELECT' ) && false !== strpos( (string) $sql, $tabella ) ? 'SELECT * FROM `' . $tabella . '_inesistente`' : $sql;
			}
		);
	}

	/**
	 * C-301: registrazione, e ogni parametro non valido rifiutato senza scrivere.
	 */
	public function test_c301_registrazione() {
		$this->registra();

		$letto = conformita_core_collegamento( self::SEZIONE, 'voce_uno' );

		$this->assertSame( self::INDIRIZZO, $letto['indirizzo'] );
		$this->assertSame( 7, $letto['intervallo'] );
		$this->assertSame( 'mai', $letto['esito'] );
		$this->assertNull( $letto['codice'] );
		$this->assertSame( '', $letto['motivo'] );
		$this->assertSame( '', $letto['verificato'] );
		$this->assertTrue( self::adesso( $letto['registrato'] ) );
		$this->assertSame( array(), $this->richieste, 'Registrare non contatta l\'altro sito.' );

		$validi   = array( self::SEZIONE, 'voce_due', self::INDIRIZZO, 7 );
		$attacchi = array(
			'sezione non registrata' => array( 0, 'sezione_inesistente' ),
			'chiave vuota'           => array( 1, '' ),
			'chiave maiuscola'       => array( 1, 'Voce' ),
			'chiave con spazio'      => array( 1, 'voce due' ),
			'chiave lunga'           => array( 1, str_repeat( 'a', 101 ) ),
			'chiave non testo'       => array( 1, 12 ),
			'senza schema'           => array( 2, 'banca-dati.example.org/ente' ),
			'ftp'                    => array( 2, 'ftp://banca-dati.example.org/ente' ),
			'javascript'             => array( 2, 'javascript:alert(1)' ),
			'con spazi'              => array( 2, 'https://banca-dati.example.org/ente dati' ),
			'a capo'                 => array( 2, "https://banca-dati.example.org/ente\n" ),
			'con virgolette'         => array( 2, 'https://banca-dati.example.org/ente"dati' ),
			'con parentesi angolari' => array( 2, 'https://banca-dati.example.org/<ente>' ),
			'lungo'                  => array( 2, 'https://banca-dati.example.org/' . str_repeat( 'a', 2048 ) ),
			'interno'                => array( 2, 'http://127.0.0.1/ente' ),
			'indirizzo non testo'    => array( 2, array( self::INDIRIZZO ) ),
			'intervallo mancante'    => array( 3, null ),
			'intervallo zero'        => array( 3, 0 ),
			'intervallo oltre'       => array( 3, 91 ),
			'intervallo testo'       => array( 3, '7' ),
			'intervallo con virgola' => array( 3, 7.5 ),
		);

		foreach ( $attacchi as $nome => list( $posto, $valore ) ) {
			$parametri           = $validi;
			$parametri[ $posto ] = $valore;

			$this->assertWPError( conformita_core_registra_collegamento( ...$parametri ), $nome );
			$this->assertCount( 1, $this->righe(), $nome );
		}

		// Il valore valido al limite passa.
		$this->assertTrue( conformita_core_registra_collegamento( self::SEZIONE, str_repeat( 'a', 100 ), self::INDIRIZZO, 90 ) );
		$this->assertTrue( conformita_core_registra_collegamento( self::SEZIONE, 'voce-tre_3', 'http://banca-dati.example.org/ente', 1 ) );
	}

	/**
	 * C-302: un collegamento che risponde, e com'e fatta la richiesta.
	 */
	public function test_c302_raggiungibile() {
		$this->registra();

		$letto = conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );

		$this->assertSame( 'raggiungibile', $letto['esito'] );
		$this->assertSame( 200, $letto['codice'] );
		$this->assertSame( '', $letto['motivo'] );
		$this->assertTrue( self::adesso( $letto['verificato'] ) );
		$this->assertSame( $letto, conformita_core_collegamento( self::SEZIONE, 'voce_uno' ), 'L\'esito e scritto.' );

		$this->assertCount( 1, $this->richieste );
		list( $indirizzo, $argomenti ) = $this->richieste[0];

		$this->assertSame( self::INDIRIZZO, $indirizzo );
		$this->assertSame( 'GET', $argomenti['method'] );
		$this->assertSame( 10, $argomenti['timeout'] );
		$this->assertSame( 5, $argomenti['redirection'] );
		$this->assertSame( 65536, $argomenti['limit_response_size'] );
		$this->assertTrue( $argomenti['reject_unsafe_urls'] );

		// Un codice 2xx diverso da 200 e raggiungibile anche lui.
		$this->risposta = self::risposta( 204 );
		$this->assertSame( 'raggiungibile', conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' )['esito'] );
	}

	/**
	 * C-303: collegamenti rotti, con il codice o il motivo.
	 */
	public function test_c303_rotti() {
		$this->registra();

		foreach ( array( 404, 500, 301, 403 ) as $codice ) {
			$this->risposta = self::risposta( $codice );
			$letto          = conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );

			$this->assertSame( 'rotto', $letto['esito'], (string) $codice );
			$this->assertSame( $codice, $letto['codice'], (string) $codice );
			$this->assertStringContainsString( (string) $codice, $letto['motivo'] );
			$this->assertTrue( self::adesso( $letto['verificato'] ) );
		}

		$errori = array(
			'tempo scaduto'       => 'cURL error 28: Operation timed out after 10000 milliseconds',
			'dominio inesistente' => 'cURL error 6: Could not resolve host: banca-dati.example.org',
			'rinvii esauriti'     => 'Too many redirects',
		);

		foreach ( $errori as $nome => $messaggio ) {
			$this->risposta = new WP_Error( 'http_request_failed', $messaggio );
			$letto          = conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );

			$this->assertSame( 'rotto', $letto['esito'], $nome );
			$this->assertNull( $letto['codice'], $nome );
			$this->assertSame( $messaggio, $letto['motivo'], $nome );
		}

		/*
		 * Un motivo lunghissimo si taglia, anche con la banca dati in modo
		 * rigoroso, dove un testo troppo lungo non si scrive affatto.
		 */
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- la prova cambia il modo della sessione e lo rimette.
		$modo = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$wpdb->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
		$this->risposta = new WP_Error( 'http_request_failed', str_repeat( 'è', 400 ) );
		$letto          = conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $modo ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		$this->assertSame( str_repeat( 'è', 255 ), $letto['motivo'] );

		// E torna raggiungibile, senza motivo.
		$this->risposta = self::risposta( 200 );
		$letto          = conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );
		$this->assertSame( 'raggiungibile', $letto['esito'] );
		$this->assertSame( '', $letto['motivo'] );
	}

	/**
	 * C-304: registrare di nuovo, sezioni distinte, togliere.
	 */
	public function test_c304_aggiornamenti() {
		$this->registra();
		$verificato = conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );
		$this->assertSame( 'raggiungibile', $verificato['esito'] );

		// Stesso indirizzo: l'esito resta.
		$this->registra();
		$this->assertSame( $verificato, conformita_core_collegamento( self::SEZIONE, 'voce_uno' ) );

		// Solo l'intervallo: l'esito resta.
		$this->registra( self::INDIRIZZO, 30 );
		$letto = conformita_core_collegamento( self::SEZIONE, 'voce_uno' );
		$this->assertSame( 30, $letto['intervallo'] );
		$this->assertSame( 'raggiungibile', $letto['esito'] );
		$this->assertSame( $verificato['verificato'], $letto['verificato'] );

		// Indirizzo diverso, anche solo per una maiuscola nel percorso: l'esito torna mai.
		$this->registra( 'https://banca-dati.example.org/ente/Dati', 30 );
		$letto = conformita_core_collegamento( self::SEZIONE, 'voce_uno' );
		$this->assertSame( 'https://banca-dati.example.org/ente/Dati', $letto['indirizzo'] );
		$this->assertSame( 'mai', $letto['esito'] );
		$this->assertNull( $letto['codice'] );
		$this->assertSame( '', $letto['verificato'] );
		$this->assertCount( 1, $this->righe() );

		// Stessa chiave in un'altra sezione: un collegamento distinto.
		$this->registra( 'https://altra.example.org/', 7, 'voce_uno', self::SEZIONE_ALTRA );
		$this->assertSame( 'https://altra.example.org/', conformita_core_collegamento( self::SEZIONE_ALTRA, 'voce_uno' )['indirizzo'] );
		$this->assertSame( 'https://banca-dati.example.org/ente/Dati', conformita_core_collegamento( self::SEZIONE, 'voce_uno' )['indirizzo'] );

		// Togliere, anche due volte.
		$this->assertTrue( conformita_core_togli_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertNull( conformita_core_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertTrue( conformita_core_togli_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertNotNull( conformita_core_collegamento( self::SEZIONE_ALTRA, 'voce_uno' ) );

		// Verificare o leggere con parametri non validi, o un collegamento che non c'e.
		$this->richieste = array();
		$this->assertWPError( conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertWPError( conformita_core_collegamento( 'sezione_inesistente', 'voce_uno' ) );
		$this->assertWPError( conformita_core_togli_collegamento( self::SEZIONE, 'Voce' ) );
		$this->assertCount( 0, $this->richieste );
	}

	/**
	 * C-305: letture e scritture che falliscono, indirizzo cambiato durante il controllo.
	 */
	public function test_c305_guasti() {
		global $wpdb;

		$this->registra();

		// Lettura fallita: errore, non assente, e nessuna richiesta.
		$this->guasta_letture();
		$this->assertWPError( conformita_core_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertWPError( conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertWPError( conformita_core_registra_collegamento( self::SEZIONE, 'voce_uno', self::INDIRIZZO, 7 ) );
		remove_all_filters( 'query' );
		$this->assertSame( array(), $this->richieste );

		// Scrittura fallita: errore.
		$tabella = Conformita_Core_Collegamenti::tabella();
		add_filter(
			'query',
			static function ( $sql ) use ( $tabella ) {
				$sql = ltrim( (string) $sql );

				return ( 0 === strpos( $sql, 'INSERT' ) || 0 === strpos( $sql, 'UPDATE' ) || 0 === strpos( $sql, 'DELETE' ) ) && false !== strpos( $sql, $tabella ) ? 'UPDATE `' . $tabella . '_inesistente` SET id = 1' : $sql;
			}
		);
		$this->assertWPError( conformita_core_registra_collegamento( self::SEZIONE, 'voce_nuova', self::INDIRIZZO, 7 ) );
		$this->assertWPError( conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' ) );
		$this->assertWPError( conformita_core_togli_collegamento( self::SEZIONE, 'voce_uno' ) );
		remove_all_filters( 'query' );
		$wpdb->suppress_errors( false );
		$this->assertNull( conformita_core_collegamento( self::SEZIONE, 'voce_nuova' ) );
		$this->assertSame( 'mai', conformita_core_collegamento( self::SEZIONE, 'voce_uno' )['esito'] );

		// L'indirizzo cambia mentre l'altro sito risponde: l'esito del controllo vecchio si butta.
		$this->richieste = array();
		$cambia          = function ( $esito ) {
			conformita_core_registra_collegamento( self::SEZIONE, 'voce_uno', 'https://banca-dati.example.org/nuovo', 7 );

			return $esito;
		};
		add_filter( 'pre_http_request', $cambia, 20 );
		$this->risposta = self::risposta( 404 );
		conformita_core_verifica_collegamento( self::SEZIONE, 'voce_uno' );
		remove_filter( 'pre_http_request', $cambia, 20 );

		$letto = conformita_core_collegamento( self::SEZIONE, 'voce_uno' );
		$this->assertSame( 'https://banca-dati.example.org/nuovo', $letto['indirizzo'] );
		$this->assertSame( 'mai', $letto['esito'] );
		$this->assertCount( 1, $this->richieste );
	}

	/**
	 * C-306: la tabella con la sua chiave unica, e la versione dell'interfaccia.
	 */
	public function test_c306_tabella_e_interfaccia() {
		global $wpdb;

		$tabella = Conformita_Core_Collegamenti::tabella();

		// La tabella nasce da zero, non da quella di una prova precedente.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prova di installazione.
		$wpdb->query( "DROP TABLE IF EXISTS `{$tabella}`" );
		delete_option( Conformita_Core_Collegamenti::OPZIONE_SCHEMA );
		$this->assertTrue( Conformita_Core_Collegamenti::installa() );
		$this->assertSame( Conformita_Core_Collegamenti::VERSIONE_SCHEMA, get_option( Conformita_Core_Collegamenti::OPZIONE_SCHEMA ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verifica di struttura.
		$indici = $wpdb->get_results( "SHOW INDEX FROM `{$tabella}` WHERE Key_name = 'collegamento'", ARRAY_A );

		$this->assertSame( array( 'sezione', 'chiave' ), array_column( $indici, 'Column_name' ) );
		$this->assertSame( array( '0', '0' ), array_map( 'strval', array_column( $indici, 'Non_unique' ) ) );

		// La chiave unica ferma un doppione scritto direttamente.
		$this->registra();
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$doppione = $wpdb->insert(
			$tabella,
			array(
				'sezione'    => self::SEZIONE,
				'chiave'     => 'voce_uno',
				'indirizzo'  => self::INDIRIZZO,
				'intervallo' => 7,
				'esito'      => 'mai',
				'registrato' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->suppress_errors( false );
		$this->assertFalse( $doppione );

		$this->assertSame( '1.12.0', conformita_core_versione_api() );
	}
}
