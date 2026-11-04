<?php
/**
 * Verificatore dei collegamenti, seconda parte: controllo periodico a lotti e
 * ritardo letto sul dato.
 *
 * Righe di collaudo C-307..C-310. Scheda:
 * `docs/implementazione/verificatore-collegamenti.md`, punto 12.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul controllo periodico.
 */
class Conformita_Core_Collegamenti_Periodico_Test extends WP_UnitTestCase {

	const SEZIONE       = 'sezione_periodico';
	const SEZIONE_TOLTA = 'sezione_periodico_tolta';

	/**
	 * Indirizzi chiesti al finto altro sito, in ordine.
	 *
	 * @var array<int, string>
	 */
	private $chiesti = array();

	/**
	 * Sezioni registrate, tabella vuota, altro sito finto che risponde 200.
	 */
	public function set_up() {
		global $wpdb;

		parent::set_up();

		$this->registra_sezioni( array( self::SEZIONE, self::SEZIONE_TOLTA ) );
		$this->assertTrue( Conformita_Core_Collegamenti::installa() );

		$tabella = Conformita_Core_Collegamenti::tabella();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella vuota a ogni prova.
		$wpdb->query( "DELETE FROM `{$tabella}`" );

		$this->chiesti = array();

		add_filter( 'pre_http_request', array( $this, 'altro_sito' ), 10, 3 );
	}

	/**
	 * Guasti tolti, sezioni azzerate.
	 */
	public function tear_down() {
		global $wpdb;

		remove_all_filters( 'query' );
		remove_filter( 'pre_http_request', array( $this, 'altro_sito' ), 10 );
		$wpdb->suppress_errors( false );
		Conformita_Core_Sezioni::azzera();
		wp_clear_scheduled_hook( Conformita_Core_Collegamenti::COMPITO );

		parent::tear_down();
	}

	/**
	 * Registra le sezioni date, e solo quelle.
	 *
	 * @param array<int, string> $sezioni Sezioni.
	 */
	private function registra_sezioni( array $sezioni ) {
		Conformita_Core_Sezioni::azzera();

		foreach ( $sezioni as $sezione ) {
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
	}

	/**
	 * Il finto altro sito: annota l'indirizzo e risponde 200.
	 *
	 * @param mixed                $esito     Risposta proposta.
	 * @param array<string, mixed> $argomenti Argomenti.
	 * @param string               $indirizzo Indirizzo.
	 * @return array<string, mixed>
	 */
	public function altro_sito( $esito, $argomenti, $indirizzo ) {
		unset( $esito, $argomenti );

		$this->chiesti[] = $indirizzo;

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * L'indirizzo di prova di una chiave.
	 *
	 * @param string $chiave Chiave.
	 * @return string
	 */
	private static function indirizzo( $chiave ) {
		return 'https://banca-dati.example.org/' . $chiave;
	}

	/**
	 * Registra un collegamento e ne fissa a mano gli istanti.
	 *
	 * @param string      $chiave     Chiave.
	 * @param int         $intervallo Intervallo in giorni.
	 * @param int|null    $verificato Secondi fa dell'ultimo controllo, nullo se mai.
	 * @param int         $registrato Secondi fa della registrazione.
	 * @param string|null $sezione    Sezione.
	 */
	private function collegamento( $chiave, $intervallo, $verificato, $registrato = 0, $sezione = self::SEZIONE ) {
		global $wpdb;

		$this->assertTrue( conformita_core_registra_collegamento( $sezione, $chiave, self::indirizzo( $chiave ), $intervallo ) );

		$campi = array( 'registrato' => gmdate( 'Y-m-d H:i:s', time() - $registrato ) );

		if ( null !== $verificato ) {
			$campi['verificato'] = gmdate( 'Y-m-d H:i:s', time() - $verificato );
			$campi['esito']      = 'raggiungibile';
			$campi['codice']     = 200;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- La prova fissa gli istanti.
		$wpdb->update(
			Conformita_Core_Collegamenti::tabella(),
			$campi,
			array(
				'sezione' => $sezione,
				'chiave'  => $chiave,
			)
		);
	}

	/**
	 * C-307: il lotto, in ordine, senza i controllati da poco e senza le sezioni non registrate.
	 */
	public function test_c307_lotto() {
		$giorno = DAY_IN_SECONDS;

		$this->collegamento( 'tolta', 7, null, 0, self::SEZIONE_TOLTA );
		$this->collegamento( 'mai_uno', 7, null );
		$this->collegamento( 'scaduto_8', 7, 8 * $giorno );
		$this->collegamento( 'mai_due', 7, null );
		$this->collegamento( 'scaduto_20', 7, 20 * $giorno );
		$this->collegamento( 'scaduto_10', 7, 10 * $giorno );
		$this->collegamento( 'mai_tre', 7, null );
		$this->collegamento( 'recente', 7, $giorno );
		$this->collegamento( 'appena', 1, 60 );

		// La sezione tolta non e registrata in questa richiesta.
		$this->registra_sezioni( array( self::SEZIONE ) );

		$this->assertSame( 5, Conformita_Core_Collegamenti::lotto() );
		$this->assertSame(
			array_map( array( __CLASS__, 'indirizzo' ), array( 'mai_uno', 'mai_due', 'mai_tre', 'scaduto_20', 'scaduto_10' ) ),
			$this->chiesti
		);

		$this->chiesti = array();
		$this->assertSame( 1, Conformita_Core_Collegamenti::lotto() );
		$this->assertSame( array( self::indirizzo( 'scaduto_8' ) ), $this->chiesti );

		$this->chiesti = array();
		$this->assertSame( 0, Conformita_Core_Collegamenti::lotto() );
		$this->assertSame( array(), $this->chiesti );

		// Il collegamento della sezione tolta e rimasto com'era.
		$this->registra_sezioni( array( self::SEZIONE, self::SEZIONE_TOLTA ) );
		$this->assertSame( 'mai', conformita_core_collegamento( self::SEZIONE_TOLTA, 'tolta' )['esito'] );
		$this->assertSame( 'raggiungibile', conformita_core_collegamento( self::SEZIONE, 'scaduto_8' )['esito'] );
	}

	/**
	 * C-308: il compito orario.
	 */
	public function test_c308_compito_orario() {
		wp_clear_scheduled_hook( Conformita_Core_Collegamenti::COMPITO );

		Conformita_Core_Collegamenti::pianifica();

		$this->assertNotFalse( wp_next_scheduled( Conformita_Core_Collegamenti::COMPITO ) );
		$this->assertSame( 'hourly', wp_get_schedule( Conformita_Core_Collegamenti::COMPITO ) );

		// Gia programmato a un'altra ora: un secondo avvio non ne aggiunge un altro.
		$fra_un_ora = time() + HOUR_IN_SECONDS;
		wp_clear_scheduled_hook( Conformita_Core_Collegamenti::COMPITO );
		wp_schedule_event( $fra_un_ora, 'hourly', Conformita_Core_Collegamenti::COMPITO );
		Conformita_Core_Collegamenti::pianifica();
		$this->assertSame( $fra_un_ora, wp_next_scheduled( Conformita_Core_Collegamenti::COMPITO ) );

		$programmati = 0;

		foreach ( _get_cron_array() as $compiti ) {
			$programmati += isset( $compiti[ Conformita_Core_Collegamenti::COMPITO ] ) ? count( $compiti[ Conformita_Core_Collegamenti::COMPITO ] ) : 0;
		}

		$this->assertSame( 1, $programmati, 'Programmato una volta sola.' );

		// Agganciato all'avvio e al compito.
		$this->assertNotFalse( has_action( 'init', array( 'Conformita_Core_Collegamenti', 'pianifica' ) ) );
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( CONFORMITA_CORE_PERCORSO . 'conformita-core.php' ), array( 'Conformita_Core_Collegamenti', 'togli_compito' ) ) );

		$this->collegamento( 'mai_uno', 7, null );
		do_action( Conformita_Core_Collegamenti::COMPITO );
		$this->assertSame( array( self::indirizzo( 'mai_uno' ) ), $this->chiesti );

		Conformita_Core_Collegamenti::togli_compito();
		$this->assertFalse( wp_next_scheduled( Conformita_Core_Collegamenti::COMPITO ) );
	}

	/**
	 * C-309: in ritardo, letto sul dato, a compito mai girato.
	 */
	public function test_c309_in_ritardo() {
		$giorno = DAY_IN_SECONDS;

		$this->collegamento( 'mai_vecchio', 1, null, 2 * $giorno + 60 );
		$this->collegamento( 'mai_nuovo', 1, null, 2 * $giorno - 60 );
		$this->collegamento( 'scaduto', 7, 8 * $giorno + 60 );
		$this->collegamento( 'quasi', 7, 8 * $giorno - 60 );
		$this->collegamento( 'recente', 7, $giorno );

		$attesi = array(
			'mai_vecchio' => true,
			'mai_nuovo'   => false,
			'scaduto'     => true,
			'quasi'       => false,
			'recente'     => false,
		);

		foreach ( $attesi as $chiave => $atteso ) {
			$this->assertSame( $atteso, conformita_core_collegamento( self::SEZIONE, $chiave )['in_ritardo'], $chiave );
		}

		// Indirizzo cambiato su un collegamento vecchio: il ritardo riparte dal cambio.
		$this->collegamento( 'cambiato', 7, 100 * $giorno, 200 * $giorno );
		$this->assertTrue( conformita_core_collegamento( self::SEZIONE, 'cambiato' )['in_ritardo'] );
		$this->assertTrue( conformita_core_registra_collegamento( self::SEZIONE, 'cambiato', self::indirizzo( 'cambiato_nuovo' ), 7 ) );
		$letto = conformita_core_collegamento( self::SEZIONE, 'cambiato' );
		$this->assertFalse( $letto['in_ritardo'] );
		$this->assertEqualsWithDelta( time(), strtotime( $letto['registrato'] . ' UTC' ), 60 );

		// Passati l'intervallo piu un giorno dal cambio, senza controllo, e in ritardo.
		$this->sposta_registrato( 'cambiato', 8 * $giorno + 60 );
		$this->assertTrue( conformita_core_collegamento( self::SEZIONE, 'cambiato' )['in_ritardo'] );

		// Stesso indirizzo registrato di nuovo: il riferimento non si sposta.
		$this->assertTrue( conformita_core_registra_collegamento( self::SEZIONE, 'cambiato', self::indirizzo( 'cambiato_nuovo' ), 7 ) );
		$this->assertTrue( conformita_core_collegamento( self::SEZIONE, 'cambiato' )['in_ritardo'] );

		$this->assertSame( array(), $this->chiesti );
		$this->assertTrue( version_compare( conformita_core_versione_api(), '1.13.0', '>=' ) );
	}

	/**
	 * Porta indietro l'istante di registrazione di un collegamento.
	 *
	 * @param string $chiave Chiave.
	 * @param int    $fa     Secondi fa.
	 */
	private function sposta_registrato( $chiave, $fa ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- La prova fissa gli istanti.
		$wpdb->update(
			Conformita_Core_Collegamenti::tabella(),
			array( 'registrato' => gmdate( 'Y-m-d H:i:s', time() - $fa ) ),
			array(
				'sezione' => self::SEZIONE,
				'chiave'  => $chiave,
			)
		);
	}

	/**
	 * C-310: guasti del lotto.
	 */
	public function test_c310_guasti() {
		global $wpdb;

		$this->collegamento( 'uno', 7, null );
		$this->collegamento( 'non_scrivibile', 7, null );
		$this->collegamento( 'tre', 7, null );

		$tabella = Conformita_Core_Collegamenti::tabella();
		$wpdb->suppress_errors( true );

		// Lettura del lotto fallita: errore e nessuna richiesta.
		add_filter(
			'query',
			static function ( $sql ) use ( $tabella ) {
				return 0 === strpos( ltrim( (string) $sql ), 'SELECT' ) && false !== strpos( (string) $sql, $tabella ) ? 'SELECT * FROM `' . $tabella . '_inesistente`' : $sql;
			}
		);
		$this->assertWPError( Conformita_Core_Collegamenti::lotto() );
		$this->assertSame( array(), $this->chiesti );
		remove_all_filters( 'query' );

		// Un collegamento che non si scrive non ferma gli altri.
		add_filter(
			'query',
			static function ( $sql ) use ( $tabella ) {
				return 0 === strpos( ltrim( (string) $sql ), 'UPDATE' ) && false !== strpos( (string) $sql, "'non_scrivibile'" ) ? 'UPDATE `' . $tabella . '_inesistente` SET id = 1' : $sql;
			}
		);
		$controllati = Conformita_Core_Collegamenti::lotto();
		remove_all_filters( 'query' );
		$wpdb->suppress_errors( false );

		// Conta solo i collegamenti con l'esito scritto.
		$this->assertSame( 2, $controllati );

		$this->assertCount( 3, $this->chiesti );
		$this->assertSame( 'raggiungibile', conformita_core_collegamento( self::SEZIONE, 'uno' )['esito'] );
		$this->assertSame( 'mai', conformita_core_collegamento( self::SEZIONE, 'non_scrivibile' )['esito'] );
		$this->assertSame( 'raggiungibile', conformita_core_collegamento( self::SEZIONE, 'tre' )['esito'] );
	}
}
