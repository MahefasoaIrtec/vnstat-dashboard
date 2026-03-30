# vnStat Dashboard

Interface web simple pour suivre le trafic reseau fourni par `vnStat`.

Le tableau de bord affiche:

- le trafic journalier
- le trafic mensuel
- l'estimation de fin de mois
- un graphe sur les tranches de 5 minutes
- uniquement les interfaces que tu choisis, par exemple `RJ45` et `Wi-Fi`

## 1. Pre-requis

Avant de commencer, verifie que tu as:

- `vnStat` installe sur le serveur
- PHP 8.x ou plus
- un serveur web, par exemple Apache ou Nginx
- les droits pour executer `vnstat` depuis PHP

Verifications utiles:

```bash
vnstat --version
php -v
vnstat --json
```

Si `vnstat --json` ne renvoie rien, il faut d'abord activer ou initialiser vnStat sur la machine.

## 2. Installation du projet

Place les fichiers du projet dans un dossier accessible par ton serveur web.

Exemple avec Apache sur Linux:

```bash
sudo mkdir -p /var/www/html/vnstat
sudo cp -r . /var/www/html/vnstat
```

Si le projet est deja present dans `/var/www/html/vnstat`, tu peux passer directement a l'etape suivante.

## 3. Demarrer l'interface

### Avec Apache / Nginx

Ouvre l'URL du projet dans ton navigateur:

```text
http://localhost/vnstat/
```

ou, depuis un autre poste du reseau:

```text
http://adresse-du-serveur/vnstat/
```

### Avec le serveur PHP integre

Si tu veux tester rapidement en local:

```bash
php -S 127.0.0.1:8000 -t /var/www/html/vnstat
```

Puis ouvre:

```text
http://127.0.0.1:8000/
```

## 4. Choisir les interfaces a afficher

Par defaut, le tableau de bord affiche les interfaces definies en haut de `index.php`.

Edite cette partie:

```php
$defaultInterfaces = ['enp0s31f6', 'wlp2s0'];
$interfaceLabels = [
    'enp0s31f6' => 'RJ45',
    'wlp2s0' => 'Wi-Fi',
];
```

Tu peux remplacer ces noms par ceux de ta machine.

Exemples:

- `enp0s31f6` pour le port Ethernet
- `wlp2s0` pour le Wi-Fi
- tu peux ajouter ou retirer des interfaces virtuelles si besoin

### Filtre par URL

Tu peux aussi choisir les interfaces directement dans l'adresse:

```text
http://localhost/vnstat/?interfaces=enp0s31f6,wlp2s0
```

Pour tout afficher:

```text
http://localhost/vnstat/?interfaces=all
```

## 5. Comment lire les cartes

Pour chaque interface, l'interface affiche:

- le total download / upload
- la consommation d'aujourd'hui
- la consommation du mois en cours
- l'estimation de fin de mois
- les points de trafic sur 5 minutes

## 6. Trouver le bon nom d'interface

Si tu ne connais pas le nom exact de ton port reseau, utilise:

```bash
vnstat --iflist
ip link
```

Tu peux aussi verifier une interface precise:

```bash
vnstat -i wlp2s0
vnstat -m -i wlp2s0
```

## 7. Depannage

### La page s'ouvre mais n'affiche rien

Verifie:

- que `api.php` est bien accessible depuis le navigateur
- que `vnstat --json` fonctionne sur le serveur
- que les noms d'interfaces dans `index.php` sont corrects
- que l'interface a bien des donnees dans la base vnStat

### L'estimation de fin de mois est vide

Cela veut souvent dire que:

- l'interface n'a pas encore assez d'historique
- `vnstat -m` ne renvoie pas de ligne `estimated`
- le service vnStat n'a pas encore collecte de donnees pour cette interface

### Le navigateur affiche une erreur de chargement

Regarde la console du navigateur et les logs du serveur web.

Verefie aussi que PHP a le droit d'executer `vnstat` via `shell_exec()`.

## 8. Personnalisation

Tu peux personnaliser l'apparence dans:

- `css/main.css`

Tu peux personnaliser les interfaces affichees dans:

- `index.php`

Tu peux personnaliser la recuperation des donnees dans:

- `api.php`

## 9. Structure du projet

```text
vnstat/
├── api.php
├── index.php
├── css/
│   └── main.css
└── README.md
```

## 10. Resume rapide

1. Installer `vnStat` et PHP.
2. Copier le projet dans le repertoire web.
3. Modifier les interfaces dans `index.php`.
4. Ouvrir `http://localhost/vnstat/`.
5. Choisir `RJ45`, `Wi-Fi` ou toute autre interface utile.
