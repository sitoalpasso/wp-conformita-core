<?php
/**
 * Registrazione dei tipi di contenuto, righe da C-70 a C-79 del catalogo.
 *
 * Il vincolo che questi test difendono è lo stesso del contratto di politica,
 * visto dal lato dei tipi: la politica sta sulla sezione, quindi un tipo che
 * vive fuori da una sezione registrata è un tipo senza politica, cioè
 * esattamente la cosa che il contratto impedisce. Per questo la registrazione
 * passa sempre da core e rifiuta la sezione sconosciuta, invece di registrare
 * il tipo e lamentarsi dopo.
 *
 * @package Conformita_Core
 */

/**
 * Prove sullo scheletro di registrazione dei tipi di contenuto.
 */
class Conformita_Core_Tipi_Test extends WP_UnitTestCase {

	/**
	 * Sezione registrata su cui poggiano le prove del percorso felice.
	 *
	 * @var string
	 */
	const SEZIONE = 'sezione_di_prova';

	/**
	 * Identificativo di tipo usato dalle prove.
	 *
	 * @var string
	 */
	const TIPO = 'prova_atto';

	/**
	 * Registri azzerati prima di ogni prova.
	 *
	 * I tipi registrati in WordPress li smonta la suite di test fra una prova e
	 * l'altra; i registri di core vivono in memoria statica e vanno svuotati qui.
	 */
	public function set_up() {
		parent::set_up();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Sezioni::azzera();
	}

	/**
	 * Registra la sezione su cui appoggiare i tipi delle prove.
	 */
	private function registra_sezione() {
		conformita_core_registra_sezione(
			self::SEZIONE,
			array(
				'indicizzazione' => Conformita_Core_Politica::INDICIZZAZIONE_VIETATA,
				'scadenza'       => Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE,
			)
		);
	}

	/**
	 * Definizione minima e valida di un tipo.
	 *
	 * @param bool $rest Valore dichiarato per show_in_rest.
	 * @return array<string, mixed>
	 */
	private function definizione( $rest = true ) {
		return array(
			'sezione'      => self::SEZIONE,
			'show_in_rest' => $rest,
		);
	}

	/**
	 * C-70: senza dichiarare show_in_rest la registrazione è rifiutata.
	 *
	 * La riga dice "in entrambi i sensi": la scelta è obbligatoria, non il suo
	 * esito. Il rifiuto vale quindi per la chiave assente quanto per un valore
	 * che booleano non è, perché entrambi lasciano la decisione a un valore
	 * predefinito di WordPress invece che al componente.
	 */
	public function test_c70_registrazione_senza_dichiarare_show_in_rest() {
		$this->registra_sezione();

		$esito = conformita_core_registra_tipo(
			self::TIPO,
			array( 'sezione' => self::SEZIONE )
		);

		$this->assertWPError( $esito, 'Senza show_in_rest dichiarato la registrazione deve fallire.' );
		$this->assertSame( 'conformita_core_rest_non_dichiarata', $esito->get_error_code() );
		$this->assertStringContainsString( 'show_in_rest', $esito->get_error_message() );
		$this->assertFalse( conformita_core_tipo_registrato( self::TIPO ) );
		$this->assertFalse( post_type_exists( self::TIPO ), 'Il tipo non deve arrivare a WordPress.' );
	}

	/**
	 * C-70: un valore che booleano non è vale come dichiarazione mancante.
	 *
	 * È il modo in cui un valore predefinito rientra dalla finestra: una chiave
	 * presente con dentro qualcosa che WordPress interpreterebbe comunque, e la
	 * scelta finisce per farla la conversione di tipo invece del componente.
	 *
	 * @dataProvider valori_non_booleani
	 *
	 * @param mixed $valore Valore che non dichiara nessuna scelta.
	 */
	public function test_c70_valore_non_booleano_vale_come_dichiarazione_mancante( $valore ) {
		$this->registra_sezione();

		$esito = conformita_core_registra_tipo(
			self::TIPO,
			array(
				'sezione'      => self::SEZIONE,
				'show_in_rest' => $valore,
			)
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_rest_non_dichiarata', $esito->get_error_code() );
		$this->assertFalse( post_type_exists( self::TIPO ) );
	}

	/**
	 * Valori che non dichiarano nessuna scelta su REST.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function valori_non_booleani() {
		return array(
			'nullo'         => array( null ),
			'stringa vuota' => array( '' ),
			'stringa vero'  => array( 'true' ),
			'stringa falso' => array( 'false' ),
			'intero uno'    => array( 1 ),
			'intero zero'   => array( 0 ),
			'array vuoto'   => array( array() ),
		);
	}

	/**
	 * C-70 e C-77: dichiarare false è una scelta valida quanto dichiarare true.
	 *
	 * @dataProvider scelte_su_rest
	 *
	 * @param bool $rest Scelta dichiarata dal componente.
	 */
	public function test_c70_c77_entrambe_le_scelte_su_rest_sono_valide( $rest ) {
		$this->registra_sezione();

		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione( $rest ) ) );

		$oggetto = get_post_type_object( self::TIPO );

		$this->assertNotNull( $oggetto, 'Il tipo deve risultare registrato in WordPress.' );
		$this->assertSame(
			$rest,
			$oggetto->show_in_rest,
			'La scelta dichiarata deve arrivare a WordPress senza essere reinterpretata.'
		);
	}

	/**
	 * Le due scelte possibili su REST.
	 *
	 * @return array<string, array<int, bool>>
	 */
	public function scelte_su_rest() {
		return array(
			'REST attiva'     => array( true ),
			'REST non attiva' => array( false ),
		);
	}

	/**
	 * C-71: le capability del tipo sono sue e non coincidono con quelle native.
	 *
	 * L'unica capability in comune con i tipi nativi è `read`, che in WordPress
	 * non governa il tipo ma l'accesso all'amministrazione: lasciarla generica è
	 * la convenzione della piattaforma, e la prova la fissa perché una seconda
	 * capability in comune sarebbe un tipo governato dai permessi degli articoli.
	 */
	public function test_c71_capability_dedicate_al_tipo() {
		$this->registra_sezione();
		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione() ) );

		$capacita = conformita_core_capacita_tipo( self::TIPO );

		$this->assertIsArray( $capacita );
		$this->assertSame( array( 'prova_atto', 'prova_atto_multipli' ), Conformita_Core_Tipi::radici_capacita( self::TIPO ) );
		$this->assertSame( 'edit_prova_atto_multipli', $capacita['edit_posts'] );
		$this->assertSame( 'edit_prova_atto', $capacita['edit_post'] );
		$this->assertSame( 'publish_prova_atto_multipli', $capacita['publish_posts'] );

		$native = array_merge(
			array_values( (array) get_post_type_object( 'post' )->cap ),
			array_values( (array) get_post_type_object( 'page' )->cap )
		);

		$this->assertSame(
			array( 'read' ),
			array_values( array_unique( array_intersect( array_values( $capacita ), $native ) ) ),
			'Nessuna capability del tipo deve coincidere con quelle dei tipi nativi.'
		);
	}

	/**
	 * C-86: due identificativi diversi non possono produrre le stesse capability.
	 *
	 * La prima stesura derivava la radice sostituendo il trattino con il trattino
	 * basso. `atto-albo` e `atto_albo` sono due tipi distinti per WordPress, ma
	 * quella sostituzione li faceva arrivare alla stessa radice: chi era
	 * autorizzato sul primo risultava autorizzato anche sul secondo, e la
	 * garanzia della riga C-71 valeva solo verso i tipi nativi, non fra i tipi
	 * di core. Il difetto e' stato trovato in revisione, non dai test.
	 *
	 * La correzione toglie la sostituzione e restringe l'insieme dei caratteri
	 * ammessi: senza trasformazione la derivazione e' l'identita', quindi
	 * identificativi distinti danno radici distinte per costruzione. Questa prova
	 * fissa entrambe le meta' della proprieta'.
	 */
	public function test_c86_radici_capacita_non_collidono() {
		$this->assertSame(
			array( 'atto_albo', 'atto_albo_multipli' ),
			Conformita_Core_Tipi::radici_capacita( 'atto_albo' ),
			'La radice e l\'identificativo, senza trasformazioni.'
		);

		$this->registra_sezione();

		$esito = conformita_core_registra_tipo( 'atto-albo', $this->definizione() );

		$this->assertWPError( $esito, 'Un identificativo con il trattino non e ammesso.' );
		$this->assertSame( 'conformita_core_tipo_non_valido', $esito->get_error_code() );

		$this->assertTrue( conformita_core_registra_tipo( 'atto_albo', $this->definizione() ) );

		$radici = array();

		foreach ( array( 'atto_albo', self::TIPO ) as $tipo ) {
			if ( ! conformita_core_tipo_registrato( $tipo ) ) {
				$this->assertTrue( conformita_core_registra_tipo( $tipo, $this->definizione() ) );
			}

			$radici[] = Conformita_Core_Tipi::radici_capacita( $tipo );
		}

		$this->assertCount(
			count( $radici ),
			array_unique( array_map( 'wp_json_encode', $radici ) ),
			'Tipi distinti devono avere radici di capability distinte.'
		);
	}

	/**
	 * C-71: senza capability l'utente non vede il tipo e non ne modifica i dati;
	 * con le capability assegnate vede e modifica.
	 *
	 * La visibilità in amministrazione la decide WordPress su `edit_posts` del
	 * tipo: è la stessa capability che la prova interroga.
	 */
	public function test_c71_utente_senza_capability_non_vede_e_non_modifica() {
		$this->registra_sezione();
		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione() ) );

		$capacita = conformita_core_capacita_tipo( self::TIPO );
		$utente   = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$bozza = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'draft',
				'post_author' => $utente->ID,
			)
		);

		wp_set_current_user( $utente->ID );

		$this->assertFalse( current_user_can( $capacita['edit_posts'] ), 'Il tipo non deve comparire.' );
		$this->assertFalse( current_user_can( $capacita['create_posts'] ) );
		$this->assertFalse( current_user_can( 'edit_post', $bozza ), 'Nessuna modifica senza capability.' );
		$this->assertFalse( current_user_can( 'delete_post', $bozza ) );

		$utente->add_cap( $capacita['edit_posts'] );
		$utente->add_cap( $capacita['delete_posts'] );

		wp_set_current_user( 0 );
		wp_set_current_user( $utente->ID );

		$this->assertTrue( current_user_can( $capacita['edit_posts'] ) );
		$this->assertTrue( current_user_can( 'edit_post', $bozza ) );
		$this->assertTrue( current_user_can( 'delete_post', $bozza ) );
	}

	/**
	 * C-71: nemmeno l'amministratore vede il tipo finché le capability non gli
	 * sono assegnate.
	 *
	 * Core registra le capability e non le distribuisce a nessun ruolo: fra le
	 * due direzioni possibili è quella sicura, perché l'altra darebbe accesso a
	 * un ruolo che nessuno ha dichiarato. L'assegnazione è compito del
	 * componente che conosce la sua sezione. La prova fissa la scelta, così un
	 * domani non cambia per distrazione.
	 */
	public function test_c71_nessuna_capability_assegnata_automaticamente() {
		$this->registra_sezione();
		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione() ) );

		$capacita = conformita_core_capacita_tipo( self::TIPO );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse(
			current_user_can( $capacita['edit_posts'] ),
			'Core non assegna le capability del tipo a nessun ruolo, nemmeno a questo.'
		);
	}

	/**
	 * C-76: dentro una sezione non registrata il tipo non si registra.
	 *
	 * È la riga che chiude il contratto sul lato dei tipi: la politica sta sulla
	 * sezione, quindi un tipo fuori da una sezione registrata è un tipo senza
	 * politica.
	 */
	public function test_c76_sezione_non_registrata() {
		$esito = conformita_core_registra_tipo( self::TIPO, $this->definizione() );

		$this->assertWPError( $esito, 'Nessun tipo vivo fuori da una sezione registrata.' );
		$this->assertSame( 'conformita_core_tipo_senza_sezione', $esito->get_error_code() );
		$this->assertStringContainsString( self::SEZIONE, $esito->get_error_message() );
		$this->assertFalse( conformita_core_tipo_registrato( self::TIPO ) );
		$this->assertFalse( post_type_exists( self::TIPO ), 'Il tipo non deve arrivare a WordPress.' );
	}

	/**
	 * C-76: sezione non dichiarata affatto, stesso rifiuto.
	 *
	 * @dataProvider sezioni_non_dichiarate
	 *
	 * @param array<string, mixed> $definizione Definizione senza sezione utile.
	 */
	public function test_c76_sezione_non_dichiarata( array $definizione ) {
		$this->registra_sezione();

		$esito = conformita_core_registra_tipo( self::TIPO, $definizione );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_senza_sezione', $esito->get_error_code() );
		$this->assertFalse( post_type_exists( self::TIPO ) );
	}

	/**
	 * Definizioni che non dichiarano nessuna sezione utile.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function sezioni_non_dichiarate() {
		return array(
			'chiave assente' => array( array( 'show_in_rest' => true ) ),
			'stringa vuota'  => array(
				array(
					'sezione'      => '',
					'show_in_rest' => true,
				),
			),
			'soli spazi'     => array(
				array(
					'sezione'      => '   ',
					'show_in_rest' => true,
				),
			),
		);
	}

	/**
	 * C-77: dentro una sezione registrata il tipo si registra, e da lì si
	 * rileggono sezione e politica.
	 */
	public function test_c77_registrazione_dentro_una_sezione_registrata() {
		$this->registra_sezione();

		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione() ) );
		$this->assertTrue( conformita_core_tipo_registrato( self::TIPO ) );
		$this->assertTrue( post_type_exists( self::TIPO ) );
		$this->assertSame( array( self::TIPO ), conformita_core_tipi_registrati() );

		$this->assertSame( self::SEZIONE, conformita_core_sezione_del_tipo( self::TIPO ) );

		$politica = conformita_core_politica_tipo( self::TIPO );

		$this->assertInstanceOf( Conformita_Core_Politica::class, $politica );
		$this->assertSame( Conformita_Core_Politica::INDICIZZAZIONE_VIETATA, $politica->indicizzazione() );
		$this->assertSame( Conformita_Core_Politica::SCADENZA_IRRAGGIUNGIBILE, $politica->scadenza() );
	}

	/**
	 * C-77: due tipi in due sezioni con politiche opposte leggono ciascuno la
	 * politica della propria sezione.
	 */
	public function test_c77_tipi_in_sezioni_con_politiche_opposte() {
		$this->registra_sezione();

		conformita_core_registra_sezione(
			'sezione_aperta',
			array(
				'indicizzazione' => Conformita_Core_Politica::INDICIZZAZIONE_CONSENTITA,
				'scadenza'       => Conformita_Core_Politica::SCADENZA_ARCHIVIO,
			)
		);

		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione() ) );
		$this->assertTrue(
			conformita_core_registra_tipo(
				'prova_scheda',
				array(
					'sezione'      => 'sezione_aperta',
					'show_in_rest' => true,
				)
			)
		);

		$this->assertFalse( conformita_core_politica_tipo( self::TIPO )->consente_indicizzazione() );
		$this->assertTrue( conformita_core_politica_tipo( 'prova_scheda' )->consente_indicizzazione() );
	}

	/**
	 * C-77: gli argomenti del componente arrivano a WordPress.
	 *
	 * Core impone le capability e la scelta su REST, e su tutto il resto non ha
	 * niente da dire: etichette, supporti e riscrittura restano del componente.
	 */
	public function test_c77_argomenti_del_componente_conservati() {
		$this->registra_sezione();

		$this->assertTrue(
			conformita_core_registra_tipo(
				self::TIPO,
				array(
					'sezione'      => self::SEZIONE,
					'show_in_rest' => false,
					'argomenti'    => array(
						'public'   => true,
						'supports' => array( 'title', 'editor' ),
					),
				)
			)
		);

		$oggetto = get_post_type_object( self::TIPO );

		$this->assertTrue( $oggetto->public );
		$this->assertFalse( $oggetto->show_in_rest );
		$this->assertTrue( $oggetto->map_meta_cap, 'Le capability dedicate hanno senso solo con la mappatura attiva.' );
	}

	/**
	 * C-78: identificativo che WordPress non accetterebbe, registrazione
	 * rifiutata prima di arrivare a WordPress.
	 *
	 * @dataProvider identificativi_non_validi
	 *
	 * @param string $identificativo Identificativo non utilizzabile come tipo.
	 */
	public function test_c78_identificativo_non_valido( $identificativo ) {
		$this->registra_sezione();

		$esito = conformita_core_registra_tipo( $identificativo, $this->definizione() );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_non_valido', $esito->get_error_code() );
		$this->assertSame( array(), conformita_core_tipi_registrati() );
	}

	/**
	 * Identificativi che WordPress non accetta come nome di tipo.
	 *
	 * Il limite di venti caratteri e l'insieme di caratteri ammessi sono vincoli
	 * della piattaforma: core li verifica prima di chiamare WordPress, così il
	 * rifiuto è un errore leggibile e non una segnalazione di uso scorretto.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function identificativi_non_validi() {
		return array(
			'stringa vuota'       => array( '' ),
			'soli spazi'          => array( '   ' ),
			'oltre venti lettere' => array( 'prova_tipo_lunghissimo' ),
			'spazi interni'       => array( 'prova atto' ),
			'lettere maiuscole'   => array( 'Prova_Atto' ),
			'trattino'            => array( 'prova-atto' ),
		);
	}

	/**
	 * C-78: lo stesso identificativo non si registra due volte, e la seconda
	 * definizione non tocca la prima.
	 */
	public function test_c78_identificativo_gia_registrato_da_core() {
		$this->registra_sezione();

		$this->assertTrue( conformita_core_registra_tipo( self::TIPO, $this->definizione( true ) ) );

		$esito = conformita_core_registra_tipo( self::TIPO, $this->definizione( false ) );

		$this->assertWPError( $esito, 'Nessuna sovrascrittura silenziosa.' );
		$this->assertSame( 'conformita_core_tipo_duplicato', $esito->get_error_code() );
		$this->assertStringContainsString( self::TIPO, $esito->get_error_message() );
		$this->assertTrue(
			get_post_type_object( self::TIPO )->show_in_rest,
			'La prima registrazione deve restare quella valida.'
		);
	}

	/**
	 * C-78: un identificativo già in uso in WordPress non si sovrascrive.
	 *
	 * Senza questo controllo `register_post_type` rimpiazzerebbe il tipo
	 * esistente senza dire niente, e il tipo nativo si ritroverebbe con le
	 * capability di un altro.
	 */
	public function test_c78_identificativo_gia_in_uso_in_wordpress() {
		$this->registra_sezione();

		$prima = get_post_type_object( 'post' )->cap->edit_posts;
		$esito = conformita_core_registra_tipo( 'post', $this->definizione() );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_gia_in_uso', $esito->get_error_code() );
		$this->assertSame(
			$prima,
			get_post_type_object( 'post' )->cap->edit_posts,
			'Il tipo esistente non deve essere toccato.'
		);
		$this->assertFalse( conformita_core_tipo_registrato( 'post' ) );
	}

	/**
	 * C-79: il componente non dichiara le capability del proprio tipo.
	 *
	 * Le capability dedicate sono la garanzia della riga C-71: se il componente
	 * potesse riscriverle, potrebbe anche riportarle a quelle degli articoli, e
	 * la garanzia varrebbe finché nessuno prova a toglierla. Lo stesso vale per
	 * `show_in_rest` passato di nascosto fra gli argomenti, che aggirerebbe la
	 * dichiarazione esplicita della riga C-70.
	 *
	 * @dataProvider argomenti_riservati
	 *
	 * @param string $chiave  Argomento che core non delega.
	 * @param mixed  $valore  Valore che il componente proverebbe a imporre.
	 */
	public function test_c79_argomenti_riservati_a_core( $chiave, $valore ) {
		$this->registra_sezione();

		$definizione              = $this->definizione();
		$definizione['argomenti'] = array( $chiave => $valore );

		$esito = conformita_core_registra_tipo( self::TIPO, $definizione );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_argomenti_riservati', $esito->get_error_code() );
		$this->assertStringContainsString( $chiave, $esito->get_error_message() );
		$this->assertFalse( post_type_exists( self::TIPO ) );
	}

	/**
	 * Argomenti che core impone e non accetta dal componente.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function argomenti_riservati() {
		return array(
			'capabilities'    => array( 'capabilities', array( 'edit_posts' => 'edit_posts' ) ),
			'capability_type' => array( 'capability_type', 'post' ),
			'map_meta_cap'    => array( 'map_meta_cap', false ),
			'show_in_rest'    => array( 'show_in_rest', true ),
		);
	}

	/**
	 * C-79: argomenti dichiarati in una forma che non è un elenco di argomenti.
	 */
	public function test_c79_argomenti_non_validi() {
		$this->registra_sezione();

		$definizione              = $this->definizione();
		$definizione['argomenti'] = 'public';

		$esito = conformita_core_registra_tipo( self::TIPO, $definizione );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_argomenti_non_validi', $esito->get_error_code() );
		$this->assertFalse( post_type_exists( self::TIPO ) );
	}

	/**
	 * La lettura di un tipo mai registrato è un errore esplicito, non un valore
	 * di comodo.
	 *
	 * @dataProvider letture_del_tipo
	 *
	 * @param string $funzione Funzione di lettura dell'API.
	 */
	public function test_lettura_di_tipo_mai_registrato( $funzione ) {
		$this->assertFalse( conformita_core_tipo_registrato( 'prova_mai_vista' ) );

		$esito = call_user_func( $funzione, 'prova_mai_vista' );

		$this->assertWPError( $esito, 'Nessun valore di comodo per un tipo sconosciuto.' );
		$this->assertSame( 'conformita_core_tipo_sconosciuto', $esito->get_error_code() );
	}

	/**
	 * Le letture dell'API che partono dall'identificativo del tipo.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function letture_del_tipo() {
		return array(
			'sezione'  => array( 'conformita_core_sezione_del_tipo' ),
			'politica' => array( 'conformita_core_politica_tipo' ),
			'capacita' => array( 'conformita_core_capacita_tipo' ),
		);
	}
}
