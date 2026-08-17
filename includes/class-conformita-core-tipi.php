<?php
/**
 * Registrazione dei tipi di contenuto governati da una sezione.
 *
 * Un tipo di contenuto non si registra mai direttamente con WordPress: passa da
 * qui, e qui passa solo se dichiara una sezione già registrata. La ragione è la
 * stessa che regge il registro delle sezioni: la politica sta sulla sezione,
 * quindi un tipo registrato fuori da una sezione sarebbe un tipo attivo senza
 * politica, cioè un contenuto pubblicato che nessuna regola governa. Il rifiuto
 * è esplicito e arriva prima della chiamata a WordPress, così un tipo respinto
 * non lascia dietro di sé niente da smontare.
 *
 * Core impone due cose e delega tutto il resto: le capability, che sono la
 * garanzia di isolamento del tipo, e la scelta su REST, che il componente deve
 * dichiarare invece di ereditare. Etichette, supporti, visibilità e riscrittura
 * restano parametri del componente.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro dei tipi di contenuto e delle sezioni che li governano.
 */
final class Conformita_Core_Tipi {

	/**
	 * Sezione e capability per identificativo di tipo.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $tipi = array();

	/**
	 * Argomenti di registrazione che core impone e non accetta dal componente.
	 *
	 * Le capability sono la garanzia che un tipo non sia governato dai permessi
	 * degli articoli: se il componente potesse riscriverle, la garanzia varrebbe
	 * solo finché nessuno prova a toglierla. La scelta su REST è riservata per il
	 * motivo opposto: va dichiarata nella definizione, dove è visibile, e non
	 * nascosta fra gli argomenti passati a WordPress.
	 *
	 * @return array<int, string>
	 */
	public static function argomenti_riservati() {
		return array( 'capabilities', 'capability_type', 'map_meta_cap', 'show_in_rest' );
	}

	/**
	 * Le due radici da cui WordPress deriva le capability del tipo.
	 *
	 * Sono derivate dall'identificativo del tipo e non configurabili: un nome di
	 * capability sbagliato di una lettera è una capability che nessuno possiede e
	 * di cui nessuno si accorge. Il suffisso del plurale serve solo a tenere
	 * distinte le capability sul singolo contenuto da quelle sull'insieme, come
	 * WordPress si aspetta.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<int, string> Radice singolare e radice plurale.
	 */
	public static function radici_capacita( $tipo ) {
		$radice = str_replace( '-', '_', $tipo );

		return array( $radice, $radice . '_multipli' );
	}

	/**
	 * Registra un tipo di contenuto dentro una sezione.
	 *
	 * @param string $tipo        Identificativo del tipo.
	 * @param array  $definizione Definizione: `sezione`, `show_in_rest` e gli
	 *                            `argomenti` da passare a WordPress.
	 * @return true|WP_Error Vero se il tipo è registrato, errore altrimenti.
	 */
	public static function registra( $tipo, array $definizione ) {
		$tipo = is_string( $tipo ) ? trim( $tipo ) : '';

		$esito = self::verifica_identificativo( $tipo );

		if ( is_wp_error( $esito ) ) {
			return $esito;
		}

		$sezione = self::sezione_dichiarata( $definizione );

		if ( is_wp_error( $sezione ) ) {
			return $sezione;
		}

		if ( ! array_key_exists( 'show_in_rest', $definizione ) || ! is_bool( $definizione['show_in_rest'] ) ) {
			return new WP_Error(
				'conformita_core_rest_non_dichiarata',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s: show_in_rest va dichiarato esplicitamente come valore booleano. La scelta è obbligatoria in entrambi i sensi e non ha un valore predefinito.', 'conformita-core' ),
					$tipo
				)
			);
		}

		$argomenti = self::argomenti_dichiarati( $definizione );

		if ( is_wp_error( $argomenti ) ) {
			return $argomenti;
		}

		$oggetto = register_post_type(
			$tipo,
			array_merge(
				$argomenti,
				array(
					'show_in_rest'    => $definizione['show_in_rest'],
					'capability_type' => self::radici_capacita( $tipo ),
					'map_meta_cap'    => true,
				)
			)
		);

		if ( is_wp_error( $oggetto ) ) {
			return $oggetto;
		}

		self::$tipi[ $tipo ] = array(
			'sezione'  => $sezione,
			'capacita' => (array) $oggetto->cap,
		);

		return true;
	}

	/**
	 * L'identificativo è utilizzabile e non è già di qualcun altro.
	 *
	 * Il limite di venti caratteri e l'insieme di caratteri ammessi sono vincoli
	 * di WordPress: si verificano qui perché `register_post_type` li segnala come
	 * uso scorretto della funzione, mentre un componente merita un errore
	 * leggibile. Il controllo su un tipo già esistente è più importante di
	 * quanto sembri: senza, `register_post_type` rimpiazzerebbe in silenzio il
	 * tipo esistente, capability comprese.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return true|WP_Error
	 */
	private static function verifica_identificativo( $tipo ) {
		if ( ! preg_match( '/^[a-z0-9_-]{1,20}$/', $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_non_valido',
				__( 'Identificativo di tipo non valido: da uno a venti caratteri fra lettere minuscole, cifre, trattino e trattino basso.', 'conformita-core' )
			);
		}

		if ( isset( self::$tipi[ $tipo ] ) ) {
			return new WP_Error(
				'conformita_core_tipo_duplicato',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s già registrato: la definizione di un tipo registrato non si sovrascrive.', 'conformita-core' ),
					$tipo
				)
			);
		}

		if ( post_type_exists( $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_gia_in_uso',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s già in uso in WordPress: registrarlo sostituirebbe il tipo esistente e le sue capability.', 'conformita-core' ),
					$tipo
				)
			);
		}

		return true;
	}

	/**
	 * La sezione dichiarata dalla definizione, se è una sezione registrata.
	 *
	 * @param array $definizione Definizione del tipo.
	 * @return string|WP_Error Identificativo della sezione, oppure errore.
	 */
	private static function sezione_dichiarata( array $definizione ) {
		$sezione = isset( $definizione['sezione'] ) && is_string( $definizione['sezione'] )
			? trim( $definizione['sezione'] )
			: '';

		if ( '' === $sezione || ! Conformita_Core_Sezioni::registrata( $sezione ) ) {
			return new WP_Error(
				'conformita_core_tipo_senza_sezione',
				sprintf(
					/* translators: %s: identificativo della sezione dichiarata, oppure una descrizione della sua assenza. */
					__( 'Sezione %s non registrata: un tipo di contenuto si registra solo dentro una sezione che ha già dichiarato la sua politica.', 'conformita-core' ),
					'' === $sezione ? __( 'non dichiarata', 'conformita-core' ) : $sezione
				),
				array( 'sezione' => $sezione )
			);
		}

		return $sezione;
	}

	/**
	 * Gli argomenti che il componente passa a WordPress, se sono suoi da passare.
	 *
	 * @param array $definizione Definizione del tipo.
	 * @return array|WP_Error Argomenti validati, oppure errore.
	 */
	private static function argomenti_dichiarati( array $definizione ) {
		if ( ! array_key_exists( 'argomenti', $definizione ) ) {
			return array();
		}

		if ( ! is_array( $definizione['argomenti'] ) ) {
			return new WP_Error(
				'conformita_core_argomenti_non_validi',
				__( 'Gli argomenti di registrazione del tipo devono essere un array associativo.', 'conformita-core' )
			);
		}

		$riservati = array_values(
			array_intersect( self::argomenti_riservati(), array_keys( $definizione['argomenti'] ) )
		);

		if ( ! empty( $riservati ) ) {
			return new WP_Error(
				'conformita_core_argomenti_riservati',
				sprintf(
					/* translators: %s: elenco degli argomenti riservati, separati da virgola. */
					__( 'Argomenti riservati a Conformita Core: %s. Le capability sono un meccanismo di core, e la scelta su REST si dichiara nella definizione del tipo.', 'conformita-core' ),
					implode( ', ', $riservati )
				),
				array( 'riservati' => $riservati )
			);
		}

		return $definizione['argomenti'];
	}

	/**
	 * Il tipo è registrato attraverso core.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return bool
	 */
	public static function registrato( $tipo ) {
		$tipo = is_string( $tipo ) ? trim( $tipo ) : '';

		return isset( self::$tipi[ $tipo ] );
	}

	/**
	 * Sezione che governa un tipo registrato.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return string|WP_Error Identificativo della sezione, oppure errore.
	 */
	public static function sezione( $tipo ) {
		$voce = self::voce( $tipo );

		return is_wp_error( $voce ) ? $voce : $voce['sezione'];
	}

	/**
	 * Politica che governa un tipo registrato.
	 *
	 * La politica non è duplicata sul tipo: si legge dalla sezione, che resta
	 * l'unico posto in cui è dichiarata.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return Conformita_Core_Politica|WP_Error Politica dichiarata, oppure errore.
	 */
	public static function politica( $tipo ) {
		$sezione = self::sezione( $tipo );

		return is_wp_error( $sezione ) ? $sezione : Conformita_Core_Sezioni::politica( $sezione );
	}

	/**
	 * Capability dedicate a un tipo registrato.
	 *
	 * Core le registra e non le assegna a nessun ruolo: appena registrato, un
	 * tipo non è visibile né modificabile da nessuno, e l'assegnazione è compito
	 * del componente che conosce la propria sezione. Fra le due direzioni
	 * possibili è quella che sbaglia in sicurezza.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<string, string>|WP_Error Mappa delle capability, oppure errore.
	 */
	public static function capacita( $tipo ) {
		$voce = self::voce( $tipo );

		return is_wp_error( $voce ) ? $voce : $voce['capacita'];
	}

	/**
	 * Identificativi dei tipi registrati, nell'ordine di registrazione.
	 *
	 * @return array<int, string>
	 */
	public static function identificativi() {
		return array_keys( self::$tipi );
	}

	/**
	 * Voce di registro di un tipo, oppure l'errore che dice che non c'è.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function voce( $tipo ) {
		$tipo = is_string( $tipo ) ? trim( $tipo ) : '';

		if ( ! isset( self::$tipi[ $tipo ] ) ) {
			return new WP_Error(
				'conformita_core_tipo_sconosciuto',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s non registrato attraverso Conformita Core: nessuna sezione e nessuna politica da applicare.', 'conformita-core' ),
					$tipo
				)
			);
		}

		return self::$tipi[ $tipo ];
	}

	/**
	 * Svuota il registro e smonta i tipi registrati.
	 *
	 * Serve alla suite di test, dove le registrazioni girano tutte nello stesso
	 * processo e un tipo lasciato dietro renderebbe verde o rossa la prova
	 * successiva per motivi che non c'entrano con quello che prova.
	 */
	public static function azzera() {
		foreach ( array_keys( self::$tipi ) as $tipo ) {
			if ( post_type_exists( $tipo ) ) {
				unregister_post_type( $tipo );
			}
		}

		self::$tipi = array();
	}
}
