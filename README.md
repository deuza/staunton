[![License: CC0](https://img.shields.io/badge/license-CC0_1.0-lightgrey.svg?style=plastic)](https://creativecommons.org/publicdomain/zero/1.0/)
![Hack The Planet](https://img.shields.io/badge/hack-the--planet-black?style=plastic\&logo=Debian\&logoColor=white)
![Built With Love](https://img.shields.io/badge/built%20with-%E2%9D%A4%20by%20DeuZa-red?style=plastic)

[![GitHub last commit](https://img.shields.io/github/last-commit/deuza/staunton?style=plastic)](https://github.com/deuza/staunton/commits/main)
![GitHub commit activity](https://img.shields.io/github/commit-activity/t/deuza/staunton?style=plastic)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/deuza/staunton?style=plastic)

# Staunton - Générateur de diagrammes de positions d'échecs 

Page de saisie de position d'échecs, qui produit à la volée un diagramme au format PDF A4 ou en page HTML statique pour une étude ou un partage d'exercice.   

*Il est donc possible d'utiliser n'importe quelle position fantaisiste, comme mettre 5 rois, dans ce but.*

![screenshot](https://github.com/deuza/staunton/blob/934976460b53ddddbfda378a891432c1b4e4aa0e/images/staunton.png)

Le gabarit de sortie contient : case de 2,4 cm, notation des lignes et colonnes de l'échiquier, ligne d'annotation sous le plateau ainsi que la position FEN.   

Voici un exemple d'une sortie annotée :   
![./images/morphy.png](https://github.com/deuza/staunton/blob/934976460b53ddddbfda378a891432c1b4e4aa0e/images/morphy-mini.png)

Des exemples de sorties (vierges et commentées) au format HTML et PDF, sont disponibles dans le répertoire [images/ du dépôt](https://github.com/deuza/staunton/tree/main/images)

---

## Installation

### 1. Dépendances système

Sur Debian Trixie :

```
apt install php-tcpdf php-xml curl
```

TCPDF glisse par défaut, en bas de la dernière page, un "Powered by TCPDF" de 1 point en mode de rendu invisible, assorti d'une annotation de lien vers tcpdf.org. 

Rien ne se voit, mais le texte ressort à l'extraction et le lien reste cliquable dans tout document diffusé.   
La propriété qui commande ce comportement est protégée et sans accesseur public : `lib/sortie-pdf.php` définit donc une sous-classe `EchiquierPdf` qui l'éteint.

TCPDF inscrit aussi son adresse dans le champ `Producer`, à la fois dans le dictionnaire d'information et dans les métadonnées XMP.    
La même sous-classe la retire au moment de l'écriture : le champ se réduit à "TCPDF" suivi du numéro de version.

### 2. Dépendances du navigateur

Bien que les pièces soient livrées dans le dépôt dans le répertoire `assets`, elles peuvent être rapatriées à nouveau en local par le script `recuperer-assets.sh`.   

Ceci dans le but que la page tourne sans accès à Internet :

```
sh recuperer-assets.sh
```

Il récupère environ 270 ko : jQuery, chessboard.js, sa feuille de style, chess.js pour la lecture des PGN, et les douze pièces au format SVG.    
Il utilise uniquement `curl` pour les récupérer.

#### Si vous souhaitez modifier ces dépendances :

Les pièces sont le jeu Cburnett, celui que chessboard.js distribue en PNG de 80 pixels.  
On prend ici les SVG d'origine : même dessin, mais net à l'impression, et le même fichier alimente le navigateur et TCPDF.   
Un PNG de 80 pixels étalé sur une case de 2,4 cm ne ferait que 85 ppp.

Le dépôt lichess sert exactement le même jeu, déjà nommé au format chessboard.js.   
Le commit est figé dans le script : sans cela vos pièces changeraient au prochain envoi de lichess sur sa branche principale.

Si vous changez `JQUERY_VERSION` dans le script, pensez à reporter la même version dans la balise `<script>` de `index.php`.   
Il en va de même pour `CHESSJS_VERSION` et l'`import` du module dans `index.php`.

La page de saisie lie la largeur du plateau à la hauteur de la fenêtre, de façon à tenir sans ascenseur dès 700 px de zone utile, ce qui couvre un écran 1600x900. 

### 3.1 Apache

Rien de particulier : un `DocumentRoot` ou un alias vers le répertoire, et `libapache2-mod-php` ou `php-fpm` déjà en place.   
Le fichier `lib/.htaccess` interdit l'accès direct aux inclusions, à condition que `AllowOverride` soit actif sur le répertoire.   

A défaut, reportez la même directive dans votre `<Directory>`.

### 3.2 Nginx

Le script `recuperer-assets.sh` n'a rien à faire dans un répertoire servi en HTTP une fois qu'il a tourné. Vous pouvez le déplacer ou le supprimer.

---

## Utilisation

Glissez une pièce depuis l'une des deux palettes vers une case.   
Pour retirer une pièce, cliquez dessus sans la déplacer, ou faites-la glisser hors du plateau.

Le champ de chargement accepte aussi bien un FEN complet à six champs, une ligne PGN ou le placement à la main.   

Dans le premier cas le trait se cale automatiquement sur le deuxième champ.   
Un FEN refusé laisse le plateau intact et affiche la raison sous le champ.

Le même champ accepte une partie au format PGN, en-têtes compris.  
Les commentaires, variantes et annotations de pendule de Lichess sont admis, ainsi qu'un en-tête `[FEN]` pour une partie qui ne part pas de la position initiale.   

La position finale s'affiche, et quatre boutons permettent ensuite de remonter la partie coup par coup pour choisir le diagramme voulu, le trait suivant la navigation.   

Si le texte contient plusieurs parties, seule la première est lue.   
La touche Entrée charge, Maj+Entrée insère un saut de ligne.

Contrairement au FEN, un PGN passe par chess.js, qui vérifie la légalité de chaque coup : un coup impossible est refusé avec son libellé, et le plateau reste intact.

Le trait est indiqué sous le plateau, aligné à droite, avec une pastille ronde reprenant la convention des recueils de problèmes :  
- Pleine pour les Noirs, vide et cerclée pour les Blancs.

L'annotation est plafonnée à 288 caractères.   
Le chiffre est mesuré, pas choisi : c'est le plus grand nombre de caractères qui tienne dans les sept lignes disponibles sous le plateau, pour le pire caractère qui puisse atteindre le PDF.    

Rien n'est jamais tronqué, le champ refuse simplement la frappe suivante.

Le diagramme généré s'ouvre dans un nouvel onglet.   
La page de saisie reste donc intacte, et vous pouvez enchaîner une sortie PDF puis une sortie HTML sans avoir à reconstruire la position.
Ou modifier la position et refaire une génération.

**Rien n'est stocké côté serveur : ni fichier, ni session, ni base de données.**   

Les paramètres `fen`, `trait` et `notes` restent acceptés en GET, ce qui permet de revenir à une position depuis le FEN imprimé en bas d'une feuille papier.  
Un `fen` invalide dans l'URL est signalé par un bandeau plutôt qu'ignoré en silence.

### À propos de l'impression HTML

La fonction PDF est dédiée à l'impression, si vous souhaitez quand même imprimer une page web : 

La feuille fait 20,4 cm de large sur une A4 de 21 cm.   
Réglez la boîte de dialogue d'impression sur des marges par défaut ou nulles, sans mise à l'échelle, faute de quoi le navigateur réduira le diagramme et la case ne
fera plus 2,4 cm.

---

## Organisation des fichiers

| Fichier                  | Rôle                                                    |
|--------------------------|----------------------------------------------------------|
| `index.php`              | page de saisie                                          |
| `generer.php`            | aiguillage POST vers la sortie choisie                  |
| `app.js`                 | pilotage du plateau côté navigateur                     |
| `style.css`              | habillage de la page de saisie                          |
|                          |                                                         |                          |                                      
| `lib/echiquier.php`      | géométrie, validation FEN, nettoyage des entrées        |
| `lib/sortie-pdf.php`     | rendu TCPDF                                             |
| `lib/sortie-html.php`    | rendu HTML autonome                                     |
| `lib/.htaccess`          | refus d'accès direct aux inclusions                     |
|                          |                                                         |
| `recuperer-assets.sh`    | récupération des dépendances du navigateur              |
| `assets/`                | jQuery, chessboard.js, chess.js, les douze pièces SVG   |
|                          |                                                         |
| `lib/test.php`           | 113 contrôles de non-régression, php-cli pur            |
|                          |                                                         |
| `images`                 | screenshot et sorties d'exemples, vierges et commentés  |

---

## Tests

```
php lib/test.php
```

113 contrôles d'intégrité sans aucune dépendance.    
Le script renvoie 0 si tout passe et 1 sinon (il peut s'employer tel quel ou dans un crochet git ou après une modification du code source).

Ce qu'il couvre :

- Validation du placement, cas légitimes et structures cassées.
- Quinze charges hostiles : traversées de répertoire simples, profondes, doublées, encodées une et deux fois, chemins absolus, antislashes, wrappers `php://` `data://` `file://`, substitution shell, octet nul, saut de ligne final.
- Résolution du chemin des pièces, seul point du code qui touche le disque, avec ses propres charges hostiles.
- Conversion en grille et parité des cases, pour éviter le bug de la case a1 claire (inversion de l'échiquier). 
- Géométrie du gabarit, comparée aux valeurs du PDF ReportLab d'origine.
- Nettoyage de l'annotation : UTF-8 invalide, caractères de contrôle, espace insécable, troncature comptée en points de code.
- Sortie HTML : nombre de cases, trait annoncé, pastille ronde, coupure des mots longs, échappement des balises, absence de tout lien.
- Sortie PDF : en-tête, page unique, absence d'annotation de lien et d'adresse dans les métadonnées, cohérence de la longueur du flux XMP.

Ces tests sont utilisés par l'application pour garantir son fonctionnement et la sécurité du champ d'entrée du FEN.

## Licences

- chessboard.js 1.0.0, Chris Oakman, MIT.
- jQuery 3.7.1, MIT.
- chess.js 1.4.0, Jeff Hlywa, BSD-2-Clause.
- Pièces Cburnett, récupérées depuis le dépôt lichess, CC BY-SA 3.0.
- Le reste du code de ce répertoire est en CC-0 : Faites en ce que vous voulez :)

<p align="center">With ❤️ by <a href="https://github.com/deuza">DeuZa</a></p>
