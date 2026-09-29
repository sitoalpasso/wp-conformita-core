<?php
/**
 * Fornitore dei contenuti della mappa del sito, senza le voci vuote.
 *
 * E' il fornitore di WordPress con una sola differenza: scarta le voci
 * rimaste senza indirizzo, che il meccanismo di indicizzazione lascia al
 * posto dei contenuti vietati. Si carica solo quando serve, dall'aggancio che
 * lo registra, perche' estende una classe di WordPress. Riga C-244.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contenuti della mappa, con l'ultima difesa del divieto di indicizzazione.
 */
final class Conformita_Core_Mappa_Contenuti extends WP_Sitemaps_Posts {

	/**
	 * Gli indirizzi di una pagina della mappa, senza le voci vuote.
	 *
	 * @param int    $page_num       Pagina della mappa.
	 * @param string $object_subtype Tipo di contenuto.
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$voci = parent::get_url_list( $page_num, $object_subtype );

		if ( ! is_array( $voci ) ) {
			return $voci;
		}

		return array_values(
			array_filter(
				$voci,
				function ( $voce ) {
					return is_array( $voce ) && ! empty( $voce['loc'] );
				}
			)
		);
	}
}
