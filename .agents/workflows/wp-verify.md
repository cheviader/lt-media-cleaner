---
description: Contrôle de Qualité GSD
---

Exécute gsd-code-review puis gsd-validate-phase. Compare le code produit avec le plan de la gsd-plan-phase. Vérifie la conformité aux standards WordPress (PSR-12, sécurité). Utilise WP-CLI ou le MCP pour confirmer qu'aucune erreur PHP n'est générée. Ne valide que si 100% du plan initial est respecté.