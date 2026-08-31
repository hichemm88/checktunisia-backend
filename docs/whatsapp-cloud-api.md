# WhatsApp Cloud API — mise en service

Ce document couvre le canal de transmission **actif** des fiches de police.
Pour l'historique de l'extraction derrière une interface, voir
[canal-transmission.md](canal-transmission.md).

## Pourquoi cette migration

Le relais `whatsapp-web.js` reposait sur un numéro appairé à WhatsApp Web par
QR code. **Ce numéro a été banni par WhatsApp.** Dernière connexion :
17/08/2026. Depuis, les fiches s'accumulent en file sans partir — c'est-à-dire
que l'obligation légale du produit n'est plus remplie.

La Cloud API officielle remplace ce relais. Elle supprime le point unique de
défaillance (session, QR, volume Railway, worker Node) mais impose deux règles
de Meta qui changent la conception :

1. **Hors fenêtre de 24 h, seul un modèle approuvé passe.** Une fiche de police
   n'ouvre jamais de conversation : personne n'y répond. La fenêtre est donc
   toujours fermée, et tout part par modèle.
2. **Un modèle ne porte qu'un seul média.** Les photos de documents ne
   transitent plus par WhatsApp. Le destinataire ouvre la fiche complète,
   pièces comprises, derrière le bouton « Consulter la fiche ».

Ce second point est un changement de conception assumé — et une meilleure
hygiène de données personnelles : des scans d'identité ne dorment plus dans les
téléphones et les sauvegardes iCloud/Google de chaque destinataire.

## Ce que le code fait

| Pièce | Rôle |
|---|---|
| `WhatsappCloudApi` | Seul endroit qui parle à Meta : URL, jeton, forme des réponses. |
| `WhatsappCloudErrors` | Traduit un code Meta en trois décisions : retenter ? alerter ? que dire ? |
| `FicheTemplate` | Fiche → variables du modèle, avec les contraintes que Meta impose aux variables. |
| `WhatsAppCloudChannel` | Adaptateur `DeliveryChannel` en push. Dernière barrière avant Meta. |
| `WhatsappSendingGuard` | Les quatre freins : coupe-circuit, bascule, débit, arriéré. |
| `WhatsappWebhookController` | Accusés de réception signés (`GET`/`POST /api/v1/webhooks/whatsapp`). |
| `whatsapp:dispatch` | Vide la file — la Cloud API est en **push**, plus rien ne vient chercher les fiches. |

## Destinataires

**Une fiche part à PLUSIEURS destinataires**, comme avec le relais Web. La
règle n'a pas changé d'un iota :

- si l'établissement a des agents cochés dans `hotel_whatsapp_recipients`
  (Établissement > Destinataires WhatsApp, profils autorité avec
  `receives_whatsapp_fiches`), la fiche part à **chacun** ;
- sinon, repli sur le numéro global `WHATSAPP_RECIPIENT`.

Chaque couple (voyageur × destinataire) est une **ligne d'outbox distincte**,
donc son propre `wamid`, son propre statut, ses propres retries. Un
destinataire injoignable n'empêche pas les autres de recevoir la fiche.

Cette liste n'a jamais été en dur dans le code : rien à migrer.

## Les garde-fous, et pourquoi ils existent

Le numéro émetteur est **neuf** et la vérification d'entreprise Meta est encore
en cours. Un émetteur neuf qui expédie soudain des centaines de messages vers
des comptes qui ne lui ont jamais écrit est le profil exact que Meta bannit.

| Garde-fou | Variable | Effet |
|---|---|---|
| Coupe-circuit | `WHATSAPP_SENDING_ENABLED` | `false` → plus rien ne part. Relu à **chaque** envoi. |
| Bascule | `WHATSAPP_CLOUD_API_CUTOVER_AT` | Aucune fiche créée avant cet instant ne part. **Non définie = rien ne part du tout.** |
| Débit | `WHATSAPP_MAX_SENDS_PER_MINUTE` (20) | La file attend le créneau suivant. |
| Plafond | `WHATSAPP_MAX_SENDS_PER_DAY` (500) | Reprise le lendemain + alerte. |
| Arriéré | `WHATSAPP_BACKLOG_ALERT_THRESHOLD` (50) | Au-delà, **l'envoi automatique s'arrête** et réclame une décision humaine. |
| Qualité | codes Meta 131049 / 80007 | Pause globale de 15 min. |

Aucun de ces freins ne perd de fiche : tout reste en attente.

Le garde-fou d'arriéré mérite une phrase de plus. Un arriéré n'est pas un
volume de travail en retard, c'est **le symptôme d'une panne**. Le vider
automatiquement transforme une panne silencieuse en rafale vers des officiels —
c'est très exactement ce qui vient de coûter le numéro précédent. Le déblocage
est donc manuel, explicite et temporaire :

```bash
php artisan whatsapp:allow-backlog --minutes=60
```

## Ordre de mise en service

Chaque étape suppose la précédente. Ne pas sauter la 3.

### 1. Déployer, tout verrouillé

```
WHATSAPP_SENDING_ENABLED=false
WHATSAPP_CLOUD_API_CUTOVER_AT=          # non définie
WHATSAPP_CHANNEL=cloud                  # voir ci-dessous
```

Aucun envoi n'est possible. C'est l'état correct pour un déploiement.

`cloud` est le défaut du code : la ligne n'est nécessaire que si
`WHATSAPP_CHANNEL=web` traîne encore dans l'environnement Railway, où elle
l'emporterait sur le défaut. **Vérifier explicitement cette variable** — un
canal resté sur `web` signifie un canal légal muet, sans que rien ne le
signale.

### 2. Créer le modèle et attendre l'approbation

```bash
php artisan whatsapp:templates --create
```

Puis, pour suivre (l'approbation est asynchrone, de quelques minutes à quelques
jours — et hors de notre contrôle) :

```bash
php artisan whatsapp:templates
```

**Ne pas continuer tant que le statut n'est pas `APPROVED`.**

### 3. Neutraliser l'arriéré — ne jamais l'envoyer

Environ 715 fiches en attente datent du bannissement. Les séjours concernés
sont terminés ; les envoyer n'apporterait rien et ferait bannir le nouveau
numéro.

```bash
php artisan whatsapp:cancel-backlog                 # dry-run : décompte par établissement et par jour
php artisan whatsapp:cancel-backlog --apply         # annule + écrit le rapport CSV
```

Le dry-run est le défaut. Rien n'est supprimé : les fiches passent en
« annulé » avec le motif `pre_cutover_backlog` et restent consultables dans
Administration > WhatsApp (compteur « Annulés »). Le rapport CSV est déposé
dans `storage/app/whatsapp/` (hors dépôt) pour un traitement manuel éventuel.

La commande est idempotente : relancée, elle ne trouve plus rien.

### 4. Armer la bascule

```
WHATSAPP_CLOUD_API_CUTOVER_AT=2026-08-31T23:00:00+01:00   # l'instant présent
```

À partir de là, seules les fiches **créées après** cet instant peuvent partir —
y compris via un renvoi manuel.

### 5. Enregistrer le webhook

L'endpoint doit être **déployé et joignable** : Meta appelle le `GET` de
vérification pendant l'exécution.

```bash
php artisan whatsapp:configure-webhook
```

La commande enchaîne les deux abonnements qu'on confond souvent — et c'est
cette confusion qui fait qu'un webhook « configuré » ne reçoit rien :

1. l'**app** déclare son URL de rappel et s'abonne à `whatsapp_business_account` ;
2. le **WABA** s'abonne à l'app.

Puis elle relit l'état chez Meta. Vérifier dans les logs backend que le `GET` a
bien été reçu.

### 6. Ouvrir le robinet et tester

```
WHATSAPP_SENDING_ENABLED=true
```

Faire un check-in de test vers **un numéro de test — pas un officiel**, et
vérifier :

- la ligne passe `sent` avec un `wamid` ;
- le webhook fait passer `delivery_status` à `delivered` puis `read`.

### 7. Bascule réelle

Seulement maintenant, remettre la vraie liste de destinataires.

## Retour arrière

```
WHATSAPP_SENDING_ENABLED=false
```

Effet immédiat, rien n'est perdu. Le retour à l'ancien relais
(`WHATSAPP_CHANNEL=web` ou `WHATSAPP_PROVIDER=legacy`) reste câblé, mais **il
ne transmettra rien** : le numéro est banni. Il n'est conservé que pour ne pas
supprimer du code encore utile au diagnostic.

## Ce qui reste à faire

Honnêtement :

- **Le bouton pointe vers `/authority/guests/{id}`**, la page du portail
  autorité, qui exige un compte. Il n'existe pas encore de page « fiche »
  accessible par jeton signé. Le jour où elle existera, il faudra changer
  `WHATSAPP_FICHE_URL_BASE` **et soumettre un nouveau modèle** : l'URL de base
  est figée à l'approbation.
- **Les destinataires sans compte autorité** (repli sur le numéro global)
  reçoivent donc un bouton qu'ils ne peuvent pas ouvrir sans compte.
- **Le code du relais Web n'est pas supprimé** (worker Node, coffre de session,
  routes internes). Nettoyage dans une PR ultérieure, une fois la Cloud API
  stabilisée en production.
