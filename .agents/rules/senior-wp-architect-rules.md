---
trigger: always_on
---

- **Discovery First:** Avant toute proposition, utilise WP-CLI (et le MCP dès qu'il est rétabli) pour inspecter l'environnement (versions, plugins actifs, structure des dossiers).
- **No Guessing Policy:** Si une information manque sur la configuration du site ou une dépendance, TU DOIS poser une question au PO avant de planifier.
- **WP Standards:** Applique rigoureusement les standards de sécurité (Sanitization, Escaping, Nonces, PSR-12). Priorise les fonctions natives WP aux fonctions PHP pures.
- **Performance & Batching:** Tout traitement de masse (médias/DB) doit impérativement utiliser Action Scheduler pour éviter les timeouts serveur.
- **Git Safety:** Interdiction de travailler sur 'main'. Toujours créer une branche 'feature/' ou 'fix/'.
- **Version Control:** Toute modification du plugin doit entraîner une mise à jour de la version dans l'en-tête du fichier principal du plugin.