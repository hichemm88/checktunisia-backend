# Images embarquées dans les PDF

## `qayed-logo.png` — attendu, non versionné pour l'instant

En-tête des PDF de fiches de police (`resources/views/pdf/police-fiches.blade.php`).

- **PNG à fond transparent**, rendu à 35 mm de large (99,2 pt), hauteur libre.
- Prévoir au moins **1200 px de large** : le PDF est consulté à l'écran et
  imprimé, et DomPDF n'interpole pas vers le haut.
- Le fichier est lu sur disque et embarqué en base64 : DomPDF ne va chercher
  aucune ressource distante, une URL laisserait un cadre vide.

**Absent, rien ne casse** : l'en-tête retombe sur le mot-symbole « QAYED », et
une ligne d'information part dans les journaux. Voir `App\Support\BrandLogo`.
