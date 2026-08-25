# Ressources du dépôt WordPress

Ces fichiers ne font **pas** partie de l'extension : ils ne doivent jamais se
retrouver dans le zip livré aux utilisateurs. Sur le SVN de wordpress.org ils
vivent dans `/assets/`, à côté de `/trunk/`, et non dedans.

| Fichier | Usage |
|---|---|
| `icon-128x128.png` | Vignette dans la recherche d'extensions |
| `icon-256x256.png` | Même vignette, écrans à haute densité |
| `banner-772x250.png` | Bandeau de la page publique |
| `banner-1544x500.png` | Même bandeau, écrans à haute densité |
| `icon.svg`, `banner.svg` | Sources, pour refabriquer les PNG |

## Refabriquer les PNG

```sh
magick icon.svg   -resize 256x256 -depth 8 icon-256x256.png
magick icon.svg   -resize 128x128 -depth 8 icon-128x128.png
```

Le bandeau ajoute son texte par-dessus le SVG, les polices n'étant pas rendues
de façon fiable par le moteur SVG interne d'ImageMagick :

```sh
magick banner.svg \
  -font "/System/Library/Fonts/Avenir Next.ttc" -pointsize 92 -fill '#f6f7f7' \
  -annotate +430+250 'Cross-Sell Insights' \
  -font "/System/Library/Fonts/Helvetica.ttc" -pointsize 36 -fill '#a7aaad' \
  -annotate +434+312 'Suggestions built from your real order history' \
  banner-1544x500.png
magick banner-1544x500.png -resize 772x250 -depth 8 banner-772x250.png
```

## Le dessin

Deux disques qui se recouvrent : deux produits, et l'intersection — ce qui
s'achète ensemble, seul propos de l'extension. Les captures manquent encore
(`screenshot-1.png` à `screenshot-3.png`), annoncées par `readme.txt`.
