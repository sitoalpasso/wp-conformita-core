<?php
/**
 * Prove di non vacuita' del filtro di scadenza: righe C-106, C-107, C-108,
 * C-109 e C-110.
 *
 * **Che problema risolvono.** Ogni riga da C-10 a C-22 verifica che un contenuto
 * scaduto non compaia da qualche parte. Una verifica scritta al negativo e'
 * verde anche quando il contenuto non compare per un motivo che non c'entra
 * niente con il filtro: un indirizzo che non porta a nulla, un preparativo
 * sbagliato, una rotta che non esiste. Il conteggio dei test non lo rivela, e
 * nemmeno rileggere il codice: un test vuoto e un test giusto hanno lo stesso
 * colore.
 *
 * **Come lo risolvono.** Ogni prova qui dentro fa due volte la stessa cosa:
 * prima con il motore acceso, e il contenuto scaduto non c'e'; poi dopo aver
 * spento **un solo** aggancio, e il contenuto scaduto deve ricomparire. Se
 * ricompare, quell'aggancio e' cio' che lo teneva fuori. Se non ricompare, la
 * riga corrispondente stava misurando qualcos'altro.
 *
 * Gli agganci spenti non vanno rimessi a mano: la suite di prova ripristina gli
 * agganci fra una prova e l'altra, e in aggiunta il motore viene riacceso nella
 * chiusura di ciascuna.
 *
 * @package Conformita_Core
 */

/**
 * Prove che dimostrano che le prove del filtro non sono vuote.
 */
class Conformita_Core_Filtro_Scadenza_Non_Vacuita_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_non_vacua';
	const TIPO    = 'prova_non_vacua';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Una sezione con un tipo pubblico ed esposto all'interfaccia informatica.
	 *
	 * Indirizzi semplici e nessuna variabile d'interrogazione propria: cosi'
	 * l'indirizzo di un contenuto porta il suo identificativo e WordPress ci
	 * risale leggendolo, senza passare dalle regole di riscrittura. E' la
	 * correzione imparata sulla riga C-17: una prova che dipende da quelle regole
	 * fallisce per motivi che non riguardano il filtro.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::azzera_avvio();
		Conformita_Core_Filtro_Scadenza::avvia();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );
		$this->set_permalink_structure( '' );

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
					'show_in_rest' => true,
					'argomenti'    => array(
						'public'      => true,
						'has_archive' => true,
						'query_var'   => false,
					),
				)
			)
		);

		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Motore riacceso, fuso e orologio ripristinati.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		update_option( 'timezone_string', $this->fuso_originale );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::azzera_avvio();
		Conformita_Core_Filtro_Scadenza::avvia();

		parent::tear_down();
	}

	/**
	 * Spegne un aggancio del motore, e verifica che ci fosse davvero.
	 *
	 * Il controllo prima di spegnere non e' pignoleria: se il nome fosse
	 * sbagliato, lo spegnimento non farebbe niente, il contenuto resterebbe
	 * nascosto, e la prova di non vacuita' fallirebbe dicendo che il filtro non
	 * funziona. Cioe' proprio il tipo di diagnosi fuorviante che queste prove
	 * esistono per evitare.
	 *
	 * @param string $aggancio Nome dell'aggancio.
	 * @param string $metodo   Nome del metodo del motore.
	 */
	private function spegni( $aggancio, $metodo ) {
		$funzione = array( 'Conformita_Core_Filtro_Scadenza', $metodo );

		$this->assertNotFalse(
			has_filter( $aggancio, $funzione ),
			'Prima di spegnerlo, l\'aggancio ' . $aggancio . ' deve esserci.'
		);

		remove_filter( $aggancio, $funzione, 10 );

		$this->assertFalse(
			has_filter( $aggancio, $funzione ),
			'Dopo lo spegnimento l\'aggancio ' . $aggancio . ' non deve esserci piu\'.'
		);
	}

	/**
	 * Spegne i due strati generali del filtro.
	 */
	private function spegni_i_due_strati() {
		$this->spegni( 'pre_get_posts', 'filtra_interrogazione' );
		$this->spegni( 'the_posts', 'filtra_risultati' );
	}

	/**
	 * Fissa l'orologio a mezzogiorno di un giorno noto, fuso del sito.
	 *
	 * @param string $giorno Giorno nel formato AAAA-MM-GG.
	 */
	private function oggi_e( $giorno ) {
		Conformita_Core_Scadenza::fissa_orologio(
			new DateTimeImmutable( $giorno . ' 12:00:00', wp_timezone() )
		);
	}

	/**
	 * Un contenuto pubblicato con una data di fine.
	 *
	 * @param string $fine  Data di fine, formato AAAA-MM-GG.
	 * @param string $data  Data di pubblicazione, formato completo.
	 * @return int Identificativo del contenuto.
	 */
	private function contenuto( $fine, $data = '' ) {
		$argomenti = array(
			'post_type'   => self::TIPO,
			'post_status' => 'publish',
			'post_title'  => 'Atto di prova',
		);

		if ( '' !== $data ) {
			$argomenti['post_date'] = $data;
		}

		$post_id = self::factory()->post->create( $argomenti );

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $post_id, $fine ) );

		return $post_id;
	}

	/**
	 * Gli identificativi nell'archivio pubblico del tipo.
	 *
	 * @return array<int, int>
	 */
	private function archivio() {
		$this->go_to( get_post_type_archive_link( self::TIPO ) );

		return wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
	}

	/**
	 * Gli indirizzi nella mappa per i motori di ricerca.
	 *
	 * @return array<int, string>
	 */
	private function mappa() {
		$fornitore = new WP_Sitemaps_Posts();

		return wp_list_pluck( $fornitore->get_url_list( 1, self::TIPO ), 'loc' );
	}

	/**
	 * Esegue una richiesta sull'interfaccia informatica.
	 *
	 * @param string $percorso  Percorso della rotta, senza parametri.
	 * @param array  $parametri Parametri della richiesta.
	 * @return WP_REST_Response
	 */
	private function chiedi( $percorso, array $parametri = array() ) {
		$richiesta = new WP_REST_Request( 'GET', $percorso );

		foreach ( $parametri as $nome => $valore ) {
			$richiesta->set_param( $nome, $valore );
		}

		return rest_get_server()->dispatch( $richiesta );
	}

	/**
	 * C-106: spenti i due strati, lo scaduto ricompare negli elenchi.
	 */
	public function test_c106_famiglia_interrogazioni() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );

		$this->assertNotContains( $scaduto, $this->archivio(), 'A motore acceso lo scaduto non c\'e\'.' );

		$this->spegni_i_due_strati();

		$this->assertContains(
			$scaduto,
			$this->archivio(),
			'A filtro spento lo scaduto deve ricomparire. Se non ricompare, la riga C-11 non stava misurando il filtro.'
		);
	}

	/**
	 * C-107: spenti gli agganci delle interfacce informatiche, lo scaduto torna.
	 *
	 * Tre agganci diversi, spenti uno per volta, perche' sono tre percorsi
	 * distinti e una prova sola non direbbe quale dei tre stava reggendo.
	 */
	public function test_c107_famiglia_interfacce_informatiche() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );

		$this->assertSame( 404, $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto )->get_status() );
		$this->spegni( 'rest_request_before_callbacks', 'filtra_richiesta_rest' );
		$this->assertSame(
			200,
			$this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto )->get_status(),
			'A filtro spento il singolo identificativo deve tornare a rispondere. Se resta 404, la riga C-16 non stava misurando il filtro.'
		);

		$this->assertSame( 404, $this->chiedi( '/oembed/1.0/embed', array( 'url' => get_permalink( $scaduto ) ) )->get_status() );
		$this->spegni( 'oembed_response_data', 'filtra_anteprima_incorporata' );
		$this->assertSame(
			200,
			$this->chiedi( '/oembed/1.0/embed', array( 'url' => get_permalink( $scaduto ) ) )->get_status(),
			'A filtro spento l\'anteprima incorporata deve tornare a essere servita. Se resta 404, la riga C-17 sta misurando un indirizzo che non porta a niente.'
		);

		$preparato = apply_filters( 'xmlrpc_prepare_post', array( 'post_id' => $scaduto ), get_post( $scaduto, ARRAY_A ), array() );
		$this->assertSame( array(), $preparato );

		$this->spegni( 'xmlrpc_prepare_post', 'filtra_dato_xmlrpc' );

		$preparato = apply_filters( 'xmlrpc_prepare_post', array( 'post_id' => $scaduto ), get_post( $scaduto, ARRAY_A ), array() );
		$this->assertSame(
			array( 'post_id' => $scaduto ),
			$preparato,
			'A filtro spento la pubblicazione remota deve tornare a restituire i dati. Se restano vuoti, li sta svuotando qualcun altro.'
		);
	}

	/**
	 * C-108: la mappa per i motori regge sui due strati, non sul suo aggancio.
	 *
	 * E' la prova che vale di piu' di tutte, perche' l'unica che scopre qualcosa
	 * invece di confermarlo. L'aggancio dedicato alla mappa oggi **non regge
	 * niente**: la mappa passa comunque da un'interrogazione, quindi la coprono i
	 * due strati generali. L'aggancio resta come rete di sicurezza, per il caso in
	 * cui una versione futura di WordPress costruisse la mappa senza
	 * interrogazione, ma va saputo che oggi non e' lui a tenere fuori lo scaduto:
	 * un aggancio che nessuna prova esercita puo' rompersi senza che nessuno se
	 * ne accorga.
	 */
	public function test_c108_famiglia_mappa_per_i_motori() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );
		$valido  = $this->contenuto( '2026-09-30' );

		$this->assertContains( get_permalink( $valido ), $this->mappa(), 'Il valido deve essere nella mappa, altrimenti la prova e\' vuota da subito.' );
		$this->assertNotContains( get_permalink( $scaduto ), $this->mappa() );

		$this->spegni( 'wp_sitemaps_posts_query_args', 'filtra_argomenti_mappa' );

		$this->assertNotContains(
			get_permalink( $scaduto ),
			$this->mappa(),
			'Spento il solo aggancio della mappa non cambia niente: oggi la mappa passa da un\'interrogazione, e a tenere fuori lo scaduto sono i due strati.'
		);

		$this->spegni_i_due_strati();

		$this->assertContains(
			get_permalink( $scaduto ),
			$this->mappa(),
			'Spenti anche i due strati lo scaduto deve ricomparire nella mappa. Se non ricompare, la riga C-14 non stava misurando niente.'
		);
	}

	/**
	 * C-109: spenti gli agganci sul vicino, lo scaduto torna a essere il vicino.
	 */
	public function test_c109_famiglia_navigazione_adiacente() {
		$this->oggi_e( '2026-09-09' );

		$vecchio = $this->contenuto( '2026-09-30', '2026-09-01 10:00:00' );
		$mezzo   = $this->contenuto( '2026-09-08', '2026-09-02 10:00:00' );
		$nuovo   = $this->contenuto( '2026-09-30', '2026-09-03 10:00:00' );

		$GLOBALS['post'] = get_post( $nuovo );

		$acceso = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $acceso, 'Un vicino deve esserci anche a motore acceso.' );
		$this->assertSame( $vecchio, $acceso->ID, 'A motore acceso il vicino salta lo scaduto.' );

		$this->spegni( 'get_previous_post_where', 'filtra_vicino' );

		$spento = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $spento );
		$this->assertSame(
			$mezzo,
			$spento->ID,
			'A filtro spento lo scaduto deve tornare a essere il vicino. Se non torna, la riga C-20 non stava misurando il filtro.'
		);

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-110: spento il secondo strato, la pagina dell'allegato torna a rispondere.
	 */
	public function test_c110_famiglia_pagina_dell_allegato() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );

		$allegato = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $scaduto,
				'post_title'     => 'Allegato di prova',
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->go_to( '/?attachment_id=' . $allegato );
		$this->assertTrue( is_404(), 'A motore acceso la pagina dell\'allegato non risponde.' );

		$this->spegni( 'the_posts', 'filtra_risultati' );

		$this->go_to( '/?attachment_id=' . $allegato );

		$this->assertFalse(
			is_404(),
			'A filtro spento la pagina dell\'allegato deve tornare a rispondere. Se resta non trovata, la riga C-19 sta misurando un indirizzo che non porta a niente.'
		);
		$this->assertSame( $allegato, get_queried_object_id() );
	}
}
