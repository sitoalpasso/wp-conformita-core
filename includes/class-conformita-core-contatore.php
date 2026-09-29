<?php
/**
 * Contatori e date limite tenuti nelle opzioni, aggiornati in un passo solo.
 *
 * **Perché non `update_option()`.** Leggere un valore, cambiarlo e riscriverlo
 * sono tre passi: due richieste che lo fanno insieme leggono lo stesso valore,
 * e la seconda riscrittura cancella la prima. Per un conteggio di eventi che
 * non devono sfuggire, come le voci di registro mancate, ogni evento perso è
 * un buco che nessuno vede. Qui ogni aggiornamento è una sola istruzione, che
 * la banca dati esegue per intero prima della successiva: crea l'opzione se
 * manca, altrimenti la cambia partendo dal valore che c'è in quel momento.
 *
 * Le opzioni sono normali opzioni di WordPress, non caricate a ogni richiesta,
 * e si leggono con `get_option()`: dopo ogni aggiornamento la copia in memoria
 * si butta, così la lettura successiva va alla banca dati.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aggiornamenti in un passo solo su opzioni di WordPress.
 */
final class Conformita_Core_Contatore {

	/**
	 * Aggiunge uno al numero conservato nell'opzione, e la crea a uno se manca.
	 *
	 * @param string $opzione Nome dell'opzione.
	 * @return bool Vero se la banca dati ha eseguito l'aggiornamento.
	 */
	public static function incrementa( $opzione ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Un passo solo: l'API delle opzioni legge e riscrive in due.
		$esito = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$opzione
			)
		);

		self::dimentica( $opzione );

		return false !== $esito;
	}

	/**
	 * Tiene nell'opzione il minore o il maggiore fra il valore che c'è e questo.
	 *
	 * Il confronto è fra testi: vale per le date nella forma
	 * `AAAA-MM-GG HH:MM:SS`, dove l'ordine alfabetico è quello del tempo.
	 *
	 * @param string $opzione Nome dell'opzione.
	 * @param string $valore  Valore da confrontare.
	 * @param bool   $minore  Vero per tenere il minore, falso per il maggiore.
	 * @return bool Vero se la banca dati ha eseguito l'aggiornamento.
	 */
	public static function limite( $opzione, $valore, $minore ) {
		global $wpdb;

		$funzione = $minore ? 'LEAST' : 'GREATEST';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Un passo solo: l'API delle opzioni legge e riscrive in due.
		$esito = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- La funzione è una delle due scritte qui sopra, non un dato ricevuto.
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = IF(option_value = '', %s, {$funzione}(option_value, %s))",
				$opzione,
				$valore,
				$valore,
				$valore
			)
		);

		self::dimentica( $opzione );

		return false !== $esito;
	}

	/**
	 * Butta la copia in memoria dell'opzione, compreso il segno di opzione
	 * inesistente che WordPress tiene per quelle cercate e non trovate.
	 *
	 * @param string $opzione Nome dell'opzione.
	 */
	private static function dimentica( $opzione ) {
		wp_cache_delete( $opzione, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
