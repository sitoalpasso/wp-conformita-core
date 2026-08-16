<?php
/**
 * La politica dichiarata da un componente per una sezione.
 *
 * Core non ha politiche proprie e non ne inventa: qui c'è solo l'insieme chiuso
 * dei valori ammessi e il rifiuto di tutto ciò che non li dichiara. Le due
 * politiche sono deliberatamente opposte fra i componenti che useranno questo
 * meccanismo, e per questo non esiste nessun valore predefinito: il default
 * corretto per uno sarebbe la violazione dell'altro.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valore immutabile che descrive la politica di una sezione.
 */
final class Conformita_Core_Politica {

	/**
	 * Indicizzazione consentita dai motori di ricerca.
	 *
	 * @var string
	 */
	const INDICIZZAZIONE_CONSENTITA = 'consentita';

	/**
	 * Indicizzazione vietata dai motori di ricerca.
	 *
	 * @var string
	 */
	const INDICIZZAZIONE_VIETATA = 'vietata';

	/**
	 * Il contenuto scaduto non è più raggiungibile su nessun percorso pubblico.
	 *
	 * @var string
	 */
	const SCADENZA_IRRAGGIUNGIBILE = 'irraggiungibile';

	/**
	 * Il contenuto scaduto esce dalla sezione e resta consultabile in archivio.
	 *
	 * @var string
	 */
	const SCADENZA_ARCHIVIO = 'archivio';

	/**
	 * Politica di indicizzazione dichiarata.
	 *
	 * @var string
	 */
	private $indicizzazione;

	/**
	 * Politica di scadenza dichiarata.
	 *
	 * @var string
	 */
	private $scadenza;

	/**
	 * Costruttore privato: si passa sempre dalla validazione di crea().
	 *
	 * @param string $indicizzazione Politica di indicizzazione.
	 * @param string $scadenza       Politica di scadenza.
	 */
	private function __construct( $indicizzazione, $scadenza ) {
		$this->indicizzazione = $indicizzazione;
		$this->scadenza       = $scadenza;
	}

	/**
	 * Le politiche che una dichiarazione deve contenere.
	 *
	 * @return array<int, string>
	 */
	public static function chiavi_richieste() {
		return array( 'indicizzazione', 'scadenza' );
	}

	/**
	 * I valori ammessi per una politica.
	 *
	 * @param string $chiave Nome della politica.
	 * @return array<int, string> Insieme chiuso dei valori ammessi.
	 */
	public static function valori_ammessi( $chiave ) {
		$insiemi = array(
			'indicizzazione' => array( self::INDICIZZAZIONE_CONSENTITA, self::INDICIZZAZIONE_VIETATA ),
			'scadenza'       => array( self::SCADENZA_IRRAGGIUNGIBILE, self::SCADENZA_ARCHIVIO ),
		);

		return isset( $insiemi[ $chiave ] ) ? $insiemi[ $chiave ] : array();
	}

	/**
	 * Costruisce la politica da una dichiarazione, o spiega perché non si può.
	 *
	 * @param array $dichiarazione Politiche dichiarate dal componente.
	 * @return Conformita_Core_Politica|WP_Error Politica valida oppure errore.
	 */
	public static function crea( array $dichiarazione ) {
		$mancanti = array();

		foreach ( self::chiavi_richieste() as $chiave ) {
			$valore = isset( $dichiarazione[ $chiave ] ) ? $dichiarazione[ $chiave ] : null;

			if ( ! is_string( $valore ) || '' === trim( $valore ) ) {
				$mancanti[] = $chiave;
			}
		}

		if ( ! empty( $mancanti ) ) {
			return new WP_Error(
				'conformita_core_politica_mancante',
				sprintf(
					/* translators: %s: elenco delle politiche non dichiarate, separate da virgola. */
					__( 'Politica non dichiarata: %s. Ogni politica va dichiarata esplicitamente, non esistono valori predefiniti.', 'conformita-core' ),
					implode( ', ', $mancanti )
				),
				array( 'mancanti' => $mancanti )
			);
		}

		$valori = array();

		foreach ( self::chiavi_richieste() as $chiave ) {
			$valore  = trim( $dichiarazione[ $chiave ] );
			$ammessi = self::valori_ammessi( $chiave );

			if ( ! in_array( $valore, $ammessi, true ) ) {
				return new WP_Error(
					'conformita_core_politica_non_valida',
					sprintf(
						/* translators: 1: nome della politica, 2: valore dichiarato, 3: elenco dei valori ammessi. */
						__( 'Politica %1$s: valore %2$s non ammesso. Valori ammessi: %3$s.', 'conformita-core' ),
						$chiave,
						$valore,
						implode( ', ', $ammessi )
					),
					array(
						'politica' => $chiave,
						'ammessi'  => $ammessi,
					)
				);
			}

			$valori[ $chiave ] = $valore;
		}

		return new self( $valori['indicizzazione'], $valori['scadenza'] );
	}

	/**
	 * Politica di indicizzazione dichiarata.
	 *
	 * @return string
	 */
	public function indicizzazione() {
		return $this->indicizzazione;
	}

	/**
	 * Politica di scadenza dichiarata.
	 *
	 * @return string
	 */
	public function scadenza() {
		return $this->scadenza;
	}

	/**
	 * La sezione consente l'indicizzazione.
	 *
	 * @return bool
	 */
	public function consente_indicizzazione() {
		return self::INDICIZZAZIONE_CONSENTITA === $this->indicizzazione;
	}

	/**
	 * La politica come dichiarazione, nella stessa forma in cui è arrivata.
	 *
	 * @return array<string, string>
	 */
	public function come_array() {
		return array(
			'indicizzazione' => $this->indicizzazione,
			'scadenza'       => $this->scadenza,
		);
	}
}
