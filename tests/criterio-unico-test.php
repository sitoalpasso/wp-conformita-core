<?php
/**
 * Un solo criterio per la chiave della fine pubblicazione e per il tipo
 * gestito, righe C-248..C-258 del catalogo.
 *
 * Core leggeva la stessa cosa in due modi. La chiave della fine pubblicazione:
 * la scadenza la leggeva dalla memoria di WordPress, che distingue le
 * maiuscole, il filtro dei percorsi di lettura dalla banca dati, che di norma
 * non le distingue. Il tipo del contenuto: core lo riconosceva in PHP, lettera
 * per lettera, mentre le ricerche di WordPress lo trovano con il confronto
 * della banca dati. Due criteri per la stessa domanda sono due risposte, e fra
 * le due vince la più permissiva.
 *
 * Il criterio ora è uno, quello della banca dati, e le prove lo usano come
 * oracolo: non scrivono "la banca dati ignora le maiuscole", chiedono alla
 * banca dati di prova che cosa trova e pretendono che core risponda allo
 * stesso modo. Dove una prova ha senso solo se la banca dati ignora le
 * maiuscole lo dice con un'asserzione, così su una banca dati che le distingue
 * diventa rossa invece di passare senza aver provato niente.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul criterio unico della banca dati.
 */
class Conformita_Core_Criterio_Unico_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_criterio';
	const TIPO    = 'prova_criterio';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Sezione e tipo pubblico, orologio fissato al 2 ottobre 2026.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Filtro_Scadenza::avvia();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );

		$this->assertTrue(
			conformita_core_registra_sezione(
				self::SEZIONE,
				array(
					'indicizzazione' => 'vietata',
					'scadenza'       => 'irraggiungibile',
				)
			)
		);

		$this->assertTrue(
			conformita_core_registra_tipo(
				self::TIPO,
				array(
					'sezione'      => self::SEZIONE,
					'show_in_rest' => false,
					'argomenti'    => array( 'public' => true ),
				)
			)
		);

		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-02 12:00:00', wp_timezone() ) );
	}

	/**
	 * Fuso e orologio ripristinati.
	 */
	public function tear_down() {
		update_option( 'timezone_string', $this->fuso_originale );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Un contenuto pubblicato del tipo gestito.
	 *
	 * @param string $fine       Data di fine scritta dall'API, vuota per nessuna.
	 * @param string $pubblicato Data di pubblicazione.
	 * @return int Identificativo del contenuto.
	 */
	private function atto( $fine, $pubblicato = '2026-09-10 10:00:00' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => self::TIPO,
				'post_status'  => 'publish',
				'post_date'    => $pubblicato,
				'post_title'   => 'Atto di prova',
				'post_content' => 'Testo con la parola cercabile.',
			)
		);

		if ( '' !== $fine ) {
			$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $post_id, $fine ) );
		}

		return $post_id;
	}

	/**
	 * Riscrive il tipo del contenuto direttamente nella banca dati.
	 *
	 * È il modo in cui un componente scritto male ci arriva: WordPress salva il
	 * tipo come lo riceve, e l'istruzione diretta non passa da nessun filtro.
	 *
	 * @param int    $post_id Identificativo del contenuto.
	 * @param string $tipo    Tipo da scrivere.
	 */
	private function tipo_nella_riga( $post_id, $tipo ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: il dato si scrive come lo scriverebbe un componente scritto male.
		$wpdb->update( $wpdb->posts, array( 'post_type' => $tipo ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	/**
	 * Aggiunge una riga di fine pubblicazione con la chiave in maiuscolo.
	 *
	 * @param int    $post_id Identificativo del contenuto.
	 * @param string $valore  Valore.
	 */
	private function riga_in_maiuscolo( $post_id, $valore ) {
		$this->assertNotFalse( add_post_meta( $post_id, strtoupper( conformita_core_chiave_fine_pubblicazione() ), $valore ) );
	}

	/**
	 * Quante righe della fine trova la banca dati cercando con la chiave vera.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return int
	 */
	private function righe_per_la_banca_dati( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: l'oracolo è la banca dati.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, conformita_core_chiave_fine_pubblicazione() ) );
	}

	/**
	 * Gli identificativi che un'interrogazione restituisce.
	 *
	 * @param array<string, mixed> $argomenti Argomenti di `WP_Query`.
	 * @return array<int, int>
	 */
	private function trovati( array $argomenti ) {
		$interrogazione = new WP_Query( $argomenti + array( 'posts_per_page' => -1 ) );

		return array_map( 'intval', 'ids' === $interrogazione->get( 'fields' ) ? $interrogazione->posts : wp_list_pluck( $interrogazione->posts, 'ID' ) );
	}

	/**
	 * Il contenuto precedente, partendo da un contenuto pubblicato dopo.
	 *
	 * @param int $partenza Identificativo del contenuto di partenza.
	 * @return int Identificativo del vicino, zero se non c'è.
	 */
	private function vicino_precedente( $partenza ) {
		$GLOBALS['post'] = get_post( $partenza );
		$vicino          = get_adjacent_post( false, '', true );
		unset( $GLOBALS['post'] );

		return $vicino instanceof WP_Post ? (int) $vicino->ID : 0;
	}

	/**
	 * La stessa decisione su tutti i percorsi: scadenza, primo strato da solo,
	 * due strati, navigazione adiacente.
	 *
	 * @param int    $post_id   Contenuto da guardare.
	 * @param bool   $visibile  Esito atteso.
	 * @param bool   $con_ids   Se guardare anche il primo strato da solo, che con
	 *                          più righe per la chiave è il limite dichiarato di
	 *                          C-102 e C-114.
	 * @param string $messaggio Contesto.
	 */
	private function decide_ovunque( $post_id, $visibile, $con_ids, $messaggio ) {
		$this->assertSame( ! $visibile, conformita_core_scaduto( $post_id ), $messaggio . ': scadenza.' );

		$trovato = in_array( $post_id, $this->trovati( array( 'post_type' => self::TIPO ) ), true );
		$this->assertSame( $visibile, $trovato, $messaggio . ': interrogazione con i due strati.' );

		if ( $con_ids ) {
			$trovato = in_array(
				$post_id,
				$this->trovati(
					array(
						'post_type' => self::TIPO,
						'fields'    => 'ids',
					)
				),
				true
			);
			$this->assertSame( $visibile, $trovato, $messaggio . ': primo strato da solo.' );
		}

		$partenza = $this->atto( '2026-12-31', '2026-09-20 10:00:00' );
		$this->assertSame( $visibile ? $post_id : 0, $this->vicino_precedente( $partenza ), $messaggio . ': navigazione adiacente.' );
		wp_delete_post( $partenza, true );
	}

	/**
	 * C-248: un nome di tipo è gestito se e solo se la banca dati, cercando il
	 * tipo gestito, trova il contenuto che ha quel nome.
	 *
	 * @dataProvider grafie
	 *
	 * @param string $grafia Il tipo come è scritto nella riga.
	 */
	public function test_c248_tipo_riconosciuto_come_lo_trova_la_banca_dati( $grafia ) {
		$post_id = self::factory()->post->create( array( 'post_type' => self::TIPO ) );
		$this->tipo_nella_riga( $post_id, $grafia );

		$trovato = in_array(
			$post_id,
			$this->trovati(
				array(
					'post_type'        => self::TIPO,
					'post_status'      => 'any',
					'post__in'         => array( $post_id ),
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			),
			true
		);

		$this->assertSame( $trovato, conformita_core_tipo_registrato( $grafia ), 'Core e la ricerca di WordPress devono dare la stessa risposta.' );
		$this->assertSame( $trovato ? self::SEZIONE : 'conformita_core_tipo_sconosciuto', is_wp_error( Conformita_Core_Tipi::sezione( $grafia ) ) ? Conformita_Core_Tipi::sezione( $grafia )->get_error_code() : Conformita_Core_Tipi::sezione( $grafia ) );
		$this->assertSame( $trovato ? self::TIPO : null, Conformita_Core_Tipi::canonico( $grafia ) );

		// Le grafie che la banca dati di prova deve riconoscere, perché la prova non passi senza aver provato niente.
		if ( in_array( $grafia, array( 'PROVA_CRITERIO', 'Prova_Criterio', 'prova_criterio  ' ), true ) ) {
			$this->assertTrue( $trovato, 'La banca dati di prova deve ignorare maiuscole e spazi in coda, come quelle su cui gira WordPress.' );
		}

		if ( in_array( $grafia, array( 'prova_criteri', 'prova-criterio', 'post' ), true ) ) {
			$this->assertFalse( $trovato );
		}
	}

	/**
	 * Grafie del tipo, uguali e diverse per la banca dati.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function grafie() {
		return array(
			'maiuscole'           => array( 'PROVA_CRITERIO' ),
			'maiuscole miste'     => array( 'Prova_Criterio' ),
			'spazi in coda'       => array( 'prova_criterio  ' ),
			'lettera accentata'   => array( 'pròva_criterio' ),
			'una lettera in meno' => array( 'prova_criteri' ),
			'trattino'            => array( 'prova-criterio' ),
			'tipo di WordPress'   => array( 'post' ),
		);
	}

	/**
	 * C-248: una risposta data prima non sopravvive a un tipo registrato dopo.
	 *
	 * Un nome che non era di nessun tipo gestito può diventarlo quando un
	 * componente registra il suo tipo più tardi nella stessa richiesta, e la
	 * risposta vecchia non deve restare.
	 */
	public function test_c248_registrazione_successiva_azzera_le_risposte() {
		$this->assertNull( Conformita_Core_Tipi::canonico( 'PROVA_SECONDO' ) );

		$this->assertTrue(
			conformita_core_registra_tipo(
				'prova_secondo',
				array(
					'sezione'      => self::SEZIONE,
					'show_in_rest' => false,
				)
			)
		);

		$this->assertSame( 'prova_secondo', Conformita_Core_Tipi::canonico( 'PROVA_SECONDO' ) );
	}

	/**
	 * C-248: se la banca dati non dice le regole della colonna, il ripiego
	 * ignora maiuscole e spazi in coda, e nient'altro.
	 */
	public function test_c248_ripiego_senza_le_regole_della_colonna() {
		$muta = static function ( $istruzione ) {
			return 0 === strpos( $istruzione, 'SHOW FULL COLUMNS' ) && false !== strpos( $istruzione, "'post_type'" )
				? 'SELECT 1 FROM DUAL WHERE 0 = 1'
				: $istruzione;
		};

		Conformita_Core_Tipi::azzera();
		$this->assertTrue(
			conformita_core_registra_tipo(
				self::TIPO,
				array(
					'sezione'      => self::SEZIONE,
					'show_in_rest' => false,
				)
			)
		);

		add_filter( 'query', $muta );

		$this->assertSame( self::TIPO, Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO  ' ) );
		$this->assertNull( Conformita_Core_Tipi::canonico( 'pròva_criterio' ), 'Il ripiego non indovina le regole sulle lettere accentate: le dichiara.' );
		$this->assertNull( Conformita_Core_Tipi::canonico( 'prova_criteri' ) );

		remove_filter( 'query', $muta );
	}

	/**
	 * C-249: un contenuto con il tipo in maiuscolo è trattato come gestito da
	 * tutti i meccanismi: il filtro della scadenza, sulla ricerca mista, sul
	 * valore corrotto e sull'anteprima incorporata, e il registro.
	 */
	public function test_c249_contenuto_con_il_tipo_in_maiuscolo() {
		$scaduto  = $this->atto( '2026-09-01' );
		$valido   = $this->atto( '2026-12-31' );
		$corrotto = $this->atto( '' );

		update_post_meta( $corrotto, conformita_core_chiave_fine_pubblicazione(), '9999-99-99' );

		foreach ( array( $scaduto, $valido, $corrotto ) as $post_id ) {
			$this->tipo_nella_riga( $post_id, 'PROVA_CRITERIO' );
		}

		$ricerca = $this->trovati(
			array(
				'post_type' => 'any',
				's'         => 'cercabile',
			)
		);

		$this->assertContains( $valido, $ricerca, 'La ricerca mista trova i contenuti con il tipo in maiuscolo: senza, la prova non proverebbe niente.' );
		$this->assertNotContains( $scaduto, $ricerca, 'Il ricontrollo per contenuto deve riconoscere il tipo come lo riconosce la ricerca.' );

		$per_tipo = $this->trovati( array( 'post_type' => self::TIPO ) );
		$this->assertContains( $valido, $per_tipo );
		$this->assertNotContains( $corrotto, $per_tipo, 'Il valore corrotto supera il confronto fra testi: lo ferma solo il ricontrollo per contenuto.' );

		$this->assertSame( array(), Conformita_Core_Filtro_Scadenza::filtra_anteprima_incorporata( array( 'title' => 'x' ), get_post( $scaduto ) ) );
		$this->assertSame( '2026-09-01', conformita_core_fine_pubblicazione( $scaduto ) );

		$this->assertTrue( Conformita_Core_Registro::tabella_presente(), 'Il registro deve essere installato.' );

		$ingresso = wp_list_filter(
			conformita_core_voci_registro( array( 'contenuto' => $valido ) ),
			array(
				'azione' => 'cambio_tipo',
				'tipo'   => 'PROVA_CRITERIO',
			)
		);
		$this->assertCount( 1, $ingresso, 'Il registro scrive l\'ingresso nel tipo scritto in maiuscolo, perché è ancora un tipo gestito.' );
		$this->assertSame( self::SEZIONE, reset( $ingresso )['sezione'] );
		$this->assertSame( 'ingresso', reset( $ingresso )['dettagli']['verso'] );

		/*
		 * Il salvataggio ordinario riporta il tipo alla grafia registrata:
		 * WordPress riduce il tipo con `sanitize_key` prima di salvarlo. Il tipo
		 * in maiuscolo arriva quindi solo da una scrittura diretta nella banca
		 * dati, o da un componente che toglie quella riduzione.
		 */
		wp_update_post(
			array(
				'ID'         => $valido,
				'post_title' => 'Titolo cambiato',
			)
		);

		$this->assertSame( self::TIPO, get_post( $valido )->post_type );

		$uscita = wp_list_filter(
			conformita_core_voci_registro( array( 'contenuto' => $valido ) ),
			array(
				'azione' => 'cambio_tipo',
				'tipo'   => 'PROVA_CRITERIO',
			)
		);
		$this->assertCount( 2, $uscita, 'Anche l\'uscita dal tipo scritto in maiuscolo ha la sua voce.' );
		$this->assertSame( array( 'post_title' ), end( $uscita )['dettagli']['campi'] );
	}

	/**
	 * C-250: un allegato con il tipo in maiuscolo segue il suo padre scaduto.
	 */
	public function test_c250_allegato_con_il_tipo_in_maiuscolo() {
		$padre    = $this->atto( '2026-09-01' );
		$allegato = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $padre,
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->tipo_nella_riga( $allegato, 'ATTACHMENT' );

		$argomenti = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post__in'    => array( $allegato ),
		);

		$this->assertContains( $allegato, $this->trovati( $argomenti + array( 'suppress_filters' => true ) ), 'La ricerca degli allegati trova l\'allegato con il tipo in maiuscolo.' );
		$this->assertNotContains( $allegato, $this->trovati( $argomenti ), 'L\'allegato di un contenuto scaduto non compare, qualunque grafia abbia il suo tipo.' );
		$this->assertTrue( Conformita_Core_Tipi::uguale( 'ATTACHMENT', 'attachment' ) );
		$this->assertFalse( Conformita_Core_Tipi::uguale( 'attachments', 'attachment' ) );
	}

	/**
	 * C-251: una sola riga della fine, con la chiave in maiuscolo. La banca
	 * dati la conta come la chiave vera, e ora anche la scadenza: tutti i
	 * percorsi decidono allo stesso modo, nei due versi.
	 */
	public function test_c251_chiave_in_maiuscolo_decide_come_la_banca_dati() {
		$futuro = $this->atto( '' );
		$this->riga_in_maiuscolo( $futuro, '2026-10-20' );

		$this->assertSame( 1, $this->righe_per_la_banca_dati( $futuro ), 'La banca dati di prova deve ignorare le maiuscole della chiave, come quelle su cui gira WordPress.' );
		$this->assertSame( '2026-10-20', conformita_core_fine_pubblicazione( $futuro ) );
		$this->decide_ovunque( $futuro, true, true, 'Data futura con la chiave in maiuscolo' );

		$passato = $this->atto( '', '2026-09-11 10:00:00' );
		$this->riga_in_maiuscolo( $passato, '2026-09-01' );
		wp_delete_post( $futuro, true );

		$this->assertSame( '2026-09-01', conformita_core_fine_pubblicazione( $passato ) );
		$this->decide_ovunque( $passato, false, true, 'Data passata con la chiave in maiuscolo' );
	}

	/**
	 * C-252: due righe della fine, una con la chiave vera e una in maiuscolo,
	 * sono l'anomalia di C-114 anche per la scadenza.
	 */
	public function test_c252_due_grafie_della_chiave_sono_un_duplicato() {
		$post_id = $this->atto( '2026-10-20' );
		$this->riga_in_maiuscolo( $post_id, '2026-10-25' );

		$this->assertSame( 2, $this->righe_per_la_banca_dati( $post_id ) );

		$fine = conformita_core_fine_pubblicazione( $post_id );
		$this->assertWPError( $fine );
		$this->assertSame( 'conformita_core_dato_duplicato', $fine->get_error_code() );
		$this->decide_ovunque( $post_id, false, false, 'Due grafie della chiave' );
	}

	/**
	 * C-253: la scrittura dall'API ripara anche le righe con l'altra grafia, e
	 * dichiara successo solo se al termine ne resta una.
	 */
	public function test_c253_la_scrittura_ripara_ogni_grafia() {
		$sola = $this->atto( '' );
		$this->riga_in_maiuscolo( $sola, '2026-09-01' );

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $sola, '2026-10-20' ) );
		$this->assertSame( array( '2026-10-20' ), Conformita_Core_Scadenza::righe( $sola ) );
		$this->assertSame( 1, $this->righe_per_la_banca_dati( $sola ) );

		wp_delete_post( $sola, true );

		$doppia = $this->atto( '2026-10-20' );
		$this->riga_in_maiuscolo( $doppia, '2026-09-01' );

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $doppia, '2026-10-20' ), 'La stessa data, già scritta con la chiave vera, non è un motivo per lasciare l\'altra riga.' );
		$this->assertSame( array( '2026-10-20' ), Conformita_Core_Scadenza::righe( $doppia ) );
		$this->decide_ovunque( $doppia, true, true, 'Dopo la riparazione' );
	}

	/**
	 * C-254: un filtro di WordPress sulla lettura dei metadati non cambia la
	 * data che vede la scadenza, perché non la cambia al filtro dei percorsi.
	 */
	public function test_c254_un_filtro_sui_metadati_non_sposta_la_scadenza() {
		$post_id = $this->atto( '2026-09-01' );

		$bugiardo = static function ( $valore, $oggetto, $chiave ) use ( $post_id ) {
			if ( (int) $oggetto === $post_id && conformita_core_chiave_fine_pubblicazione() === $chiave ) {
				return array( '2099-12-31' );
			}

			return $valore;
		};

		add_filter( 'get_post_metadata', $bugiardo, 10, 3 );

		$this->assertSame( '2099-12-31', get_post_meta( $post_id, conformita_core_chiave_fine_pubblicazione(), true ), 'Il filtro deve agire sulla lettura di WordPress: senza, la prova non proverebbe niente.' );
		$this->assertSame( '2026-09-01', conformita_core_fine_pubblicazione( $post_id ) );
		$this->decide_ovunque( $post_id, false, true, 'Con un filtro che mente sulla data' );

		remove_filter( 'get_post_metadata', $bugiardo, 10 );
	}

	/**
	 * C-255: le risposte ricordate valgono per la tabella a cui sono state
	 * chieste.
	 *
	 * Nella stessa richiesta WordPress può passare da una tabella dei contenuti
	 * all'altra, e le due tabelle possono confrontare il tipo con regole
	 * diverse. Qui la prima è una copia temporanea con il confronto binario, che
	 * distingue le maiuscole; la seconda è quella vera. La risposta "non
	 * gestito" data per la prima non deve valere per la seconda, dove la
	 * ricerca del sito trova il contenuto.
	 */
	public function test_c255_risposte_ricordate_per_tabella() {
		global $wpdb;

		$scaduto = $this->atto( '2026-09-01' );
		$valido  = $this->atto( '2026-12-31' );

		foreach ( array( $scaduto, $valido ) as $post_id ) {
			$this->tipo_nella_riga( $post_id, 'PROVA_CRITERIO' );
		}

		$vera    = $wpdb->posts;
		$copia   = $vera . '_binaria';
		$colonna = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$vera} LIKE 'post_type'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: struttura della tabella.
		$insieme = strtok( (string) $colonna['Collation'], '_' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- prova: una tabella temporanea non chiude la transazione della prova.
		$wpdb->query( "CREATE TEMPORARY TABLE {$copia} LIKE {$vera}" );
		$wpdb->query( "ALTER TABLE {$copia} MODIFY post_type VARCHAR(20) CHARACTER SET {$insieme} COLLATE {$insieme}_bin NOT NULL DEFAULT 'post'" );
		$wpdb->query( "INSERT INTO {$copia} SELECT * FROM {$vera} WHERE ID IN ( {$scaduto}, {$valido} )" );
		$trovati_nella_copia = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$copia} WHERE post_type = %s", self::TIPO ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		try {
			$wpdb->posts = $copia;

			$this->assertSame( 0, $trovati_nella_copia, 'Nella copia binaria la ricerca per il tipo gestito non trova la grafia in maiuscolo.' );
			$this->assertNull( Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO' ), 'Per la copia binaria il nome non è di un tipo gestito.' );
		} finally {
			$wpdb->posts = $vera;
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$copia}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- prova: pulizia della tabella temporanea.
		}

		$this->assertSame( self::TIPO, Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO' ), 'Tornati alla tabella vera, vale la risposta della tabella vera.' );

		$ricerca = $this->trovati(
			array(
				'post_type' => 'any',
				's'         => 'cercabile',
			)
		);

		$this->assertContains( $valido, $ricerca );
		$this->assertNotContains( $scaduto, $ricerca, 'La risposta ricordata per l\'altra tabella non lascia passare il contenuto scaduto.' );
	}

	/**
	 * C-256: un tipo registrato in WordPress con un nome non ridotto non è una
	 * scorciatoia.
	 *
	 * Un componente che cambia la riduzione dei nomi può registrare
	 * `PROVA_CRITERIO` accanto al tipo gestito `prova_criterio`. Su una colonna
	 * che non distingue le maiuscole la ricerca del tipo gestito trova anche i
	 * contenuti di quello, quindi core deve trattarli come gestiti.
	 */
	public function test_c256_tipo_registrato_con_nome_non_ridotto() {
		$conserva = static function ( $ridotto, $originale ) {
			return 'PROVA_CRITERIO' === $originale ? $originale : $ridotto;
		};

		add_filter( 'sanitize_key', $conserva, 10, 2 );
		register_post_type( 'PROVA_CRITERIO', array( 'public' => true ) ); // phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.InvalidCharacters -- prova: è proprio il nome non ridotto.
		remove_filter( 'sanitize_key', $conserva, 10 );

		try {
			$this->assertTrue( post_type_exists( 'PROVA_CRITERIO' ), 'Il tipo con il nome in maiuscolo deve essere registrato: senza, la prova non proverebbe niente.' );

			$scaduto = $this->atto( '2026-09-01' );
			$valido  = $this->atto( '2026-12-31' );

			foreach ( array( $scaduto, $valido ) as $post_id ) {
				$this->tipo_nella_riga( $post_id, 'PROVA_CRITERIO' );
			}

			$this->assertSame( self::TIPO, Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO' ) );

			$ricerca = $this->trovati(
				array(
					'post_type' => 'any',
					's'         => 'cercabile',
				)
			);

			$this->assertContains( $valido, $ricerca );
			$this->assertNotContains( $scaduto, $ricerca, 'Il contenuto scaduto resta fuori anche se il suo tipo è registrato in WordPress.' );
		} finally {
			unregister_post_type( 'PROVA_CRITERIO' );
		}
	}

	/**
	 * C-256: nessuna risposta sopravvive a un cambio della tabella sotto lo
	 * stesso nome.
	 *
	 * Una tabella temporanea con lo stesso nome nasconde quella vera e
	 * confronta il tipo lettera per lettera; quando viene tolta, torna la
	 * tabella vera, che non distingue le maiuscole. Il nome della tabella non
	 * cambia, le regole sì.
	 */
	public function test_c256_tabella_omonima_nella_stessa_richiesta() {
		global $wpdb;

		$scaduto = $this->atto( '2026-09-01' );
		$valido  = $this->atto( '2026-12-31' );

		foreach ( array( $scaduto, $valido ) as $post_id ) {
			$this->tipo_nella_riga( $post_id, 'PROVA_CRITERIO' );
		}

		$vera     = $wpdb->posts;
		$appoggio = $vera . '_appoggio';
		$colonna  = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$vera} LIKE 'post_type'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: struttura della tabella.
		$insieme  = strtok( (string) $colonna['Collation'], '_' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- prova: le tabelle temporanee non chiudono la transazione della prova.
		$wpdb->query( "CREATE TEMPORARY TABLE {$appoggio} LIKE {$vera}" );
		$wpdb->query( "ALTER TABLE {$appoggio} MODIFY post_type VARCHAR(20) CHARACTER SET {$insieme} COLLATE {$insieme}_bin NOT NULL DEFAULT 'post'" );
		$wpdb->query( "INSERT INTO {$appoggio} SELECT * FROM {$vera} WHERE ID IN ( {$scaduto}, {$valido} )" );
		$wpdb->query( "CREATE TEMPORARY TABLE {$vera} LIKE {$appoggio}" );
		$wpdb->query( "INSERT INTO {$vera} SELECT * FROM {$appoggio}" );

		try {
			$colonna_ombra = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$vera} LIKE 'post_type'", ARRAY_A );
			$this->assertStringEndsWith( '_bin', (string) $colonna_ombra['Collation'], 'La tabella temporanea deve nascondere quella vera: senza, la prova non proverebbe niente.' );
			$this->assertNull( Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO' ), 'Per la tabella che distingue le maiuscole il nome non è gestito.' );
		} finally {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$vera}" );
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$appoggio}" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		$this->assertSame( self::TIPO, Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO' ), 'Tolta la tabella temporanea, vale la tabella vera.' );

		$ricerca = $this->trovati(
			array(
				'post_type' => 'any',
				's'         => 'cercabile',
			)
		);

		$this->assertContains( $valido, $ricerca );
		$this->assertNotContains( $scaduto, $ricerca );
	}

	/**
	 * C-257: un tipo gestito con un nome di sole cifre si riconosce come gli
	 * altri, anche in una grafia che la banca dati considera uguale.
	 *
	 * PHP trasforma in numero la chiave di un elenco scritta solo con cifre, e
	 * un confronto stretto fra il numero e il nome letto dalla banca dati
	 * fallirebbe sempre.
	 */
	public function test_c257_tipo_con_nome_di_sole_cifre() {
		$this->assertTrue(
			conformita_core_registra_tipo(
				'123',
				array(
					'sezione'      => self::SEZIONE,
					'show_in_rest' => false,
				)
			)
		);

		$this->assertContains( '123', Conformita_Core_Tipi::identificativi() );

		$post_id = self::factory()->post->create( array( 'post_type' => '123' ) );
		$grafia  = "\u{FF11}\u{FF12}\u{FF13}";
		$this->tipo_nella_riga( $post_id, $grafia );

		$trovato = in_array(
			$post_id,
			$this->trovati(
				array(
					'post_type'        => '123',
					'post_status'      => 'any',
					'post__in'         => array( $post_id ),
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			),
			true
		);

		$this->assertTrue( $trovato, 'La banca dati di prova deve considerare uguali le cifre a larghezza piena e quelle normali: senza, la prova non proverebbe niente.' );
		$this->assertSame( '123', Conformita_Core_Tipi::canonico( $grafia ) );
		$this->assertSame( '123', Conformita_Core_Tipi::canonico( '123' ) );
		$this->assertSame( self::SEZIONE, Conformita_Core_Tipi::sezione( $grafia ) );
	}

	/**
	 * C-258: le regole si leggono dalla colonna del tipo e da nessun'altra.
	 *
	 * Una colonna aggiunta da un componente con un nome che differisce da
	 * `post_type` in un solo carattere, messa prima e con il confronto binario,
	 * non deve prendere il posto della colonna vera nella lettura delle regole.
	 */
	public function test_c258_regole_dalla_colonna_giusta() {
		global $wpdb;

		$vera     = $wpdb->posts;
		$appoggio = $vera . '_appoggio';
		$colonna  = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$vera} WHERE Field = 'post_type'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: struttura della tabella.
		$insieme  = strtok( (string) $colonna['Collation'], '_' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- prova: le tabelle temporanee non chiudono la transazione della prova.
		$wpdb->query( "CREATE TEMPORARY TABLE {$appoggio} LIKE {$vera}" );
		$wpdb->query( "ALTER TABLE {$appoggio} ADD COLUMN postXtype VARCHAR(20) CHARACTER SET {$insieme} COLLATE {$insieme}_bin NOT NULL DEFAULT '' FIRST" );
		$wpdb->query( "CREATE TEMPORARY TABLE {$vera} LIKE {$appoggio}" );

		try {
			$simile = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$vera} LIKE 'post_type'", ARRAY_A );
			$this->assertSame( 'postXtype', $simile['Field'], 'La colonna aggiunta deve essere la prima che la somiglianza trova: senza, la prova non proverebbe niente.' );
			$this->assertSame( self::TIPO, Conformita_Core_Tipi::canonico( 'PROVA_CRITERIO' ), 'Le regole sono quelle della colonna del tipo, che non distingue le maiuscole.' );
		} finally {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$vera}" );
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$appoggio}" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
