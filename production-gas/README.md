# Relais e-mail Gmail — Google Apps Script (recommandé)

Ce relais permet au formulaire d'envoyer **les trois e-mails** (responsable,
chef de département, client) **directement depuis
`ifeminformatique@gmail.com`**, sans PHP, sans serveur et sans service tiers :
le script vit dans la boîte elle-même et expédie par l'infrastructure Gmail.

```
Page du formulaire ──POST text/plain──► Application web Apps Script
        (une seule requête)                     │ exécutée par la boîte
                                                ▼
                              Gmail : 1) responsable  2) chef [Copie]  3) client
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

Dans **chacune des 4 pages** (`lead-assurances`, `lead-assurances-rcs`,
`lead-energies`, `lead-energies-rcs`), en tête du `<script>` :

```js
provider: 'gas',
gas: {
  url: 'https://script.google.com/macros/s/AKfy…/exec',
  token: 'le-même-jeton-que-dans-TOKEN'
},
fallbackProvider: 'formsubmit',
```

Puis envoyer une demande test : **trois e-mails** doivent arriver (responsable,
chef avec l'objet `[Copie] …`, et le prospect saisi), tous expédiés par
`ifeminformatique@gmail.com`.

---

## 4. Quotas et garde-fous

| Limite | Valeur | Conséquence |
|---|---|---|
| Gmail (compte personnel) via Apps Script | **100 e-mails / jour** | 3 e-mails par demande → ~33 demandes/jour |
| Garde-fou du script (`MAX_LEADS_PER_DAY`) | **30 demandes / jour** | au-delà : refus `quota`, repli FormSubmit |
| Taille d'un message (`MAX_MESSAGE_CHARS`) | 120 000 caractères | message refusé (`invalid`) |

Bien au-delà du besoin réel : 30 demandes/jour = 900 par mois.

---

## 5. Sécurité, à connaître

- L'URL et le jeton sont **visibles dans le code source de la page** (comme
  toute configuration côté navigateur). Ils empêchent un usage accidentel, pas
  un usage malveillant déterminé : quelqu'un qui les extrait peut envoyer des
  messages depuis la boîte, **dans la limite de 30 demandes/jour**.
- En cas d'abus : changer `TOKEN` dans le script → **Déployer → Gérer les
  déploiements → Modifier → Nouvelle version**, puis reporter le nouveau jeton
  dans les 4 pages.
- Le relais **n'écrit rien** dans la boîte à part les messages envoyés ; il ne
  lit aucune donnée du compte. Les journaux d'exécution Google sont conservés
  quelques jours (désactivables dans le projet).
- RGPD : le traitement reste chez Google (Gmail), déjà sous-traitant de la
  messagerie ; aucun intermédiaire supplémentaire n'entre en jeu.

---

## 6. Mettre à jour le script

Modifier `Code.gs` ne suffit pas : l'URL publique sert la **version déployée**.

**Déployer → Gérer les déploiements → (crayon) → Version : Nouvelle version →
Déployer.** L'URL `/exec` reste la même.

---

## 7. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `{"ok":false,"error":"token"}` | jeton différent entre la page et `TOKEN` | recopier le même jeton des deux côtés |
| Réponse HTML « Connexion à votre compte Google » | accès non réglé sur **Tout le monde** | modifier le déploiement |
| `{"ok":false,"error":"invalid"}` | corps vide ou message incomplet | vérifier que la page envoie bien du JSON |
| `{"ok":false,"error":"quota"}` | 30 demandes/jour ou 100 e-mails/jour atteints | le formulaire bascule seul sur FormSubmit |
| `Script function not found: doPost` | fichier non enregistré, ou déploiement sur une autre version | enregistrer puis redéployer |
| Aucun e-mail reçu | adresse invalide, ou filtrage | consulter **Exécutions** (journal) et les indésirables |
| L'expéditeur n'est pas `ifeminformatique@gmail.com` | script déployé depuis une autre boîte, ou « Exécuter en tant que » mal réglé | recréer le déploiement depuis le bon compte |
