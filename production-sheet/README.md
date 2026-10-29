# Relais « feuille de suivi » — Google Sheets (Apps Script)

Ce relais ajoute **une ligne par demande** dans **ta propre feuille de calcul**,
pour faire le suivi individuel des prospects. Il est **totalement séparé** du
relais e-mail (`production-gas/Code.gs`) : celui-ci n'est ni modifié, ni
redéployé, et une panne de l'un n'affecte jamais l'autre.

```
Formulaire ──┬──► Relais e-mail (production-gas/)   → 3 e-mails
             │
             └──► Relais feuille (ce dossier)        → 1 ligne dans ta feuille
                     script attaché au classeur
                     aucune autorisation Gmail
```

Fichier à installer : **`Code.gs`** (dans ce dossier).

---

## 1. Installer le script dans la feuille (5 minutes, une seule fois)

1. Ouvrir **ta feuille de calcul** (celle créée avec ton adresse privée),
   connecté au compte qui la possède.
2. Menu **Extensions → Apps Script** : un projet est créé, **attaché à ce
   classeur** (c'est ce qui lui permet de retrouver la feuille tout seul).
3. Effacer le contenu de `Code.gs` et coller **tout** le fichier `Code.gs` de
   ce dossier.
4. Remplacer la valeur de `TOKEN` par un jeton long et aléatoire — par exemple
   en ouvrant la console du navigateur (F12) et en collant :

   ```js
   crypto.randomUUID().replace(/-/g, '') + crypto.randomUUID().replace(/-/g, '')
   ```

   **Garder ce jeton** : il faudra le même dans les pages.
5. Optionnel — `SHEET_TAB = 'Leads'` pour écrire dans un onglet précis ; vide
   = la première feuille du classeur.

### Test de la feuille (recommandé)

- Menu **Exécuter** → fonction **`testLigne`** → premier lancement : Google
  demande une autorisation.
  - « Google n'a pas vérifié cette application » → **Paramètres avancés** →
    **Accéder à … (non sécurisé)** : c'est votre propre script.
- Une ligne de test apparaît dans la feuille, et le journal (**Exécutions**)
  affiche `{"ok":true,"row":2,...}`.

---

## 2. Publier l'application web

1. **Déployer → Nouveau déploiement → Application web**.
2. Renseigner :
   - **Description** : `Feuille de suivi HelloLead`
   - **Exécuter en tant que** : **moi** (le compte propriétaire de la feuille)
   - **Qui a accès** : **Tout le monde** — indispensable : la page appelle
     l'URL sans compte Google.
3. **Déployer** → autoriser si besoin → copier l'**URL** terminée par `/exec`.
4. L'ouvrir dans un navigateur : elle doit répondre

   ```json
   {"ok":true,"service":"HelloLead — feuille de suivi","ready":true,
    "tab":"Leads","rows":12,"columns":16,"headers":["Reçue le", …]}
   ```

   `ready:true` = jeton configuré ; `headers` = colonnes réellement reconnues.

---

## 3. Brancher le formulaire

Dans **chacune des 4 pages**, en tête du `<script>`, une seule ligne à changer :

```js
sheet: { url: '', token: '' },
```

→

```js
sheet: { url: 'https://script.google.com/macros/s/AKfy…/exec', token: 'le-même-jeton-que-dans-TOKEN' },
```

Rien d'autre ne bouge : `provider: 'gas'`, `gas: { … }` et le reste de la
configuration restent identiques. Laisser `sheet` vide = aucune ligne écrite
(les e-mails partent normalement).

Puis envoyer une demande test : elle doit apparaître en ligne dans la feuille.

---

## 4. Colonnes reconnues (adaptation automatique)

Le script **lit la ligne 1 de ta feuille** et remplit chaque colonne dont le
nom correspond à un champ. Comparaison insensible à la casse, aux accents, aux
espaces et à la ponctuation finale : `E-mail`, `EMAIL`, `e mail` ou `Courriel`
sont équivalents.

| Champ | Intitulés acceptés (entre autres) |
|---|---|
| Reçue le | `Reçue le`, `Date`, `Date de réception`, `Horodatage` |
| Référence | `Référence`, `Ref`, `Lead ID`, `Identifiant` |
| Formulaire | `Formulaire`, `Form`, `Offre` |
| Nom et prénom | `Nom`, `Nom et prénom`, `Contact` |
| Société | `Société`, `Entreprise`, `Raison sociale` |
| E-mail | `E-mail`, `Email`, `Mail`, `Courriel` |
| Téléphone | `Téléphone`, `Tel`, `Mobile`, `Numéro` |
| Code postal | `Code postal`, `CP`, `Postal` |
| Volume souhaité | `Volume`, `Volume souhaité`, `Nombre de leads` |
| Besoins | `Besoins`, `Besoin`, `Services` |
| Page d'origine | `Page`, `Page d'origine`, `URL`, `Landing` |
| UTM source / support / campagne | `UTM source`, `Source`, `UTM support`, `Support`, `UTM campagne`, `Campagne` |
| gclid / fbclid | `gclid`, `fbclid` |

**Colonnes absentes** : elles sont ajoutées automatiquement **à la fin de la
ligne 1**, dans l'ordre du tableau ci-dessus, pour ne rien perdre (UTM, page
d'origine…). Les colonnes existantes, elles, ne sont **jamais** renommées,
déplacées ni écrasées. Pour désactiver cet ajout : `ADD_MISSING_COLUMNS = false`.

**Formats appliqués à la ligne écrite uniquement** : date lisible
(`jj/mm/aaaa hh:mm`), téléphone et code postal en **texte** (le `0` initial est
conservé), e-mail cliquable.

---

## 5. Garanties

| Situation | Comportement |
|---|---|
| Même demande renvoyée deux fois | ignorée (déduplication par **Référence**) |
| Deux demandes simultanées | verrou d'écriture, pas de lignes entremêlées |
| Feuille indisponible / URL erronée | la page n'affiche **rien** au visiteur ; les e-mails partent ; l'échec est tracé dans GTM (`hl_lead_form_sheet`) |
| Mode démonstration (`provider: 'local'`) | aucune écriture, aucun appel réseau |
| Plus de 300 lignes par jour | refus `quota` (garde-fou anti-abus) |

Le script **ne fait qu'ajouter des lignes** : il ne supprime, ne modifie et ne
lit jamais les autres colonnes de votre feuille.

---

## 6. Sécurité

- L'URL et le jeton sont **visibles dans le code source de la page** (comme
  toute configuration côté navigateur). Ils évitent un usage accidentel, pas un
  usage malveillant déterminé — d'où le plafond de 300 lignes/jour et le fait
  que le script n'écrit **que** dans cette feuille.
- En cas d'abus : changer `TOKEN`, **Déployer → Gérer les déploiements →
  Nouvelle version → Déployer**, puis reporter le nouveau jeton dans les
  4 pages.
- La feuille doit rester **privée** (partagée au maximum avec l'équipe).

---

## 7. RGPD et conservation

Les données de prospects sont désormais stockées dans un fichier Google : la
durée de conservation doit être décidée et appliquée.

- **Suppression par lot** : sélectionner les lignes anciennes → clic droit →
  *Supprimer les lignes sélectionnées*.
- **Automatisation possible** : dans le même projet Apps Script, ajouter un
  déclencheur mensuel qui supprime les lignes dont la date dépasse 180 jours.
- La copie locale du navigateur (`/boite-de-reception.html`) reste un journal
  de secours, effaçable d'un bouton.

---

## 8. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `/exec` répond `"ready":false` | `TOKEN` encore sur la valeur d'exemple | le remplacer, enregistrer, redéployer une **nouvelle version** |
| Réponse HTML « Connexion à votre compte Google » | accès non réglé sur **Tout le monde** | modifier le déploiement |
| `{"ok":false,"error":"token"}` | jeton différent entre la page et `TOKEN` | recopier le même jeton des deux côtés |
| `{"ok":false,"error":"invalid"}` | corps vide, ou fiche sans nom ni e-mail | vérifier que la page envoie bien du JSON |
| `{"ok":false,"error":"quota"}` | 300 lignes dans la journée | le formulaire continue normalement, sans la ligne |
| « Aucun classeur » dans les journaux | script non attaché à la feuille | ouvrir la feuille → **Extensions → Apps Script**, ou renseigner `SHEET_ID` |
| « Onglet introuvable » | `SHEET_TAB` ne correspond à aucun onglet | corriger le nom ou vider la variable |
| Les colonnes se dupliquent | en-têtes existants avec un intitulé non reconnu | renommer l'en-tête selon le tableau § 4, ou compléter la liste `FIELDS` |
| Ligne écrite mais valeurs au mauvais endroit | `ADD_MISSING_COLUMNS` a ajouté des colonnes après coup | supprimer les colonnes ajoutées et renommer proprement la ligne 1 |
| Aucune ligne alors que tout semble prêt | URL `/exec` avec un `?` ou un espace de trop | recopier l'URL exacte, jusqu'à `/exec` |
