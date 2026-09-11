<?php
/**
 * Contratto di politica, righe C-01, C-02, C-05 del catalogo di collaudo.
 *
 * Il vincolo che questi test difendono: core fornisce il meccanismo e pretende
 * la politica come parametro esplicito. Una politica mancante o scritta male non
 * si corregge con un valore ragionevole, perché il valore ragionevole per un
 * componente è quello sbagliato per l'altro: l'indicizzazione è vietata dove la
 * pubblicità legale è temporanea ed è obbligatoria dove la pubblicazione serve a
 * essere trovata. Un default, qualunque default, romperebbe uno dei due.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul valore politica e sul rifiuto delle dichiarazioni incomplete.
 */
class Conformita_Core_Politica_Test extends WP_UnitTestCase {

	/**
	 * Identificativo usato dalle prove di questa classe.
	 *
	 * @var string
	 */
	const SEZIONE = 'sezione_di_prova';

	/**
	 * Registro azzerato prima di ogni prova: le registrazioni vivono in memoria
	 * e girano tutte nello stesso processo.
	 */
	public function set_up() {
		parent::set_up();
		Conformita_Core_Sezioni::azzera();
	}

	/**
	 * C-01: senza politica di indicizzazione la registrazione è rifiutata.
	 */
	public function test_c01_registrazione_senza_politica_di_indicizzazione() {
		$esito = conformita_core_registra_sezione(
			self::SEZIONE,
			array( 'scadenza' => Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE )
		);

		$this->assertWPError( $esito, 'Senza politica di indicizzazione la registrazione deve fallire.' );
		$this->assertSame( 'conformita_core_politica_mancante', $esito->get_error_code() );
		$this->assertStringContainsString(
			'indicizzazione',
			$esito->get_error_message(),
			"L'errore deve dire quale politica manca."
		);
		$this->assertFalse(
			conformita_core_sezione_registrata( self::SEZIONE ),
			'La sezione non deve risultare attiva.'
		);
	}

	/**
	 * C-02: senza politica di scadenza la registrazione è rifiutata.
	 */
	public function test_c02_registrazione_senza_politica_di_scadenza() {
		$esito = conformita_core_registra_sezione(
			self::SEZIONE,
			array( 'indicizzazione' => Conformita_Core_Politica::INDICIZZAZIONE_VIETATA )
		);

		$this->assertWPError( $esito, 'Senza politica di scadenza la registrazione deve fallire.' );
		$this->assertSame( 'conformita_core_politica_mancante', $esito->get_error_code() );
		$this->assertStringContainsString( 'scadenza', $esito->get_error_message() );
		$this->assertFalse( conformita_core_sezione_registrata( self::SEZIONE ) );
	}

	/**
	 * C-01 e C-02: nessuna politica dichiarata, l'errore le nomina entrambe.
	 */
	public function test_c01_c02_registrazione_senza_nessuna_politica() {
		$esito = conformita_core_registra_sezione( self::SEZIONE, array() );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_politica_mancante', $esito->get_error_code() );
		$this->assertStringContainsString( 'indicizzazione', $esito->get_error_message() );
		$this->assertStringContainsString( 'scadenza', $esito->get_error_message() );
		$this->assertFalse( conformita_core_sezione_registrata( self::SEZIONE ) );
	}

	/**
	 * C-01 e C-02: valore vuoto o nullo vale come politica mancante.
	 *
	 * È il modo in cui un default rientra dalla finestra: una chiave presente ma
	 * vuota che il codice interpreta come "vai avanti lo stesso".
	 *
	 * @dataProvider valori_vuoti
	 *
	 * @param mixed $vuoto Valore che non dichiara nessuna politica.
	 */
	public function test_c01_c02_valore_vuoto_vale_come_politica_mancante( $vuoto ) {
		$esito = conformita_core_registra_sezione(
			self::SEZIONE,
			array(
				'indicizzazione' => $vuoto,
				'scadenza'       => Conformita_Core_Politica::SCADENZA_ARCHIVIO,
			)
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_politica_mancante', $esito->get_error_code() );
		$this->assertFalse( conformita_core_sezione_registrata( self::SEZIONE ) );
	}

	/**
	 * Valori che non dichiarano nessuna politica.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function valori_vuoti() {
		return array(
			'stringa vuota' => array( '' ),
			'soli spazi'    => array( '   ' ),
			'nullo'         => array( null ),
		);
	}

	/**
	 * C-05: politica di indicizzazione fuori dall'insieme ammesso.
	 */
	public function test_c05_indicizzazione_fuori_dai_valori_ammessi() {
		$esito = conformita_core_registra_sezione(
			self::SEZIONE,
			array(
				'indicizzazione' => 'forse',
				'scadenza'       => Conformita_Core_Politica::SCADENZA_ARCHIVIO,
			)
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_politica_non_valida', $esito->get_error_code() );
		$this->assertStringContainsString(
			Conformita_Core_Politica::INDICIZZAZIONE_CONSENTITA,
			$esito->get_error_message(),
			"L'errore deve elencare i valori ammessi."
		);
		$this->assertStringContainsString(
			Conformita_Core_Politica::INDICIZZAZIONE_VIETATA,
			$esito->get_error_message()
		);
		$this->assertFalse( conformita_core_sezione_registrata( self::SEZIONE ) );
	}

	/**
	 * C-05: politica di scadenza fuori dall'insieme ammesso.
	 */
	public function test_c05_scadenza_fuori_dai_valori_ammessi() {
		$esito = conformita_core_registra_sezione(
			self::SEZIONE,
			array(
				'indicizzazione' => Conformita_Core_Politica::INDICIZZAZIONE_CONSENTITA,
				'scadenza'       => 'si_vedra',
			)
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_politica_non_valida', $esito->get_error_code() );
		$this->assertStringContainsString(
			Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE,
			$esito->get_error_message()
		);
		$this->assertStringContainsString(
			Conformita_Core_Politica::SCADENZA_ARCHIVIO,
			$esito->get_error_message()
		);
		$this->assertFalse( conformita_core_sezione_registrata( self::SEZIONE ) );
	}

	/**
	 * I due insiemi di valori ammessi sono chiusi e contengono le due opposte.
	 *
	 * Se un domani qualcuno aggiungesse un terzo valore "predefinito" a uno dei
	 * due insiemi, questo test lo direbbe subito.
	 */
	public function test_insiemi_di_valori_ammessi_chiusi() {
		$this->assertSame(
			array(
				Conformita_Core_Politica::INDICIZZAZIONE_CONSENTITA,
				Conformita_Core_Politica::INDICIZZAZIONE_VIETATA,
			),
			Conformita_Core_Politica::valori_ammessi( 'indicizzazione' )
		);

		$this->assertSame(
			array(
				Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE,
				Conformita_Core_Politica::SCADENZA_ARCHIVIO,
			),
			Conformita_Core_Politica::valori_ammessi( 'scadenza' )
		);
	}
}
