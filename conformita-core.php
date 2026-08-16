<?php
/**
 * Plugin Name:       Conformita Core
 * Plugin URI:        https://github.com/sitoalpasso/wp-conformita-core
 * Description:       Meccanismi comuni per i componenti di conformità WordPress per la pubblica amministrazione italiana. Non contiene politiche proprie: ogni meccanismo pretende la politica come parametro esplicito.
 * Version:           0.1.0-alpha
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            sitoalpasso
 * Author URI:        https://github.com/sitoalpasso
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       conformita-core
 * Domain Path:       /languages
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/*
 * Nessun meccanismo di core ha una politica implicita: la politica arriva dal
 * componente che registra la sezione, per intero, e senza di essa la sezione
 * non si attiva.
 */

/**
 * Percorso della cartella del plugin, con la barra finale.
 */
defined( 'CONFORMITA_CORE_PERCORSO' ) || define( 'CONFORMITA_CORE_PERCORSO', plugin_dir_path( __FILE__ ) );

require_once CONFORMITA_CORE_PERCORSO . 'includes/class-conformita-core-politica.php';
require_once CONFORMITA_CORE_PERCORSO . 'includes/class-conformita-core-sezioni.php';
require_once CONFORMITA_CORE_PERCORSO . 'includes/funzioni-api.php';
