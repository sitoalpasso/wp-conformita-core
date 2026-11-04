<?php
/**
 * Il compito che lavora sui contenuti scaduti.
 *
 * La visibilita' non dipende da questo compito: il filtro di lettura rende un
 * contenuto scaduto irraggiungibile dal primo istante, anche se il compito non
 * gira mai. Il compito fa il lavoro che il filtro non fa: porta il contenuto
 * nello stato che il componente vuole per uno scaduto, cosi' che l'archivio e
 * il registro delle modifiche dicano quello che e' successo.
 *
 * **Core non decide che cosa si fa.** Il componente registra, per una sua
 * sezione, da quali stati un contenuto scaduto va preso e l'azione da fare su
 * ciascuno. Senza registrazione il compito non tocca niente. L'azione e' del
 * componente, che conosce i suoi passaggi di stato; il cambio di stato fatto
 * con WordPress lascia la sua voce nel registro delle modifiche da se'.
 *
 * **Gira da fuori.** E' un compito di WordPress programmato ogni ora, e gira
 * quando qualcuno chiama `wp-cron.php`: il cron di sistema del server, o un
 * servizio esterno, con il cron interno di WordPress spento. Il cron interno da
 * solo parte solo con il traffico del sito.
 *
 * **Una alla volta.** Un'esecuzione tiene un lucchetto comune; una seconda,
 * nello stesso momento, rinuncia subito. Ogni esecuzione lavora a lotti e si
 * ferma a un massimo di contenuti per sezione: la successiva riparte da dove
 * si era fermata, cosi' contenuti che non si risolvono mai non tengono fermi
 * quelli dopo. Un contenuto la cui azione fallisce non ferma gli altri.
 *
 * **Il battito.** Ogni esecuzione arrivata in fondo scrive l'istante in cui e'
 * finita e quanti contenuti ha toccato: e' la traccia che dice se il compito
 * gira, per chi sorveglia il sito.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compito alla scadenza, battito compreso.
 */
final class Conformita_Core_Compito_Scadenza {

	/**
	 * Il nome del compito di WordPress.
	 */
	const COMPITO = 'conformita_core_compito_scadenza';

	/**
	 * L'opzione del battito.
	 */
	const BATTITO = 'conformita_core_battito_scadenza';

	/**
	 * L'opzione con il punto da cui riparte ogni sezione.
	 */
	const SEGNAPOSTO = 'conformita_core_compito_scadenza_segnaposto';

	/**
	 * Il lucchetto dell'esecuzione, distinto per sito.
	 */
	const LUCCHETTO = 'conformita_compito_scadenza';

	/**
	 * Contenuti letti per volta.
	 */
	const LOTTO = 50;

	/**
	 * Contenuti al massimo per sezione in un'esecuzione.
	 */
	const MASSIMO = 500;

	/**
	 * Le azioni registrate, per sezione.
	 *
	 * @var array<string, array{stati: array<int, string>, azione: callable}>
	 */
	private static $azioni = array();

	/**
	 * Registra l'azione da fare sui contenuti scaduti di una sezione.
	 *
	 * @param string   $sezione Una sezione registrata.
	 * @param array    $stati   Gli stati da cui un contenuto scaduto si prende: almeno uno.
	 * @param callable $azione  Riceve l'identificativo del contenuto; vero se fatto, errore altrimenti.
	 * @return true|WP_Error
	 */
	public static function registra( $sezione, $stati, $azione ) {
		if ( ! is_string( $sezione ) || ! Conformita_Core_Sezioni::registrata( $sezione ) ) {
			return self::errore( 'conformita_core_compito_sezione', __( 'Compito alla scadenza: la sezione non è registrata.', 'conformita-core' ) );
		}

		if ( isset( self::$azioni[ $sezione ] ) ) {
			return self::errore( 'conformita_core_compito_duplicato', __( 'Compito alla scadenza: la sezione ha già un\'azione registrata, che non si sovrascrive.', 'conformita-core' ) );
		}

		if ( ! is_array( $stati ) || array() === $stati ) {
			return self::errore( 'conformita_core_compito_stati', __( 'Compito alla scadenza: servono gli stati da cui prendere i contenuti scaduti. Non c\'è un elenco predefinito.', 'conformita-core' ) );
		}

		foreach ( $stati as $stato ) {
			if ( ! is_string( $stato ) || 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/', $stato ) ) {
				return self::errore( 'conformita_core_compito_stati', __( 'Compito alla scadenza: uno stato non è valido.', 'conformita-core' ) );
			}
		}

		if ( ! is_callable( $azione ) ) {
			return self::errore( 'conformita_core_compito_azione', __( 'Compito alla scadenza: l\'azione non si può chiamare.', 'conformita-core' ) );
		}

		self::$azioni[ $sezione ] = array(
			'stati'  => array_values( array_unique( $stati ) ),
			'azione' => $azione,
		);

		return true;
	}

	/**
	 * Esegue il compito: per ogni sezione con un'azione, i contenuti scaduti
	 * negli stati dichiarati.
	 *
	 * @return array{contenuti: int, falliti: int, completo: bool}|WP_Error
	 */
	public static function esegui() {
		$lucchetto = self::nome_lucchetto();

		if ( ! Conformita_Core_Lucchetti::prendi( $lucchetto, 0 ) ) {
			return self::errore( 'conformita_core_compito_occupato', __( 'Compito alla scadenza: un\'altra esecuzione è in corso.', 'conformita-core' ) );
		}

		try {
			$fatti      = 0;
			$falliti    = 0;
			$completo   = true;
			$segnaposti = get_option( self::SEGNAPOSTO, array() );
			$segnaposti = is_array( $segnaposti ) ? $segnaposti : array();

			foreach ( self::$azioni as $sezione => $azione ) {
				/*
				 * Ogni sezione ha il suo massimo, e riparte da dove si era
				 * fermata: dopo l'ultimo contenuto provato fino in fondo, poi da
				 * capo fino a li'. Cosi' contenuti che non si risolvono mai non
				 * tengono fermi quelli dopo, ne' una sezione le altre.
				 */
				$partenza = isset( $segnaposti[ $sezione ] ) ? max( 0, (int) $segnaposti[ $sezione ] ) : 0;
				$giri     = array( array( $partenza, null ) );
				$provati  = 0;
				$ultimo   = 0;

				if ( $partenza > 0 ) {
					$giri[] = array( 0, $partenza );
				}

				foreach ( $giri as $giro ) {
					$dopo = $giro[0];

					while ( $provati < self::MASSIMO ) {
						$candidati = self::candidati( $sezione, $azione['stati'], $dopo, $giro[1] );

						if ( is_wp_error( $candidati ) ) {
							return $candidati;
						}

						if ( array() === $candidati ) {
							break;
						}

						foreach ( $candidati as $id ) {
							if ( $provati >= self::MASSIMO ) {
								break;
							}

							$dopo   = $id;
							$ultimo = $id;
							++$provati;

							$da_toccare = self::da_toccare( $id, $azione['stati'] );

							// Una lettura fallita non e' un dato da saltare: l'esecuzione si ferma senza battito, e la prossima riparte dallo stesso punto.
							if ( is_wp_error( $da_toccare ) ) {
								return $da_toccare;
							}

							if ( ! $da_toccare ) {
								continue;
							}

							try {
								$esito = call_user_func( $azione['azione'], $id );
							} catch ( Throwable $guasto ) {
								// Il guasto di un'azione non ferma gli altri contenuti.
								$esito = false;
							}

							if ( true === $esito ) {
								++$fatti;
							} else {
								++$falliti;
							}
						}
					}
				}

				if ( $provati >= self::MASSIMO ) {
					$completo               = false;
					$segnaposti[ $sezione ] = $ultimo;
				} else {
					unset( $segnaposti[ $sezione ] );
				}
			}

			update_option( self::SEGNAPOSTO, $segnaposti, false );

			$esito = array(
				'contenuti' => $fatti,
				'falliti'   => $falliti,
				'completo'  => $completo,
			);

			update_option(
				self::BATTITO,
				array( 'istante' => gmdate( 'Y-m-d H:i:s', Conformita_Core_Scadenza::ora()->getTimestamp() ) ) + $esito,
				false
			);

			return $esito;
		} finally {
			Conformita_Core_Lucchetti::lascia( $lucchetto );
		}
	}

	/**
	 * L'ultima esecuzione arrivata in fondo, o nulla se non ce n'e' stata.
	 *
	 * @return array{istante: string, contenuti: int, falliti: int, completo: bool}|null
	 */
	public static function battito() {
		$battito = get_option( self::BATTITO, null );

		if ( ! is_array( $battito ) || ! isset( $battito['istante'] ) ) {
			return null;
		}

		return array(
			'istante'   => (string) $battito['istante'],
			'contenuti' => (int) ( $battito['contenuti'] ?? 0 ),
			'falliti'   => (int) ( $battito['falliti'] ?? 0 ),
			'completo'  => (bool) ( $battito['completo'] ?? false ),
		);
	}

	/**
	 * Il compito di WordPress che chiama l'esecuzione.
	 */
	public static function da_compito() {
		self::esegui();
	}

	/**
	 * Programma il compito orario, se non e' gia' programmato.
	 */
	public static function pianifica() {
		if ( false === wp_next_scheduled( self::COMPITO ) ) {
			wp_schedule_event( time(), 'hourly', self::COMPITO );
		}
	}

	/**
	 * Toglie il compito orario.
	 */
	public static function togli_compito() {
		wp_clear_scheduled_hook( self::COMPITO );
	}

	/**
	 * Dimentica le azioni registrate. Per le prove.
	 */
	public static function azzera() {
		self::$azioni = array();
	}

	/**
	 * Il nome del lucchetto dell'esecuzione.
	 *
	 * @return string
	 */
	public static function nome_lucchetto() {
		global $wpdb;

		return substr( $wpdb->prefix, 0, 40 ) . self::LUCCHETTO;
	}

	/**
	 * I prossimi contenuti scaduti di una sezione, in ordine di identificativo.
	 *
	 * @param string            $sezione Sezione.
	 * @param array<int,string> $stati   Stati.
	 * @param int               $dopo    Solo identificativi maggiori di questo.
	 * @param int|null          $fino    Solo identificativi fino a questo compreso, o nessun limite.
	 * @return array<int, int>|WP_Error
	 */
	private static function candidati( $sezione, array $stati, $dopo, $fino ) {
		global $wpdb;

		$tipi = array();

		foreach ( Conformita_Core_Tipi::identificativi() as $tipo ) {
			if ( Conformita_Core_Tipi::sezione( $tipo ) === $sezione ) {
				$tipi[] = $tipo;
			}
		}

		if ( array() === $tipi ) {
			return array();
		}

		$sql    = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value < %s"
			. ' AND p.post_type IN (' . implode( ', ', array_fill( 0, count( $tipi ), '%s' ) ) . ')'
			. ' AND p.post_status IN (' . implode( ', ', array_fill( 0, count( $stati ), '%s' ) ) . ')';
		$valori = array_merge( array( Conformita_Core_Scadenza::chiave(), Conformita_Core_Scadenza::giorno_corrente() ), $tipi, $stati );

		$sql     .= ' AND p.ID > %d';
		$valori[] = (int) $dopo;

		if ( null !== $fino ) {
			$sql     .= ' AND p.ID <= %d';
			$valori[] = (int) $fino;
		}

		$sql     .= ' ORDER BY p.ID LIMIT %d';
		$valori[] = self::LOTTO;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- I valori passano da prepare; il lotto va letto dalla banca dati, non dalla memoria.
		$righe = $wpdb->get_col( $wpdb->prepare( $sql, $valori ) );

		if ( '' !== $wpdb->last_error || ! is_array( $righe ) ) {
			return self::errore( 'conformita_core_compito_lettura', __( 'Compito alla scadenza: i contenuti scaduti non si sono potuti leggere dalla banca dati.', 'conformita-core' ) );
		}

		return array_map( 'intval', $righe );
	}

	/**
	 * Il contenuto e' davvero scaduto, con una data valida, e ancora in uno
	 * degli stati: la lettura per lotti puo' essere vecchia di qualche istante.
	 *
	 * Una data assente o rotta il filtro la tratta come scaduta, per
	 * prudenza; il compito no: un passaggio di stato su un dato rotto non lo
	 * decide nessuno, e il contenuto si salta. Una lettura che la banca dati
	 * non porta a termine invece non dice niente del contenuto: e' un errore.
	 *
	 * @param int               $id    Contenuto.
	 * @param array<int,string> $stati Stati.
	 * @return bool|WP_Error
	 */
	private static function da_toccare( $id, array $stati ) {
		global $wpdb;

		clean_post_cache( $id );

		$stato = get_post_status( $id );

		if ( '' !== (string) $wpdb->last_error ) {
			return self::errore( 'conformita_core_compito_lettura', __( 'Compito alla scadenza: lo stato di un contenuto non si è potuto leggere dalla banca dati.', 'conformita-core' ) );
		}

		if ( ! is_string( $stato ) || ! in_array( $stato, $stati, true ) ) {
			return false;
		}

		$istante = Conformita_Core_Scadenza::istante( $id );

		if ( is_wp_error( $istante ) ) {
			if ( 'conformita_core_lettura_fallita' === $istante->get_error_code() ) {
				return self::errore( 'conformita_core_compito_lettura', __( 'Compito alla scadenza: la data di fine di un contenuto non si è potuta leggere dalla banca dati.', 'conformita-core' ) );
			}

			return false;
		}

		return Conformita_Core_Scadenza::ora() >= $istante;
	}

	/**
	 * Un errore del compito.
	 *
	 * @param string $codice    Codice.
	 * @param string $messaggio Messaggio.
	 * @return WP_Error
	 */
	private static function errore( $codice, $messaggio ) {
		return new WP_Error( $codice, $messaggio );
	}
}
