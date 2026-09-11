<?php
/**
 * Registro delle sezioni, righe C-03, C-04, C-06, C-07, C-08 e C-95 del catalogo.
 *
 * La riga che conta di più è C-04: due componenti con politiche di
 * indicizzazione opposte stanno nello stesso sito, e nessuno dei due deve poter
 * cambiare la politica dell'altro. Per questo la politica sta in un registro
 * per identificativo di sezione e non in un'opzione globale: uno stato globale
 * avrebbe un solo valore, e uno dei due componenti lo troverebbe sbagliato.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul registro delle sezioni e sulla convivenza di politiche opposte.
 */
class Conformita_Core_Sezioni_Test extends WP_UnitTestCase {

	/**
	 * Registro azzerato prima di ogni prova.
	 */
	public function set_up() {
		parent::set_up();
		Conformita_Core_Sezioni::azzera();
	}

	/**
	 * Politica con indicizzazione vietata e contenuto scaduto irraggiungibile.
	 *
	 * @return array<string, string>
	 */
	private function politica_chiusa() {
		return array(
			'indicizzazione' => Conformita_Core_Politica::INDICIZZAZIONE_VIETATA,
			'scadenza'       => Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE,
		);
	}

	/**
	 * Politica con indicizzazione consentita e contenuto scaduto in archivio.
	 *
	 * @return array<string, string>
	 */
	private function politica_aperta() {
		return array(
			'indicizzazione' => Conformita_Core_Politica::INDICIZZAZIONE_CONSENTITA,
			'scadenza'       => Conformita_Core_Politica::SCADENZA_ARCHIVIO,
		);
	}

	/**
	 * C-03: con entrambe le politiche dichiarate la sezione si attiva e le
	 * politiche si rileggono dall'API interna.
	 */
	public function test_c03_registrazione_con_entrambe_le_politiche() {
		$esito = conformita_core_registra_sezione( 'sezione_chiusa', $this->politica_chiusa() );

		$this->assertTrue( $esito, 'La registrazione completa deve riuscire.' );
		$this->assertTrue( conformita_core_sezione_registrata( 'sezione_chiusa' ) );

		$politica = conformita_core_politica_sezione( 'sezione_chiusa' );

		$this->assertInstanceOf( Conformita_Core_Politica::class, $politica );
		$this->assertSame( Conformita_Core_Politica::INDICIZZAZIONE_VIETATA, $politica->indicizzazione() );
		$this->assertSame( Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE, $politica->scadenza() );
		$this->assertFalse( $politica->consente_indicizzazione() );
	}

	/**
	 * C-04: due sezioni con politiche di indicizzazione opposte convivono.
	 */
	public function test_c04_due_sezioni_con_politiche_opposte() {
		$this->assertTrue( conformita_core_registra_sezione( 'sezione_chiusa', $this->politica_chiusa() ) );
		$this->assertTrue( conformita_core_registra_sezione( 'sezione_aperta', $this->politica_aperta() ) );

		$chiusa = conformita_core_politica_sezione( 'sezione_chiusa' );
		$aperta = conformita_core_politica_sezione( 'sezione_aperta' );

		$this->assertFalse( $chiusa->consente_indicizzazione(), 'La sezione chiusa vieta l\'indicizzazione.' );
		$this->assertTrue( $aperta->consente_indicizzazione(), 'La sezione aperta la impone.' );

		$this->assertSame( Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE, $chiusa->scadenza() );
		$this->assertSame( Conformita_Core_Politica::SCADENZA_ARCHIVIO, $aperta->scadenza() );

		$this->assertSame(
			array( 'sezione_chiusa', 'sezione_aperta' ),
			conformita_core_sezioni_registrate()
		);
	}

	/**
	 * C-04: la seconda registrazione non tocca la prima, in nessuno dei due
	 * ordini di arrivo.
	 *
	 * L'ordine conta perché in un sito reale dipende dall'ordine di caricamento
	 * dei plugin, che non controlliamo: se ci fosse un'interferenza, comparirebbe
	 * solo in uno dei due ordini e sarebbe un difetto che si manifesta a caso.
	 *
	 * @dataProvider ordini_di_registrazione
	 *
	 * @param string $prima  Identificativo registrato per primo.
	 * @param string $dopo   Identificativo registrato per secondo.
	 */
	public function test_c04_nessuna_interferenza_fra_le_due_registrazioni( $prima, $dopo ) {
		$politiche = array(
			'sezione_chiusa' => $this->politica_chiusa(),
			'sezione_aperta' => $this->politica_aperta(),
		);

		conformita_core_registra_sezione( $prima, $politiche[ $prima ] );
		$prima_di_tutto = conformita_core_politica_sezione( $prima )->come_array();

		conformita_core_registra_sezione( $dopo, $politiche[ $dopo ] );

		$this->assertSame(
			$prima_di_tutto,
			conformita_core_politica_sezione( $prima )->come_array(),
			'La registrazione di una sezione non deve cambiare la politica di un\'altra.'
		);
		$this->assertSame( $politiche[ $dopo ], conformita_core_politica_sezione( $dopo )->come_array() );
	}

	/**
	 * I due ordini di caricamento possibili.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function ordini_di_registrazione() {
		return array(
			'chiusa poi aperta' => array( 'sezione_chiusa', 'sezione_aperta' ),
			'aperta poi chiusa' => array( 'sezione_aperta', 'sezione_chiusa' ),
		);
	}

	/**
	 * C-06: identificativo già registrato, la seconda registrazione è rifiutata
	 * e la prima resta intatta.
	 */
	public function test_c06_identificativo_gia_registrato() {
		$this->assertTrue( conformita_core_registra_sezione( 'sezione_chiusa', $this->politica_chiusa() ) );

		$esito = conformita_core_registra_sezione( 'sezione_chiusa', $this->politica_aperta() );

		$this->assertWPError( $esito, 'Nessuna sovrascrittura silenziosa.' );
		$this->assertSame( 'conformita_core_sezione_duplicata', $esito->get_error_code() );
		$this->assertStringContainsString( 'sezione_chiusa', $esito->get_error_message() );

		$this->assertSame(
			$this->politica_chiusa(),
			conformita_core_politica_sezione( 'sezione_chiusa' )->come_array(),
			'La prima registrazione deve restare quella valida.'
		);
	}

	/**
	 * C-07: la politica di una sezione mai registrata non esiste, e leggerla è
	 * un errore esplicito.
	 */
	public function test_c07_lettura_di_sezione_mai_registrata() {
		$this->assertFalse( conformita_core_sezione_registrata( 'sezione-mai-vista' ) );

		$esito = conformita_core_politica_sezione( 'sezione-mai-vista' );

		$this->assertWPError( $esito, 'Nessuna politica di comodo per una sezione sconosciuta.' );
		$this->assertSame( 'conformita_core_sezione_sconosciuta', $esito->get_error_code() );
		$this->assertStringContainsString( 'sezione-mai-vista', $esito->get_error_message() );
	}

	/**
	 * C-08: identificativo vuoto, registrazione rifiutata.
	 *
	 * @dataProvider identificativi_vuoti
	 *
	 * @param string $identificativo Identificativo che non identifica niente.
	 */
	public function test_c08_identificativo_vuoto( $identificativo ) {
		$esito = conformita_core_registra_sezione( $identificativo, $this->politica_chiusa() );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_sezione_non_valida', $esito->get_error_code() );
		$this->assertSame( array(), conformita_core_sezioni_registrate() );
	}

	/**
	 * Identificativi che non identificano niente.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function identificativi_vuoti() {
		return array(
			'stringa vuota' => array( '' ),
			'soli spazi'    => array( '   ' ),
		);
	}

	/**
	 * C-95: identificativo fuori dall'insieme dei caratteri ammessi, rifiutato.
	 *
	 * L'insieme e' lo stesso degli identificativi di tipo: lettere minuscole,
	 * cifre e trattino basso. E' una restrizione decisa prima del rilascio, non
	 * la correzione di una collisione: senza normalizzazione `sezione-a` e
	 * `sezione_a` darebbero due capability diverse, quindi non collidono. Il
	 * vincolo esiste per tre ragioni. Una regola sola invece di due, perche' chi
	 * scrive un componente non deve ricordarsene una per i tipi e una per le
	 * sezioni. La convenzione interna del progetto, che per sezioni e capability
	 * adotta l'insieme `[a-z0-9_]`. E il fatto che una trasformazione esplicita
	 * del tipo `str_replace( '-', '_', ... )`, introdotta un domani, riaprirebbe
	 * la collisione della riga C-86. Nota: `sanitize_key()` **conserva** il
	 * trattino, quindi non e' quella la trasformazione da temere.
	 *
	 * @dataProvider identificativi_di_sezione_non_validi
	 *
	 * @param string $identificativo Identificativo fuori dall'insieme ammesso.
	 */
	public function test_c95_identificativo_fuori_dai_caratteri_ammessi( $identificativo ) {
		$esito = conformita_core_registra_sezione( $identificativo, $this->politica_chiusa() );

		$this->assertWPError( $esito, 'Un identificativo fuori dall\'insieme ammesso non si registra.' );
		$this->assertSame( 'conformita_core_sezione_non_valida', $esito->get_error_code() );
		$this->assertSame( array(), conformita_core_sezioni_registrate() );
	}

	/**
	 * Identificativi di sezione fuori dall'insieme dei caratteri ammessi.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function identificativi_di_sezione_non_validi() {
		return array(
			'trattino'          => array( 'albo-pretorio' ),
			'lettere maiuscole' => array( 'Albo' ),
			'spazi interni'     => array( 'albo pretorio' ),
			'punto'             => array( 'albo.pretorio' ),
			'barra'             => array( 'albo/pretorio' ),
		);
	}

	/**
	 * C-95: l'identificativo ammesso resta ammesso.
	 *
	 * Meta' necessaria della riga: un vincolo che rifiuta tutto sarebbe corretto
	 * quanto inutile, e il difetto non si vedrebbe finche' qualcuno non prova a
	 * registrare una sezione vera.
	 */
	public function test_c95_identificativo_ammesso_resta_ammesso() {
		$this->assertTrue( conformita_core_registra_sezione( 'albo_pretorio', $this->politica_chiusa() ) );
		$this->assertTrue( conformita_core_registra_sezione( 'sezione2', $this->politica_chiusa() ) );
		$this->assertSame( array( 'albo_pretorio', 'sezione2' ), conformita_core_sezioni_registrate() );
	}
}
