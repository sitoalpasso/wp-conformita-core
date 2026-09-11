<?php
/**
 * Il filtro di scadenza sui percorsi di lettura: righe C-10, C-11, C-12, C-13,
 * C-14, C-21, C-22, C-89, C-90, C-92, C-93, C-94, C-97, C-98, C-101, C-102,
 * C-103, C-104, C-19, C-20, C-111, C-113 e C-114.
 *
 * Il file collauda **dove** la scadenza viene applicata. Quando un contenuto sia
 * scaduto lo stabilisce il contratto del dato, collaudato in `scadenza-test.php`.
 *
 * La riga che conta di piu' e' C-22, e viene prima di tutte le altre: se un
 * contenuto non scaduto sparisse, il filtro cancellerebbe dati validi, e tutte
 * le prove che verificano una sparizione resterebbero verdi lo stesso. Un filtro
 * che nasconde tutto passa ogni prova scritta al negativo. Per questo ogni prova
 * di sparizione qui dentro verifica anche che il contenuto sano sia ancora al
 * suo posto: e' la prova di non vacuita' fatta a mano, in attesa di quelle
 * sistematiche per famiglia di aggancio.
 *
 * La riga piu' pericolosa e' C-90. La condizione sui metadati si aggiunge
 * soltanto quando l'interrogazione riguarda esclusivamente tipi gestiti:
 * aggiungerla a un'interrogazione mista cancellerebbe dal sito tutti gli
 * articoli, che quel metadato non ce l'hanno.
 *
 * @package Conformita_Core
 */

/**
 * Prove sull'applicazione della scadenza nei percorsi di lettura.
 */
class Conformita_Core_Filtro_Scadenza_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_filtro';
	const TIPO    = 'prova_filtro';

	const SEZIONE_DUE = 'sezione_filtro_due';
	const TIPO_DUE    = 'prova_filtro_due';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Due sezioni con due tipi pubblici, e i permalink attivi.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );

		$this->set_permalink_structure( '/%postname%/' );

		$this->registra_sezione_con_tipo( self::SEZIONE, self::TIPO );
		$this->registra_sezione_con_tipo( self::SEZIONE_DUE, self::TIPO_DUE );

		flush_rewrite_rules();
	}

	/**
	 * Fuso, orologio e permalink ripristinati.
	 */
	public function tear_down() {
		update_option( 'timezone_string', $this->fuso_originale );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Registra una sezione e un tipo pubblico con archivio.
	 *
	 * @param string $sezione Identificativo della sezione.
	 * @param string $tipo    Identificativo del tipo.
	 */
	private function registra_sezione_con_tipo( $sezione, $tipo ) {
		$this->assertTrue(
			conformita_core_registra_sezione(
				$sezione,
				array(
					'indicizzazione' => 'vietata',
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
	 * @param string $fine   Data di fine, formato AAAA-MM-GG.
	 * @param string $tipo   Tipo di contenuto.
	 * @param string $titolo Titolo del contenuto.
	 * @return int Identificativo del contenuto.
	 */
	private function contenuto( $fine, $tipo = self::TIPO, $titolo = 'Atto di prova' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => $tipo,
				'post_status'  => 'publish',
				'post_title'   => $titolo,
				'post_content' => 'Testo di prova con la parola cercabile.',
			)
		);

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $post_id, $fine ) );

		return $post_id;
	}

	/**
	 * Un amministratore con tutte le capability del tipo gestito assegnate.
	 *
	 * L'assegnazione e' esplicita perche' core registra le capability e non le
	 * assegna a nessun ruolo: e' il componente a farlo, e finche' non lo fa
	 * nemmeno un amministratore puo' gestire il tipo. Qui serve il caso piu'
	 * forte disponibile, per poter dire che se nemmeno lui vede il contenuto
	 * scaduto sul sito pubblico non lo vede nessuno.
	 *
	 * @return int Identificativo dell'utente.
	 */
	private function utente_autorizzato_sul_tipo() {
		$utente_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$utente    = get_user_by( 'id', $utente_id );

		foreach ( array_unique( array_values( conformita_core_capacita_tipo( self::TIPO ) ) ) as $capacita ) {
			$utente->add_cap( $capacita );
		}

		return $utente_id;
	}

	/**
	 * Sposta la data di pubblicazione di un contenuto.
	 *
	 * @param int    $post_id Identificativo del contenuto.
	 * @param string $data    Data completa.
	 */
	private function datato( $post_id, $data ) {
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_date' => $data,
			)
		);
	}

	/**
	 * Un contenuto con un valore di fine scritto direttamente, senza validazione.
	 *
	 * E' il modo in cui un valore corrotto puo' davvero esistere: l'API valida in
	 * scrittura, quindi un valore malformato arriva solo per migrazione o per
	 * scrittura diretta nella banca dati.
	 *
	 * @param string $valore Valore grezzo da scrivere.
	 * @param string $data   Data di pubblicazione.
	 * @return int Identificativo del contenuto.
	 */
	private function contenuto_con_valore_grezzo( $valore, $data ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
				'post_date'   => $data,
			)
		);

		update_post_meta( $post_id, conformita_core_chiave_fine_pubblicazione(), $valore );

		return $post_id;
	}

	/**
	 * Un allegato appeso a un contenuto.
	 *
	 * @param int $padre Identificativo del contenuto padre.
	 * @return int Identificativo dell'allegato.
	 */
	private function allegato_di( $padre ) {
		return self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $padre,
				'post_title'     => 'Allegato di prova',
				'post_mime_type' => 'application/pdf',
			)
		);
	}

	/**
	 * Gli identificativi restituiti da un'interrogazione.
	 *
	 * @param WP_Query $interrogazione Interrogazione gia' eseguita.
	 * @return array<int, int>
	 */
	private function identificativi( WP_Query $interrogazione ) {
		return wp_list_pluck( $interrogazione->posts, 'ID' );
	}

	/**
	 * C-22 e C-11: nell'archivio del tipo il contenuto scaduto non compare e
	 * quello valido resta.
	 */
	public function test_c11_c22_archivio_del_tipo() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-09' );
		$scaduto = $this->contenuto( '2026-09-08' );
		$assente = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_post_type_archive_link( self::TIPO ) );

		$trovati = $this->identificativi( $GLOBALS['wp_query'] );

		$this->assertContains( $valido, $trovati, 'Il contenuto non scaduto deve restare visibile: senza questa asserzione un filtro che nasconde tutto passerebbe la prova.' );
		$this->assertNotContains( $scaduto, $trovati, 'Il contenuto scaduto non deve comparire nell\'archivio.' );
		$this->assertNotContains( $assente, $trovati, 'Il contenuto senza data di fine si comporta come scaduto: si sbaglia nella direzione sicura.' );
	}

	/**
	 * C-10: l'indirizzo proprio di un contenuto scaduto non risponde.
	 *
	 * La politica della sezione di prova e' `irraggiungibile`, quindi la
	 * risposta e' "non trovato" e non "vietato": chi non deve vedere non deve
	 * nemmeno sapere che c'e' qualcosa.
	 */
	public function test_c10_scheda_singola() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-09', self::TIPO, 'Atto ancora valido' );
		$scaduto = $this->contenuto( '2026-09-01', self::TIPO, 'Atto gia scaduto' );

		$this->go_to( get_permalink( $valido ) );
		$this->assertFalse( is_404(), 'Il contenuto non scaduto deve rispondere al proprio indirizzo.' );
		$this->assertTrue( is_singular( self::TIPO ) );
		$this->assertSame( $valido, get_queried_object_id() );

		$this->go_to( get_permalink( $scaduto ) );
		$this->assertTrue( is_404(), 'L\'indirizzo di un contenuto scaduto non deve rispondere.' );
	}

	/**
	 * C-12: la ricerca interna non restituisce contenuti scaduti.
	 *
	 * Due forme: ricerca ristretta al tipo gestito, dove agisce anche il primo
	 * strato, e ricerca su tutto il sito, dove il primo strato si tira indietro
	 * e resta il secondo.
	 */
	public function test_c12_ricerca_interna() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-01' );

		$this->go_to( '/?s=cercabile&post_type=' . self::TIPO );

		$trovati = $this->identificativi( $GLOBALS['wp_query'] );
		$this->assertContains( $valido, $trovati );
		$this->assertNotContains( $scaduto, $trovati );

		$mista = new WP_Query(
			array(
				's'         => 'cercabile',
				'post_type' => array( 'post', self::TIPO ),
			)
		);

		$trovati = $this->identificativi( $mista );
		$this->assertContains( $valido, $trovati, 'Nella ricerca mista il contenuto valido resta.' );
		$this->assertNotContains( $scaduto, $trovati, 'Nella ricerca mista lavora il secondo strato, e lo scaduto esce comunque.' );
	}

	/**
	 * C-13: il feed non contiene contenuti scaduti.
	 */
	public function test_c13_feed() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO );

		$this->assertTrue( is_feed(), 'La richiesta deve essere riconosciuta come feed.' );

		$trovati = $this->identificativi( $GLOBALS['wp_query'] );
		$this->assertContains( $valido, $trovati );
		$this->assertNotContains( $scaduto, $trovati );
	}

	/**
	 * C-14: la mappa per i motori di ricerca non elenca contenuti scaduti.
	 */
	public function test_c14_mappa_per_i_motori() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$fornitore = new WP_Sitemaps_Posts();
		$elenco    = $fornitore->get_url_list( 1, self::TIPO );

		$indirizzi = wp_list_pluck( $elenco, 'loc' );

		$this->assertContains( get_permalink( $valido ), $indirizzi, 'Il contenuto valido resta nella mappa.' );
		$this->assertNotContains( get_permalink( $scaduto ), $indirizzi, 'Il contenuto scaduto non deve comparire nella mappa.' );
	}

	/**
	 * C-21: `WP_Query` di terzi sul tipo registrato, con i filtri attivi.
	 */
	public function test_c21_query_di_terzi() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-09' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$interrogazione = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
			)
		);

		$trovati = $this->identificativi( $interrogazione );

		$this->assertContains( $valido, $trovati );
		$this->assertNotContains( $scaduto, $trovati );
	}

	/**
	 * C-103: una `meta_query` di terzi dichiarata in OR non aggira il filtro.
	 *
	 * E' il motivo per cui la clausola di core annida la dichiarazione esistente
	 * come sottogruppo invece di accodarsi: accodata a un elenco in OR
	 * diventerebbe un'alternativa alle altre condizioni, e il contenuto scaduto
	 * tornerebbe visibile a chiunque usi una `meta_query` in OR.
	 */
	public function test_c103_meta_query_di_terzi_in_or() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		update_post_meta( $valido, 'etichetta_di_prova', 'si' );
		update_post_meta( $scaduto, 'etichetta_di_prova', 'si' );

		$interrogazione = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- E' proprio la meta_query di terzi che la riga C-103 collauda.
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'etichetta_di_prova',
						'value'   => 'si',
						'compare' => '=',
					),
					array(
						'key'     => 'etichetta_di_prova',
						'value'   => 'no',
						'compare' => '=',
					),
				),
			)
		);

		$trovati = $this->identificativi( $interrogazione );

		$this->assertContains( $valido, $trovati, 'La condizione di terzi deve continuare a funzionare.' );
		$this->assertNotContains( $scaduto, $trovati, 'La clausola sulla scadenza resta in AND: non e\' un\'alternativa.' );
	}

	/**
	 * C-89: bozze e contenuti privati non li tocca il filtro della scadenza.
	 *
	 * Non hanno una data di fine, e trattarli come scaduti li farebbe sparire
	 * dagli strumenti di chi li sta scrivendo. La loro visibilita' la governa
	 * WordPress con gli stati, e i due meccanismi non devono interferire.
	 */
	public function test_c89_bozza_e_contenuto_privato() {
		$this->oggi_e( '2026-09-09' );

		$bozza = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'draft',
			)
		);

		$privato = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'private',
			)
		);

		wp_set_current_user( $this->utente_autorizzato_sul_tipo() );

		$interrogazione = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'post_status'    => array( 'draft', 'private' ),
				'posts_per_page' => -1,
			)
		);

		$trovati = $this->identificativi( $interrogazione );

		$this->assertContains( $bozza, $trovati, 'La bozza non e\' un contenuto scaduto.' );
		$this->assertContains( $privato, $trovati, 'Il contenuto privato non e\' un contenuto scaduto.' );

		$pubblica = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
			)
		);

		$this->assertNotContains( $bozza, $this->identificativi( $pubblica ), 'E il filtro non rende pubblica una bozza.' );
	}

	/**
	 * C-90: le interrogazioni che comprendono tipi non gestiti restano intatte.
	 *
	 * E' la riga piu' pericolosa del blocco. La condizione sui metadati si
	 * aggiunge solo alle interrogazioni che riguardano esclusivamente tipi
	 * gestiti: su un'interrogazione mista cancellerebbe tutti gli articoli del
	 * sito, che quel metadato non ce l'hanno.
	 */
	public function test_c90_tipi_non_gestiti_intatti() {
		$this->oggi_e( '2026-09-09' );

		$articolo = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$pagina   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$scaduto  = $this->contenuto( '2026-09-08' );

		$soli_articoli = new WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => -1,
			)
		);

		$this->assertContains( $articolo, $this->identificativi( $soli_articoli ), 'Un articolo senza il metadato deve restare visibile.' );

		$mista = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page', self::TIPO ),
				'posts_per_page' => -1,
			)
		);

		$trovati = $this->identificativi( $mista );

		$this->assertContains( $articolo, $trovati, 'Nell\'interrogazione mista gli articoli restano.' );
		$this->assertContains( $pagina, $trovati, 'Nell\'interrogazione mista le pagine restano.' );
		$this->assertNotContains( $scaduto, $trovati, 'E il contenuto gestito scaduto esce comunque, per opera del secondo strato.' );

		$qualsiasi = new WP_Query(
			array(
				'post_type'      => 'any',
				'posts_per_page' => -1,
			)
		);

		$trovati = $this->identificativi( $qualsiasi );

		$this->assertContains( $articolo, $trovati, 'Con post_type "any" gli articoli restano.' );
		$this->assertNotContains( $scaduto, $trovati, 'Con post_type "any" lo scaduto esce comunque.' );
	}

	/**
	 * C-92: la capability non esenta dal filtro sulle superfici pubbliche.
	 *
	 * L'esenzione e' legata alla superficie, non all'utente. Qui l'utente e'
	 * amministratore, cioe' il caso piu' forte disponibile finche' la capability
	 * dedicata all'archivio non esiste: se nemmeno lui vede il contenuto scaduto
	 * sul sito pubblico, non lo vede nessuno.
	 */
	public function test_c92_capability_non_esenta_le_superfici_pubbliche() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );
		$valido  = $this->contenuto( '2026-09-30' );

		wp_set_current_user( $this->utente_autorizzato_sul_tipo() );

		$this->go_to( get_post_type_archive_link( self::TIPO ) );
		$this->assertNotContains( $scaduto, $this->identificativi( $GLOBALS['wp_query'] ), 'Elenco: niente ricompare per un amministratore.' );

		$this->go_to( '/?s=cercabile&post_type=' . self::TIPO );
		$this->assertNotContains( $scaduto, $this->identificativi( $GLOBALS['wp_query'] ), 'Ricerca: niente ricompare per un amministratore.' );

		$this->go_to( '/?feed=rss2&post_type=' . self::TIPO );
		$this->assertNotContains( $scaduto, $this->identificativi( $GLOBALS['wp_query'] ), 'Feed: niente ricompare per un amministratore.' );

		$fornitore = new WP_Sitemaps_Posts();
		$indirizzi = wp_list_pluck( $fornitore->get_url_list( 1, self::TIPO ), 'loc' );
		$this->assertNotContains( get_permalink( $scaduto ), $indirizzi, 'Mappa: niente ricompare per un amministratore.' );
		$this->assertContains( get_permalink( $valido ), $indirizzi, 'E il contenuto valido e\' ancora li\': la prova non e\' vuota.' );
	}

	/**
	 * C-101: sulla superficie di amministrazione il contenuto scaduto resta
	 * visibile.
	 *
	 * Farlo sparire anche di la' lo renderebbe irreparabile: un contenuto con la
	 * data rotta non lo troverebbe piu' nessuno per correggerla.
	 */
	public function test_c101_amministrazione() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );

		set_current_screen( 'edit-post' );

		$interrogazione = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
			)
		);

		$trovati = $this->identificativi( $interrogazione );

		set_current_screen( 'front' );

		$this->assertContains( $scaduto, $trovati, 'In amministrazione il contenuto scaduto resta raggiungibile per essere corretto.' );
	}

	/**
	 * C-93: `suppress_filters` documenta un limite, non prova una conformita'.
	 *
	 * Il primo strato agisce lo stesso, perche' `pre_get_posts` viene emesso
	 * comunque; il secondo no, perche' `the_posts` non viene applicato. La prova
	 * fissa quale meta' del meccanismo resta attiva, cosi' che se un giorno
	 * cambiasse ce ne accorgeremmo qui e non sul sito di qualcun altro.
	 */
	public function test_c93_suppress_filters() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$interrogazione = new WP_Query(
			array(
				'post_type'        => self::TIPO,
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);

		$trovati = $this->identificativi( $interrogazione );

		$this->assertContains( $valido, $trovati );
		$this->assertNotContains( $scaduto, $trovati, 'Il primo strato agisce anche con i filtri soppressi.' );

		$corrotto = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $corrotto, conformita_core_chiave_fine_pubblicazione(), '9999-99-99' );

		$soppressa = new WP_Query(
			array(
				'post_type'        => self::TIPO,
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);

		$this->assertContains(
			$corrotto,
			$this->identificativi( $soppressa ),
			'Limite dichiarato: con i filtri soppressi manca il secondo strato, e un valore corrotto che ordina alto passa il confronto sulla banca dati.'
		);

		$normale = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
			)
		);

		$this->assertNotContains(
			$corrotto,
			$this->identificativi( $normale ),
			'Con i filtri normali attivi il secondo strato ferma il valore corrotto: e\' la ragione per cui i due strati sono due.'
		);
	}

	/**
	 * C-102: con `fields => 'ids'` agisce il solo primo strato.
	 *
	 * WordPress restituisce le colonne e torna subito, senza applicare
	 * `the_posts`: e' lo stesso limite di C-93 su un percorso diverso. La prova
	 * lo fissa perche' resti visibile, non perche' vada bene.
	 */
	public function test_c102_fields_ids() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$corrotto = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $corrotto, conformita_core_chiave_fine_pubblicazione(), '9999-99-99' );

		$interrogazione = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$trovati = $interrogazione->posts;

		$this->assertContains( $valido, $trovati, 'Il contenuto valido resta: la prova non e\' vuota.' );
		$this->assertNotContains( $scaduto, $trovati, 'Il primo strato tiene fuori lo scaduto anche qui.' );
		$this->assertContains(
			$corrotto,
			$trovati,
			'Limite dichiarato: senza `the_posts` manca il secondo strato, e un valore corrotto che ordina alto supera il confronto sulla banca dati.'
		);
	}

	/**
	 * Lo spegnimento del motore spegne **tutti** gli agganci.
	 *
	 * Nella prima stesura l'accensione ne registrava otto e lo spegnimento ne
	 * toglieva tre: `azzera_avvio()` dichiarava il motore spento mentre cinque
	 * agganci continuavano a girare. Il difetto non poteva produrre nessun
	 * errore, perche' uno spegnimento incompleto non fallisce: fa semplicemente
	 * una cosa diversa da quella che dice. Adesso accensione e spegnimento
	 * leggono lo stesso elenco, e questa prova lo verifica dall'esterno invece di
	 * fidarsi.
	 */
	public function test_spegnimento_completo_del_motore() {
		$this->assertTrue( Conformita_Core_Filtro_Scadenza::avviato() );

		foreach ( Conformita_Core_Filtro_Scadenza::agganci() as $aggancio ) {
			$this->assertNotFalse(
				has_filter( $aggancio['aggancio'], array( 'Conformita_Core_Filtro_Scadenza', $aggancio['metodo'] ) ),
				'A motore acceso l\'aggancio ' . $aggancio['aggancio'] . ' deve esserci.'
			);
		}

		Conformita_Core_Filtro_Scadenza::azzera_avvio();

		$this->assertFalse( Conformita_Core_Filtro_Scadenza::avviato() );

		foreach ( Conformita_Core_Filtro_Scadenza::agganci() as $aggancio ) {
			$this->assertFalse(
				has_filter( $aggancio['aggancio'], array( 'Conformita_Core_Filtro_Scadenza', $aggancio['metodo'] ) ),
				'A motore spento l\'aggancio ' . $aggancio['aggancio'] . ' non deve esserci piu\'. Un motore che si dichiara spento mentre meta\' dei suoi agganci gira e\' peggio di uno che non si spegne.'
			);
		}

		Conformita_Core_Filtro_Scadenza::avvia();
	}

	/**
	 * C-111: l'aggancio dedicato alla mappa trasforma gli argomenti come deve.
	 *
	 * Serve perche' la prova di non vacuita' C-108 dimostra che quell'aggancio
	 * oggi non regge nessun comportamento: la mappa passa comunque da
	 * un'interrogazione. Resta come rete di sicurezza, e una rete che nessuno
	 * prova puo' rompersi restando sempre verde.
	 */
	public function test_c111_aggancio_della_mappa() {
		$this->oggi_e( '2026-09-09' );

		$gestiti = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => self::TIPO ), self::TIPO );

		$this->assertArrayHasKey( 'meta_query', $gestiti, 'Per un tipo gestito la condizione sulla scadenza va aggiunta.' );

		$clausola = end( $gestiti['meta_query'] );

		$this->assertSame( conformita_core_chiave_fine_pubblicazione(), $clausola['key'] );
		$this->assertSame( '2026-09-09', $clausola['value'], 'Il confronto usa il giorno corrente letto dallo stesso orologio del resto del motore.' );
		$this->assertSame( '>=', $clausola['compare'] );

		$altri = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'post' ), 'post' );

		$this->assertArrayNotHasKey( 'meta_query', $altri, 'Su un tipo non gestito non si tocca niente: la condizione cancellerebbe tutti gli articoli dalla mappa.' );
	}

	/**
	 * C-113: un contenuto con data corrotta non e' mai il vicino.
	 *
	 * La riga C-88 dice che una data corrotta rende il contenuto scaduto. Su
	 * questo percorso non c'e' un secondo strato che ricontrolli il risultato,
	 * quindi il confronto sulla banca dati deve bastare da solo: un valore che
	 * ordina alto come `9999-99-99` supererebbe un confronto fra stringhe e
	 * contraddirebbe il contratto.
	 */
	public function test_c113_vicino_con_data_corrotta() {
		$this->oggi_e( '2026-09-09' );

		$buono = $this->contenuto( '2026-09-30', self::TIPO, 'Vicino buono' );
		$this->datato( $buono, '2026-09-01 10:00:00' );

		$corrotti = array( '9999-99-99', '2026-02-31', '2026-13-01', '2026-00-10', '2027-02-29', '2026-04-31', 'non una data', '' );
		$giorno   = 2;

		foreach ( $corrotti as $valore ) {
			$this->contenuto_con_valore_grezzo( $valore, sprintf( '2026-09-%02d 10:00:00', $giorno ) );
			++$giorno;
		}

		$partenza = $this->contenuto( '2026-09-30', self::TIPO, 'Punto di partenza' );
		$this->datato( $partenza, '2026-09-20 10:00:00' );

		$GLOBALS['post'] = get_post( $partenza );

		$precedente = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $precedente, 'Un vicino deve esserci: senza questa asserzione la prova sarebbe verde anche cancellando tutti i vicini.' );
		$this->assertSame(
			$buono,
			$precedente->ID,
			'Il vicino salta tutti i valori corrotti e arriva a quello valido. Se si ferma prima, la condizione sta accettando date che non esistono.'
		);

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-113: una data valida e lontana resta accettata.
	 *
	 * Controllo opposto del precedente, e non e' una formalita': una condizione
	 * troppo stretta escluderebbe date buone, e il 29 febbraio di un anno
	 * bisestile e' quella su cui si sbaglia.
	 */
	public function test_c113_data_valida_di_anno_bisestile() {
		$this->oggi_e( '2026-09-09' );

		$bisestile = $this->contenuto( '2028-02-29', self::TIPO, 'Scade in un anno bisestile' );
		$this->datato( $bisestile, '2026-09-05 10:00:00' );

		$partenza = $this->contenuto( '2026-09-30', self::TIPO, 'Punto di partenza' );
		$this->datato( $partenza, '2026-09-20 10:00:00' );

		$GLOBALS['post'] = get_post( $partenza );

		$precedente = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $precedente );
		$this->assertSame( $bisestile, $precedente->ID, 'Il 29 febbraio di un anno bisestile e\' una data che esiste, e va accettata.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-114: due date registrate sullo stesso contenuto sono un'anomalia.
	 *
	 * WordPress ammette piu' righe di metadato con la stessa chiave, e la lettura
	 * normale ne restituisce una sola. Con un valore corrotto e uno futuro valido
	 * la lettura e la condizione sulla banca dati potrebbero decidere in modo
	 * opposto, e su un percorso senza secondo strato vincerebbe la piu'
	 * permissiva. Un valore solo per chiave e' il contratto.
	 *
	 * L'ordine dei due valori e' scelto apposta: il corrotto e' scritto per
	 * primo, quindi la lettura normale prende quello, mentre la condizione sulla
	 * banca dati troverebbe il secondo valido. E' la combinazione che fa
	 * divergere le due strade.
	 */
	public function test_c114_due_date_sullo_stesso_contenuto() {
		$this->oggi_e( '2026-09-09' );

		$doppio = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		add_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), '9999-99-99' );
		add_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), '2026-12-31' );

		$this->assertCount( 2, get_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), false ), 'La prova parte da due righe vere: senza, non verifica niente.' );

		$fine = conformita_core_fine_pubblicazione( $doppio );

		$this->assertWPError( $fine );
		$this->assertSame( 'conformita_core_dato_duplicato', $fine->get_error_code() );
		$this->assertTrue( conformita_core_scaduto( $doppio ), 'Un\'anomalia rende scaduto: si sbaglia nella direzione sicura.' );

		$this->assertNotContains(
			$doppio,
			$this->identificativi(
				new WP_Query(
					array(
						'post_type'      => self::TIPO,
						'posts_per_page' => -1,
					)
				)
			),
			'Il contenuto con due date non compare negli elenchi.'
		);
	}

	/**
	 * C-114: un contenuto con due date non e' mai il vicino.
	 *
	 * E' il percorso che conta di piu', perche' e' l'unico senza un secondo
	 * strato che ricontrolli il risultato: qui la condizione sulla banca dati
	 * deve escludere i duplicati da sola.
	 */
	public function test_c114_vicino_con_due_date() {
		$this->oggi_e( '2026-09-09' );

		$buono = $this->contenuto( '2026-09-30', self::TIPO, 'Vicino buono' );
		$this->datato( $buono, '2026-09-01 10:00:00' );

		$doppio = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
				'post_date'   => '2026-09-05 10:00:00',
			)
		);

		add_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), '9999-99-99' );
		add_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), '2026-12-31' );

		$partenza = $this->contenuto( '2026-09-30', self::TIPO, 'Punto di partenza' );
		$this->datato( $partenza, '2026-09-20 10:00:00' );

		$GLOBALS['post'] = get_post( $partenza );

		$precedente = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $precedente, 'Un vicino deve esserci: senza questa asserzione la prova sarebbe verde cancellando tutti i vicini.' );
		$this->assertSame(
			$buono,
			$precedente->ID,
			'Il vicino salta il contenuto con due date e arriva a quello buono. Se si ferma sul doppio, la condizione accetta il contenuto perche\' almeno una delle due righe e\' valida.'
		);

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-114: la scrittura dall'API ripara il duplicato.
	 *
	 * E' la via d'uscita: senza, un contenuto con due date resterebbe invisibile
	 * per sempre e nessuno potrebbe rimetterlo a posto passando dall'API.
	 */
	public function test_c114_la_scrittura_ripara() {
		$this->oggi_e( '2026-09-09' );

		$doppio = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		add_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), '9999-99-99' );
		add_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), '2026-12-31' );

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $doppio, '2026-09-30' ) );

		$valori = get_post_meta( $doppio, conformita_core_chiave_fine_pubblicazione(), false );

		$this->assertCount( 1, $valori, 'Dopo la scrittura dall\'API resta una riga sola.' );
		$this->assertSame( '2026-09-30', reset( $valori ) );
		$this->assertSame( '2026-09-30', conformita_core_fine_pubblicazione( $doppio ) );
		$this->assertFalse( conformita_core_scaduto( $doppio ), 'Riparato, il contenuto torna visibile.' );
	}

	/**
	 * C-19: la pagina dell'allegato di un contenuto scaduto non risponde.
	 *
	 * La scadenza si legge sul contenuto padre: l'allegato non ha una data di
	 * fine propria, e dargliene una sarebbe un secondo dato da tenere allineato
	 * al primo, cioe' un secondo posto dove sbagliare.
	 *
	 * Questa prova riguarda la pagina che WordPress genera per l'allegato, non il
	 * file: servire il file da un indirizzo protetto e' l'unita' della consegna
	 * allegati, e finche' non c'e' il file resta scaricabile dal suo indirizzo
	 * diretto. E' un limite noto, non una dimenticanza.
	 */
	public function test_c19_pagina_dell_allegato() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$allegato_buono = $this->allegato_di( $valido );
		$allegato_morto = $this->allegato_di( $scaduto );

		$this->go_to( '/?attachment_id=' . $allegato_buono );

		$this->assertTrue( is_attachment(), 'La pagina dell\'allegato di un contenuto valido deve rispondere: senza questa asserzione un filtro che nega tutto passerebbe la prova.' );
		$this->assertFalse( is_404() );
		$this->assertSame( $allegato_buono, get_queried_object_id() );

		$this->go_to( '/?attachment_id=' . $allegato_morto );

		$this->assertTrue( is_404(), 'La pagina dell\'allegato di un contenuto scaduto non deve rispondere.' );
	}

	/**
	 * C-20: il contenuto scaduto non e' mai il vicino di un altro.
	 *
	 * I collegamenti al precedente e al successivo li cerca WordPress con
	 * un'interrogazione propria, che non passa ne' dal primo strato ne' dal
	 * secondo: senza un aggancio dedicato un atto scaduto resterebbe
	 * raggiungibile dai suoi vicini, che e' il modo meno vistoso di restare
	 * pubblicato.
	 */
	public function test_c20_navigazione_adiacente() {
		$this->oggi_e( '2026-09-09' );

		$vecchio = $this->contenuto( '2026-09-30', self::TIPO, 'Atto vecchio' );
		$mezzo   = $this->contenuto( '2026-09-08', self::TIPO, 'Atto di mezzo' );
		$nuovo   = $this->contenuto( '2026-09-30', self::TIPO, 'Atto nuovo' );

		wp_update_post(
			array(
				'ID'        => $vecchio,
				'post_date' => '2026-09-01 10:00:00',
			)
		);
		wp_update_post(
			array(
				'ID'        => $mezzo,
				'post_date' => '2026-09-02 10:00:00',
			)
		);
		wp_update_post(
			array(
				'ID'        => $nuovo,
				'post_date' => '2026-09-03 10:00:00',
			)
		);

		$GLOBALS['post'] = get_post( $nuovo );

		$precedente = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $precedente, 'Un vicino deve esserci: senza questa asserzione un filtro che cancella tutti i vicini passerebbe la prova.' );
		$this->assertSame( $vecchio, $precedente->ID, 'Il vicino all\'indietro salta lo scaduto e arriva a quello buono.' );

		$GLOBALS['post'] = get_post( $vecchio );

		$successivo = get_adjacent_post( false, '', false );

		$this->assertInstanceOf( WP_Post::class, $successivo );
		$this->assertSame( $nuovo, $successivo->ID, 'Il vicino in avanti salta lo scaduto.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-20: sui tipi non gestiti la navigazione adiacente resta intatta.
	 *
	 * La condizione sulla scadenza esclude chi non ha il metadato: aggiungerla
	 * agli articoli normali cancellerebbe tutti i loro vicini.
	 */
	public function test_c20_tipi_non_gestiti_intatti() {
		$this->oggi_e( '2026-09-09' );

		$primo   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-09-01 10:00:00',
			)
		);
		$secondo = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-09-02 10:00:00',
			)
		);

		$GLOBALS['post'] = get_post( $secondo );

		$precedente = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $precedente, 'Un articolo normale deve continuare ad avere i suoi vicini.' );
		$this->assertSame( $primo, $precedente->ID );

		unset( $GLOBALS['post'] );
	}

	/**
	 * C-94: a motore spento la registrazione di una sezione fallisce.
	 */
	public function test_c94_sezione_a_motore_spento() {
		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Filtro_Scadenza::azzera_avvio();

		$this->assertFalse( Conformita_Core_Filtro_Scadenza::avviato() );

		$esito = conformita_core_registra_sezione(
			'sezione_orfana',
			array(
				'indicizzazione' => 'vietata',
				'scadenza'       => 'irraggiungibile',
			)
		);

		Conformita_Core_Filtro_Scadenza::avvia();

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_motore_non_avviato', $esito->get_error_code() );
		$this->assertFalse( conformita_core_sezione_registrata( 'sezione_orfana' ), 'La sezione non deve risultare registrata.' );
	}

	/**
	 * C-97: il filtro di una sezione non tocca i contenuti dell'altra.
	 */
	public function test_c97_nessuna_contaminazione_fra_sezioni() {
		$this->oggi_e( '2026-09-09' );

		$scaduto_uno = $this->contenuto( '2026-09-08', self::TIPO );
		$valido_due  = $this->contenuto( '2026-09-30', self::TIPO_DUE );
		$scaduto_due = $this->contenuto( '2026-09-01', self::TIPO_DUE );

		$prima = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
			)
		);

		$seconda = new WP_Query(
			array(
				'post_type'      => self::TIPO_DUE,
				'posts_per_page' => -1,
			)
		);

		$this->assertNotContains( $scaduto_uno, $this->identificativi( $prima ) );
		$this->assertContains( $valido_due, $this->identificativi( $seconda ), 'Il contenuto valido della seconda sezione resta.' );
		$this->assertNotContains( $scaduto_due, $this->identificativi( $seconda ) );
	}

	/**
	 * Gli agganci del motore, contati per metodo e su tutte le priorita'.
	 *
	 * Il conteggio guarda la chiave con cui WordPress registra il singolo
	 * callback, non quanti callback ci sono sull'aggancio: cosi' non dipende da
	 * quello che aggancia il resto di WordPress, e soprattutto vede un doppione
	 * anche se fosse stato aggiunto a una priorita' diversa, che e' il caso in
	 * cui WordPress non deduplica.
	 *
	 * @return array<string, int> Quante volte ciascun aggancio risulta registrato.
	 */
	private function agganci_del_motore() {
		$attesi = array(
			'pre_get_posts'                 => 'filtra_interrogazione',
			'the_posts'                     => 'filtra_risultati',
			'wp_sitemaps_posts_query_args'  => 'filtra_argomenti_mappa',
			'rest_request_before_callbacks' => 'filtra_richiesta_rest',
			'oembed_response_data'          => 'filtra_anteprima_incorporata',
			'xmlrpc_prepare_post'           => 'filtra_dato_xmlrpc',
			'get_previous_post_where'       => 'filtra_vicino',
			'get_next_post_where'           => 'filtra_vicino',
		);

		$this->assertCount(
			count( $attesi ),
			Conformita_Core_Filtro_Scadenza::agganci(),
			'Il motore dichiara un numero di agganci diverso da quello che questa prova conosce: se ne e\' stato aggiunto uno, va aggiunto anche qui, altrimenti nessuna prova lo guarderebbe.'
		);

		$conteggio = array();

		foreach ( $attesi as $aggancio => $metodo ) {
			$chiave  = 'Conformita_Core_Filtro_Scadenza::' . $metodo;
			$quante  = 0;
			$elenchi = isset( $GLOBALS['wp_filter'][ $aggancio ] )
				? $GLOBALS['wp_filter'][ $aggancio ]->callbacks
				: array();

			foreach ( $elenchi as $per_priorita ) {
				if ( isset( $per_priorita[ $chiave ] ) ) {
					++$quante;
				}
			}

			$conteggio[ $aggancio ] = $quante;
		}

		return $conteggio;
	}

	/**
	 * C-98: dopo piu' avvii ciascun aggancio risulta registrato una volta sola.
	 *
	 * **Che cosa questa prova dimostra e che cosa no.** Dimostra lo stato
	 * osservabile degli agganci dopo avvii ripetuti. Non dimostra che la guardia
	 * dentro `avvia()` sia indispensabile: WordPress identifica ogni aggancio con
	 * una chiave univoca, e per un metodo statico quella chiave e' deterministica,
	 * quindi riagganciare lo stesso metodo alla stessa priorita' sostituisce la
	 * registrazione invece di aggiungerne una seconda. Togliendo la guardia,
	 * questa prova resterebbe verde. La prima stesura sosteneva il contrario, ed
	 * era falsa.
	 *
	 * Le ragioni vere della guardia sono altre e stanno nel codice: `$avviato` e'
	 * la condizione che la registrazione di una sezione legge per rifiutarsi a
	 * motore spento, e la deduplicazione di WordPress non varrebbe piu' se uno di
	 * questi agganci diventasse una chiusura.
	 *
	 * L'ultima parte della prova serve a mostrare che il conteggio sa vedere un
	 * doppione: senza, l'asserzione precedente sarebbe vera per costruzione.
	 */
	public function test_c98_agganci_registrati_una_volta_sola() {
		$this->assertTrue( Conformita_Core_Filtro_Scadenza::avviato() );

		$attesi = array(
			'pre_get_posts'                 => 1,
			'the_posts'                     => 1,
			'wp_sitemaps_posts_query_args'  => 1,
			'rest_request_before_callbacks' => 1,
			'oembed_response_data'          => 1,
			'xmlrpc_prepare_post'           => 1,
			'get_previous_post_where'       => 1,
			'get_next_post_where'           => 1,
		);

		$this->assertSame( $attesi, $this->agganci_del_motore(), 'Ogni aggancio del motore deve risultare registrato una volta sola.' );

		Conformita_Core_Filtro_Scadenza::avvia();
		Conformita_Core_Filtro_Scadenza::avvia();

		$this->assertTrue( Conformita_Core_Filtro_Scadenza::avviato() );
		$this->assertSame( $attesi, $this->agganci_del_motore(), 'Dopo altri due avvii lo stato degli agganci non cambia.' );

		add_filter( 'the_posts', array( 'Conformita_Core_Filtro_Scadenza', 'filtra_risultati' ), 11, 2 );

		$this->assertSame(
			2,
			$this->agganci_del_motore()['the_posts'],
			'Il conteggio sa vedere un doppione aggiunto a una priorita\' diversa: e\' la prova che l\'asserzione precedente non e\' vera per costruzione.'
		);

		remove_filter( 'the_posts', array( 'Conformita_Core_Filtro_Scadenza', 'filtra_risultati' ), 11 );

		$this->assertSame( $attesi, $this->agganci_del_motore(), 'Tolto il doppione si torna allo stato di partenza.' );
	}

	/**
	 * C-104: una clausola di terzi sulla stessa chiave non fa credere a core di
	 * aver gia' aggiunto la propria.
	 *
	 * Il percorso si collauda con `fields => 'ids'` di proposito: e' uno dei due
	 * casi in cui il secondo strato non gira, quindi se il primo strato si
	 * tirasse indietro il contenuto scaduto uscirebbe davvero. Con
	 * un'interrogazione normale il secondo strato mascherebbe il difetto e la
	 * prova sarebbe verde con il codice sbagliato.
	 */
	public function test_c104_clausola_di_terzi_sulla_stessa_chiave() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$interrogazione = new WP_Query(
			array(
				'post_type'      => self::TIPO,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- E' proprio la meta_query di terzi che la riga C-104 collauda.
				'meta_query'     => array(
					array(
						'key'     => Conformita_Core_Scadenza::chiave(),
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$trovati = $interrogazione->posts;

		$this->assertContains( $valido, $trovati, 'La condizione di terzi deve continuare a funzionare.' );
		$this->assertNotContains(
			$scaduto,
			$trovati,
			'La clausola di core si riconosce per intero e non dalla sola chiave: qui va aggiunta, altrimenti lo scaduto esce.'
		);
	}
}
