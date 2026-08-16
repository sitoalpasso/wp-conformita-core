<?php
/**
 * Versione dell'API di core e guardia dei componenti dipendenti.
 *
 * L'intestazione `Requires Plugins` di WordPress 6.5 accetta slug e non
 * versioni: garantisce che core sia presente, non che sia della versione
 * attesa. Il vincolo di versione si controlla quindi qui, all'avvio del
 * componente, e il suo esito negativo è una disattivazione con messaggio.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guardia di compatibilità fra core e componenti dipendenti.
 */
final class Conformita_Core_Dipendenza {

	/**
	 * Versione dell'API esposta da core.
	 *
	 * @return string
	 */
	public static function versione_api() {
		return defined( 'CONFORMITA_CORE_VERSIONE_API' ) ? CONFORMITA_CORE_VERSIONE_API : '';
	}

	/**
	 * La versione disponibile soddisfa quella richiesta.
	 *
	 * Regola: stesso numero maggiore, e versione disponibile non inferiore a
	 * quella richiesta. Un numero maggiore diverso è incompatibile in entrambe le
	 * direzioni, perché il numero maggiore cambia quando l'API rompe la
	 * compatibilità.
	 *
	 * @param string      $richiesta   Versione richiesta dal componente.
	 * @param string|null $disponibile Versione esposta da core.
	 * @return bool
	 */
	public static function compatibile( $richiesta, $disponibile ) {
		$richiesta   = is_string( $richiesta ) ? trim( $richiesta ) : '';
		$disponibile = is_string( $disponibile ) ? trim( $disponibile ) : '';

		if ( '' === $richiesta || '' === $disponibile ) {
			return false;
		}

		$maggiore_richiesta   = self::numero_maggiore( $richiesta );
		$maggiore_disponibile = self::numero_maggiore( $disponibile );

		if ( null === $maggiore_richiesta || null === $maggiore_disponibile ) {
			return false;
		}

		if ( $maggiore_richiesta !== $maggiore_disponibile ) {
			return false;
		}

		return version_compare( $disponibile, $richiesta, '>=' );
	}

	/**
	 * Numero maggiore di una versione, o nullo se la versione non è leggibile.
	 *
	 * @param string $versione Versione da leggere.
	 * @return int|null
	 */
	private static function numero_maggiore( $versione ) {
		$parti = explode( '.', $versione );

		return ctype_digit( $parti[0] ) ? (int) $parti[0] : null;
	}

	/**
	 * Verifica un componente contro una versione disponibile dell'API.
	 *
	 * @param string      $nome            Nome visibile del componente.
	 * @param string      $api_richiesta   Versione di API richiesta dal componente.
	 * @param string|null $api_disponibile Versione di API disponibile, nulla se core non la espone.
	 * @return true|WP_Error Vero se compatibile, errore leggibile altrimenti.
	 */
	public static function verifica_componente( $nome, $api_richiesta, $api_disponibile ) {
		if ( ! is_string( $api_disponibile ) || '' === trim( $api_disponibile ) ) {
			return new WP_Error(
				'conformita_core_api_non_disponibile',
				sprintf(
					/* translators: 1: nome del componente, 2: versione di API richiesta. */
					__( '%1$s resta disattivato: richiede la versione %2$s dell\'API di Conformita Core, che non risulta disponibile. Installare e attivare Conformita Core, poi riattivare il componente.', 'conformita-core' ),
					$nome,
					$api_richiesta
				)
			);
		}

		if ( ! self::compatibile( $api_richiesta, $api_disponibile ) ) {
			return new WP_Error(
				'conformita_core_api_incompatibile',
				sprintf(
					/* translators: 1: nome del componente, 2: versione di API richiesta, 3: versione di API disponibile. */
					__( '%1$s resta disattivato: richiede la versione %2$s dell\'API di Conformita Core, mentre la versione disponibile è %3$s. Aggiornare Conformita Core, poi riattivare il componente.', 'conformita-core' ),
					$nome,
					$api_richiesta,
					$api_disponibile
				)
			);
		}

		return true;
	}

	/**
	 * Avvia un componente dipendente, oppure lo disattiva spiegando perché.
	 *
	 * @param string $nome          Nome visibile del componente.
	 * @param string $file          Percorso del componente come lo conosce WordPress.
	 * @param string $api_richiesta Versione di API richiesta dal componente.
	 * @return bool Vero se il componente può proseguire l'avvio.
	 */
	public static function avvia_componente( $nome, $file, $api_richiesta ) {
		$esito = self::verifica_componente( $nome, $api_richiesta, self::versione_api() );

		if ( ! is_wp_error( $esito ) ) {
			return true;
		}

		self::disattiva( $file, $esito->get_error_message() );

		return false;
	}

	/**
	 * Disattiva il componente e prepara l'avviso in bacheca.
	 *
	 * @param string $file      Percorso del componente come lo conosce WordPress.
	 * @param string $messaggio Messaggio da mostrare all'amministrazione.
	 */
	private static function disattiva( $file, $messaggio ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $file );

		add_action(
			'admin_notices',
			static function () use ( $messaggio ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( $messaggio )
				);
			}
		);
	}
}
