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
 * @package Cross_Sell_Insights
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Réglages et journaux. Les clés en bit_ sont celles de l'ancien nom de
// l'extension (« BuyIt Together ») : elles ne subsistent que si l'extension a
// été supprimée avant d'avoir été chargée une fois sous son nouveau nom, donc
// sans que la reprise ait pu s'exécuter. On les retire aussi, pour ne rien
// laisser derrière soi.
$csins_prefixes = [ 'csins_', 'bit_' ];
$csins_cles     = [ 'regles', 'exclus', 'non_recommandes', 'sans_suggestions', 'historique', 'dernier_calcul', 'mode_fiche', 'affichages', 'modal_style' ];
foreach ( $csins_prefixes as $csins_prefixe ) {
	foreach ( $csins_cles as $csins_cle ) {
		delete_option( $csins_prefixe . $csins_cle );
	}
	delete_transient( $csins_prefixe . 'analyse' );
}
delete_option( 'csins_reprise_bit' );

// Associations calculées et suggestions saisies à la main.
// delete_post_meta_by_key() invalide le cache objet, contrairement à une
// suppression SQL directe : le site peut utiliser Redis ou Memcached.
delete_post_meta_by_key( '_csins_compagnons' );
delete_post_meta_by_key( '_csins_manuel' );
delete_post_meta_by_key( '_bit_compagnons' );
delete_post_meta_by_key( '_bit_manuel' );

// Tâches planifiées, au cas où la désactivation ne les aurait pas retirées.
wp_clear_scheduled_hook( 'csins_recalcul' );
wp_clear_scheduled_hook( 'bit_recalcul' );
