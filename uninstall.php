<?php
/**
 * Désinstallation : ne rien laisser derrière soi.
 *
 * Exécuté par WordPress à la suppression de l'extension, jamais à sa simple
 * désactivation. On retire les réglages, les caches et les données calculées.
 *
 * Les ventes croisées et montées en gamme de WooCommerce ne sont PAS touchées :
 * ce sont des données du site, pas de l'extension, et l'utilisateur les a
 * peut-être renseignées à la main avant de l'installer.
 *
 * @package BuyIt_Together
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Réglages et journaux.
foreach ( [ 'bit_regles', 'bit_exclus', 'bit_non_recommandes', 'bit_sans_suggestions', 'bit_historique', 'bit_dernier_calcul' ] as $option ) {
	delete_option( $option );
}

delete_transient( 'bit_analyse' );

// Associations calculées et suggestions saisies à la main.
// delete_post_meta_by_key() invalide le cache objet, contrairement à une
// suppression SQL directe : le site peut utiliser Redis ou Memcached.
delete_post_meta_by_key( '_bit_compagnons' );
delete_post_meta_by_key( '_bit_manuel' );

// Tâche planifiée, au cas où la désactivation ne l'aurait pas retirée.
wp_clear_scheduled_hook( 'bit_recalcul' );
