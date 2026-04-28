---
trigger: always_on
---

- En aucun cas tu ne dois implémenter du code sans l'approbation de l'utilisateur.
- Pour toute modification de code, un plan d'implémentation (via la commande /wp-plan) est obligatoire.
- Aucun patch ne doit être  développé "à la volée".
- Avant de lancer une commande, ou un traitement, dis explicitement ce que tu t'apprêtes à faire. (Ex : "je vais lancer la commande pour lire les fichiers sur le serveur"
- Toute manipulation de la base de données via le plugin ou via des scripts WP-CLI devra toujours s'accompagner d'une purge de cache (clean_post_cache, wp cache flush) pour éviter tout problème de cache.