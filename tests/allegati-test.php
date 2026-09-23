<?php
/**
 * Cartella protetta, verifica della protezione, deposito e impronta.
 *
 * Righe di collaudo C-115..C-125, C-146, C-147, C-153, C-154..C-158, C-163..C-166,
 * C-169, C-170, C-172..C-175, C-177..C-184.
 *
 * **Come si simula il server.** La verifica della protezione e' una richiesta
 * HTTP verso il sito stesso. Qui non c'e' nessun server web, quindi la
 * richiesta si intercetta con `pre_http_request` e le si fa dire quello che
 * direbbe un server coperto, uno scoperto, o uno irraggiungibile. E' la stessa
 * sostituzione che la suite di WordPress usa per le richieste in uscita: quello
 * che resta vero e' la lettura della risposta, che e' la parte che decide.
 *
 * Il filtro conta anche **quante** richieste partono, perche' due righe
 * (C-157 e C-140) non verificano un esito ma un'assenza: un deposito normale e
 * una consegna non devono fare nessuna richiesta.
 *
 * @package Conformita_Core
 */

/**
 * Prove sulla cartella protetta, sul deposito e sull'impronta.
 */
class Conformita_Core_Allegati_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_allegati';
	const TIPO    = 'prova_allegato';

	/**
	 * Risposta che il server finto dara' alla prossima richiesta all'esca.
	 *
	 * @var mixed
	 */
	private $risposta_finta = null;

	/**
	 * Quante richieste HTTP sono partite da quando la prova e' cominciata.
	 *
	 * @var int
	 */
	private $richieste = 0;

	/**
	 * File temporanei creati dalla prova, da togliere alla fine.
	 *
	 * @var array<int, string>
	 */
	private $temporanei = array();

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Da quale decisione del nome in poi l'estensione sparisce.
	 *
	 * @var int
	 */
	private $togli_estensione_dalla = 1;

	/**
	 * Quante volte il nome e' stato deciso.
	 *
	 * @var int
	 */
	private $nomi_decisi = 0;

	/**
	 * Una sezione con un tipo gestito, e il server finto agganciato.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::azzera_avvio();
		Conformita_Core_Filtro_Scadenza::avvia();
		Conformita_Core_Consegna::azzera_avvio();
		Conformita_Core_Consegna::avvia();
		Conformita_Core_Allegati::azzera();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );

		$this->risposta_finta = $this->rifiuto_del_server();
		$this->richieste      = 0;

		add_filter( 'pre_http_request', array( $this, 'server_finto' ), 10, 3 );

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
	}

	/**
	 * Smonta tutto quello che la prova ha lasciato in giro.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'server_finto' ), 10 );

		foreach ( $this->temporanei as $percorso ) {
			if ( file_exists( $percorso ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
				unlink( $percorso );
			}
		}

		$this->svuota_cartella_protetta();
		Conformita_Core_Allegati::azzera();
		Conformita_Core_Consegna::azzera_avvio();
		Conformita_Core_Consegna::avvia();

		update_option( 'timezone_string', $this->fuso_originale );

		parent::tear_down();
	}

	/**
	 * Il server finto: risponde quello che la prova ha deciso, e si conta.
	 *
	 * @param mixed $esito      Esito gia' deciso da qualcun altro.
	 * @param mixed $argomenti  Argomenti della richiesta.
	 * @param mixed $indirizzo  Indirizzo richiesto.
	 * @return mixed
	 */
	public function server_finto( $esito, $argomenti = array(), $indirizzo = '' ) {
		unset( $esito, $argomenti );

		++$this->richieste;

		/*
		 * Quando la risposta finta e' una funzione, la decide l'indirizzo: serve
		 * alle righe in cui il server tratta due percorsi in modo diverso, che
		 * e' esattamente il caso che l'esca sola non vedeva.
		 */
		if ( $this->risposta_finta instanceof Closure ) {
			$funzione = $this->risposta_finta;

			return $funzione( (string) $indirizzo );
		}

		return $this->risposta_finta;
	}

	/**
	 * La risposta di un server che nega il percorso.
	 *
	 * @return array<string, mixed>
	 */
	private function rifiuto_del_server() {
		return array(
			'response' => array(
				'code'    => 403,
				'message' => 'Forbidden',
			),
			'body'     => '<html><body>Forbidden</body></html>',
		);
	}

	/**
	 * La risposta di un server che serve l'esca, gettone compreso.
	 *
	 * @return array<string, mixed>
	 */
	private function esca_servita() {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => Conformita_Core_Allegati::contenuto_esca(),
		);
	}

	/**
	 * La risposta di un server con lo stato indicato e il corpo indicato.
	 *
	 * @param int    $stato  Stato HTTP.
	 * @param string $corpo  Corpo della risposta.
	 * @return array<string, mixed>
	 */
	private function risposta_con_stato( $stato, $corpo = '' ) {
		return array(
			'response' => array(
				'code'    => $stato,
				'message' => '',
			),
			'body'     => $corpo,
		);
	}

	/**
	 * La risposta di un server che reindirizza il percorso altrove.
	 *
	 * @return array<string, mixed>
	 */
	private function reindirizzamento_del_server() {
		return array(
			'response' => array(
				'code'    => 302,
				'message' => 'Found',
			),
			'headers'  => array( 'location' => 'https://esempio.invalid/accesso' ),
			'body'     => '',
		);
	}

	/**
	 * Un file vero sul disco, con il contenuto e l'estensione indicati.
	 *
	 * Il PDF comincia davvero con la firma di un PDF: la verifica del tipo di
	 * WordPress guarda il contenuto e non solo l'estensione, quindi un file
	 * finto verrebbe rifiutato per il motivo sbagliato.
	 *
	 * @param string $nome      Nome del file.
	 * @param string $contenuto Contenuto del file.
	 * @return array<string, mixed> Voce nella forma di $_FILES.
	 */
	private function file_da_depositare( $nome = 'atto.pdf', $contenuto = null ) {
		if ( null === $contenuto ) {
			$contenuto = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
		}

		$percorso = wp_tempnam( $nome );

		file_put_contents( $percorso, $contenuto ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: il file deve esistere sul disco prima che WP_Filesystem sia inizializzato.

		$this->temporanei[] = $percorso;

		return array(
			'name'     => $nome,
			'tmp_name' => $percorso,
			'type'     => '',
			'size'     => strlen( $contenuto ),
			'error'    => 0,
		);
	}

	/**
	 * Un contenuto pubblicato del tipo gestito, con la fine fra un mese.
	 *
	 * @return int
	 */
	private function atto_valido() {
		$atto = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		$this->assertTrue(
			conformita_core_imposta_fine_pubblicazione(
				$atto,
				gmdate( 'Y-m-d', strtotime( '+30 days' ) )
			)
		);

		return $atto;
	}

	/**
	 * Toglie di mezzo la cartella protetta fra una prova e l'altra.
	 */
	private function svuota_cartella_protetta() {
		$cartella = Conformita_Core_Allegati::cartella();

		if ( ! is_dir( $cartella ) ) {
			return;
		}

		$voci = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $cartella, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		/*
		 * Si tolgono i file e si lasciano le cartelle. Non e' pigrizia:
		 * `wp_upload_dir()` tiene una memoria interna dei percorsi che ha gia'
		 * creato, quindi una cartella cancellata fra una prova e l'altra non
		 * verrebbe ricreata e lo spostamento dei byte fallirebbe per un motivo
		 * che non c'entra niente con quello che la prova verifica.
		 */
		foreach ( $voci as $voce ) {
			if ( ! $voce->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
				unlink( $voce->getPathname() );
			}
		}
	}

	/**
	 * C-115: il file non finisce nella cartella pubblica dei caricamenti, e il
	 * percorso memorizzato porta il segmento protetto per intero.
	 *
	 * Il percorso memorizzato **deve** contenere il segmento: se contenesse solo
	 * l'anno e il mese, riletto a filtro spento punterebbe a un file pubblico
	 * con lo stesso nome, e la consegna servirebbe il documento sbagliato senza
	 * nessun errore.
	 */
	public function test_c115_il_file_sta_nella_cartella_protetta() {
		$atto = $this->atto_valido();

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );

		$relativo = get_post_meta( $allegato, '_wp_attached_file', true );

		$this->assertStringStartsWith( 'conformita-core-protetto/', $relativo );

		$caricamenti = wp_get_upload_dir();
		$assoluto    = $caricamenti['basedir'] . '/' . $relativo;

		$this->assertFileExists( $assoluto );
		$this->assertStringStartsWith( Conformita_Core_Allegati::cartella() . '/', $assoluto );
	}

	/**
	 * C-116: la cartella nasce con i tre file di regole e con l'esca, e l'esca
	 * contiene il gettone conservato accanto all'esito.
	 */
	public function test_c116_la_cartella_nasce_con_le_regole_e_con_lesca() {
		$this->assertTrue( Conformita_Core_Allegati::prepara() );

		$cartella = Conformita_Core_Allegati::cartella();

		$this->assertFileExists( $cartella . '/.htaccess' );
		$this->assertFileExists( $cartella . '/web.config' );
		$this->assertFileExists( $cartella . '/index.php' );
		$this->assertFileExists( $cartella . '/prova-accesso-diretto.txt' );

		$htaccess = file_get_contents( $cartella . '/.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- prova: si legge un file locale appena scritto, non un indirizzo remoto.

		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'deny from all', $htaccess );

		$esca = file_get_contents( $cartella . '/prova-accesso-diretto.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- prova: come sopra.

		$this->assertSame( Conformita_Core_Allegati::contenuto_esca(), $esca );
		$this->assertNotSame( '', Conformita_Core_Allegati::gettone() );
		$this->assertStringContainsString( Conformita_Core_Allegati::gettone(), $esca );
	}

	/**
	 * C-117: regole cancellate a mano, il deposito successivo le riscrive prima
	 * di muovere i byte.
	 */
	public function test_c117_le_regole_si_riscrivono_prima_di_depositare() {
		$atto = $this->atto_valido();

		$this->assertTrue( Conformita_Core_Allegati::prepara() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( Conformita_Core_Allegati::cartella() . '/.htaccess' );

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );
		$this->assertFileExists( Conformita_Core_Allegati::cartella() . '/.htaccess' );
	}

	/**
	 * C-118: cartella non creabile, il deposito fallisce e non lascia dietro
	 * ne file ne allegato.
	 *
	 * La cartella si rende impossibile spostando i caricamenti sotto un percorso
	 * che nessuno puo' creare, invece di togliere i permessi di scrittura: le
	 * prove girano spesso come amministratore di sistema, e in quel caso i
	 * permessi non fermerebbero niente e la prova sarebbe verde per il motivo
	 * sbagliato.
	 */
	public function test_c118_cartella_non_creabile_niente_deposito() {
		$atto = $this->atto_valido();

		$prima = wp_count_posts( 'attachment' );

		add_filter( 'upload_dir', array( $this, 'caricamenti_impossibili' ), 1 );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'upload_dir', array( $this, 'caricamenti_impossibili' ), 1 );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_cartella_non_protetta', $esito->get_error_code() );

		$dopo = wp_count_posts( 'attachment' );

		$this->assertSame( $prima->inherit, $dopo->inherit );
	}

	/**
	 * Sposta i caricamenti sotto un percorso che non si puo' creare.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @return array<string, string>
	 */
	public function caricamenti_impossibili( $caricamenti ) {
		$caricamenti['basedir'] = '/proc/conformita-core-non-creabile';
		$caricamenti['path']    = $caricamenti['basedir'];

		return $caricamenti;
	}

	/**
	 * C-119: copertura `non_coperta`, deposito rifiutato, e l'errore riporta
	 * esito, istante e stato osservato.
	 */
	public function test_c119_copertura_non_coperta_deposito_rifiutato() {
		$atto = $this->atto_valido();

		$this->risposta_finta = $this->esca_servita();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'non_coperta', $stato['copertura'] );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );

		$dati = $esito->get_error_data();

		$this->assertSame( 'non_coperta', $dati['copertura'] );
		$this->assertSame( 200, $dati['stato_http'] );
		$this->assertNotSame( '', $dati['istante'] );
	}

	/**
	 * C-120: copertura `ignota`, deposito rifiutato; con lo scavalcamento
	 * dichiarato il deposito procede e lo stato continua a dire la verita'.
	 */
	public function test_c120_copertura_ignota_e_scavalcamento() {
		$atto = $this->atto_valido();

		$this->risposta_finta = new WP_Error( 'http_request_failed', 'Nessuna risposta.' );

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $stato['copertura'] );
		$this->assertFalse( $stato['scavalcata'] );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );

		Conformita_Core_Allegati::fissa_scavalcamento( true );

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );

		$stato = conformita_core_stato_protezione_allegati();

		$this->assertSame( 'ignota', $stato['copertura'], 'Lo scavalcamento cambia cosa fa il deposito, non cosa dice lo stato.' );
		$this->assertTrue( $stato['scavalcata'] );
	}

	/**
	 * C-121: deposito su un contenuto di tipo non registrato attraverso core.
	 */
	public function test_c121_tipo_non_gestito_rifiutato() {
		$estraneo = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$esito = conformita_core_deposita_allegato(
			$estraneo,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_non_gestito', $esito->get_error_code() );
	}

	/**
	 * C-122: origine non dichiarata, e origine `caricamento` senza un vero
	 * caricamento.
	 *
	 * Il secondo caso e' la ragione per cui `origine` esiste: senza la
	 * dichiarazione, un percorso ricevuto dall'esterno diventerebbe una lettura
	 * di file arbitrari travestita da caricamento.
	 */
	public function test_c122_origine_obbligatoria_e_coerente() {
		$atto = $this->atto_valido();

		$senza = conformita_core_deposita_allegato( $atto, $this->file_da_depositare(), array() );

		$this->assertWPError( $senza );
		$this->assertSame( 'conformita_core_origine_non_dichiarata', $senza->get_error_code() );

		$sbagliata = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'caricamento' )
		);

		$this->assertWPError( $sbagliata );
		$this->assertSame( 'conformita_core_origine_incoerente', $sbagliata->get_error_code() );

		$inventata = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'qualunque_cosa' )
		);

		$this->assertWPError( $inventata );
		$this->assertSame( 'conformita_core_origine_non_dichiarata', $inventata->get_error_code() );
	}

	/**
	 * C-123: tipo di file non ammesso dall'installazione.
	 */
	public function test_c123_tipo_di_file_non_ammesso() {
		$atto = $this->atto_valido();

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'programma.php', "<?php echo 'ciao';" ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_file_non_ammesso', $esito->get_error_code() );
	}

	/**
	 * C-124: impronta al deposito, con dimensione e istante nel fuso del sito.
	 */
	public function test_c124_impronta_al_deposito() {
		$atto      = $this->atto_valido();
		$contenuto = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf', $contenuto ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );

		$impronta = conformita_core_impronta_allegato( $allegato );

		$this->assertSame( 'sha256', $impronta['algoritmo'] );
		$this->assertSame( hash( 'sha256', $contenuto ), $impronta['valore'] );
		$this->assertSame( strlen( $contenuto ), $impronta['dimensione'] );

		$memorizzato = get_post_meta( $allegato, '_conformita_core_impronta', true );

		$this->assertSame( 'sha256:' . hash( 'sha256', $contenuto ), $memorizzato );

		$deposito = new DateTimeImmutable( $impronta['deposito'] );

		$this->assertSame(
			wp_timezone()->getOffset( $deposito ),
			$deposito->getOffset(),
			"L'istante di deposito porta lo scostamento del fuso del sito, non quello del server."
		);
	}

	/**
	 * C-125: file sostituito sul disco dopo il deposito.
	 *
	 * **Documentazione eseguibile di un limite, non prova di conformita'.** La
	 * consegna non ricalcola l'impronta, quindi non si accorge della
	 * sostituzione: questa prova lo mette nero su bianco perche' il limite resti
	 * visibile invece di essere scoperto da qualcuno che conta sul contrario.
	 */
	public function test_c125_limite_il_file_sostituito_non_si_nota() {
		$atto = $this->atto_valido();

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$impronta = conformita_core_impronta_allegato( $allegato );
		$percorso = get_attached_file( $allegato );

		file_put_contents( $percorso, "%PDF-1.4\naltro contenuto\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: la sostituzione del file e' proprio cio' che si sta simulando.

		$dopo = conformita_core_impronta_allegato( $allegato );

		$this->assertSame( $impronta['valore'], $dopo['valore'], 'Limite dichiarato: l\'impronta e\' quella del deposito.' );
		$this->assertNotSame( hash_file( 'sha256', $percorso ), $dopo['valore'] );
	}

	/**
	 * C-146: lo stato si rilegge con il suo istante, e leggerlo non costa una
	 * richiesta.
	 */
	public function test_c146_lo_stato_si_rilegge_senza_richieste() {
		$verificato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'verificata', $verificato['copertura'] );
		$this->assertSame( 1, $this->richieste );

		$riletto = conformita_core_stato_protezione_allegati();

		$this->assertSame( 'verificata', $riletto['copertura'] );
		$this->assertSame( $verificato['istante'], $riletto['istante'] );
		$this->assertSame( 1, $this->richieste, 'Leggere lo stato non deve fare nessuna richiesta.' );
	}

	/**
	 * C-147: la sezione si registra anche a consegna spenta; il deposito no.
	 */
	public function test_c147_la_sezione_non_dipende_dalla_consegna() {
		Conformita_Core_Consegna::azzera_avvio();

		$this->assertFalse( Conformita_Core_Consegna::avviato() );

		Conformita_Core_Sezioni::azzera();

		$this->assertTrue(
			conformita_core_registra_sezione(
				'sezione_senza_consegna',
				array(
					'indicizzazione' => 'vietata',
					'scadenza'       => 'irraggiungibile',
				)
			),
			'La consegna spenta chiude, non apre: non c\'e\' ragione di bloccare la registrazione.'
		);
	}

	/**
	 * C-153: la versione dell'interfaccia e le funzioni nuove.
	 */
	public function test_c153_contratto_pubblico() {
		$this->assertSame( '1.3.0', CONFORMITA_CORE_VERSIONE_API );
		$this->assertSame( '1.3.0', conformita_core_versione_api() );

		foreach (
			array(
				'conformita_core_deposita_allegato',
				'conformita_core_indirizzo_consegna',
				'conformita_core_indirizzo_consegna_amministrativa',
				'conformita_core_allegato_protetto',
				'conformita_core_impronta_allegato',
				'conformita_core_stato_protezione_allegati',
				'conformita_core_verifica_protezione_allegati',
			) as $funzione
		) {
			$this->assertTrue( function_exists( $funzione ), $funzione . ' non esiste.' );
		}
	}

	/**
	 * C-154: l'esca riceve un rifiuto, quindi la protezione e' verificata.
	 */
	public function test_c154_rifiuto_dellesca_vale_verificata() {
		$this->risposta_finta = $this->rifiuto_del_server();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'verificata', $stato['copertura'] );
		$this->assertSame( 403, $stato['stato_http'] );
		$this->assertNotSame( '', $stato['istante'] );

		/*
		 * Gli stati che valgono diniego sono due e sono un elenco chiuso: il 403
		 * di chi nega, e il 404 di chi nega senza confermare che il file esista.
		 */
		$this->risposta_finta = $this->risposta_con_stato( 404, 'Not Found' );

		$questa = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'verificata', $questa['copertura'] );
		$this->assertSame( 404, $questa['stato_http'] );
	}

	/**
	 * C-155: l'esca torna con il suo gettone, quindi la cartella e' aperta.
	 */
	public function test_c155_esca_servita_vale_non_coperta() {
		$this->risposta_finta = $this->esca_servita();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'non_coperta', $stato['copertura'] );
		$this->assertSame( 200, $stato['stato_http'] );
	}

	/**
	 * C-156: richiesta fallita, e 200 senza il gettone: `ignota` tutt'e due, con
	 * motivi distinti.
	 *
	 * Il secondo caso e' quello che il gettone serve a distinguere: una pagina
	 * di accesso o un catch-all rispondono 200 e non sono un rifiuto. Trattarlo
	 * come protezione attiva sarebbe la direzione sbagliata.
	 */
	public function test_c156_richiesta_fallita_o_risposta_estranea_vale_ignota() {
		$this->risposta_finta = new WP_Error( 'http_request_failed', 'Nessuna risposta.' );

		$fallita = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $fallita['copertura'] );
		$this->assertSame( 0, $fallita['stato_http'] );

		$this->risposta_finta = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '<html><body>Accedi</body></html>',
		);

		$estranea = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $estranea['copertura'] );
		$this->assertSame( 200, $estranea['stato_http'] );
		$this->assertNotSame( $fallita['motivo'], $estranea['motivo'] );
	}

	/**
	 * C-163: il server reindirizza il percorso, quindi l'esito e' `ignota`.
	 *
	 * Un reindirizzamento non e' un rifiuto. La richiesta all'esca non segue i
	 * reindirizzamenti, quindi di dove porta questo non si sa niente, e il caso
	 * comune e' un sito che manda da http a https: li' il file arriverebbe lo
	 * stesso, un passo piu' in la'. Leggerlo come un rifiuto sarebbe l'unico
	 * punto in cui questo meccanismo sbaglia aprendo, perche' autorizzerebbe il
	 * deposito su una cartella che nessuno ha dimostrato protetta.
	 */
	public function test_c163_reindirizzamento_vale_ignota() {
		$this->risposta_finta = $this->reindirizzamento_del_server();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $stato['copertura'] );
		$this->assertSame( 302, $stato['stato_http'] );

		$esito = conformita_core_deposita_allegato(
			$this->atto_valido(),
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );
	}

	/**
	 * C-164: uno stato di errore che non e' un diniego non vale `verificata`.
	 *
	 * E' la stessa famiglia della C-163, trovata dalla revisione indipendente.
	 * Un 503 o un 429 li dice un proxy che in quel momento non sta servendo
	 * niente, e non dicono niente sulle regole della cartella: quando il proxy
	 * torna a servire, i file sarebbero li'. Un 401 lo dice un sito messo per
	 * intero dietro un'autenticazione, tipicamente una installazione in
	 * costruzione: nemmeno quello e' un giudizio sulla cartella, e il giorno che
	 * l'autenticazione si toglie l'esito conservato direbbe `verificata` su una
	 * cartella mai provata.
	 */
	public function test_c164_stati_che_non_dimostrano_niente_valgono_ignota() {
		foreach ( array( 500, 503, 429, 401 ) as $codice ) {
			$this->risposta_finta = $this->risposta_con_stato( $codice, 'errore' );

			$stato = conformita_core_verifica_protezione_allegati();

			$this->assertSame(
				'ignota',
				$stato['copertura'],
				'Lo stato ' . $codice . ' non dimostra che la cartella sia protetta.'
			);
			$this->assertSame( $codice, $stato['stato_http'] );
		}

		$esito = conformita_core_deposita_allegato(
			$this->atto_valido(),
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );
	}

	/**
	 * C-165: il gettone vince sullo stato.
	 *
	 * Se il contenuto dell'esca torna indietro, la cartella e' aperta, e con
	 * quale stato sia tornato non cambia niente: i byte sono arrivati. Guardare
	 * lo stato per primo lasciava passare il caso di un server che serve il file
	 * accompagnandolo con uno stato di errore, che e' cio' che fa una rete di
	 * distribuzione mal configurata davanti all'origine.
	 */
	public function test_c165_il_gettone_vince_sullo_stato() {
		$this->risposta_finta = $this->risposta_con_stato( 403, Conformita_Core_Allegati::contenuto_esca() );

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'non_coperta', $stato['copertura'] );
		$this->assertSame( 403, $stato['stato_http'] );
	}

	/**
	 * C-166: se il deposito salta per aria, il dirottamento dei caricamenti non
	 * resta acceso.
	 *
	 * Il filtro che manda i byte nella cartella protetta si spegne subito dopo
	 * lo spostamento. Se lo spostamento esce per un'eccezione, sollevata da un
	 * aggancio di un altro componente, la riga che lo spegne non verrebbe
	 * eseguita e il filtro resterebbe acceso per tutto il resto della richiesta:
	 * da li' in poi ogni caricamento finirebbe nella cartella protetta e ogni
	 * percorso memorizzato sarebbe calcolato rispetto a una cartella diversa.
	 * E' la stessa collisione silenziosa della riga C-115, per un'altra via.
	 */
	public function test_c166_il_dirottamento_si_spegne_anche_se_il_deposito_salta() {
		$scoppia = static function () {
			throw new RuntimeException( 'Un altro componente ha sollevato un\'eccezione.' );
		};

		add_filter( 'wp_handle_sideload_prefilter', $scoppia );

		$sollevata = false;

		try {
			conformita_core_deposita_allegato(
				$this->atto_valido(),
				$this->file_da_depositare(),
				array( 'origine' => 'percorso_locale' )
			);
		} catch ( RuntimeException $eccezione ) {
			$sollevata = true;
		}

		remove_filter( 'wp_handle_sideload_prefilter', $scoppia );

		$this->assertTrue( $sollevata, 'La prova non ha misurato niente: l\'eccezione non e\' uscita.' );

		$this->assertFalse(
			has_filter( 'upload_dir', array( 'Conformita_Core_Allegati', 'dirotta' ) ),
			'Il dirottamento dei caricamenti e\' rimasto acceso.'
		);

		$caricamenti = wp_get_upload_dir();

		$this->assertStringNotContainsString(
			Conformita_Core_Allegati::CARTELLA,
			$caricamenti['basedir'],
			'La cartella dei caricamenti punta ancora dentro quella protetta.'
		);
	}

	/**
	 * C-157: la verifica si fa su richiesta e alla riscrittura delle regole; un
	 * deposito normale non fa nessuna richiesta.
	 */
	public function test_c157_quando_la_verifica_si_fa_e_quando_no() {
		$atto = $this->atto_valido();

		$this->assertSame( 0, $this->richieste );

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo );
		$this->assertSame( 2, $this->richieste, 'Il primo deposito scrive le regole e prova il suo ambito.' );

		$secondo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $secondo );
		$this->assertSame( 2, $this->richieste, 'Un deposito che non riscrive niente e resta nel suo ambito non deve fare richieste.' );

		conformita_core_verifica_protezione_allegati();

		$this->assertSame( 3, $this->richieste, 'La richiesta esplicita, invece, la fa.' );
	}

	/**
	 * C-158: regole cancellate a mano, il deposito successivo le riscrive e
	 * rifa' la verifica, decidendo con l'esito nuovo.
	 *
	 * E' la riga che chiude l'unico modo di guasto che sbagliava aprendo: un
	 * esito conservato da prima direbbe ancora `verificata` su una cartella che
	 * nel frattempo e' stata riaperta.
	 */
	public function test_c158_regole_cancellate_la_verifica_si_rifa() {
		$atto = $this->atto_valido();

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo );
		$this->assertSame( 'verificata', conformita_core_stato_protezione_allegati()['copertura'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( Conformita_Core_Allegati::cartella() . '/.htaccess' );

		$this->risposta_finta = $this->esca_servita();

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertSame( 3, $this->richieste, 'La riscrittura delle regole rifa\' la verifica.' );
		$this->assertWPError( $esito );
		$this->assertSame( 'non_coperta', conformita_core_stato_protezione_allegati()['copertura'] );
	}

	/**
	 * C-169: l'esca `.txt` negata non prova il PDF nella sottocartella.
	 *
	 * Il caso vero: una regola che nega la cartella e, accanto, una regola per
	 * estensione che serve i file statici. Su nginx la seconda vince sulla
	 * prima, e non serve che nessuno cambi niente dopo. L'esca `.txt` risponde
	 * 403 senza gettone, quindi l'esito generale dice `verificata`, ma i PDF di
	 * quella cartella sono scaricabili dal loro percorso.
	 */
	public function test_c169_il_diniego_del_txt_non_prova_il_pdf() {
		$atto = $this->atto_valido();

		$this->risposta_finta = function ( $indirizzo ) {
			if ( '.pdf' === substr( $indirizzo, -4 ) ) {
				return $this->esca_servita();
			}

			return $this->rifiuto_del_server();
		};

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			),
			'Nessun file deve essere entrato.'
		);

		$this->assertSame(
			'non_coperta',
			conformita_core_stato_protezione_allegati()['copertura'],
			'Il gettone e\' uscito, quindi non e\' un fatto della sola estensione.'
		);
	}

	/**
	 * C-170: l'esito di un ambito si conserva, e il deposito dopo non chiede.
	 *
	 * La verifica per ambito non deve diventare una richiesta a ogni deposito:
	 * il primo file di un'estensione nuova la paga, i successivi no.
	 */
	public function test_c170_lesito_dellambito_si_conserva() {
		$atto = $this->atto_valido();

		$this->assertSame( 0, $this->richieste );

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo, is_wp_error( $primo ) ? $primo->get_error_message() : '' );
		$this->assertSame( 2, $this->richieste, 'Le regole appena scritte, piu\' l\'ambito dei PDF.' );

		$secondo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $secondo, is_wp_error( $secondo ) ? $secondo->get_error_message() : '' );
		$this->assertSame( 2, $this->richieste, 'Stesso ambito: nessuna richiesta in piu\'.' );

		$terzo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'terzo.txt', "Un documento in chiaro.\n" ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $terzo, is_wp_error( $terzo ) ? $terzo->get_error_message() : '' );
		$this->assertSame( 3, $this->richieste, 'Estensione nuova, ambito nuovo, una richiesta.' );

		$ambiti = conformita_core_stato_protezione_allegati()['ambiti'];

		$this->assertArrayHasKey(
			Conformita_Core_Allegati::chiave_ambito( Conformita_Core_Allegati::sottocartella_corrente(), 'pdf' ),
			$ambiti
		);
		$this->assertArrayHasKey(
			Conformita_Core_Allegati::chiave_ambito( Conformita_Core_Allegati::sottocartella_corrente(), 'txt' ),
			$ambiti
		);
	}

	/**
	 * C-172: la sottocartella si prova com'e' scritta, e quella che non si sa
	 * chiedere si rifiuta.
	 *
	 * Il percorso che si chiede al server deve essere esattamente quello in cui
	 * i byte finiscono. Una riduzione del nome, per quanto prudente, fa provare
	 * un percorso e scriverne un altro: e' lo stesso difetto della riga C-169
	 * per un'altra via. Quindi il nome non si corregge in silenzio. O lo si sa
	 * chiedere com'e', e allora si prova com'e', oppure non si deposita.
	 *
	 * La prova fa tutte e due le meta'. Nella prima il server nega **solo** i
	 * due indirizzi esatti, quello generale e quello della sottocartella col
	 * punto: se il codice avesse tolto il punto, l'esca sarebbe stata chiesta
	 * altrove, il server l'avrebbe servita e il deposito sarebbe stato
	 * rifiutato. Nella seconda la sottocartella ha uno spazio, che in un
	 * indirizzo si scrive in un altro modo: il deposito si rifiuta prima di
	 * scrivere un byte e senza chiedere niente al server.
	 */
	public function test_c172_la_sottocartella_si_prova_come_e_scritta() {
		$atto = $this->atto_valido();

		/*
		 * Gli indirizzi negati si scrivono per esteso, non si chiedono al codice
		 * che si sta provando: chiederli a lui li farebbe ridurre tutti e due
		 * allo stesso modo, e la prova non vedrebbe piu' niente.
		 */
		$radice = Conformita_Core_Allegati::indirizzo_cartella();
		$esca   = Conformita_Core_Allegati::ESCA_PREFISSO;

		$this->risposta_finta = function ( $indirizzo ) use ( $radice, $esca ) {
			$negati = array(
				$radice . '/' . $esca . '.txt',
				$radice . '/documenti.v1/' . $esca . '.pdf',
			);

			if ( in_array( $indirizzo, $negati, true ) ) {
				return $this->rifiuto_del_server();
			}

			return $this->esca_servita();
		};

		add_filter( 'upload_dir', array( $this, 'sottocartella_con_un_punto' ), 5 );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'upload_dir', array( $this, 'sottocartella_con_un_punto' ), 5 );

		$this->assertIsInt( $esito, is_wp_error( $esito ) ? $esito->get_error_message() : '' );

		$this->assertSame(
			'verificata',
			Conformita_Core_Allegati::stato_ambito( '/documenti.v1', 'pdf' )['copertura'],
			'L\'ambito provato e\' quello col punto, non una sua riduzione.'
		);

		$chieste = $this->richieste;

		add_filter( 'upload_dir', array( $this, 'sottocartella_con_uno_spazio' ), 5 );

		$rifiutato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'altro.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'upload_dir', array( $this, 'sottocartella_con_uno_spazio' ), 5 );

		$this->assertWPError( $rifiutato );
		$this->assertSame( 'conformita_core_destinazione_non_provabile', $rifiutato->get_error_code() );

		$this->assertSame(
			$chieste,
			$this->richieste,
			'Un percorso che non si sa chiedere non si chiede nemmeno.'
		);

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			),
			'Solo il primo file deve essere entrato.'
		);
	}

	/**
	 * Una sottocartella con un punto nel nome: si sa chiedere com'e'.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @return array<string, string>
	 */
	public function sottocartella_con_un_punto( $caricamenti ) {
		return $this->sottocartella_finta( $caricamenti, '/documenti.v1' );
	}

	/**
	 * Una sottocartella con uno spazio nel nome: non si sa chiedere com'e'.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @return array<string, string>
	 */
	public function sottocartella_con_uno_spazio( $caricamenti ) {
		return $this->sottocartella_finta( $caricamenti, '/documenti v1' );
	}

	/**
	 * Mette una sottocartella decisa dalla prova al posto di quella per data.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @param string                $sotto       Sottocartella voluta.
	 * @return array<string, string>
	 */
	private function sottocartella_finta( $caricamenti, $sotto ) {
		$caricamenti['subdir'] = $sotto;
		$caricamenti['path']   = $caricamenti['basedir'] . $sotto;
		$caricamenti['url']    = $caricamenti['baseurl'] . $sotto;

		return $caricamenti;
	}

	/**
	 * C-173: regole riscritte durante la verifica di un ambito.
	 *
	 * Se le regole mancavano, la cartella era aperta, e tutto quello che si
	 * sapeva parla di una configurazione cambiata due volte. Non basta
	 * dimenticare quando la verifica chiesta e' quella generale: anche quella
	 * di un ambito solo, se riscrive, deve dimenticare tutto e rimisurare il
	 * generale, altrimenti il deposito successivo trova le regole a posto e
	 * riusa un giudizio vecchio.
	 */
	public function test_c173_regole_riscritte_durante_la_verifica_di_un_ambito() {
		$atto  = $this->atto_valido();
		$sotto = Conformita_Core_Allegati::sottocartella_corrente();

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo, is_wp_error( $primo ) ? $primo->get_error_message() : '' );
		$this->assertSame(
			'verificata',
			Conformita_Core_Allegati::stato_ambito( $sotto, 'pdf' )['copertura']
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( Conformita_Core_Allegati::cartella() . '/.htaccess' );

		$this->risposta_finta = $this->esca_servita();

		Conformita_Core_Allegati::verifica( $sotto, 'png' );

		$this->assertSame(
			'ignota',
			Conformita_Core_Allegati::stato_ambito( $sotto, 'pdf' )['copertura'],
			'L\'esito dei PDF parlava di una configurazione che non c\'e\' piu\'.'
		);

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
	}

	/**
	 * C-174: l'estensione si prova com'e' finira' sul disco.
	 *
	 * Stesso difetto della riga C-172 per l'altra meta' del percorso. Quello
	 * che il server tratta in modo diverso non e' "un PDF": e' un indirizzo che
	 * finisce in un certo modo. WordPress ripulisce il nome del file prima di
	 * scriverlo, ma non abbassa le maiuscole e non toglie il trattino: se la
	 * verifica riduce l'estensione mentre chi scrive i byte non la riduce, si
	 * prova un indirizzo e se ne apre un altro.
	 *
	 * Nella prima meta' il file si chiama `.PDF`, e WordPress lo scrivera'
	 * `.pdf`, perche' abbassa le maiuscole dell'estensione. Il server nega
	 * **solo** l'indirizzo minuscolo e serve quello maiuscolo: il deposito
	 * riesce, quindi l'esca chiesta era quella del file vero e non quella del
	 * nome dichiarato. Nella seconda l'installazione ammette `pdf-x` e il
	 * server si comporta come il caso che si teme: nega tutto tranne `.pdf-x`,
	 * che serve. Il deposito deve essere rifiutato, perche' il percorso vero e'
	 * quello aperto.
	 */
	public function test_c174_lestensione_si_prova_come_e_scritta() {
		$atto  = $this->atto_valido();
		$sotto = Conformita_Core_Allegati::sottocartella_corrente();

		// Per esteso, per lo stesso motivo della riga C-172.
		$radice = Conformita_Core_Allegati::indirizzo_cartella();
		$esca   = Conformita_Core_Allegati::ESCA_PREFISSO;

		$this->risposta_finta = function ( $indirizzo ) use ( $radice, $esca, $sotto ) {
			$negati = array(
				$radice . '/' . $esca . '.txt',
				$radice . $sotto . '/' . $esca . '.pdf',
			);

			if ( in_array( $indirizzo, $negati, true ) ) {
				return $this->rifiuto_del_server();
			}

			return $this->esca_servita();
		};

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.PDF' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $esito, is_wp_error( $esito ) ? $esito->get_error_message() : '' );

		$this->assertSame(
			'verificata',
			Conformita_Core_Allegati::stato_ambito( $sotto, 'pdf' )['copertura'],
			'L\'ambito provato e\' quello che il file avra\' davvero.'
		);

		$this->assertSame(
			'ignota',
			Conformita_Core_Allegati::stato_ambito( $sotto, 'PDF' )['copertura'],
			'L\'estensione dichiarata non e\' quella che si prova.'
		);

		$this->assertSame(
			'pdf',
			pathinfo( get_post_meta( $esito, '_wp_attached_file', true ), PATHINFO_EXTENSION ),
			'Il file sul disco ha l\'estensione che si e\' provata.'
		);

		add_filter( 'upload_mimes', array( $this, 'ammetti_pdf_x' ) );

		$this->risposta_finta = function ( $indirizzo ) {
			if ( '.pdf-x' === substr( $indirizzo, -6 ) ) {
				return $this->esca_servita();
			}

			return $this->rifiuto_del_server();
		};

		$rifiutato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf-x' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'upload_mimes', array( $this, 'ammetti_pdf_x' ) );

		$this->assertWPError( $rifiutato );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $rifiutato->get_error_code() );

		$this->assertSame(
			'non_coperta',
			Conformita_Core_Allegati::stato_ambito( $sotto, 'pdf-x' )['copertura'],
			'Il gettone e\' uscito dall\'indirizzo vero, non da quello ridotto.'
		);

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			),
			'Solo il primo file deve essere entrato.'
		);
	}

	/**
	 * Ammette un\'estensione con un trattino, che la riduzione toglierebbe.
	 *
	 * @param array<string, string> $tipi Tipi ammessi dall\'installazione.
	 * @return array<string, string>
	 */
	public function ammetti_pdf_x( $tipi ) {
		$tipi['pdf-x'] = 'application/pdf';

		return $tipi;
	}

	/**
	 * C-175: una riscrittura fallita a meta' dimentica lo stesso.
	 *
	 * I file di regole si scrivono uno dopo l'altro. Se il primo viene riscritto
	 * e il secondo non si riesce a scrivere, chi ha chiamato vede solo l'errore:
	 * se la dimenticanza stesse li', gli esiti conservati resterebbero validi su
	 * una cartella che nel frattempo e' stata riscritta a meta'. Basta poi che
	 * qualcuno rimetta a mano il file mancante perche' il deposito successivo
	 * non trovi piu' differenze e riusi un giudizio vecchio, su una
	 * configurazione che nessuno ha piu' provato.
	 *
	 * La prova fa esattamente quel giro: primo deposito riuscito, `.htaccess`
	 * alterato ma riscrivibile, `web.config` sostituito da una cartella, che
	 * nessuno riesce a scrivere nemmeno da amministratore del sistema.
	 */
	public function test_c175_riscrittura_fallita_a_meta_dimentica_lo_stesso() {
		$atto     = $this->atto_valido();
		$cartella = Conformita_Core_Allegati::cartella();

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo, is_wp_error( $primo ) ? $primo->get_error_message() : '' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		$htaccess_atteso  = file_get_contents( $cartella . '/.htaccess' );
		$webconfig_atteso = file_get_contents( $cartella . '/web.config' );

		file_put_contents( $cartella . '/.htaccess', "# qualcuno ha cambiato le regole\n" );

		unlink( $cartella . '/web.config' );
		mkdir( $cartella . '/web.config' );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		/*
		 * Una cartella al posto di un file fa fallire la scrittura e fa emettere
		 * a PHP i suoi avvisi. Qui sono previsti: si mettono a tacere per il
		 * tempo della chiamata, altrimenti la suite li trasforma in eccezioni e
		 * la prova fallirebbe prima di guardare quello che le interessa.
		 */
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- prova: gli avvisi di PHP sono il comportamento atteso, e la suite li trasformerebbe in eccezioni.
			static function () {
				return true;
			}
		);

		$fallito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		restore_error_handler();

		$this->assertWPError( $fallito );
		$this->assertSame( 'conformita_core_cartella_non_protetta', $fallito->get_error_code() );

		$this->assertSame(
			$htaccess_atteso,
			file_get_contents( $cartella . '/.htaccess' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- prova: si legge un file locale appena scritto.
			'Il primo file e\' stato davvero riscritto: la riscrittura e\' fallita a meta\'.'
		);

		// Il ripristino a mano del file rimasto indietro.
		// phpcs:disable WordPress.WP.AlternativeFunctions -- prova: come sopra.
		rmdir( $cartella . '/web.config' );
		file_put_contents( $cartella . '/web.config', $webconfig_atteso );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$dopo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'terzo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError(
			$dopo,
			'Le regole sono di nuovo a posto, ma nessuno ha piu\' provato questa configurazione.'
		);
		$this->assertSame( 'conformita_core_protezione_non_verificata', $dopo->get_error_code() );

		Conformita_Core_Allegati::verifica();

		$ripreso = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'quarto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt(
			$ripreso,
			is_wp_error( $ripreso ) ? $ripreso->get_error_message() : ''
		);
	}

	/**
	 * C-177: sottocartella che finisce con un a capo.
	 *
	 * La convalida usava `$` come ancora finale, e in PCRE il dollaro accetta
	 * anche la posizione prima di un a capo finale: un nome cosi' passava. Il
	 * disco quel carattere lo conserva, mentre l'indirizzo chiesto al server lo
	 * perde per strada, quindi si sarebbe tornati a provare un percorso e a
	 * scriverne un altro. Adesso le ancore sono `\A` e `\z`.
	 */
	public function test_c177_sottocartella_con_un_a_capo_finale() {
		$atto = $this->atto_valido();

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo, is_wp_error( $primo ) ? $primo->get_error_message() : '' );

		$chieste = $this->richieste;

		add_filter( 'upload_dir', array( $this, 'sottocartella_con_un_a_capo' ), 5 );

		$rifiutato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'upload_dir', array( $this, 'sottocartella_con_un_a_capo' ), 5 );

		$this->assertWPError( $rifiutato );
		$this->assertSame( 'conformita_core_destinazione_non_provabile', $rifiutato->get_error_code() );

		$this->assertSame(
			$chieste,
			$this->richieste,
			'Un percorso che non si sa chiedere non si chiede nemmeno.'
		);

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			),
			'Solo il primo file deve essere entrato.'
		);
	}

	/**
	 * Una sottocartella che finisce con un a capo.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @return array<string, string>
	 */
	public function sottocartella_con_un_a_capo( $caricamenti ) {
		return $this->sottocartella_finta( $caricamenti, "/documenti\n" );
	}

	/**
	 * C-178: il nome previsto non e' il nome imposto.
	 *
	 * Il percorso di destinazione si calcola in anticipo con la stessa funzione
	 * che decidera' il nome del file, ed e' la previsione piu' fedele che si
	 * possa fare da fuori. Resta una previsione: dentro `wp_handle_sideload()`
	 * c'e' un aggancio con cui un altro componente puo' cambiare il nome, e
	 * l'estensione con lui, **dopo** quel calcolo. Qui il server nega i `.pdf` e
	 * serve i `.pdf-x`, e un componente rinomina il file da uno all'altro: senza
	 * la guardia sul percorso definitivo, il deposito proverebbe un ambito negato
	 * e scriverebbe in uno aperto.
	 */
	public function test_c178_il_nome_cambiato_durante_lo_spostamento() {
		$atto = $this->atto_valido();

		$this->risposta_finta = function ( $indirizzo ) {
			if ( '.pdf-x' === substr( $indirizzo, -6 ) ) {
				return $this->esca_servita();
			}

			return $this->rifiuto_del_server();
		};

		add_filter( 'upload_mimes', array( $this, 'ammetti_pdf_x' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'rinomina_in_pdf_x' ) );

		$rifiutato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'rinomina_in_pdf_x' ) );
		remove_filter( 'upload_mimes', array( $this, 'ammetti_pdf_x' ) );

		$this->assertWPError( $rifiutato );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $rifiutato->get_error_code() );

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			),
			'Nessun file deve essere entrato.'
		);

		$sotto = Conformita_Core_Allegati::sottocartella_corrente();

		$this->assertFileDoesNotExist(
			Conformita_Core_Allegati::cartella() . $sotto . '/atto.pdf-x',
			'Nessun byte deve essere finito nell\'ambito aperto.'
		);

		$this->assertSame(
			'non_coperta',
			Conformita_Core_Allegati::stato_ambito( $sotto, 'pdf-x' )['copertura'],
			'L\'ambito vero e\' stato provato, e risulta scoperto.'
		);
	}

	/**
	 * Rinomina il file durante lo spostamento, cambiandogli l\'estensione.
	 *
	 * @param array<string, mixed> $file Voce del file.
	 * @return array<string, mixed>
	 */
	public function rinomina_in_pdf_x( $file ) {
		$file['name'] = 'atto.pdf-x';

		return $file;
	}

	/**
	 * C-179: la guardia decide anche se qualcun altro ha gia' risposto.
	 *
	 * Lo stesso aggancio su cui sta la guardia serve ai componenti che spostano
	 * i file per conto proprio: rispondono con un valore, e WordPress non copia
	 * piu' niente. Se la guardia tacesse davanti a una risposta pronta, uno di
	 * quei componenti basterebbe a spegnere il controllo sulla destinazione.
	 *
	 * Due meta'. Nella prima il componente estraneo si aggancia con una
	 * priorita' normale: la guardia, che sta alla piu' bassa che esiste, passa
	 * prima di lui e ferma tutto. Nella seconda si aggancia alla stessa
	 * priorita' della guardia ma prima di lei, quindi copia il file prima che
	 * lei parli: la guardia lo trova al suo posto, lo toglie e ferma lo stesso.
	 */
	public function test_c179_la_guardia_decide_anche_se_qualcun_altro_ha_risposto() {
		$atto  = $this->atto_valido();
		$sotto = Conformita_Core_Allegati::sottocartella_corrente();

		$this->risposta_finta = function ( $indirizzo ) {
			if ( '.pdf-x' === substr( $indirizzo, -6 ) ) {
				return $this->esca_servita();
			}

			return $this->rifiuto_del_server();
		};

		add_filter( 'upload_mimes', array( $this, 'ammetti_pdf_x' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'rinomina_in_pdf_x' ) );

		add_filter( 'pre_move_uploaded_file', array( $this, 'sposta_per_conto_proprio' ), 5, 3 );

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'pre_move_uploaded_file', array( $this, 'sposta_per_conto_proprio' ), 5 );

		$this->assertWPError( $primo, 'La guardia passa prima del componente estraneo.' );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $primo->get_error_code() );
		$this->assertFileDoesNotExist( Conformita_Core_Allegati::cartella() . $sotto . '/atto.pdf-x' );

		add_filter( 'pre_move_uploaded_file', array( $this, 'sposta_per_conto_proprio' ), PHP_INT_MIN, 3 );

		$secondo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'pre_move_uploaded_file', array( $this, 'sposta_per_conto_proprio' ), PHP_INT_MIN );
		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'rinomina_in_pdf_x' ) );
		remove_filter( 'upload_mimes', array( $this, 'ammetti_pdf_x' ) );

		$this->assertWPError( $secondo, 'Il componente estraneo ha copiato prima, e la guardia ferma lo stesso.' );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $secondo->get_error_code() );
		$this->assertFileDoesNotExist(
			Conformita_Core_Allegati::cartella() . $sotto . '/atto.pdf-x',
			'Il file messo li\' da qualcun altro non deve restare in un ambito non provato.'
		);

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Un componente che sposta il file per conto proprio e lo dice a WordPress.
	 *
	 * @param mixed  $esito      Esito gia' deciso.
	 * @param array  $file       Voce del file.
	 * @param string $nuovo_file Destinazione.
	 * @return bool
	 */
	public function sposta_per_conto_proprio( $esito, $file, $nuovo_file ) {
		unset( $esito );

		copy( $file['tmp_name'], $nuovo_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- prova: fa quello che farebbe un componente che sposta i file da se'.

		return true;
	}

	/**
	 * C-180: la destinazione si confronta sul percorso vero.
	 *
	 * Il nome puo' cominciare per quello della cartella protetta e portare lo
	 * stesso fuori: basta che la sottocartella del mese sia un collegamento
	 * simbolico verso un'altra parte del disco. La consegna fa il confronto
	 * con `realpath()` prima di leggere; la guardia deve farlo prima di
	 * scrivere, perche' un file finito fuori dalla cartella protetta non e'
	 * protetto da niente.
	 */
	public function test_c180_la_destinazione_si_confronta_sul_percorso_vero() {
		$atto   = $this->atto_valido();
		$sotto  = Conformita_Core_Allegati::sottocartella_corrente();
		$dentro = Conformita_Core_Allegati::cartella() . $sotto;
		$fuori  = get_temp_dir() . 'cc-fuori-' . wp_generate_password( 8, false );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		$this->assertTrue( wp_mkdir_p( dirname( $dentro ) ) );
		$this->assertTrue( mkdir( $fuori ) );

		if ( is_dir( $dentro ) && ! is_link( $dentro ) ) {
			$this->assertTrue( rmdir( $dentro ), 'La sottocartella del mese deve essere vuota per poterla sostituire.' );
		}

		$this->assertTrue( symlink( $fuori, $dentro ) );

		try {
			$esito = conformita_core_deposita_allegato(
				$atto,
				$this->file_da_depositare( 'atto.pdf' ),
				array( 'origine' => 'percorso_locale' )
			);

			$this->assertWPError( $esito );
			$this->assertSame( 'conformita_core_destinazione_fuori_cartella', $esito->get_error_code() );
			$this->assertFileDoesNotExist( $fuori . '/atto.pdf', 'Nessun byte deve essere uscito dalla cartella protetta.' );
		} finally {
			unlink( $dentro );
			wp_mkdir_p( $dentro );

			foreach ( glob( $fuori . '/*' ) as $residuo ) {
				unlink( $residuo );
			}

			rmdir( $fuori );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * C-181: il nome dell'esca e' riservato.
	 *
	 * Un documento che si chiamasse come l'esca finirebbe al suo posto il
	 * giorno in cui l'esca manca, e la verifica successiva, trovando un
	 * contenuto diverso da quello atteso, lo sovrascriverebbe con il proprio:
	 * un atto sostituito da una riga di prova, senza nessun errore. La prova
	 * riproduce la premessa (ambito gia' provato, esca tolta) e pretende il
	 * rifiuto prima che un byte si muova.
	 */
	public function test_c181_il_nome_dellesca_e_riservato() {
		$atto  = $this->atto_valido();
		$sotto = Conformita_Core_Allegati::sottocartella_corrente();

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo, is_wp_error( $primo ) ? $primo->get_error_message() : '' );

		$esca = Conformita_Core_Allegati::cartella() . $sotto . '/' . Conformita_Core_Allegati::ESCA_PREFISSO . '.pdf';

		$this->assertFileExists( $esca, 'L\'ambito dei PDF e\' stato provato, quindi la sua esca c\'e\'.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: e' la premessa del guasto, l'esca che manca.
		unlink( $esca );

		$chieste = $this->richieste;

		$rifiutato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( Conformita_Core_Allegati::ESCA_PREFISSO . '.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $rifiutato );
		$this->assertSame( 'conformita_core_nome_riservato', $rifiutato->get_error_code() );
		$this->assertSame( $chieste, $this->richieste, 'Si rifiuta prima di chiedere niente al server.' );
		$this->assertFileDoesNotExist( $esca, 'Nessun documento deve aver preso il posto dell\'esca.' );

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			),
			'Solo il primo file deve essere entrato.'
		);
	}

	/**
	 * C-182: un file senza estensione non e' un file `.txt`.
	 *
	 * Un aggancio su `wp_unique_filename` puo' togliere l'estensione dopo che
	 * il tipo del file e' stato controllato. Se l'estensione vuota diventasse
	 * `txt` quando si cerca l'ambito, il deposito proverebbe l'esca `.txt` e
	 * scriverebbe un file senza estensione: su un server che nega i `.txt` e
	 * serve tutto il resto, il documento sarebbe pubblico dal percorso diretto.
	 *
	 * Due meta'. Nella prima l'estensione sparisce gia' nel nome previsto, e il
	 * rifiuto arriva prima di chiedere niente al server. Nella seconda sparisce
	 * solo durante lo spostamento, e il rifiuto arriva dalla guardia.
	 */
	public function test_c182_lestensione_vuota_non_e_quella_dei_txt() {
		$atto  = $this->atto_valido();
		$sotto = Conformita_Core_Allegati::sottocartella_corrente();

		$this->risposta_finta = function ( $indirizzo ) {
			if ( '.txt' === substr( $indirizzo, -4 ) ) {
				return $this->rifiuto_del_server();
			}

			return $this->esca_servita();
		};

		$this->togli_estensione_dalla = 1;
		$this->nomi_decisi            = 0;

		$this->assertTrue( Conformita_Core_Allegati::prepara(), 'La cartella nasce, e con lei la verifica generale.' );

		add_filter( 'wp_unique_filename', array( $this, 'togli_estensione' ) );

		$chieste = $this->richieste;

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $primo );
		$this->assertSame( 'conformita_core_destinazione_non_provabile', $primo->get_error_code() );
		$this->assertSame( $chieste, $this->richieste, 'Un ambito senza estensione non si prova, quindi non parte nessuna richiesta.' );
		$this->assertFileDoesNotExist( Conformita_Core_Allegati::cartella() . $sotto . '/atto' );

		$this->togli_estensione_dalla = 2;
		$this->nomi_decisi            = 0;

		$secondo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'wp_unique_filename', array( $this, 'togli_estensione' ) );

		$this->assertSame( 2, $this->nomi_decisi, 'Il nome e\' stato deciso due volte, quindi lo spostamento e\' partito.' );
		$this->assertWPError( $secondo );
		$this->assertSame( 'conformita_core_destinazione_non_provabile', $secondo->get_error_code() );
		$this->assertFileDoesNotExist(
			Conformita_Core_Allegati::cartella() . $sotto . '/atto',
			'Nessun byte deve essere finito in un ambito senza estensione.'
		);

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Toglie l'estensione al nome deciso da WordPress, dalla volta indicata in poi.
	 *
	 * @param string $nome Nome deciso.
	 * @return string
	 */
	public function togli_estensione( $nome ) {
		++$this->nomi_decisi;

		if ( $this->nomi_decisi < $this->togli_estensione_dalla ) {
			return $nome;
		}

		return (string) pathinfo( $nome, PATHINFO_FILENAME );
	}

	/**
	 * C-183: un collegamento dentro la cartella non apre un ambito non provato.
	 *
	 * La riga C-180 guarda i collegamenti che portano fuori. Questa guarda
	 * quelli che restano dentro: se la sottocartella del mese e' un
	 * collegamento verso un'altra sottocartella, il file raggiungibile dal
	 * percorso scritto lo e' anche dall'altro, e il server puo' trattare i due
	 * indirizzi in modo diverso. Provare l'uno non dice niente dell'altro.
	 *
	 * Tre parti. Nella prima la sottocartella e' un collegamento a `aperta`,
	 * che il server serve. Nella seconda la cartella e' vera ma al posto del
	 * file c'e' gia' un collegamento che non punta a niente: il nome sembra
	 * libero, e la copia scriverebbe attraverso il collegamento. Nella terza
	 * e' la cartella protetta stessa a essere un collegamento verso un'altra
	 * cartella dei caricamenti, che ha un indirizzo suo.
	 */
	public function test_c183_un_collegamento_interno_non_apre_un_altro_ambito() {
		$atto   = $this->atto_valido();
		$sotto  = Conformita_Core_Allegati::sottocartella_corrente();
		$radice = Conformita_Core_Allegati::cartella();
		$dentro = $radice . $sotto;
		$aperta = $radice . '/aperta';
		$fuori  = get_temp_dir() . 'cc-fuori-' . wp_generate_password( 8, false );

		$this->risposta_finta = function ( $indirizzo ) {
			if ( false !== strpos( $indirizzo, '/aperta/' ) ) {
				return $this->esca_servita();
			}

			return $this->rifiuto_del_server();
		};

		// phpcs:disable WordPress.WP.AlternativeFunctions -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		$this->assertTrue( wp_mkdir_p( dirname( $dentro ) ) );
		$this->assertTrue( wp_mkdir_p( $aperta ) );

		if ( is_dir( $dentro ) && ! is_link( $dentro ) ) {
			$this->assertTrue( rmdir( $dentro ), 'La sottocartella del mese deve essere vuota per poterla sostituire.' );
		}

		$this->assertTrue( symlink( $aperta, $dentro ) );

		try {
			$primo = conformita_core_deposita_allegato(
				$atto,
				$this->file_da_depositare( 'atto.pdf' ),
				array( 'origine' => 'percorso_locale' )
			);

			$this->assertWPError( $primo );
			$this->assertSame( 'conformita_core_destinazione_non_canonica', $primo->get_error_code() );
			$this->assertFileDoesNotExist( $aperta . '/atto.pdf', 'Nessun byte deve essere finito nell\'ambito servito.' );
		} finally {
			unlink( $dentro );
			wp_mkdir_p( $dentro );

			foreach ( glob( $aperta . '/*' ) as $residuo ) {
				unlink( $residuo );
			}

			rmdir( $aperta );
		}

		$this->assertTrue( mkdir( $fuori ) );
		$this->assertTrue( symlink( $fuori . '/atto.pdf', $dentro . '/atto.pdf' ) );

		try {
			$secondo = conformita_core_deposita_allegato(
				$atto,
				$this->file_da_depositare( 'atto.pdf' ),
				array( 'origine' => 'percorso_locale' )
			);

			$this->assertWPError( $secondo );
			$this->assertSame( 'conformita_core_destinazione_non_canonica', $secondo->get_error_code() );
			$this->assertFileDoesNotExist( $fuori . '/atto.pdf', 'Nessun byte deve essere passato attraverso il collegamento.' );
		} finally {
			if ( is_link( $dentro . '/atto.pdf' ) ) {
				unlink( $dentro . '/atto.pdf' );
			}

			foreach ( glob( $fuori . '/*' ) as $residuo ) {
				unlink( $residuo );
			}

			rmdir( $fuori );
		}

		$altrove = dirname( $radice ) . '/cc-altrove-' . wp_generate_password( 8, false );

		$this->assertTrue( rename( $radice, $altrove ) );
		$this->assertTrue( symlink( $altrove, $radice ) );

		try {
			$terzo = conformita_core_deposita_allegato(
				$atto,
				$this->file_da_depositare( 'atto.pdf' ),
				array( 'origine' => 'percorso_locale' )
			);

			$this->assertWPError( $terzo );
			$this->assertSame( 'conformita_core_destinazione_non_canonica', $terzo->get_error_code() );
			$this->assertFileDoesNotExist( $altrove . $sotto . '/atto.pdf', 'Nessun byte deve essere entrato da un indirizzo diverso da quello provato.' );
		} finally {
			unlink( $radice );
			rename( $altrove, $radice );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $atto,
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * C-184: l'origine si ricontrolla sul file che si sposta davvero.
	 *
	 * L'origine «caricamento» si controlla all'ingresso con `is_uploaded_file()`,
	 * ma fra l'ingresso e lo spostamento c'e' `wp_handle_sideload_prefilter`,
	 * e un aggancio li' puo' sostituire il file caricato con un file locale
	 * qualunque. La guardia, che vede il file che sta per essere copiato, deve
	 * rifare la stessa domanda su quello.
	 *
	 * **Perche' la prova chiama la guardia direttamente.** Un caricamento vero
	 * nasce solo da una richiesta HTTP: da riga di comando `is_uploaded_file()`
	 * risponde sempre di no, quindi un deposito con origine «caricamento» si
	 * ferma all'ingresso e non arriva mai alla guardia. La prova fa allora due
	 * cose separate: dentro un deposito vero controlla che la guardia sappia
	 * quale origine e' stata dichiarata, e poi chiama la guardia con
	 * l'origine «caricamento» e un file locale, che e' esattamente quello che
	 * vedrebbe dopo la sostituzione.
	 */
	public function test_c184_lorigine_si_ricontrolla_sul_file_che_si_sposta() {
		$atto     = $this->atto_valido();
		$sotto    = Conformita_Core_Allegati::sottocartella_corrente();
		$in_corso = new ReflectionProperty( 'Conformita_Core_Allegati', 'origine_in_corso' );
		$fermata  = new ReflectionProperty( 'Conformita_Core_Allegati', 'fermata' );
		$vista    = array();
		$spia     = static function ( $esito ) use ( $in_corso, &$vista ) {
			$vista[] = $in_corso->getValue();

			return $esito;
		};

		$in_corso->setAccessible( true );
		$fermata->setAccessible( true );

		add_filter( 'pre_move_uploaded_file', $spia, 10 );

		$depositato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'pre_move_uploaded_file', $spia, 10 );

		$this->assertIsInt( $depositato, is_wp_error( $depositato ) ? $depositato->get_error_message() : '' );
		$this->assertSame( array( 'percorso_locale' ), $vista, 'Durante lo spostamento la guardia conosce l\'origine dichiarata.' );
		$this->assertSame( '', $in_corso->getValue(), 'Finito il deposito, l\'origine non resta in giro.' );

		$locale       = $this->file_da_depositare( 'sostituto.pdf' );
		$destinazione = Conformita_Core_Allegati::cartella() . $sotto . '/sostituto.pdf';

		$in_corso->setValue( null, 'caricamento' );
		$fermato = null;

		try {
			Conformita_Core_Allegati::guardia_destinazione( null, $locale, $destinazione );
		} catch ( Conformita_Core_Deposito_Fermato $eccezione ) {
			$fermato = $eccezione;
		} finally {
			$in_corso->setValue( null, '' );
			remove_filter( 'upload_dir', array( 'Conformita_Core_Allegati', 'dirotta' ) );
		}

		$this->assertInstanceOf( 'Conformita_Core_Deposito_Fermato', $fermato, 'Un file locale al posto di un caricamento non si sposta.' );
		$this->assertSame( 'conformita_core_origine_incoerente', $fermata->getValue()['codice'] );
		$this->assertFileExists( $locale['tmp_name'], 'Il file sorgente non si tocca.' );
		$this->assertFileDoesNotExist( $destinazione );

		$fermata->setValue( null, array() );
		$fermato = null;

		try {
			Conformita_Core_Allegati::guardia_destinazione( null, $locale, $destinazione );
		} catch ( Conformita_Core_Deposito_Fermato $eccezione ) {
			$fermato = $eccezione;
		} finally {
			remove_filter( 'upload_dir', array( 'Conformita_Core_Allegati', 'dirotta' ) );
		}

		$this->assertInstanceOf( 'Conformita_Core_Deposito_Fermato', $fermato, 'Fuori da un deposito la guardia non sa quale origine controllare, quindi ferma.' );
		$this->assertSame( 'conformita_core_origine_non_dichiarata', $fermata->getValue()['codice'] );
		$this->assertFileDoesNotExist( $destinazione );

		$fermata->setValue( null, array() );
		$in_corso->setValue( null, 'percorso_locale' );

		try {
			$this->assertNull(
				Conformita_Core_Allegati::guardia_destinazione( null, $locale, $destinazione ),
				'Con l\'origine «percorso_locale» lo stesso file passa: il rifiuto viene dall\'origine e da nient\'altro.'
			);
		} finally {
			$in_corso->setValue( null, '' );
			remove_filter( 'upload_dir', array( 'Conformita_Core_Allegati', 'dirotta' ) );
		}
	}
}
