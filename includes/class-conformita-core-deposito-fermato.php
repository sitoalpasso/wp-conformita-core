<?php
/**
 * L'eccezione con cui la guardia ferma uno spostamento di byte.
 *
 * **Perché un'eccezione e non un valore di ritorno.** La guardia vive dentro
 * `pre_move_uploaded_file`, che è l'ultimo aggancio prima che il file venga
 * copiato al suo posto. Quel filtro non ha un modo di dire «rifiuta»: se
 * restituisce un valore diverso da `null`, WordPress salta la copia ma tira
 * dritto lo stesso, e finisce per dare i permessi a un file che non esiste.
 * L'unico modo di fermare davvero la sequenza da lì è uscirne, e il punto che
 * raccoglie l'uscita è `Conformita_Core_Allegati::deposita()`, che la converte
 * in un errore normale con il motivo vero.
 *
 * È un tipo dedicato e non un'eccezione generica proprio perché chi la raccoglie
 * deve poter distinguere «l'ho fermata io» da «l'ha sollevata l'aggancio di
 * qualcun altro», che è un caso diverso e va lasciato passare. Riga C-178.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lo spostamento è stato fermato dalla guardia sulla destinazione.
 */
final class Conformita_Core_Deposito_Fermato extends RuntimeException {
}
