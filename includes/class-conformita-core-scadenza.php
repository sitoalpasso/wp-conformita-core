<?php
/**
 * Contratto del dato di fine pubblicazione.
 *
 * Questa classe non nasconde niente da sola: definisce **quando** un contenuto è
 * scaduto, e lo definisce senza ambiguità. Dove quella decisione viene applicata
 * lo stabilisce `Conformita_Core_Filtro_Scadenza`, che senza un istante di
 * scadenza definito qui non avrebbe niente di preciso da applicare.
 *
 * Tre scelte, tutte imposte da core e non negoziabili dal componente, per la
 * stessa ragione per cui lo sono le capability: il filtro deve poter leggere
 * senza chiedere permesso al componente, e due componenti non devono potersi
 * pestare i piedi.
 *
 * **La chiave del metadato è una sola per tutti i tipi.** Se fosse
 * configurabile, il filtro dovrebbe costruire la propria interrogazione a
 * partire dall'elenco dei tipi registrati, e un componente potrebbe indicare una
 * chiave che scrive anche per altri scopi.
 *
 * **Il valore è una data civile, non un istante.** La data civile è il dato di
 * dominio appropriato perché il termine di legge è espresso in giorni: un atto
 * si pubblica per quindici giorni. La scadenza si calcola come mezzanotte
 * civile del giorno successivo nel fuso del sito, il che evita di trattare
 * quindici giorni come un numero fisso di secondi: **durante i cambi d'ora un
 * giorno civile dura 23 o 25 ore**, quindi un calcolo a secondi porterebbe la
 * scadenza a un'ora diversa dalla mezzanotte.
 *
 * Correzione di una spiegazione sbagliata scritta qui in prima stesura: un
 * istante già memorizzato **non si sposta** dopo un cambio d'ora. L'errore non
 * sta nel conservare un istante, sta nel calcolarlo sommando una quantità fissa
 * di secondi a una data.
 *
 * **La data di fine è inclusiva.** Un atto con fine il 9 settembre resta
 * pubblico per tutto il 9, e scade alla mezzanotte del 10 nel fuso del sito. Un
 * confronto ingenuo fra la data e "adesso" lo farebbe sparire un giorno prima,
 * e il difetto si vedrebbe solo quando un ente contesta una pubblicazione durata
 * quattordici giorni invece di quindici.
 *
 * Righe di collaudo C-23, C-24, C-87, C-88, C-99, C-100, C-114.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Il dato di fine pubblicazione: dove sta, come è fatto, quando scade.
 */
final class Conformita_Core_Scadenza {

	/**
	 * Chiave del metadato che contiene la fine della pubblicazione.
	 *
	 * Il trattino basso iniziale la tiene fuori dall'interfaccia dei campi
	 * personalizzati: la scrittura passa dall'API, che valida.
	 */
	const CHIAVE = '_conformita_core_fine_pubblicazione';

	/**
	 * Formato canonico del valore.
	 *
	 * Il punto esclamativo azzera i campi dell'ora non presenti nel formato:
	 * senza, `createFromFormat` userebbe l'ora corrente e due letture nello
	 * stesso giorno darebbero istanti diversi.
	 */
	const FORMATO = '!Y-m-d';

	/**
	 * Orologio fissato, usato solo dalle prove.
	 *
	 * @internal Non fa parte dell'interfaccia pubblica del componente. Esiste
	 *           perché l'alternativa sarebbe un parametro `$adesso` sulle
	 *           funzioni pubbliche, che qualcuno prima o poi userebbe per far
	 *           sembrare non scaduto qualcosa che lo è.
	 *
	 * @var DateTimeImmutable|null
	 */
	private static $orologio = null;

	/**
	 * Fissa l'orologio interno.
	 *
	 * @internal Solo per le prove.
	 *
	 * @param DateTimeImmutable $istante Istante da considerare corrente.
	 */
	public static function fissa_orologio( DateTimeImmutable $istante ) {
		self::$orologio = $istante;
	}

	/**
	 * Rimette l'orologio interno sull'ora vera.
	 *
	 * @internal Solo per le prove.
	 */
	public static function azzera_orologio() {
		self::$orologio = null;
	}

	/**
	 * L'istante corrente nel fuso del sito.
	 *
	 * @return DateTimeImmutable
	 */
	private static function adesso() {
		if ( self::$orologio instanceof DateTimeImmutable ) {
			return self::$orologio;
		}

		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Il giorno civile corrente nel fuso del sito, nel formato del metadato.
	 *
	 * Serve al primo strato del filtro, che deve esprimere la scadenza come
	 * condizione sulla banca dati e non può confrontare istanti. Le due forme
	 * sono equivalenti: un contenuto non è scaduto finché la sua data di fine
	 * non è anteriore al giorno corrente, perché la data di fine è inclusiva.
	 *
	 * Legge lo stesso orologio di `scaduto()`, quindi i due strati del filtro non
	 * possono trovarsi in disaccordo per una differenza di lettura dell'ora.
	 *
	 * @return string Data nel formato AAAA-MM-GG.
	 */
	public static function giorno_corrente() {
		return self::adesso()->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/**
	 * La chiave del metadato.
	 *
	 * @return string
	 */
	public static function chiave() {
		return self::CHIAVE;
	}

	/**
	 * Il valore rispetta il formato ed è una data che esiste.
	 *
	 * I due errori sono distinti perché sono due difetti diversi: il primo è di
	 * chi scrive il codice del componente, il secondo di chi inserisce il dato.
	 *
	 * @param mixed $valore Valore da validare.
	 * @return true|WP_Error
	 */
	public static function valida( $valore ) {
		if ( ! is_string( $valore ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valore ) ) {
			return new WP_Error(
				'conformita_core_data_formato',
				__( 'Fine pubblicazione: il formato atteso è AAAA-MM-GG, con quattro cifre per l\'anno e due per mese e giorno.', 'conformita-core' )
			);
		}

		$data = DateTimeImmutable::createFromFormat( self::FORMATO, $valore, wp_timezone() );

		if ( false === $data || $data->format( 'Y-m-d' ) !== $valore ) {
			return new WP_Error(
				'conformita_core_data_inesistente',
				sprintf(
					/* translators: %s: la data indicata. */
					__( 'Fine pubblicazione: la data %s rispetta il formato ma non esiste nel calendario.', 'conformita-core' ),
					$valore
				)
			);
		}

		return true;
	}

	/**
	 * Scrive la fine della pubblicazione su un contenuto di tipo gestito.
	 *
	 * Idempotente. `update_post_meta` restituisce falso sia quando fallisce sia
	 * quando il valore era già identico: distinguere i due casi rileggendo è
	 * obbligatorio, altrimenti il secondo salvataggio della stessa data
	 * diventerebbe un errore.
	 *
	 * **Ripara i duplicati, e verifica di esserci riuscita.** Se il contenuto ha
	 * più valori per la chiave li rimuove e ne scrive uno solo. Sia la rimozione
	 * sia l'esito finale si controllano: la rimozione può fallire, e la scrittura
	 * può riferire successo avendo aggiornato più righe invece di lasciarne una.
	 * In entrambi i casi questa funzione restituisce errore, perché dichiarare un
	 * successo che non c'è stato è il difetto peggiore dei due: chi ha chiamato
	 * crederebbe di aver riparato un contenuto che resta anomalo, e quindi
	 * invisibile, e nessuno andrebbe a verificare. Riga C-114.
	 *
	 * @param int    $post_id Identificativo del contenuto.
	 * @param string $data    Data di fine, formato AAAA-MM-GG.
	 * @return true|WP_Error Vero se al termine il contenuto ha esattamente quella
	 *                       data e nessun'altra, errore altrimenti.
	 */
	public static function imposta( $post_id, $data ) {
		$post_id = (int) $post_id;
		$tipo    = get_post_type( $post_id );

		if ( false === $tipo || ! Conformita_Core_Tipi::registrato( $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_non_gestito',
				__( 'Fine pubblicazione: il contenuto non è di un tipo registrato da questo componente.', 'conformita-core' )
			);
		}

		$esito = self::valida( $data );

		if ( is_wp_error( $esito ) ) {
			return $esito;
		}

		$valori = get_post_meta( $post_id, self::CHIAVE, false );
		$valori = is_array( $valori ) ? $valori : array();

		/*
		 * WordPress ammette più righe di metadato con la stessa chiave. Scrivere
		 * senza guardarle lascerebbe il contenuto con più valori, e la scrittura
		 * normale li aggiornerebbe tutti allo stesso valore senza ridurne il
		 * numero: resterebbe un'anomalia, solo meno visibile. Qui si ripara,
		 * perché questa è la strada per cui il dato entra correttamente.
		 */
		if ( count( $valori ) > 1 ) {
			if ( ! delete_post_meta( $post_id, self::CHIAVE ) ) {
				return new WP_Error(
					'conformita_core_dato_non_riparato',
					__( 'Fine pubblicazione: il contenuto ha più di una data registrata e non è stato possibile rimuoverle. Il contenuto resta anomalo, quindi scaduto.', 'conformita-core' )
				);
			}

			$valori = array();
		}

		if ( array( $data ) === $valori ) {
			return true;
		}

		if ( ! update_post_meta( $post_id, self::CHIAVE, $data ) ) {
			return new WP_Error(
				'conformita_core_data_non_scritta',
				__( 'Fine pubblicazione: il valore non è stato scritto.', 'conformita-core' )
			);
		}

		/*
		 * Rilettura di controllo. La scrittura di WordPress può riferire successo
		 * avendo aggiornato più righe invece di lasciarne una, e in quel caso
		 * questa funzione restituirebbe vero mentre la lettura continua a dare
		 * anomalia: chi ha chiamato crederebbe di aver riparato un contenuto che
		 * resta invisibile. Fra le due, dichiarare un successo che non c'è è il
		 * difetto peggiore, perché nessuno lo va a verificare.
		 */
		$scritti = get_post_meta( $post_id, self::CHIAVE, false );

		if ( array( $data ) !== ( is_array( $scritti ) ? $scritti : array() ) ) {
			return new WP_Error(
				'conformita_core_dato_non_riparato',
				__( 'Fine pubblicazione: dopo la scrittura il contenuto non ha esattamente una data di fine, quindi resta anomalo e scaduto.', 'conformita-core' )
			);
		}

		return true;
	}

	/**
	 * La fine della pubblicazione registrata sul contenuto.
	 *
	 * **Più di un valore è un'anomalia, non una scelta fra due.** WordPress ammette
	 * più righe di metadato con la stessa chiave, e la lettura normale ne
	 * restituisce una sola, la prima. Con due valori diversi, per esempio uno
	 * corrotto e uno futuro valido, questa funzione e una condizione scritta
	 * sulla banca dati potrebbero decidere in modo opposto, e fra le due
	 * vincerebbe la più permissiva. Un valore solo per chiave è il contratto:
	 * i duplicati danno errore, e l'errore rende scaduto. Riga C-114.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return string|WP_Error La data, stringa vuota se assente, errore se il
	 *                         tipo non è gestito o se le date registrate sono
	 *                         più di una.
	 */
	public static function fine( $post_id ) {
		$post_id = (int) $post_id;
		$tipo    = get_post_type( $post_id );

		if ( false === $tipo || ! Conformita_Core_Tipi::registrato( $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_non_gestito',
				__( 'Fine pubblicazione: il contenuto non è di un tipo registrato da questo componente.', 'conformita-core' )
			);
		}

		$valori = get_post_meta( $post_id, self::CHIAVE, false );
		$valori = is_array( $valori ) ? $valori : array();

		if ( count( $valori ) > 1 ) {
			return new WP_Error(
				'conformita_core_dato_duplicato',
				__( 'Fine pubblicazione: il contenuto ha più di una data di fine registrata, e non è possibile stabilire quale valga.', 'conformita-core' )
			);
		}

		if ( empty( $valori ) ) {
			return '';
		}

		$valore = reset( $valori );

		return is_string( $valore ) ? $valore : '';
	}

	/**
	 * L'istante in cui il contenuto smette di essere pubblicabile.
	 *
	 * Mezzanotte del giorno successivo alla data di fine, nel fuso del sito.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return DateTimeImmutable|WP_Error
	 */
	public static function istante( $post_id ) {
		$valore = self::fine( $post_id );

		if ( is_wp_error( $valore ) ) {
			return $valore;
		}

		if ( '' === $valore ) {
			return new WP_Error(
				'conformita_core_data_assente',
				__( 'Fine pubblicazione: il contenuto non ha una data di fine.', 'conformita-core' )
			);
		}

		$esito = self::valida( $valore );

		if ( is_wp_error( $esito ) ) {
			return new WP_Error(
				'conformita_core_data_non_valida',
				__( 'Fine pubblicazione: il valore registrato sul contenuto non è una data valida.', 'conformita-core' )
			);
		}

		$data = DateTimeImmutable::createFromFormat( self::FORMATO, $valore, wp_timezone() );

		return $data->modify( '+1 day' );
	}

	/**
	 * Il contenuto è scaduto.
	 *
	 * **Non restituisce mai un errore.** È chiamata nel percorso di lettura, e
	 * una funzione che può fallire lì prima o poi lascia passare qualcosa mentre
	 * qualcuno gestisce l'eccezione. Data assente, valore corrotto o tipo non
	 * gestito danno tutti scaduto: si sbaglia nella direzione sicura.
	 *
	 * Il confronto è maggiore o uguale. Nell'istante esatto della scadenza il
	 * contenuto è già scaduto: fra le due direzioni possibili questa sbaglia
	 * rendendo invisibile un attimo prima invece che visibile un attimo dopo.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return bool
	 */
	public static function scaduto( $post_id ) {
		$istante = self::istante( $post_id );

		if ( is_wp_error( $istante ) ) {
			return true;
		}

		return self::adesso() >= $istante;
	}
}
