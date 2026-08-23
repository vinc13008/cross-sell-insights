# BuyIt Together

Propose sur chaque fiche produit les pièces **réellement achetées avec elle**,
déduites de l'historique de commandes. Diagnostique aussi les ventes croisées
WooCommerce existantes et recommande les corrections.

**Aucune dépendance externe, aucune donnée sortante.** Tout est calculé depuis
la base WooCommerce du site.

## Pourquoi

Sur un catalogue de pièces détachées, deux tiers des commandes ne contiennent
qu'un seul article, alors que les réparations en demandent souvent plusieurs.
Configurer les ventes croisées produit par produit est impraticable à l'échelle
de 1 000 références — et les associations issues d'un import fournisseur ne
reflètent pas les achats réels.

## Ce que fait l'extension

**Associations calculées.** Un recalcul hebdomadaire compte les couples de
produits apparaissant dans une même commande sur 12 mois, et retient les plus
fréquents. Le bloc s'affiche sous le résumé de la fiche produit.

**Règles par étiquette ou catégorie.** Pour les produits sans historique
suffisant : on cible une famille de pièces ou une série de drone, et on désigne
les articles à proposer.

**Diagnostic et recommandations.** Compare les ventes croisées configurées aux
achats constatés, chiffre l'écart, et liste les associations solidement établies
mais absentes de la configuration. Application en un clic, annulable.

**Attribution en masse.** Ajoute ou remplace les ventes croisées WooCommerce sur
toute une catégorie, une étiquette ou une sélection de produits.

**Éditeur par catégorie.** Les produits d'une famille listés à la suite, un par
ligne, avec leurs montées en gamme et leurs ventes croisées modifiables sur
place. Les suggestions calculées sont rappelées en regard, à titre indicatif.
Une barre permet d'ajouter — ou de retirer — un produit sur toutes les lignes
affichées d'un seul geste, avant validation.

## Organisation de l'écran

Trois onglets, correspondant à trois moments du parcours client :

| Onglet | Rôle | Données modifiées |
|---|---|---|
| Analyse | Lecture : calcul, diagnostic, recommandations, classement | aucune, sauf application explicite |
| Suggestions sur la fiche produit | Règles de repli | propres à l'extension |
| Ventes croisées du panier | Attribution en masse | ventes croisées WooCommerce |
| Montées en gamme | Alternatives supérieures, par catégorie | montées en gamme WooCommerce |
| Éditeur par catégorie | Relecture ligne à ligne | suggestions et ventes croisées |

L'éditeur et l'onglet Montées en gamme acceptent une catégorie, une étiquette,
des produits choisis un à un, ou n'importe quelle combinaison des trois. Le
nombre de produits par page se choisit parmi 10, 25, 50 ou 100 — chaque ligne
portant deux sélecteurs select2, au-delà la page devient lente à initialiser.
La sélection et ce réglage sont conservés d'une page à l'autre et après
enregistrement.

Les trois emplacements ne se confondent pas :

| | Où le client le voit | Nature |
|---|---|---|
| Suggestions | fiche produit, sous le résumé | pièces complémentaires |
| Montées en gamme | fiche produit, avant les suggestions | alternative supérieure |
| Ventes croisées | panier | pièces complémentaires |

Le bloc de l'extension s'accroche à `woocommerce_after_single_product_summary`
en priorité **16** : après les montées en gamme de WooCommerce (15), avant les
produits similaires (20). À 15, l'ordre d'affichage aurait été indéterminé.

Une suggestion saisie à la main est stockée dans `_bit_manuel`, distincte des
associations calculées : sans cette séparation, le recalcul hebdomadaire
effacerait silencieusement toute correction manuelle. La saisie est prioritaire.

L'onglet Analyse ne configure rien : il lit les ventes et propose. Les trois
autres configurent.

## Installation

Copier le dossier dans `wp-content/plugins/` et activer. Une règle est amorcée
automatiquement — le kit d'outils sur les pièces qui demandent un démontage.

Réglages : **WooCommerce → Achetés ensemble**.

## Points d'extension

| Filtre | Rôle | Défaut |
|---|---|---|
| `bit_nb_affiches` | Compagnons montrés sur la fiche | `3` |
| `bit_min_paires` | Occurrences minimales d'une paire | `2` |
| `bit_fenetre_jours` | Profondeur d'historique analysée | `365` |
| `bit_seuil_reco` | Seuil d'une recommandation | `3` |
| `bit_historique_max` | Opérations annulables conservées | `10` |
| `bit_exclus` | Produits à ne jamais proposer ailleurs | option |
| `bit_non_recommandes` | Fiches retirées des recommandations | option |
| `bit_compagnons` | Liste finale, avant affichage | — |
| `bit_titre` | Titre du bloc | — |

## Notes techniques

Le stockage passe par la meta `_bit_compagnons`, purgée via
`delete_post_meta_by_key()` — et non en SQL direct : le site utilise un cache
objet persistant, qu'une suppression en base laisserait périmé.

Les sauvegardes de ventes croisées sont **empilées** dans `bit_historique`,
jamais écrasées : deux fonctionnalités modifient les mêmes données, et une
option unique ferait perdre le moyen d'annuler l'opération précédente.

L'analyse est mise en cache une heure (`bit_analyse`).

Deux listes d'exclusion existent, symétriques et indépendantes. `bit_exclus`
empêche un produit d'**être proposé** : il est filtré au comptage des paires et
à l'affichage — le second n'est pas redondant, une suggestion saisie à la main
ou produite par une règle de repli ne passant jamais par le calcul.
`bit_non_recommandes` est d'une autre nature : réglage d'écran, non
d'affichage. La fiche sort de la colonne « Sur cette fiche produit » du tableau
de recommandations, mais garde son bloc côté client et continue de compter dans
le calcul comme dans le classement.

Modifier l'une ou l'autre liste purge le transient d'analyse : le tableau de
recommandations en dépend, et resterait sinon périmé jusqu'à une heure.

Dans les tableaux de résultats de l'onglet Analyse, chaque nom de produit
renvoie vers l'éditeur filtré sur ce seul produit (`&produits[]=…`), plutôt que
de redéployer les sélecteurs d'édition dans chaque tableau : deux
implémentations du même écran finiraient par diverger. Le paramètre `depuis`
sert de fil d'Ariane et survit à un enregistrement — il ne pouvait pas s'appeler
`retour`, nom déjà pris par le formulaire de l'éditeur.

L'habillage est émis par `styles()`, portée par `.bit-wrap` : jetons de
couleur en variables CSS, cartes, tuiles de chiffres et jauge de correspondance.
Le taux de correspondance est la donnée la plus parlante de l'écran, il est donc
traité en chiffre-héros plutôt qu'en phrase. La couleur y indique un **état**
(conforme, partiel, faible, sans rapport) et ne porte jamais seule le sens :
elle est toujours accompagnée d'une pastille et d'un libellé, car les tons
d'avertissement passent sous le seuil de contraste sur fond clair.

Chaque `<select multiple>` de l'éditeur est précédé d'un champ caché de même
nom. Sans lui, un champ vidé n'enverrait rien et la suppression serait perdue :
la clé doit exister pour que le traitement distingue « champ absent » de
« champ volontairement vide ».

`uninstall.php` retire réglages, caches et metas à la suppression. Les ventes
croisées et montées en gamme de WooCommerce sont conservées : ce sont des
données du site, pas de l'extension.

Chaque enregistrement redirige vers l'écran avec un paramètre de confirmation
(`recos=`, `edites=`, `exclus=1`, `muets=1`, `regles=1`, `masse=`, `annule=`,
`recalcul=1`), lu et affiché par `notices()`. Un redirect silencieux ne dit
rien de ce qui vient de se passer ; ces paramètres n'ont aucun effet de bord,
ils ne font que choisir le message.

Le bouton « Recalculer maintenant » vit sur l'onglet Analyse, mais sa
redirection ne précisait pas l'onglet — un recalcul renvoyait vers l'onglet
par défaut, cachant les chiffres qu'on venait justement de rafraîchir. Corrigé
en fixant `&onglet=analyse` dans la redirection.

Les tuiles de l'onglet Analyse sont des ancres vers la section qui explique le
chiffre (`#bit-calcul`, `#bit-recommandations`) : un résumé n'a d'intérêt que
si on peut creuser sans chercher où regarder. La jauge de correspondance
s'anime au chargement plutôt que de s'afficher pré-remplie — deux passages par
`requestAnimationFrame` avant de porter sa largeur à la valeur réelle, pour que
le remplissage se lise comme une réponse plutôt qu'un simple fait. Respecte
`prefers-reduced-motion`.

Le panneau d'attribution en masse de l'éditeur par catégorie reste visible en
défilant une longue liste (`position: sticky`), pour ne pas forcer un
aller-retour en haut de page à chaque application.

## Compatibilité

WooCommerce avec stockage HPOS (`wp_wc_orders`), PHP 8.0+, WordPress 6.0+.

## Publication

`readme.txt` est le descriptif lu par wordpress.org ; `README.md` est celui-ci,
destiné au dépôt de code. Les deux coexistent volontairement : leurs formats
et leurs publics diffèrent.

Les visuels du dépôt (icône, bandeau) vivent dans `.wordpress-org/` et ne
doivent jamais entrer dans le zip livré : sur le SVN de wordpress.org ils vont
dans `/assets/`, à côté de `/trunk/`, pas dedans.
