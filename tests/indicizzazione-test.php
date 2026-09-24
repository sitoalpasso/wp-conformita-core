<?php
/**
 * Il meccanismo di indicizzazione: righe C-80..C-85 e C-225..C-230.
 *
 * La riga C-228, il divieto sui file consegnati, sta in `consegna-test.php`,
 * perche' li' ci sono gli strumenti per chiedere un file al punto di consegna.
 *
 * **Perche' ogni prova guarda due pagine.** Una pagina senza `noindex` ha lo
 * stesso aspetto di una pagina con `noindex`, e un meccanismo che mettesse il
 * divieto dappertutto passerebbe ogni prova sulla sezione `vietata`; uno che
 * non lo mettesse mai passerebbe ogni prova sulla sezione `consentita`. Per
 * questo ogni prova confronta le due sezioni nello stesso sito, e le prove sui
 * contenuti estranei guardano anche una pagina vietata: se il divieto non
 * compare nemmeno li', la prova e' vuota.
 *
 * **Come si leggono i due segnali.** Il sorgente si legge stampando quello che
 * WordPress stampa nell'intestazione HTML della pagina. Le intestazioni della
 * risposta si leggono dal filtro con cui WordPress le prepara, dopo aver
 * eseguito la richiesta: emetterle davvero, dentro una prova, non si puo',
 * perche' la suite ha gia' scritto sull'uscita.
 *
 * @package Conformita_Core
 */

/**
 * Prove sull'applicazione della politica di indicizzazione.
 */
class Conformita_Core_Indicizzazione_Test extends WP_UnitTestCase {

	const SEZIONE_CHIUSA = 'sezione_chiusa';
	const TIPO_CHIUSO    = 'prova_chiusa';

	const SEZIONE_APERTA = 'sezione_aperta';
	const TIPO_APERTO    = 'prova_aperta';

	/**
	 * Valore di `blog_public` prima della prova.
	 *
	 * @var mixed
	 */
	private $pubblico_originale = null;

	/**
	 * Due sezioni con politiche opposte, permalink attivi, sito indicizzabile.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();
		Conformita_Core_Indicizzazione::azzera_avvio();
		Conformita_Core_Indicizzazione::avvia();

		/*
		 * Con il sito impostato come "non indicizzabile" WordPress mette il
		 * divieto su ogni pagina da solo, e le prove sulla sezione consentita
		 * e sui contenuti estranei diventerebbero rosse per un motivo che non
		 * riguarda questo meccanismo.
		 */
		$this->pubblico_originale = get_option( 'blog_public' );
		update_option( 'blog_public', '1' );
		update_option( 'wp_attachment_pages_enabled', 1 );

		$this->set_permalink_structure( '/%postname%/' );

		$this->registra( self::SEZIONE_CHIUSA, self::TIPO_CHIUSO, 'vietata' );
		$this->registra( self::SEZIONE_APERTA, self::TIPO_APERTO, 'consentita' );

		flush_rewrite_rules();
	}

	/**
	 * Meccanismo riacceso, impostazioni ripristinate.
	 */
	public function tear_down() {
		Conformita_Core_Indicizzazione::avvia();
		update_option( 'blog_public', $this->pubblico_originale );
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Registra una sezione e un tipo pubblico con archivio.
	 *
	 * @param string $sezione        Identificativo della sezione.
	 * @param string $tipo           Identificativo del tipo.
	 * @param string $indicizzazione Politica di indicizzazione.
	 */
	private function registra( $sezione, $tipo, $indicizzazione ) {
		$this->assertTrue(
			conformita_core_registra_sezione(
				$sezione,
				array(
					'indicizzazione' => $indicizzazione,
					'scadenza'       => 'irraggiungibile',
				)
			),
			'La sezione di prova deve registrarsi.'
		);

		$this->assertTrue(
			conformita_core_registra_tipo(
				$tipo,
				array(
					'sezione'      => $sezione,
					'show_in_rest' => false,
					'argomenti'    => array(
						'public'      => true,
						'has_archive' => true,
						'rewrite'     => array( 'slug' => $tipo ),
					),
				)
			),
			'Il tipo di prova deve registrarsi.'
		);
	}

	/**
	 * Un contenuto pubblicato e non scaduto.
	 *
	 * @param string $tipo Tipo di contenuto.
	 * @return int
	 */
	private function contenuto( $tipo ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $tipo,
				'post_status' => 'publish',
				'post_title'  => 'Contenuto ' . $tipo,
			)
		);

		if ( conformita_core_tipo_registrato( $tipo ) ) {
			$fine = ( new DateTimeImmutable( '+30 days', wp_timezone() ) )->format( 'Y-m-d' );
			$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $post_id, $fine ) );
		}

		return $post_id;
	}

	/**
	 * Quello che WordPress stampa come metatag `robots` nel sorgente.
	 *
	 * @return string
	 */
	private function sorgente() {
		return get_echo( 'wp_robots' );
	}

	/**
	 * Le intestazioni che WordPress prepara per la richiesta appena eseguita.
	 *
	 * @return array<string, string>
	 */
	private function intestazioni() {
		return apply_filters( 'wp_headers', array(), $GLOBALS['wp'] );
	}

	/**
	 * La richiesta appena eseguita porta il divieto nei due segnali.
	 *
	 * @param string $dove Descrizione della pagina, per il messaggio.
	 */
	private function assert_vietata( $dove ) {
		$intestazioni = $this->intestazioni();

		$this->assertStringContainsString( 'noindex', $this->sorgente(), $dove . ': il divieto deve stare nel sorgente.' );
		$this->assertArrayHasKey( 'X-Robots-Tag', $intestazioni, $dove . ': il divieto deve stare nell\'intestazione.' );
		$this->assertStringContainsString( 'noindex', $intestazioni['X-Robots-Tag'], $dove );
	}

	/**
	 * La richiesta appena eseguita non porta nessun divieto.
	 *
	 * @param string $dove Descrizione della pagina, per il messaggio.
	 */
	private function assert_libera( $dove ) {
		$intestazioni = $this->intestazioni();

		$this->assertStringNotContainsString( 'noindex', $this->sorgente(), $dove . ': nessun divieto nel sorgente.' );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $intestazioni, $dove . ': nessun divieto nell\'intestazione.' );
	}

	/**
	 * I tipi elencati nella mappa per i motori.
	 *
	 * @return array<int, string>
	 */
	private function tipi_in_mappa() {
		return array_keys( ( new WP_Sitemaps_Posts() )->get_object_subtypes() );
	}

	/**
	 * Gli indirizzi della mappa per un tipo.
	 *
	 * @param string $tipo Tipo di contenuto.
	 * @return array<int, string>
	 */
	private function indirizzi_in_mappa( $tipo ) {
		return wp_list_pluck( ( new WP_Sitemaps_Posts() )->get_url_list( 1, $tipo ), 'loc' );
	}

	/**
	 * C-80: la pagina di un contenuto di una sezione `vietata` porta `noindex`
	 * nell'intestazione e nel sorgente.
	 */
	public function test_c80_pagina_di_un_contenuto_vietato() {
		$vietato = $this->contenuto( self::TIPO_CHIUSO );

		$this->go_to( get_permalink( $vietato ) );

		$this->assertTrue( is_singular( self::TIPO_CHIUSO ), 'Precondizione: la pagina del contenuto si apre.' );
		$this->assertSame( 1, has_action( 'wp_head', 'wp_robots' ), 'Precondizione: WordPress stampa il metatag nella testata della pagina.' );
		$this->assert_vietata( 'Pagina del contenuto vietato' );
	}

	/**
	 * C-81: la pagina di un contenuto di una sezione `consentita` non porta
	 * nessun divieto.
	 */
	public function test_c81_pagina_di_un_contenuto_consentito() {
		$consentito = $this->contenuto( self::TIPO_APERTO );
		$vietato    = $this->contenuto( self::TIPO_CHIUSO );

		$this->go_to( get_permalink( $consentito ) );

		$this->assertTrue( is_singular( self::TIPO_APERTO ), 'Precondizione: la pagina del contenuto si apre.' );
		$this->assertStringContainsString( "name='robots'", $this->sorgente(), 'Precondizione: il metatag viene stampato, quindi l\'assenza del divieto e\' una scelta e non un silenzio.' );
		$this->assert_libera( 'Pagina del contenuto consentito' );

		$this->go_to( get_permalink( $vietato ) );
		$this->assert_vietata( 'Controllo nello stesso sito' );
	}

	/**
	 * C-82: la mappa elenca i contenuti della sezione `consentita` e non quelli
	 * della sezione `vietata`.
	 */
	public function test_c82_mappa_per_i_motori() {
		$vietato    = $this->contenuto( self::TIPO_CHIUSO );
		$consentito = $this->contenuto( self::TIPO_APERTO );

		$this->assertContains( self::TIPO_APERTO, $this->tipi_in_mappa() );
		$this->assertContains( get_permalink( $consentito ), $this->indirizzi_in_mappa( self::TIPO_APERTO ) );

		$this->assertNotContains( self::TIPO_CHIUSO, $this->tipi_in_mappa(), 'Il tipo vietato non deve avere una pagina nella mappa.' );
		$this->assertNotContains( get_permalink( $vietato ), $this->indirizzi_in_mappa( self::TIPO_CHIUSO ) );

		/*
		 * Non vacuita': a meccanismo spento il tipo vietato ricompare, quindi
		 * a toglierlo e' il meccanismo e non un'altra ragione (tipo non
		 * pubblico, contenuto scaduto, mappa spenta).
		 */
		Conformita_Core_Indicizzazione::azzera_avvio();

		$this->assertContains( self::TIPO_CHIUSO, $this->tipi_in_mappa() );
		$this->assertContains( get_permalink( $vietato ), $this->indirizzi_in_mappa( self::TIPO_CHIUSO ) );
	}

	/**
	 * C-83: due sezioni con politiche opposte, registrate nei due ordini.
	 */
	public function test_c83_due_sezioni_nei_due_ordini() {
		$ordini = array(
			'prima la vietata'    => array( 'vietata', 'consentita' ),
			'prima la consentita' => array( 'consentita', 'vietata' ),
		);

		foreach ( $ordini as $nome => $ordine ) {
			Conformita_Core_Sezioni::azzera();
			Conformita_Core_Tipi::azzera();

			$tipi = array();

			foreach ( $ordine as $indice => $politica ) {
				$tipi[ $politica ] = 'prova_ordine_' . $indice;
				$this->registra( 'sezione_ordine_' . $indice, $tipi[ $politica ], $politica );
			}

			flush_rewrite_rules();

			$vietato    = $this->contenuto( $tipi['vietata'] );
			$consentito = $this->contenuto( $tipi['consentita'] );

			$this->go_to( get_permalink( $vietato ) );
			$this->assertTrue( is_singular( $tipi['vietata'] ), $nome );
			$this->assert_vietata( $nome . ', contenuto vietato' );

			$this->go_to( get_permalink( $consentito ) );
			$this->assertTrue( is_singular( $tipi['consentita'] ), $nome );
			$this->assert_libera( $nome . ', contenuto consentito' );

			$this->assertNotContains( $tipi['vietata'], $this->tipi_in_mappa(), $nome );
			$this->assertContains( $tipi['consentita'], $this->tipi_in_mappa(), $nome );
		}
	}

	/**
	 * C-84: pagine e articoli del sito, che non appartengono a nessuna sezione,
	 * restano come sono.
	 */
	public function test_c84_contenuti_estranei_intatti() {
		$articolo = $this->contenuto( 'post' );
		$pagina   = $this->contenuto( 'page' );
		$vietato  = $this->contenuto( self::TIPO_CHIUSO );

		$this->go_to( get_permalink( $articolo ) );
		$this->assertTrue( is_singular( 'post' ) );
		$this->assert_libera( 'Articolo' );

		$this->go_to( get_permalink( $pagina ) );
		$this->assertTrue( is_singular( 'page' ) );
		$this->assert_libera( 'Pagina' );

		$this->go_to( home_url( '/' ) );
		$this->assert_libera( 'Pagina iniziale' );

		$this->assertContains( 'post', $this->tipi_in_mappa() );
		$this->assertContains( 'page', $this->tipi_in_mappa() );
		$this->assertContains( get_permalink( $articolo ), $this->indirizzi_in_mappa( 'post' ) );
		$this->assertContains( get_permalink( $pagina ), $this->indirizzi_in_mappa( 'page' ) );

		$this->go_to( get_permalink( $vietato ) );
		$this->assert_vietata( 'Controllo nello stesso sito' );
	}

	/**
	 * C-85: a meccanismo spento la registrazione di una sezione fallisce, e lo
	 * dice.
	 */
	public function test_c85_sezione_a_meccanismo_spento() {
		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Indicizzazione::azzera_avvio();

		$this->assertFalse( Conformita_Core_Indicizzazione::avviato() );
		$this->assertTrue( Conformita_Core_Filtro_Scadenza::avviato(), 'Precondizione: il rifiuto non deve venire dal motore di scadenza.' );

		$esito = conformita_core_registra_sezione(
			'sezione_orfana',
			array(
				'indicizzazione' => 'vietata',
				'scadenza'       => 'irraggiungibile',
			)
		);

		Conformita_Core_Indicizzazione::avvia();

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_indicizzazione_non_avviata', $esito->get_error_code() );
		$this->assertStringContainsString( 'indicizzazione', $esito->get_error_message() );
		$this->assertFalse( conformita_core_sezione_registrata( 'sezione_orfana' ), 'La sezione non deve risultare registrata.' );

		$this->assertTrue(
			conformita_core_registra_sezione(
				'sezione_orfana',
				array(
					'indicizzazione' => 'vietata',
					'scadenza'       => 'irraggiungibile',
				)
			),
			'Riacceso il meccanismo, la stessa registrazione riesce.'
		);
	}

	/**
	 * C-225: l'elenco di un tipo vietato porta il divieto, quello di un tipo
	 * consentito no.
	 */
	public function test_c225_elenco_del_tipo() {
		$this->contenuto( self::TIPO_CHIUSO );
		$this->contenuto( self::TIPO_APERTO );

		$this->go_to( get_post_type_archive_link( self::TIPO_CHIUSO ) );
		$this->assertTrue( is_post_type_archive( self::TIPO_CHIUSO ), 'Precondizione: l\'elenco si apre.' );
		$this->assert_vietata( 'Elenco del tipo vietato' );

		$this->go_to( get_post_type_archive_link( self::TIPO_APERTO ) );
		$this->assertTrue( is_post_type_archive( self::TIPO_APERTO ), 'Precondizione: l\'elenco si apre.' );
		$this->assert_libera( 'Elenco del tipo consentito' );
	}

	/**
	 * C-226: i feed dei tipi vietati, e il feed dei commenti di un contenuto
	 * vietato, portano il divieto nell'intestazione. Un feed che mescola i due
	 * tipi lo porta anche lui.
	 *
	 * Indirizzi con le variabili in chiaro e non con le regole di riscrittura:
	 * nella suite completa le regole dei tipi registrati dalle prove precedenti
	 * possono non essere quelle attese, e la prova fallirebbe per un motivo che
	 * non riguarda il meccanismo. E' la stessa scelta delle prove sul feed di S4.
	 */
	public function test_c226_feed() {
		$vietato    = $this->contenuto( self::TIPO_CHIUSO );
		$consentito = $this->contenuto( self::TIPO_APERTO );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO_CHIUSO );
		$this->assertTrue( is_feed(), 'Precondizione: e\' un feed.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Feed del tipo vietato.' );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO_CHIUSO . '&p=' . $vietato );
		$this->assertTrue( is_feed() && is_singular( self::TIPO_CHIUSO ), 'Precondizione: e\' il feed dei commenti del contenuto.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Feed dei commenti del contenuto vietato.' );

		$this->go_to( '/?feed=rss2&post_type[]=' . self::TIPO_APERTO . '&post_type[]=' . self::TIPO_CHIUSO );
		$this->assertTrue( is_feed(), 'Precondizione: e\' un feed.' );
		$this->assertSame( array( self::TIPO_APERTO, self::TIPO_CHIUSO ), (array) get_query_var( 'post_type' ), 'Precondizione: il feed riguarda i due tipi.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Feed misto: vince il divieto.' );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO_APERTO );
		$this->assertTrue( is_feed(), 'Precondizione: e\' un feed.' );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $this->intestazioni(), 'Feed del tipo consentito.' );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO_APERTO . '&p=' . $consentito );
		$this->assertTrue( is_feed() && is_singular( self::TIPO_APERTO ), 'Precondizione: e\' il feed dei commenti del contenuto.' );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $this->intestazioni(), 'Feed dei commenti del contenuto consentito.' );

		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $this->intestazioni(), 'Feed generale del sito.' );
	}

	/**
	 * C-227: la pagina di un allegato risponde per il contenuto a cui
	 * appartiene.
	 */
	public function test_c227_pagina_di_un_allegato() {
		$vietato    = $this->contenuto( self::TIPO_CHIUSO );
		$consentito = $this->contenuto( self::TIPO_APERTO );
		$articolo   = $this->contenuto( 'post' );

		$allegati = array(
			'vietato'    => self::factory()->attachment->create_object( 'vietato.pdf', $vietato, array( 'post_mime_type' => 'application/pdf' ) ),
			'consentito' => self::factory()->attachment->create_object( 'consentito.pdf', $consentito, array( 'post_mime_type' => 'application/pdf' ) ),
			'articolo'   => self::factory()->attachment->create_object( 'articolo.pdf', $articolo, array( 'post_mime_type' => 'application/pdf' ) ),
			'orfano'     => self::factory()->attachment->create_object( 'orfano.pdf', 0, array( 'post_mime_type' => 'application/pdf' ) ),
		);

		foreach ( $allegati as $nome => $allegato ) {
			$this->go_to( get_attachment_link( $allegato ) );
			$this->assertTrue( is_attachment(), 'Precondizione: la pagina dell\'allegato ' . $nome . ' si apre.' );

			if ( 'vietato' === $nome ) {
				$this->assert_vietata( 'Allegato di un contenuto vietato' );
			} else {
				$this->assert_libera( 'Allegato ' . $nome );
			}
		}
	}

	/**
	 * C-229: il meccanismo non tocca `robots.txt`.
	 *
	 * Un indirizzo escluso li' non viene visitato, quindi il motore non legge
	 * mai il divieto e l'indirizzo puo' finire nell'indice lo stesso, se
	 * collegato da fuori. La prova confronta il testo prodotto con il
	 * meccanismo acceso e spento, a parita' di sezioni registrate.
	 */
	public function test_c229_robots_txt_intatto() {
		$acceso = apply_filters( 'robots_txt', "User-agent: *\n", '1' );

		Conformita_Core_Indicizzazione::azzera_avvio();
		$spento = apply_filters( 'robots_txt', "User-agent: *\n", '1' );
		Conformita_Core_Indicizzazione::avvia();

		$this->assertSame( $spento, $acceso );
		$this->assertStringNotContainsString( self::TIPO_CHIUSO, $acceso );
	}

	/**
	 * C-230: spento il meccanismo, nessuno dei suoi agganci risponde; acceso,
	 * rispondono tutti.
	 */
	public function test_c230_accensione_e_spegnimento() {
		$agganci = Conformita_Core_Indicizzazione::agganci();

		$this->assertCount( 3, $agganci );

		foreach ( $agganci as $aggancio ) {
			$this->assertSame(
				Conformita_Core_Indicizzazione::PRIORITA,
				has_filter( $aggancio['aggancio'], array( 'Conformita_Core_Indicizzazione', $aggancio['metodo'] ) ),
				'Acceso: ' . $aggancio['aggancio']
			);
		}

		$vietato = $this->contenuto( self::TIPO_CHIUSO );

		Conformita_Core_Indicizzazione::azzera_avvio();

		$this->assertFalse( Conformita_Core_Indicizzazione::avviato() );

		foreach ( $agganci as $aggancio ) {
			$this->assertFalse(
				has_filter( $aggancio['aggancio'], array( 'Conformita_Core_Indicizzazione', $aggancio['metodo'] ) ),
				'Spento: ' . $aggancio['aggancio']
			);
		}

		$this->go_to( get_permalink( $vietato ) );
		$this->assertTrue( is_singular( self::TIPO_CHIUSO ) );
		$this->assert_libera( 'A meccanismo spento' );

		Conformita_Core_Indicizzazione::avvia();

		$this->go_to( get_permalink( $vietato ) );
		$this->assert_vietata( 'Riacceso' );
	}
}
