# Relais e-mail Gmail — Google Apps Script (recommandé)

Ce relais permet au formulaire d'envoyer **les trois e-mails** (responsable,
chef de département, client) **directement depuis
`ifeminformatique@gmail.com`**, sans PHP, sans serveur et sans service tiers :
le script vit dans la boîte elle-même et expédie par l'infrastructure Gmail.

```
Page du formulaire ──POST text/plain──► Application web Apps Script
        (une seule requête)                     │ exécutée par la boîte
                                                ├──► Gmail : 1) responsable
                                                │             2) chef [Copie]
                                                │             3) client
                                                └──► Google Sheets : une ligne
                                                     par prospect (suivi)
```

Le fichier à installer est **`Code.gs`** (dans ce dossier).

---

## 1. Installer le script dans la boîte (5 minutes, une seule fois)

1. Ouvrir <https://script.google.com> **connecté au compte
   `ifeminformatique@gmail.com`** (vérifier l'avatar en haut à droite : c'est
   ce compte qui expédiera les messages).
2. **Nouveau projet** → renommer en `HelloLead — relais e-mail`.
3. Effacer le contenu de `Code.gs` et coller **tout** le fichier `Code.gs`
   fourni ici.
4. Remplacer la valeur de `TOKEN` par un jeton long et aléatoire, par exemple
   en ouvrant la console du navigateur (F12) et en collant :

   ```js
   crypto.randomUUID().replace(/-/g, '') + crypto.randomUUID().replace(/-/g, '')
   ```

   Copier le résultat et **le garder de côté** : il faudra le même dans les
   pages.
5. Enregistrer (💾).

### Test facultatif mais recommandé

- Menu **Exécuter** → fonction `testEnvoi` → premier lancement : Google demande
  une autorisation.
  - « Google n'a pas vérifié cette application » → **Paramètres avancés** →
    **Accéder à HelloLead — relais e-mail (non sécurisé)** : c'est votre propre
    script, l'avertissement est normal.
- Un e-mail de test arrive dans la boîte ; le journal (**Exécutions**) affiche
  `{"ok":true,"sent":{"responsable":true}}`.

### Expéditeur affiché (`FROM_ADDRESS`)

Par défaut (`var FROM_ADDRESS = '';`), les messages partent de la boîte qui
héberge le script — l'authentification Gmail est alors parfaite sans réglage.

Pour afficher une autre adresse, par exemple `pbenateau@hellolead.pro` :

1. Gmail → **Paramètres** → onglet **Comptes** → « Envoyer des e-mails en tant
   que » → **Ajouter une autre adresse e-mail** → vérifier l'adresse.
2. Dans `Code.gs` : `var FROM_ADDRESS = 'pbenateau@hellolead.pro';`
3. **Déployer → Gérer les déploiements → (crayon) → Nouvelle version →
   Déployer.**

Si Gmail refuse l'alias (non vérifié, révoqué), le script le signale dans le
journal et **expédie quand même** depuis la boîte : aucune demande perdue.
L'adresse réellement utilisée est visible sur l'URL `/exec` (champ `from`).

---

## 2. Publier l'application web

1. **Déployer** → **Nouveau déploiement** → type **Application web**.
2. Renseigner :
   - **Description** : `Formulaire HelloLead`
   - **Exécuter en tant que** : **moi (`ifeminformatique@gmail.com`)** — c'est
     ce réglage qui fait partir les messages de cette adresse.
   - **Qui a accès** : **Tout le monde** — indispensable : la page appelle
     l'URL sans compte Google. Sans ce réglage, Google renvoie une page de
     connexion et le formulaire échoue.
3. **Déployer** → autoriser si demandé → **copier l'URL** de l'application web
   (elle se termine par `/exec`).
4. Vérifier dans un navigateur : l'URL doit répondre

   ```json
   {"ok":true,"service":"HelloLead","ready":true,"remaining":97}
   ```

   `ready:true` = le jeton est bien configuré ; `remaining` = e-mails encore
   disponibles aujourd'hui chez Gmail.

---

## 3. Brancher le formulaire

### Où exactement ?

Chaque page contient **un seul** bloc de configuration, en haut du `<script>`
du widget du formulaire. Il commence par `window.HL_LEAD_CONFIG = {` et se
termine par `};`. C'est le seul endroit à modifier.

> **Dans ce dépôt, les 4 pages sont déjà renseignées** avec le déploiement de
> production. Cette étape ne concerne donc que les pages à (ré)importer dans
> WordPress, ou un futur changement de déploiement.

Dans ce bloc, il y a **une seule ligne à changer** :

```js
gas: { url: '', token: '' },
```

Rechercher cette ligne dans la page (Ctrl+F / ⌘+F sur `gas:`), et la remplacer
par les deux valeurs relevées à l'étape 2 :

```js
gas: { url: 'https://script.google.com/macros/s/AKfy…/exec', token: 'le-même-jeton-que-dans-TOKEN' },
```

Rien d'autre ne bouge : ni `provider: 'gas'` (déjà en place), ni
`fallbackProvider` (déjà en place), ni les adresses. Attention à garder :

- les **guillemets simples** autour des deux valeurs ;
- la **virgule finale** ;
- le jeton **identique** à la constante `TOKEN` du script (majuscules et
  chiffres compris).

### Comment y accéder selon l'endroit où vit la page

| Contexte | Chemin |
|---|---|
| **WordPress + Elementor** | ouvrir la page → cliquer sur le bloc qui contient le formulaire → onglet **Contenu** du widget **HTML** → bouton **Modifier le HTML** → chercher `HL_LEAD_CONFIG` dans le code |
| **Éditeur de thème / fichiers du site** | ouvrir `lead-…/index.html` → chercher `HL_LEAD_CONFIG` |
| **Jeu de pages de ce dépôt** | mêmes fichiers : `lead-…/index.html` |

### Les 4 pages à modifier (une fois chacune)

| Page | Fichier |
|---|---|
| Assurances | `lead-assurances/index.html` |
| Assurances RCS | `lead-assurances-rcs/index.html` |
| Énergies | `lead-energies/index.html` |
| Énergies RCS | `lead-energies-rcs/index.html` |

Les **mêmes** `url` et `token` servent sur les quatre pages.

### Vérifier que c'est bien pris en compte

1. Enregistrer la page, l'ouvrir dans un navigateur, **vider le cache**
   (Ctrl+Shift+R).
2. Console du navigateur (F12) :

   ```js
   window.HL_LEAD_CONFIG.gas
   // → { url: 'https://script.google.com/macros/s/AKfy…/exec', token: '…' }
   ```

   Si `url` et `token` sont vides, la modification n'a pas été enregistrée (ou
   pas sur la bonne page).
3. Envoyer une demande test : **trois e-mails** arrivent — `cremy@hellolead.pro`
   (responsable), `pbenateau@hellolead.pro` (objet `[Copie] …`) et le prospect
   saisi — expédiés par la boîte qui héberge le script (ou par `FROM_ADDRESS`,
   § précédent).

> Si vous préférez ne pas toucher au code : transmettez l'`url` et le `token` à
> l'équipe technique — ce sont exactement ces deux valeurs, et rien d'autre, à
> reporter dans les quatre pages.

---

## 4. Feuille de suivi Google (automatique)

Chaque demande valide ajoute **une ligne** dans une feuille de calcul : les
données complètes du prospect, prêtes pour le suivi individuel.

### Comment ça se passe

1. À la première demande après la mise à jour, le script **crée** la feuille
   « Leads HelloLead — suivi », onglet **Leads**.
2. Elle est **partagée automatiquement** en modification avec
   `SHEET_EMAIL` (`manajahelvin@gmail.com` dans le script) : l'adresse reçoit un
   e-mail d'invitation Google, il suffit de l'accepter.
3. L'identifiant est mémorisé dans les propriétés du script (`SHEET_ID`) : la
   même feuille sert ensuite indéfiniment.
4. L'URL de la feuille est renvoyée par l'URL `/exec` (champ `sheet`) et par
   chaque réponse du formulaire (`sheet.url`).

> **Réautorisation nécessaire une seule fois** : l'accès à Sheets et Drive
> ajoute des autorisations. Après avoir collé le nouveau `Code.gs`, lancer
> **Exécuter → `testFeuille`** et accepter la demande de Google, puis
> **Déployer → Gérer les déploiements → Nouvelle version → Déployer**.

### Colonnes

| # | Colonne | Contenu |
|---|---|---|
| 1 | Reçue le | date et heure (format `jj/mm/aaaa hh:mm`) |
| 2 | Référence | identifiant unique de la demande (`hl-…`), sert à éviter les doublons |
| 3 | Formulaire | Assurances pro, Énergies pro (RCS)… |
| 4 | Nom et prénom | |
| 5 | Société | |
| 6 | E-mail | cliquable (`mailto:`) |
| 7 | Téléphone | conservé en texte (zéros initiaux) |
| 8 | Code postal | conservé en texte |
| 9 | Volume souhaité | fourchette choisie à l'étape 1 |
| 10 | Besoins | séparés par `·` |
| 11 | Page d'origine | URL exacte du formulaire |
| 12-16 | UTM source / support / campagne, `gclid`, `fbclid` | traçabilité des campagnes |
| 17 | Suivi par | `SHEET_EMAIL` — qui traite la demande |
| 18 | E-mails | ce qui est réellement parti : `3/3 (responsable, chef, client)` |

Les en-têtes sont figés, les colonnes dimensionnées, la première ligne colorée
aux couleurs du site.

### Garanties

- **Aucun doublon** : une même référence n'est jamais écrite deux fois, même si
  le navigateur renvoie sa requête ; un verrou empêche deux écritures
  simultanées.
- **Jamais bloquant** : si la feuille est indisponible, les e-mails partent
  quand même et le visiteur voit le succès normal.
- **Aucune donnée inventée** : une ligne n'est écrite que si la fiche est
  présente (les pages à jour l'envoient toujours).

### Utiliser une feuille existante

Créer la feuille, puis renseigner son identifiant (la partie entre `/d/` et
`/edit` dans l'URL) dans **Paramètres du projet → Propriétés du script** :

| Propriété | Valeur |
|---|---|
| `SHEET_ID` | `1AbC…xyz` |

Ajouter l'onglet **Leads** (les en-têtes seront créés à la première demande).
Le script n'essaiera pas de créer une autre feuille.

### Purge RGPD

Les données de prospects ne doivent pas être conservées indéfiniment :

- **Exécuter → `purgerLeadsAnciens`** supprime les lignes de plus de 180 jours
  (paramétrable : `purgerLeadsAnciens(90)`).
- Un déclencheur mensuel peut l'automatiser : **Déclencheurs → Ajouter un
  déclencheur → `purgerLeadsAnciens` → mensuel**.

---

## 5. Quotas et garde-fous

| Limite | Valeur | Conséquence |
|---|---|---|
| Gmail (compte personnel) via Apps Script | **100 e-mails / jour** | 3 e-mails par demande → ~33 demandes/jour |
| Garde-fou du script (`MAX_LEADS_PER_DAY`) | **30 demandes / jour** | au-delà : refus `quota`, repli FormSubmit |
| Taille d'un message (`MAX_MESSAGE_CHARS`) | 120 000 caractères | message refusé (`invalid`) |

Bien au-delà du besoin réel : 30 demandes/jour = 900 par mois.

---

## 6. Sécurité, à connaître

- L'URL et le jeton sont **visibles dans le code source de la page** (comme
  toute configuration côté navigateur) et donc dans ce dépôt Git. Ils empêchent
  un usage accidentel, pas un usage malveillant déterminé : quelqu'un qui les
  extrait peut envoyer des messages depuis la boîte, **dans la limite de
  30 demandes/jour**.
- En cas d'abus : changer `TOKEN` dans le script → **Déployer → Gérer les
  déploiements → Modifier → Nouvelle version**, puis reporter le nouveau jeton
  dans les 4 pages.
- Le relais **n'écrit rien** dans la boîte à part les messages envoyés ; il ne
  lit aucune donnée du compte. Les journaux d'exécution Google sont conservés
  quelques jours (désactivables dans le projet).
- RGPD : le traitement reste chez Google (Gmail), déjà sous-traitant de la
  messagerie ; aucun intermédiaire supplémentaire n'entre en jeu.

---

## 7. Mettre à jour le script

Modifier `Code.gs` ne suffit pas : l'URL publique sert la **version déployée**.

**Déployer → Gérer les déploiements → (crayon) → Version : Nouvelle version →
Déployer.** L'URL `/exec` reste la même.

---

## 8. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `{"ok":false,"error":"token"}` | jeton différent entre la page et `TOKEN` | recopier le même jeton des deux côtés |
| Réponse HTML « Connexion à votre compte Google » | accès non réglé sur **Tout le monde** | modifier le déploiement |
| `{"ok":false,"error":"invalid"}` | corps vide ou message incomplet | vérifier que la page envoie bien du JSON |
| `{"ok":false,"error":"quota"}` | 30 demandes/jour ou 100 e-mails/jour atteints | le formulaire bascule seul sur FormSubmit |
| `Script function not found: doPost` | fichier non enregistré, ou déploiement sur une autre version | enregistrer puis redéployer |
| Aucun e-mail reçu | adresse invalide, ou filtrage | consulter **Exécutions** (journal) et les indésirables |
| L'expéditeur n'est pas celui attendu | script déployé depuis une autre boîte, « Exécuter en tant que » mal réglé, ou `FROM_ADDRESS` vide | vérifier le champ `from` renvoyé par l'URL `/exec` |
| « Alias refusé » dans les journaux | adresse de `FROM_ADDRESS` non vérifiée dans Gmail | la vérifier (Paramètres → Comptes), ou vider `FROM_ADDRESS` |
| Aucune feuille créée | autorisations Sheets/Drive non accordées, ou script non redéployé | Exécuter → `testFeuille`, accepter l'autorisation, redéployer une **nouvelle version** |
| « Feuille introuvable » dans les journaux | feuille supprimée ou `SHEET_ID` erroné | vider la propriété `SHEET_ID` (le script en recrée une) ou corriger l'identifiant |
| Lignes en double | envoi manuel répété avec une nouvelle référence | les doublons ne concernent que des références différentes ; supprimer la ligne à la main |
| Téléphone sans le zéro initial | colonne modifiée à la main hors format texte | garder le format « Texte » sur la colonne G (format appliqué automatiquement aux nouvelles lignes) |
