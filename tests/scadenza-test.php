<?php
/**
 * Contratto del dato di fine pubblicazione, righe C-23, C-24, C-87, C-88, C-99
 * e C-100 del catalogo.
 *
 * Questo file collauda il **contratto**, non il filtro di lettura: chiave del
 * metadato, formato del valore, istante di scadenza e decisione. I dodici
 * percorsi di lettura sono le righe C-10..C-21 e arrivano dopo, perche' senza
 * un istante di scadenza definito senza ambiguita' un filtro non avrebbe niente
 * di preciso da applicare.
 *
 * La riga che conta di piu' e' C-87. La data di fine e' **inclusiva**: un atto
 * con fine 2026-09-09 resta pubblico per tutto il 9 settembre e scade alla
 * mezzanotte del 10, nel fuso del sito. Un confronto ingenuo fra la data e
 * "adesso" lo farebbe sparire un giorno prima, e nessuno se ne accorgerebbe
 * finche' un ente non contesta una pubblicazione durata quattordici giorni
 * invece di quindici.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul contratto del dato di fine pubblicazione.
 */
class Conformita_Core_Scadenza_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_di_prova';
	const TIPO    = 'prova_atto';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Registro azzerato e sezione con tipo registrati prima di ogni prova.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );

		conformita_core_registra_sezione(
			self::SEZIONE,
			array(
				'indicizzazione' => 'vietata',
				'scadenza'       => 'irraggiungibile',
			)
		);

		conformita_core_registra_tipo(
			self::TIPO,
			array(
				'sezione'      => self::SEZIONE,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Fuso e orologio ripristinati.
	 */
	public function tear_down() {
		update_option( 'timezone_string', $this->fuso_originale );
		Conformita_Core_Scadenza::azzera_orologio();

		parent::tear_down();
	}

	/**
	 * Un contenuto del tipo gestito.
	 *
	 * @return int Identificativo del contenuto.
	 */
	private function atto() {
		return self::factory()->post->create( array( 'post_type' => self::TIPO ) );
	}

	/**
	 * L'orologio interno fissato a un istante civile del fuso del sito.
	 *
	 * @param string $istante Istante nel formato Y-m-d H:i:s.
	 */
	private function adesso( $istante ) {
		Conformita_Core_Scadenza::fissa_orologio(
			new DateTimeImmutable( $istante, wp_timezone() )
		);
	}

	/**
	 * La chiave del metadato e' quella di core e non e' negoziabile.
	 */
	public function test_chiave_del_metadato() {
		$this->assertSame( '_conformita_core_fine_pubblicazione', conformita_core_chiave_fine_pubblicazione() );
	}

	/**
	 * C-100: valore fuori formato, rifiutato con codice distinto.
	 *
	 * @dataProvider valori_fuori_formato
	 *
	 * @param string $valore Valore che non rispetta il formato.
	 */
	public function test_c100_valore_fuori_formato( $valore ) {
		$esito = conformita_core_valida_fine_pubblicazione( $valore );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_data_formato', $esito->get_error_code() );
	}

	/**
	 * Valori che non rispettano il formato Y-m-d.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function valori_fuori_formato() {
		return array(
			'formato italiano' => array( '09/09/2026' ),
			'senza zeri'       => array( '2026-9-9' ),
			'stringa vuota'    => array( '' ),
			'testo'            => array( 'domani' ),
			'con ora'          => array( '2026-09-09 12:00:00' ),
			'ordine invertito' => array( '09-09-2026' ),
		);
	}

	/**
	 * C-100: data nel formato giusto ma inesistente, rifiutata con codice suo.
	 *
	 * Il 31 febbraio rispetta il formato: se si validasse solo quello,
	 * entrerebbe nella banca dati e produrrebbe un istante di scadenza che
	 * nessuno ha scelto.
	 *
	 * @dataProvider date_inesistenti
	 *
	 * @param string $valore Data nel formato giusto ma che non esiste.
	 */
	public function test_c100_data_inesistente( $valore ) {
		$esito = conformita_core_valida_fine_pubblicazione( $valore );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_data_inesistente', $esito->get_error_code() );
	}

	/**
	 * Date nel formato giusto che non esistono nel calendario.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function date_inesistenti() {
		return array(
			'31 febbraio'      => array( '2026-02-31' ),
			'31 aprile'        => array( '2026-04-31' ),
			'mese tredicesimo' => array( '2026-13-01' ),
			'giorno zero'      => array( '2026-09-00' ),
		);
	}

	/**
	 * C-100: una data valida e' accettata.
	 *
	 * Meta' necessaria della riga: una validazione che rifiuta tutto sarebbe
	 * corretta quanto inutile.
	 */
	public function test_c100_data_valida_accettata() {
		$this->assertTrue( conformita_core_valida_fine_pubblicazione( '2026-09-09' ) );
		$this->assertTrue( conformita_core_valida_fine_pubblicazione( '2028-02-29' ) );
	}

	/**
	 * C-99: impostare due volte la stessa data non e' un errore.
	 *
	 * `update_post_meta` restituisce falso sia quando fallisce sia quando il
	 * valore era gia' identico. Chi non distingue i due casi trasforma
	 * un'operazione riuscita in un errore, e il difetto compare solo al secondo
	 * salvataggio.
	 */
	public function test_c99_impostazione_idempotente() {
		$atto = $this->atto();

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $atto, '2026-09-09' ) );
		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $atto, '2026-09-09' ) );
		$this->assertSame( '2026-09-09', conformita_core_fine_pubblicazione( $atto ) );
	}

	/**
	 * L'impostazione rifiuta un valore non valido e non scrive niente.
	 */
	public function test_impostazione_rifiuta_valore_non_valido() {
		$atto = $this->atto();

		$esito = conformita_core_imposta_fine_pubblicazione( $atto, '2026-02-31' );

		$this->assertWPError( $esito );
		$this->assertSame( '', conformita_core_fine_pubblicazione( $atto ) );
	}

	/**
	 * L'impostazione rifiuta un contenuto di tipo non gestito da core.
	 */
	public function test_impostazione_rifiuta_tipo_non_gestito() {
		$articolo = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$esito = conformita_core_imposta_fine_pubblicazione( $articolo, '2026-09-09' );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_non_gestito', $esito->get_error_code() );
	}

	/**
	 * C-87: l'istante di scadenza e' la mezzanotte del giorno successivo.
	 *
	 * E' la conseguenza del fatto che la data di fine e' inclusiva.
	 */
	public function test_c87_istante_di_scadenza_e_mezzanotte_del_giorno_dopo() {
		$atto = $this->atto();
		conformita_core_imposta_fine_pubblicazione( $atto, '2026-09-09' );

		$istante = conformita_core_istante_scadenza( $atto );

		$this->assertInstanceOf( DateTimeImmutable::class, $istante );
		$this->assertSame( '2026-09-10 00:00:00', $istante->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'Europe/Rome', $istante->getTimezone()->getName() );
	}

	/**
	 * C-87: un istante prima non e' scaduto, l'istante esatto lo e'.
	 *
	 * Le tre asserzioni insieme fissano il confine. Presa da sola, la prima
	 * passerebbe anche con un confronto sbagliato di un giorno.
	 */
	public function test_c87_confine_esatto_della_scadenza() {
		$atto = $this->atto();
		conformita_core_imposta_fine_pubblicazione( $atto, '2026-09-09' );

		$this->adesso( '2026-09-09 23:59:59' );
		$this->assertFalse( conformita_core_scaduto( $atto ), 'Un istante prima della mezzanotte non e scaduto.' );

		$this->adesso( '2026-09-10 00:00:00' );
		$this->assertTrue( conformita_core_scaduto( $atto ), 'Alla mezzanotte esatta e scaduto.' );

		$this->adesso( '2026-09-09 00:00:00' );
		$this->assertFalse( conformita_core_scaduto( $atto ), 'Il giorno stesso della fine non e scaduto: la data e inclusiva.' );
	}

	/**
	 * C-88: metadato assente, il contenuto risulta scaduto.
	 */
	public function test_c88_data_assente_risulta_scaduto() {
		$atto = $this->atto();

		$this->adesso( '2026-09-09 12:00:00' );

		$this->assertTrue( conformita_core_scaduto( $atto ), 'Senza data si sbaglia nella direzione sicura.' );
	}

	/**
	 * C-88: metadato corrotto, il contenuto risulta scaduto.
	 *
	 * Il valore entra con `update_post_meta` diretto e non con l'API: e' il
	 * percorso di una migrazione o di una scrittura di terzi, cioe' l'unico modo
	 * in cui un valore corrotto puo' esistere.
	 *
	 * @dataProvider valori_corrotti
	 *
	 * @param string $valore Valore corrotto gia' presente nella banca dati.
	 */
	public function test_c88_data_corrotta_risulta_scaduta( $valore ) {
		$atto = $this->atto();
		update_post_meta( $atto, conformita_core_chiave_fine_pubblicazione(), $valore );

		$this->adesso( '2026-09-09 12:00:00' );

		$this->assertTrue( conformita_core_scaduto( $atto ) );
	}

	/**
	 * Valori corrotti che possono trovarsi nella banca dati.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function valori_corrotti() {
		return array(
			'formato italiano' => array( '09/09/2026' ),
			'data inesistente' => array( '2026-02-31' ),
			'testo'            => array( 'boh' ),
			'stringa vuota'    => array( '' ),
		);
	}

	/**
	 * C-88: la decisione non produce mai un errore, e sbaglia in sicurezza.
	 *
	 * E' chiamata nel percorso di lettura: una funzione che puo' fallire li'
	 * prima o poi lascia passare qualcosa mentre qualcuno gestisce l'eccezione.
	 *
	 * **Correzione di una prova debole scritta in prima stesura.** Le tre
	 * asserzioni erano `assertIsBool`, che verificano solo il tipo del risultato:
	 * la prova sarebbe passata anche restituendo falso, cioe' nella direzione
	 * insicura, che e' esattamente il difetto che questa riga esiste per
	 * escludere. Il contratto dice che tipo non gestito e identificativo
	 * inesistente danno scaduto, quindi le asserzioni devono dirlo.
	 */
	public function test_c88_la_decisione_sbaglia_in_sicurezza() {
		$articolo = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->assertTrue( conformita_core_scaduto( $articolo ), 'Tipo non gestito: scaduto.' );
		$this->assertTrue( conformita_core_scaduto( 0 ), 'Identificativo zero: scaduto.' );
		$this->assertTrue( conformita_core_scaduto( 999999 ), 'Identificativo inesistente: scaduto.' );
	}

	/**
	 * La lettura della data su un tipo non gestito e' un errore esplicito.
	 *
	 * Qui l'errore ci vuole, al contrario della decisione: chi chiede la data di
	 * un contenuto che core non governa sta facendo una domanda sbagliata, e
	 * deve saperlo. La decisione invece non puo' fallire perche' sta nel
	 * percorso di lettura.
	 */
	public function test_lettura_su_tipo_non_gestito_e_un_errore() {
		$articolo = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$esito = conformita_core_fine_pubblicazione( $articolo );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_non_gestito', $esito->get_error_code() );

		$istante = conformita_core_istante_scadenza( $articolo );

		$this->assertWPError( $istante );
		$this->assertSame( 'conformita_core_tipo_non_gestito', $istante->get_error_code() );
	}

	/**
	 * C-23: la scadenza cade nell'istante civile giusto anche ai cambi d'ora.
	 *
	 * Marzo e ottobre. E' la ragione per cui il termine si esprime in giorni
	 * civili e non in secondi: nei giorni del cambio d'ora **un giorno civile
	 * dura 23 o 25 ore**, quindi sommare 86.400 secondi alla data di fine
	 * porterebbe la scadenza alle 23 o all'una invece che a mezzanotte. Un
	 * istante gia' memorizzato, invece, non si sposta: non e' quello il
	 * problema.
	 *
	 * @dataProvider giorni_di_cambio_ora
	 *
	 * @param string $fine     Data di fine pubblicazione.
	 * @param string $atteso   Istante di scadenza atteso.
	 */
	public function test_c23_cambi_d_ora( $fine, $atteso ) {
		$atto = $this->atto();
		conformita_core_imposta_fine_pubblicazione( $atto, $fine );

		$istante = conformita_core_istante_scadenza( $atto );

		$this->assertSame( $atteso, $istante->format( 'Y-m-d H:i:s' ) );

		$prima = new DateTimeImmutable( $atteso, wp_timezone() );
		Conformita_Core_Scadenza::fissa_orologio( $prima->modify( '-1 second' ) );
		$this->assertFalse( conformita_core_scaduto( $atto ) );

		Conformita_Core_Scadenza::fissa_orologio( $prima );
		$this->assertTrue( conformita_core_scaduto( $atto ) );
	}

	/**
	 * I due giorni in cui l'ora civile cambia, in Italia, nel 2026.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function giorni_di_cambio_ora() {
		return array(
			'vigilia del passaggio a ora legale' => array( '2026-03-28', '2026-03-29 00:00:00' ),
			'giorno del passaggio a ora legale'  => array( '2026-03-29', '2026-03-30 00:00:00' ),
			'vigilia del ritorno a ora solare'   => array( '2026-10-24', '2026-10-25 00:00:00' ),
			'giorno del ritorno a ora solare'    => array( '2026-10-25', '2026-10-26 00:00:00' ),
		);
	}

	/**
	 * C-24: il calcolo segue il fuso del sito, nessun valore cablato.
	 */
	public function test_c24_fuso_diverso_da_roma() {
		update_option( 'timezone_string', 'Pacific/Auckland' );

		$atto = $this->atto();
		conformita_core_imposta_fine_pubblicazione( $atto, '2026-09-09' );

		$istante = conformita_core_istante_scadenza( $atto );

		$this->assertSame( 'Pacific/Auckland', $istante->getTimezone()->getName() );
		$this->assertSame( '2026-09-10 00:00:00', $istante->format( 'Y-m-d H:i:s' ) );

		// A mezzanotte ad Auckland e' ancora il 9 in Italia: l'atto e' scaduto
		// secondo il fuso del sito, che e' l'unico che conta.
		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-09-10 00:00:00', wp_timezone() ) );
		$this->assertTrue( conformita_core_scaduto( $atto ) );
	}
}
