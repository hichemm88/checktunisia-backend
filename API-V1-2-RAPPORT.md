# API partenaire Qayed — V1.2 : rapport

Date : 23/11/2026. Fait suite à la V1.1 (en production depuis le 22/11/2026) et au premier
test réel de Hichem (Android, Chrome, depuis Diarna, arrivée Dar Dou du 18/04/2027, clé de test).

## 1. Cause de l'écran « liste » vu par Hichem

**Ce n'était pas le widget V1.1, c'était l'ancien bundle du 16/09, resservi par le cache de Chrome.**

Preuves (données de production, lecture seule) :

| Heure (UTC) | Événement |
|---|---|
| 22:19:13 | Diarna crée la session de test Dar Dou (brouillon créé par l'utilisateur d'intégration). Le téléphone ouvre le widget à 22:19:40, **avant** la publication de la V1.1. Il charge donc `widget-main.js` du 16/09. Aucun voyageur n'est ajouté. |
| 22:23:25 | Publication de la V1.1 (nouveau `widget-main.js`, même nom de fichier). |
| 22:47:07 | Nouvelle demande de widget pour le même `booking_ref` : nouvelle session rattachée **au même brouillon** (reprise). |
| 22:47:55 | Scan du document depuis Android Chrome 157. |
| 22:48:29 | Voyageur ajouté. |
| 22:52:57 | Fiche soumise, 4 min 28 s plus tard. |

- Les libellés décrits par Hichem (« Voyageurs », « Ajouter un voyageur », petit bouton « Scanner un
  document », « Soumettre la fiche », référence de réservation affichée) n'existent **que** dans le
  bundle du 16/09 (commit 73b091f). Aucun ne figure dans le bundle V1.1.
- Le bundle était servi sous un nom **fixe** (`/widget-assets/widget-main.js`), sans `Cache-Control`
  ni version dans l'URL. Faute d'en-tête, Chrome applique une fraîcheur heuristique de 10 % de l'âge
  du fichier : le fichier du 16/09 avait environ 67 jours, soit **environ une semaine de cache sans
  revalidation**. Le téléphone, qui l'avait chargé à 22:19, l'a réutilisé à 22:47 sans redemander le
  nouveau.
- L'hypothèse « reprise d'un brouillon » est **en partie juste** : la session de 22:47 reprenait bien
  le brouillon de 22:19. Ce n'est pas elle qui a produit l'écran liste, mais elle a révélé un vrai
  défaut de la V1.1, corrigé aussi : en reprise d'un brouillon (mode `create`), le bootstrap ne
  renvoyait pas les voyageurs déjà saisis ; le widget aurait redemandé le voyageur 1.

Correctifs :
- `WidgetShellController` ajoute `?v=<empreinte du bundle>` aux URL du JS et du CSS : chaque nouveau
  bundle a une nouvelle URL, l'ancien cache devient inoffensif. La page porteuse du jeton est servie
  en `Cache-Control: no-store`.
- Le bootstrap renvoie `existing_guests` dans tous les modes, avec `removable`.

## 2. Attribution de la fiche de test du 22/11/2026

Fiche Dar Dou, arrivée du 18/04/2027 (check-in `01a20557…`, statut actif, 1 voyageur, chambre
« Chambre Turquoise », `test_mode = true`) :
- voyageur ajouté par **Hichem Mathlouthi** (compte Qayed réel, actif, non système) ;
- fiche finalisée (`completed_by`) par **Hichem Mathlouthi** ;
- brouillon initial créé par l'utilisateur d'intégration (normal : c'est Diarna qui l'a créé, avant
  toute ouverture du widget).

L'attribution est conforme. Cette fiche sera supprimée par la purge des données de test (lot 4).

<!-- SECTIONS SUIVANTES COMPLÉTÉES APRÈS INTÉGRATION DES LOTS 2 À 5 -->
