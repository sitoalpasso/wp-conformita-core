<?php
/**
 * La schermata di consultazione del registro: chi la apre, che cosa mostra,
 * come filtra.
 *
 * Righe di collaudo C-214..C-221, che dettagliano C-52.
 *
 * **Come si guarda la schermata.** Si chiama la funzione che la stampa, con i
 * parametri dell'indirizzo messi dove WordPress li mette, e si legge quello che
 * ha stampato. Le voci si riconoscono dalla loro motivazione, che ogni prova
 * sceglie diversa: cercare un numero nella pagina lo troverebbe anche nelle
 * date e nei numeri di pagina.
 *
 * @package Conformita_Core
 */

/**
 * Prove sulla schermata di consultazione.
 */
class Conformita_Core_Registro_Schermata_Test extends WP_UnitTestCase {

	const SEZIONE       = 'sezione_schermata';
	const TIPO          = 'prova_schermata';
	const SEZIONE_ALTRA = 'sezione_schermata_b';
	const TIPO_ALTRO    = 'prova_schermata_b';

	/**
	 * Fuso del sito prima della prova.
	 *
	 * @var string|false
	 */
	private $fuso_originale = false;

	/**
	 * Sezioni, tipi, orologio.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		delete_option( Conformita_Core_Registro::OPZIONE_MANCATE );
		$this->fuso_originale = get_option( 'timezone_string' );

		foreach ( array(
			self::SEZIONE       => self::TIPO,
			self::SEZIONE_ALTRA => self::TIPO_ALTRO,
		) as $sezione => $tipo ) {
			$this->assertTrue(
				conformita_core_registra_sezione(
					$sezione,
					array(
						'indicizzazione' => 'vietata',
						'scadenza'       => 'irraggiungibile',
					)
				)
			);
			$this->assertTrue(
				conformita_core_registra_tipo(
					$tipo,
					array(
						'sezione'      => $sezione,
						'show_in_rest' => false,
						'argomenti'    => array( 'public' => true ),
					)
				)
			);
		}
	}

	/**
	 * Rimette a posto orologio, fuso, parametri e utente.
	 */
	public function tear_down() {
		Conformita_Core_Scadenza::azzera_orologio();
		update_option( 'timezone_string', $this->fuso_originale );
		$_GET     = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Un utente che puo' leggere il registro.
	 *
	 * @return int
	 */
	private function lettore() {
		$utente = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $utente )->add_cap( Conformita_Core_Registro::CAPACITA );

		return $utente;
	}

	/**
	 * Quello che la schermata stampa.
	 *
	 * @param array<string, string> $parametri Parametri dell'indirizzo.
	 * @return string
	 */
	private function schermata( array $parametri = array() ) {
		$_GET     = $parametri;
		$_REQUEST = $parametri;

		ob_start();

		try {
			Conformita_Core_Registro_Schermata::mostra();
		} finally {
			$stampato = (string) ob_get_clean();
		}

		return $stampato;
	}

	/**
	 * Una voce del componente, riconoscibile dalla motivazione.
	 *
	 * @param string   $motivazione Motivazione, unica nella prova.
	 * @param array    $altro       Altre chiavi della voce.
	 * @param int|null $utente      Chi la scrive.
	 * @param string   $istante     Istante in UTC.
	 * @return int
	 */
	private function voce( $motivazione, array $altro = array(), $utente = null, $istante = '2026-10-02 09:00:00' ) {
		$prima = get_current_user_id();

		if ( null !== $utente ) {
			wp_set_current_user( $utente );
		}

		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( $istante, new DateTimeZone( 'UTC' ) ) );

		$id = conformita_core_registra_voce(
			array_merge(
				array(
					'sezione'     => self::SEZIONE,
					'azione'      => 'prova',
					'motivazione' => $motivazione,
				),
				$altro
			)
		);

		wp_set_current_user( $prima );
		$this->assertIsInt( $id, 'Preparazione: la voce ' . $motivazione . ' deve esistere.' );

		return $id;
	}

	/**
	 * C-214: la schermata si apre solo con la capability dedicata, e l'essere
	 * amministratore non basta.
	 */
	public function test_c214_permesso() {
		global $menu;

		$this->voce( 'VOCE-PERMESSO' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- prova: si ricostruisce il menu da zero.
		Conformita_Core_Registro_Schermata::menu();

		$voce = array_values(
			array_filter(
				(array) $menu,
				function ( $elemento ) {
					return Conformita_Core_Registro_Schermata::PAGINA === $elemento[2];
				}
			)
		);
		$this->assertCount( 1, $voce );
		$this->assertSame( Conformita_Core_Registro::CAPACITA, $voce[0][1], 'La voce di menu chiede la capability dedicata.' );

		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $ruolo ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $ruolo ) ) );

			try {
				$this->schermata();
				$this->fail( "Ruolo {$ruolo} senza la capability: doveva essere rifiutato." );
			} catch ( WPDieException $rifiuto ) {
				$this->assertStringContainsString( 'permesso', $rifiuto->getMessage() );
			}
		}

		wp_set_current_user( $this->lettore() );
		$this->assertStringContainsString( 'VOCE-PERMESSO', $this->schermata(), 'Controllo positivo: con la capability la voce si legge.' );
	}

	/**
	 * C-215: core non assegna la capability di lettura a nessun ruolo.
	 */
	public function test_c215_capability_non_assegnata() {
		$ruoli = wp_roles();

		foreach ( $ruoli->role_objects as $nome => $ruolo ) {
			$this->assertFalse( $ruolo->has_cap( Conformita_Core_Registro::CAPACITA ), "Il ruolo {$nome} non deve averla da core." );
		}

		Conformita_Core_Registro::installa();

		foreach ( wp_roles()->role_objects as $nome => $ruolo ) {
			$this->assertFalse( $ruolo->has_cap( Conformita_Core_Registro::CAPACITA ), "Nemmeno dopo l'installazione: {$nome}." );
		}
	}

	/**
	 * C-216: ogni filtro mostra le voci che gli rispondono e nasconde le altre.
	 */
	public function test_c216_filtri() {
		update_option( 'timezone_string', 'Europe/Rome' );

		$lettore = $this->lettore();
		$altro   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$uno     = self::factory()->post->create( array( 'post_type' => self::TIPO ) );
		$due     = self::factory()->post->create( array( 'post_type' => self::TIPO ) );
		$altrove = self::factory()->post->create( array( 'post_type' => self::TIPO_ALTRO ) );

		$this->voce( 'VOCE-UNO', array( 'contenuto' => $uno ), $lettore, '2026-10-01 10:00:00' );
		$this->voce(
			'VOCE-DUE',
			array(
				'contenuto' => $due,
				'azione'    => 'annullamento',
			),
			$altro,
			'2026-10-02 10:00:00'
		);
		$this->voce(
			'VOCE-ALTROVE',
			array(
				'sezione'   => self::SEZIONE_ALTRA,
				'contenuto' => $altrove,
			),
			$lettore,
			'2026-10-03 22:30:00'
		);

		wp_set_current_user( $lettore );
		$gettone = wp_create_nonce( Conformita_Core_Registro_Schermata::AZIONE_FILTRI );

		$tutte = $this->schermata();

		foreach ( array( 'VOCE-UNO', 'VOCE-DUE', 'VOCE-ALTROVE' ) as $segno ) {
			$this->assertStringContainsString( $segno, $tutte, 'Precondizione: senza filtri si vedono tutte.' );
		}

		$casi = array(
			'sezione'         => array( array( 'sezione' => self::SEZIONE_ALTRA ), array( 'VOCE-ALTROVE' ) ),
			'contenuto'       => array( array( 'contenuto' => (string) $due ), array( 'VOCE-DUE' ) ),
			'azione'          => array( array( 'azione' => 'annullamento' ), array( 'VOCE-DUE' ) ),
			'utente'          => array( array( 'utente' => (string) $altro ), array( 'VOCE-DUE' ) ),
			'dal'             => array( array( 'dal' => '2026-10-02' ), array( 'VOCE-DUE', 'VOCE-ALTROVE' ) ),
			'al'              => array( array( 'al' => '2026-10-01' ), array( 'VOCE-UNO' ) ),
			'giorno del sito' => array(
				array(
					'dal' => '2026-10-03',
					'al'  => '2026-10-03',
				),
				array(),
			),
			'giorno dopo'     => array(
				array(
					'dal' => '2026-10-04',
					'al'  => '2026-10-04',
				),
				array( 'VOCE-ALTROVE' ),
			),
			'insieme'         => array(
				array(
					'sezione' => self::SEZIONE,
					'utente'  => (string) $lettore,
				),
				array( 'VOCE-UNO' ),
			),
		);

		foreach ( $casi as $nome => $caso ) {
			$pagina = $this->schermata( array_merge( $caso[0], array( Conformita_Core_Registro_Schermata::CAMPO_GETTONE => $gettone ) ) );

			foreach ( array( 'VOCE-UNO', 'VOCE-DUE', 'VOCE-ALTROVE' ) as $segno ) {
				if ( in_array( $segno, $caso[1], true ) ) {
					$this->assertStringContainsString( $segno, $pagina, "Filtro {$nome}: {$segno} deve esserci." );
				} else {
					$this->assertStringNotContainsString( $segno, $pagina, "Filtro {$nome}: {$segno} non deve esserci." );
				}
			}
		}
	}

	/**
	 * C-216, seconda parte: un filtro sconosciuto o malformato e' un errore,
	 * mai un filtro ignorato che restituirebbe tutto.
	 */
	public function test_c216_filtri_malformati() {
		$this->voce( 'VOCE-QUALUNQUE' );

		$this->assertNotEmpty( conformita_core_voci_registro(), 'Precondizione: il registro non e\' vuoto.' );

		$casi = array(
			'filtro sconosciuto'      => array( 'contenuti' => 1 ),
			'contenuto non numero'    => array( 'contenuto' => 'abc' ),
			'contenuto zero'          => array( 'contenuto' => 0 ),
			'utente negativo'         => array( 'utente' => -1 ),
			'giorno inesistente'      => array( 'dal' => '2026-02-30' ),
			'giorno in altro formato' => array( 'al' => '02/10/2026' ),
			'giorno con a capo'       => array( 'al' => "2026-10-02\n" ),
			'ordine sconosciuto'      => array( 'ordine' => 'casuale' ),
			'pagina zero'             => array(
				'per_pagina' => 10,
				'pagina'     => 0,
			),
			'sezione vuota'           => array( 'sezione' => '' ),
			'non un elenco'           => 'sezione',
		);

		foreach ( $casi as $nome => $filtri ) {
			$this->assertWPError( conformita_core_voci_registro( $filtri ), "Caso {$nome}." );
		}

		$lettore = $this->lettore();
		wp_set_current_user( $lettore );

		$pagina = $this->schermata(
			array(
				'contenuto' => 'abc',
				Conformita_Core_Registro_Schermata::CAMPO_GETTONE => wp_create_nonce( Conformita_Core_Registro_Schermata::AZIONE_FILTRI ),
			)
		);

		$this->assertStringContainsString( 'Uno dei filtri non è valido', $pagina );
		$this->assertStringNotContainsString( 'VOCE-QUALUNQUE', $pagina, 'Un filtro sbagliato non mostra tutto.' );
	}

	/**
	 * C-217: senza un gettone valido i filtri dell'indirizzo non si applicano,
	 * e la schermata lo dice.
	 */
	public function test_c217_gettone() {
		$lettore = $this->lettore();
		$this->voce( 'VOCE-A', array(), $lettore );
		$this->voce( 'VOCE-B', array( 'azione' => 'altra' ), $lettore );

		wp_set_current_user( $lettore );

		foreach ( array(
			'assente'   => null,
			'sbagliato' => 'abcdef1234',
		) as $nome => $gettone ) {
			$parametri = array( 'azione' => 'altra' );

			if ( null !== $gettone ) {
				$parametri[ Conformita_Core_Registro_Schermata::CAMPO_GETTONE ] = $gettone;
			}

			$pagina = $this->schermata( $parametri );

			$this->assertStringContainsString( 'VOCE-A', $pagina, "Gettone {$nome}: il filtro non si applica." );
			$this->assertStringContainsString( 'non sono stati applicati', $pagina, "Gettone {$nome}: la schermata lo dice." );
		}

		$valido = $this->schermata(
			array(
				'azione' => 'altra',
				Conformita_Core_Registro_Schermata::CAMPO_GETTONE => wp_create_nonce( Conformita_Core_Registro_Schermata::AZIONE_FILTRI ),
			)
		);
		$this->assertStringNotContainsString( 'VOCE-A', $valido, 'Controllo positivo: con il gettone il filtro si applica.' );
		$this->assertStringContainsString( 'VOCE-B', $valido );
		$this->assertStringNotContainsString( 'non sono stati applicati', $valido );
	}

	/**
	 * C-218: gli orari si mostrano nel fuso del sito, anche nel giorno del
	 * cambio d'ora.
	 */
	public function test_c218_fuso() {
		$lettore = $this->lettore();

		$this->voce( 'VOCE-ORA', array(), $lettore, '2026-03-29 00:30:00' );
		$this->voce( 'VOCE-DOPO', array(), $lettore, '2026-03-29 01:30:00' );

		wp_set_current_user( $lettore );

		update_option( 'timezone_string', 'Europe/Rome' );
		$pagina = $this->schermata();
		$this->assertStringContainsString( '29/03/2026 01:30:00', $pagina, 'Prima del cambio: UTC piu\' uno.' );
		$this->assertStringContainsString( '29/03/2026 03:30:00', $pagina, 'Dopo il cambio: UTC piu\' due.' );

		update_option( 'timezone_string', 'America/New_York' );
		$pagina = $this->schermata();
		$this->assertStringContainsString( '28/03/2026 20:30:00', $pagina, 'Un altro fuso, un\'altra ora: il fuso non e\' cablato.' );
	}

	/**
	 * C-219: quello che le voci contengono si stampa come testo, mai come codice.
	 */
	public function test_c219_uscita() {
		$lettore = $this->lettore();
		$id      = self::factory()->post->create(
			array(
				'post_type'  => self::TIPO,
				'post_title' => '<img src=x onerror=alert(2)>',
			)
		);

		$this->voce(
			"<script>alert(1)</script>\nSeconda riga",
			array(
				'contenuto' => $id,
				'dettagli'  => array( 'nota' => '<b onclick="alert(3)">x</b>' ),
			),
			$lettore
		);

		wp_set_current_user( $lettore );
		$pagina = $this->schermata();

		$this->assertStringNotContainsString( '<script>alert(1)', $pagina );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $pagina );
		$this->assertStringContainsString( '&lt;/script&gt;<br />', $pagina, 'L\'a capo della motivazione diventa un a capo della pagina.' );
		$this->assertStringNotContainsString( '<b onclick', $pagina );
		$this->assertStringNotContainsString( '<img src=x', $pagina );
	}

	/**
	 * C-220: chi ha agito si legge dal numero al momento della consultazione;
	 * il sistema e l'utente che non c'e' piu' hanno la loro dicitura.
	 */
	public function test_c220_chi() {
		$lettore = $this->lettore();
		$persona = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Nome Visualizzato Prova',
			)
		);
		$andato  = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->voce( 'VOCE-SISTEMA', array(), 0 );
		$this->voce( 'VOCE-PERSONA', array(), $persona );
		$this->voce( 'VOCE-ANDATO', array(), $andato );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $andato );

		wp_update_user(
			array(
				'ID'           => $persona,
				'display_name' => 'Nome Cambiato Dopo',
			)
		);

		wp_set_current_user( $lettore );
		$pagina = $this->schermata();

		$this->assertStringContainsString( 'sistema (nessun utente)', $pagina );
		$this->assertStringContainsString( 'Nome Cambiato Dopo (utente ' . $persona . ')', $pagina, 'Il nome e\' quello di adesso: nel registro c\'e\' solo il numero.' );
		$this->assertStringNotContainsString( 'Nome Visualizzato Prova', $pagina );
		$this->assertStringContainsString( 'utente ' . $andato . ', non più presente', $pagina );
	}

	/**
	 * C-221: una richiesta di scrittura verso la schermata non cambia niente, e
	 * la schermata non offre niente che scriva.
	 */
	public function test_c221_schermata_in_sola_lettura() {
		global $wpdb;

		$lettore = $this->lettore();
		$voce    = $this->voce( 'VOCE-INTOCCABILE', array(), $lettore );
		$tabella = Conformita_Core_Registro::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova: fotografia della tabella.
		$prima = $wpdb->get_results( "SELECT * FROM {$tabella} ORDER BY id", ARRAY_A );

		wp_set_current_user( $lettore );

		$_POST                     = array(
			'action'   => 'delete',
			'action2'  => 'delete',
			'voce'     => array( (string) $voce ),
			'id'       => (string) $voce,
			'_wpnonce' => wp_create_nonce( 'bulk-voci' ),
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$pagina = $this->schermata(
			array(
				'action' => 'delete',
				'id'     => (string) $voce,
			)
		);

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova: fotografia della tabella.
		$dopo = $wpdb->get_results( "SELECT * FROM {$tabella} ORDER BY id", ARRAY_A );

		$this->assertSame( $prima, $dopo, 'La tabella e\' identica.' );
		$this->assertStringContainsString( 'VOCE-INTOCCABILE', $pagina );
		$this->assertDoesNotMatchRegularExpression( '/<form[^>]*method="post"/i', $pagina, 'Nessun modulo che scrive.' );
		$this->assertDoesNotMatchRegularExpression( '/type="checkbox"/i', $pagina, 'Nessuna selezione per azioni di gruppo.' );
		$this->assertMatchesRegularExpression( '/<form[^>]*method="get"/i', $pagina, 'Controllo positivo: il modulo dei filtri c\'e\', e legge.' );
	}

	/**
	 * C-221, seconda parte: le voci automatiche non scritte si vedono nella
	 * schermata.
	 */
	public function test_c221_voci_mancate_visibili() {
		$lettore = $this->lettore();
		wp_set_current_user( $lettore );

		$this->assertStringNotContainsString( 'non sono state registrate', $this->schermata(), 'Precondizione: nessun avviso senza buchi.' );

		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-02 08:00:00', new DateTimeZone( 'UTC' ) ) );
		Conformita_Core_Registro::annota_mancata();
		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-03 08:00:00', new DateTimeZone( 'UTC' ) ) );
		Conformita_Core_Registro::annota_mancata();

		update_option( 'timezone_string', 'Europe/Rome' );
		$pagina = $this->schermata();

		$this->assertStringContainsString( '2 operazioni non sono state registrate', $pagina );
		$this->assertStringContainsString( '02/10/2026 10:00:00', $pagina );
		$this->assertStringContainsString( '03/10/2026 10:00:00', $pagina );
	}
}
