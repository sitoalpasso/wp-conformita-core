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
	 * Quello che WordPress stampa come metatag `robots` nel sorgente, a
	 * condizione che sia davvero agganciato alla testata della pagina.
	 *
	 * La testata intera non si stampa: in un ambiente di prova costruito dai
	 * sorgenti di WordPress porta con se' il caricatore degli script, che cerca
	 * file generati dalla compilazione e fallisce per un motivo che non
	 * riguarda questo meccanismo. Si stampa quindi il metatag, e si pretende
	 * che la testata lo stampi: se un tema o un componente lo toglie dalla
	 * testata, questa funzione restituisce vuoto e il divieto nel sorgente
	 * risulta assente, come sulla pagina vera. Riga C-236.
	 *
	 * @return string
	 */
	private function sorgente() {
		if ( false === has_action( 'wp_head', 'wp_robots' ) ) {
			return '';
		}

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
	 * C-225 e C-226, elenco e feed vuoti: il divieto dipende dal tipo e non
	 * dai contenuti mostrati.
	 *
	 * Un elenco con dentro un contenuto vietato porterebbe il divieto anche per
	 * la regola della riga C-231. Senza questa prova, dimenticare gli elenchi e
	 * i feed dei tipi resterebbe invisibile: si vedrebbe solo quando l'elenco e'
	 * vuoto, per esempio perche' tutti gli atti sono scaduti.
	 */
	public function test_c225_c226_elenco_e_feed_vuoti() {
		$this->go_to( get_post_type_archive_link( self::TIPO_CHIUSO ) );
		$this->assertTrue( is_post_type_archive( self::TIPO_CHIUSO ), 'Precondizione: l\'elenco vuoto si apre.' );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts, 'Precondizione: l\'elenco e\' vuoto.' );
		$this->assert_vietata( 'Elenco vuoto del tipo vietato' );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO_CHIUSO );
		$this->assertTrue( is_feed() );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts, 'Precondizione: il feed e\' vuoto.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Feed vuoto del tipo vietato.' );

		/*
		 * Un tipo senza elenco proprio ha comunque il suo feed, e li' la
		 * richiesta non e' un elenco del tipo: e' il caso che distingue la
		 * regola dei feed da quella degli elenchi.
		 */
		$this->assertTrue(
			conformita_core_registra_tipo(
				'prova_chiusa_bis',
				array(
					'sezione'      => self::SEZIONE_CHIUSA,
					'show_in_rest' => false,
					'argomenti'    => array(
						'public'      => true,
						'has_archive' => false,
					),
				)
			)
		);

		$this->go_to( '/?feed=rss2&post_type=prova_chiusa_bis' );
		$this->assertTrue( is_feed() );
		$this->assertFalse( is_post_type_archive(), 'Precondizione: non e\' l\'elenco di un tipo.' );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Feed vuoto di un tipo vietato senza elenco.' );

		$this->go_to( get_post_type_archive_link( self::TIPO_APERTO ) );
		$this->assertTrue( is_post_type_archive( self::TIPO_APERTO ) );
		$this->assert_libera( 'Elenco vuoto del tipo consentito' );
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

		$this->assertCount( 5, $agganci );

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

	/**
	 * C-231: un elenco che non e' quello del tipo, ma mostra contenuti vietati,
	 * porta il divieto.
	 *
	 * La pagina iniziale e gli elenchi per autore diventano misti con una
	 * personalizzazione comune dell'interrogazione principale; il feed generale
	 * con `post_type=any`. Ogni caso verifica prima che il contenuto vietato sia
	 * davvero fra quelli mostrati, e poi che senza di esso lo stesso elenco resti
	 * libero.
	 */
	public function test_c231_elenchi_misti() {
		$autore  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$vietato = $this->contenuto( self::TIPO_CHIUSO );
		wp_update_post(
			array(
				'ID'          => $vietato,
				'post_author' => $autore,
			)
		);
		$articolo = $this->contenuto( 'post' );
		wp_update_post(
			array(
				'ID'          => $articolo,
				'post_author' => $autore,
			)
		);

		$allarga = function ( $interrogazione ) {
			if ( $interrogazione->is_main_query() && ( $interrogazione->is_home() || $interrogazione->is_author() ) ) {
				$interrogazione->set( 'post_type', array( 'post', self::TIPO_CHIUSO ) );
			}
		};

		$casi = array(
			'Pagina iniziale'   => home_url( '/' ),
			'Elenco per autore' => get_author_posts_url( $autore ),
		);

		foreach ( $casi as $nome => $indirizzo ) {
			$this->go_to( $indirizzo );
			$this->assertContains( $articolo, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), $nome . ': precondizione, l\'elenco si apre.' );
			$this->assertNotContains( $vietato, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), $nome . ': precondizione, senza personalizzazione il vietato non c\'e\'.' );
			$this->assert_libera( $nome . ' senza contenuti vietati' );

			add_action( 'pre_get_posts', $allarga );
			$this->go_to( $indirizzo );
			remove_action( 'pre_get_posts', $allarga );

			$this->assertContains( $vietato, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), $nome . ': precondizione, il vietato e\' nell\'elenco.' );
			$this->assert_vietata( $nome . ' con un contenuto vietato' );
		}

		$this->go_to( '/?feed=rss2&post_type=any' );
		$this->assertTrue( is_feed() );
		$this->assertContains( $vietato, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'Precondizione: il feed di tutti i tipi contiene il vietato.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Feed di tutti i tipi.' );
	}

	/**
	 * C-232: come si compone `X-Robots-Tag` quando un altro componente ne ha
	 * gia' scritto uno.
	 */
	public function test_c232_composizione_dell_intestazione() {
		$vietato = $this->contenuto( self::TIPO_CHIUSO );
		$this->go_to( get_permalink( $vietato ) );

		$casi = array(
			'nessuna'              => array( array(), 'noindex', array() ),
			'generale senza'       => array( array( 'X-Robots-Tag' => 'nofollow' ), 'nofollow, noindex', array() ),
			'generale con'         => array( array( 'X-Robots-Tag' => 'NoIndex, nofollow' ), 'NoIndex, nofollow', array() ),
			'none'                 => array( array( 'X-Robots-Tag' => 'none' ), 'none', array() ),
			'direttiva con valore' => array( array( 'X-Robots-Tag' => 'max-snippet: 20' ), 'max-snippet: 20, noindex', array() ),
			'nome minuscolo'       => array( array( 'x-robots-tag' => 'nofollow' ), 'nofollow, noindex', array() ),
			'motore con noindex'   => array( array( 'X-Robots-Tag' => 'googlebot: noindex' ), 'noindex', array( 'googlebot: noindex' ) ),
			'motore con nofollow'  => array( array( 'X-Robots-Tag' => 'googlebot: nofollow' ), 'noindex', array( 'googlebot: nofollow' ) ),
		);

		foreach ( $casi as $nome => $caso ) {
			list( $prima, $atteso, $specifiche ) = $caso;

			$dopo = apply_filters( 'wp_headers', $prima, $GLOBALS['wp'] );

			$nomi = array_values(
				array_filter(
					array_keys( $dopo ),
					function ( $chiave ) {
						return 0 === strcasecmp( $chiave, 'X-Robots-Tag' );
					}
				)
			);

			$this->assertSame( array( 'X-Robots-Tag' ), $nomi, $nome . ': una riga generale sola.' );
			$this->assertSame( $atteso, $dopo['X-Robots-Tag'], $nome );
			$this->assertSame( $specifiche, Conformita_Core_Indicizzazione::specifiche_in_attesa(), $nome . ': direttive per un motore messe da parte.' );
		}

		$consentito = $this->contenuto( self::TIPO_APERTO );
		$this->go_to( get_permalink( $consentito ) );

		$this->assertSame(
			array( 'X-Robots-Tag' => 'googlebot: nofollow' ),
			apply_filters( 'wp_headers', array( 'X-Robots-Tag' => 'googlebot: nofollow' ), $GLOBALS['wp'] ),
			'Su un contenuto consentito l\'intestazione altrui resta com\'era.'
		);
		$this->assertSame( array(), Conformita_Core_Indicizzazione::specifiche_in_attesa() );
	}

	/**
	 * C-233: un allegato il cui contenuto padre non esiste piu' non prende la
	 * politica di nessun altro, nemmeno del contenuto globale.
	 */
	public function test_c233_padre_inesistente() {
		$vietato   = $this->contenuto( self::TIPO_CHIUSO );
		$scomparso = $this->contenuto( self::TIPO_APERTO );

		$orfano = self::factory()->attachment->create_object( 'orfano.pdf', $scomparso, array( 'post_mime_type' => 'application/pdf' ) );

		/*
		 * Cancellare il padre con le funzioni di WordPress stacca anche gli
		 * allegati. Il caso reale nasce fuori da quelle funzioni, da
		 * un'importazione o da una cancellazione diretta, e la prova lo
		 * riproduce allo stesso modo.
		 */
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: si riproduce un riferimento rotto scritto fuori dalle funzioni di WordPress.
		$wpdb->delete( $wpdb->posts, array( 'ID' => $scomparso ) );
		clean_post_cache( $scomparso );
		clean_post_cache( $orfano );

		$this->assertSame( $scomparso, (int) get_post( $orfano )->post_parent, 'Precondizione: il padre dichiarato resta nel dato.' );
		$this->assertNull( get_post( $scomparso ), 'Precondizione: il padre non esiste piu\'.' );

		$GLOBALS['post'] = get_post( $vietato );
		$this->assertFalse( Conformita_Core_Indicizzazione::contenuto_vietato( $orfano ), 'Con un contenuto vietato come globale.' );

		$GLOBALS['post'] = get_post( $orfano );
		$this->assertFalse( Conformita_Core_Indicizzazione::contenuto_vietato( $orfano ), 'Con l\'allegato stesso come globale.' );

		$this->assertFalse( Conformita_Core_Indicizzazione::contenuto_vietato( 0 ) );
		$this->assertFalse( Conformita_Core_Indicizzazione::contenuto_vietato( null ) );

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-234: se un altro componente toglie un aggancio solo, il meccanismo non
	 * risulta acceso, la registrazione di una sezione e' rifiutata, e la
	 * riaccensione rimette l'aggancio mancante.
	 */
	public function test_c234_aggancio_tolto_da_fuori() {
		foreach ( Conformita_Core_Indicizzazione::agganci() as $aggancio ) {
			Conformita_Core_Indicizzazione::avvia();
			$this->assertTrue( Conformita_Core_Indicizzazione::avviato() );

			remove_filter( $aggancio['aggancio'], array( 'Conformita_Core_Indicizzazione', $aggancio['metodo'] ), Conformita_Core_Indicizzazione::PRIORITA );

			$this->assertFalse( Conformita_Core_Indicizzazione::avviato(), 'Senza ' . $aggancio['aggancio'] . ' non e\' acceso.' );

			$esito = conformita_core_registra_sezione(
				'sezione_' . $aggancio['metodo'],
				array(
					'indicizzazione' => 'vietata',
					'scadenza'       => 'irraggiungibile',
				)
			);
			$this->assertWPError( $esito, 'Senza ' . $aggancio['aggancio'] );
			$this->assertSame( 'conformita_core_indicizzazione_non_avviata', $esito->get_error_code() );

			Conformita_Core_Indicizzazione::avvia();

			$this->assertSame(
				Conformita_Core_Indicizzazione::PRIORITA,
				has_filter( $aggancio['aggancio'], array( 'Conformita_Core_Indicizzazione', $aggancio['metodo'] ) ),
				'La riaccensione rimette ' . $aggancio['aggancio']
			);
		}
	}

	/**
	 * C-235: il meccanismo si accende caricando il file di core, senza che
	 * nessuno lo chieda, e la versione dell'interfaccia e' almeno quella che lo
	 * garantisce.
	 *
	 * Il file di core si ricarica per intero dentro la prova: le definizioni sono
	 * protette e le classi caricate una volta sola, quindi ricaricarlo rifa'
	 * soltanto le accensioni. Senza la chiamata nel file, il meccanismo resta
	 * spento e la prova e' rossa.
	 */
	public function test_c235_accensione_al_caricamento() {
		Conformita_Core_Indicizzazione::azzera_avvio();
		$this->assertFalse( Conformita_Core_Indicizzazione::avviato() );

		require CONFORMITA_CORE_PERCORSO . 'conformita-core.php';

		$this->assertTrue( Conformita_Core_Indicizzazione::avviato(), 'Caricare il file di core accende il meccanismo.' );

		$this->assertTrue( version_compare( CONFORMITA_CORE_VERSIONE_API, '1.4.0', '>=' ), 'La versione dell\'interfaccia che garantisce il divieto e\' la 1.4.0.' );
		$this->assertSame( CONFORMITA_CORE_VERSIONE_API, conformita_core_versione_api() );
	}

	/**
	 * C-236: il percorso vero della pagina. Il divieto sta nella testata
	 * stampata per intero, sta nell'incorporamento, e se un tema o un componente
	 * toglie il metatag dalla testata resta almeno l'intestazione.
	 */
	public function test_c236_percorso_della_pagina() {
		$vietato    = $this->contenuto( self::TIPO_CHIUSO );
		$consentito = $this->contenuto( self::TIPO_APERTO );

		$this->go_to( get_post_embed_url( $vietato ) );
		$this->assertTrue( is_embed(), 'Precondizione: e\' l\'incorporamento.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Incorporamento del vietato.' );

		$this->go_to( get_post_embed_url( $consentito ) );
		$this->assertTrue( is_embed() );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $this->intestazioni(), 'Incorporamento del consentito.' );

		remove_action( 'wp_head', 'wp_robots', 1 );

		$this->go_to( get_permalink( $vietato ) );
		$this->assertStringNotContainsString( "name='robots'", $this->sorgente(), 'Precondizione: la testata non stampa piu\' il metatag.' );
		$this->assertStringContainsString( 'noindex', $this->intestazioni()['X-Robots-Tag'] ?? '', 'Resta l\'intestazione.' );

		add_action( 'wp_head', 'wp_robots', 1 );
	}

	/**
	 * C-237: la mappa di un altro tipo, allargata da un componente ai tipi
	 * vietati, non elenca i contenuti vietati.
	 *
	 * La richiesta simulata e' quella di WordPress quando costruisce la mappa:
	 * la variabile `sitemap` e' valorizzata, e la lettura dei contenuti passa da
	 * un'interrogazione secondaria.
	 */
	public function test_c237_mappa_allargata_da_un_componente() {
		$vietato  = $this->contenuto( self::TIPO_CHIUSO );
		$articolo = $this->contenuto( 'post' );

		$allarga = function ( $interrogazione ) {
			if ( ! $interrogazione->is_main_query() && 'post' === $interrogazione->get( 'post_type' ) ) {
				$interrogazione->set( 'post_type', array( 'post', self::TIPO_CHIUSO ) );
			}
		};
		add_action( 'pre_get_posts', $allarga );

		set_query_var( 'sitemap', 'posts' );

		Conformita_Core_Indicizzazione::azzera_avvio();
		$spento = $this->indirizzi_in_mappa( 'post' );
		Conformita_Core_Indicizzazione::avvia();
		$acceso = $this->indirizzi_in_mappa( 'post' );

		set_query_var( 'sitemap', '' );
		$fuori = wp_list_pluck(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => -1,
					'suppress_filters' => false,
				)
			),
			'ID'
		);

		remove_action( 'pre_get_posts', $allarga );

		$this->assertContains( get_permalink( $vietato ), $spento, 'Precondizione: senza il meccanismo il componente porta il vietato nella mappa degli articoli.' );
		$this->assertNotContains( get_permalink( $vietato ), $acceso, 'Con il meccanismo il vietato non c\'e\'.' );
		$this->assertContains( get_permalink( $articolo ), $acceso, 'L\'articolo resta.' );
		$this->assertContains( $vietato, $fuori, 'Fuori dalla mappa le interrogazioni altrui non si toccano.' );
	}
}
