# Audit qualité — sylius-tier-pricing-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative par lecture directe (orchestrateur).

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 58 tests, 1 289 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 30 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 paquets abandonnés (transitifs Sylius) |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 30 fichiers signalés relèvent de `FlipTypeControlToUseExclusiveTypeRector` et `FlipNegatedTernaryInstanceofRector` — suggestions de modernisation non bloquantes, non appliquées. Lancer `make rector-fix` pour les appliquer puis relancer ECS/PHPStan/tests.

**Paquets abandonnés** : `behat/transliterator` et `php-http/message-factory` sont des dépendances transitives de Sylius — non actionnable directement dans ce plugin.

## 2. Audit qualitatif (revue par lecture directe)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | Typage strict, `final` sur tout le code applicatif, value objects immuables, interfaces fines. Léger DIP sur deux paramètres par défaut. |
| Intégration Symfony / Sylius | **8/10** | Cache TagAware + `ResetInterface`, section-awareness, bornes de prix documentées et respectées, priority 44 justifiée. |
| Sécurité (OWASP 2025) | **7/10** | DQL 100 % paramétré, bornes strictes dans le calcul, validation multi-couches. Inconsistance de borne sur le prix fixe à 0. |

> Caveats : la suite non-unit (fonctionnelle / intégration) n'a pas été exécutée localement (base de données requise). Les tests unitaires couvrent 1 289 assertions — couverture comportementale du moteur de résolution, du cache et du processor solide.

### Points forts

- `declare(strict_types=1)` + classes `final` sur les 48 fichiers de `src/` (sauf l'entité `TierPriceRule` et son interface, par conception Doctrine).
- Value objects immuables couvrant tout le chemin de calcul : `TierPriceRuleData`, `TierPriceContext`, `TierPriceQuote`, `TierPriceRow`, `TierPriceNudge`, `TierPriceTable`.
- `TierPriceResolver` est pur (sans DB, sans horloge) : testable en isolation totale ; le moteur de bornage `min(max($price, $minimumPrice, 0), $currentPrice)` garantit qu'aucune règle ne peut produire un prix négatif ni supérieur au prix courant.
- Cache tag-aware avec invalidation fine par produit (`TierPriceCacheInvalidator` onFlush/postFlush) + `ResetInterface` sur le provider.
- Validation multi-couches : contrainte XML (`Assert\Range min=2`), `ValidTierPriceRuleValidator` (target, value, dates, unicité), et `CHECK` SQL dans les migrations — trois lignes de défense.
- Tout le DQL est QueryBuilder paramétré ; `findConflicting` gère le NULL SQL (IS NULL / IS NOT NULL) correctement pour l'unicité.
- CSV importer : transaction unique, rollback sur erreur, flush par ligne (unicité vérifiable ligne à ligne), `em->clear()` tous les 500 lignes.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Prix fixe à 0 autorisé — inconsistance de borne** (`src/Validator/ValidTierPriceRuleValidator.php:52`, OWASP A04:2025). La validation du type `fixed_unit_price` rejette `value < 0` mais **pas `value = 0`** : une règle "prix fixe 0 € dès N unités" passe la validation et s'applique en caisse, rendant des articles gratuits. `TYPE_PERCENTAGE_OFF` (ligne 49) rejette déjà `value <= 0`. → Si les articles gratuits ne sont pas un cas d'usage voulu, aligner sur `<= 0` ; sinon documenter explicitement ce comportement et ajouter un test dédié.

2. **`parseValue` accepte les valeurs négatives en amont** (`src/Csv/TierPriceRuleCsvFormat.php:34`). Le regex `/^-?\d{1,10}…/` n'exclut pas les négatifs — la défense repose uniquement sur le validator en aval. → Rejeter les négatifs directement dans `parseValue` (supprimer `-?` ou ajouter `if ($parsed < 0) return null`) pour un échec rapide et une erreur de parsing explicite.

3. **`TierPriceRule` entity non-`final` avec propriétés `protected`** (`src/Entity/TierPriceRule.php`). L'ouverture à l'héritage est intentionnelle (superclass Doctrine), mais elle permet à une extension de remplacer `setValue()` / `setMinQuantity()` sans passer par les invariants. → Documenter explicitement les propriétés qui forment l'invariant (`value`, `minQuantity`) et/ou ajouter un test de contrat qui s'assure que le validator est appliqué indépendamment de l'implémentation concrète.

### 🟡 Mineur

- **DIP léger** (`src/OrderProcessing/TierPriceOrderProcessor.php:43-46`) : deux paramètres de constructeur ont des valeurs par défaut `new OrderCustomerGroupResolver()` / `new TaxonQuantityCounter()`. Ce sont des classes sans dépendances (quasi-value objects), mais l'instanciation dans le constructeur viole formellement le DIP. → Laisser la DI les injecter explicitement dans `config/services.php` ou les rendre `readonly` et finaux.

- **Paquets abandonnés** (`composer audit`) : `behat/transliterator` et `php-http/message-factory` sont des dépendances transitives de Sylius — non actionnable ici, à remonter en issue Sylius ou à exclure du rapport.

- **30 suggestions Rector** : `FlipTypeControlToUseExclusiveTypeRector` (conversion `instanceof` en union negative) et `FlipNegatedTernaryInstanceofRector` (ternaire négatif) — modernisation sûre. → Appliquer avec `make rector-fix`, puis relancer ECS/PHPStan/tests.

- **`TierPriceCalculator.table()`** (`src/Pricing/TierPriceCalculator.php:36-78`, 42 lignes, complexité ~7) : approche les limites KISS. Envisager d'extraire `buildRows()` et `buildNudge()` pour améliorer la lisibilité.

### ℹ️ Pour information (choix de conception assumés)

- `CompositeTierPriceExemptionChecker` retourne `false` si aucun checker n'est taggué — défaut sûr (non exempt = tier appliqué).
- L'`originalUnitPrice` est écrasé par le prix tier en channels consommateurs : délibéré (pas de prix barré légal pour les remises quantitatives B2C).
- La priorité 44 du processor est positionnée après le verrou de devis (45) et avant les promotions panier (20) : correctement documentée dans le docblock du processor.

## 4. Verdict

Plugin **solide et bien architecturé** : outillage au vert (ECS, PHPStan level max, 58 tests unitaires avec 1 289 assertions), typage strict, value objects immuables, défenses multi-couches et cache invalidation fine. L'axe d'amélioration principal est l'**inconsistance de borne sur la valeur du prix fixe** (0 autorisé mais probablement non intentionnel comme cas général) et le **rejet tardif des valeurs négatives dans le parseur CSV** — deux points de sécurité mineurs mais adressables en quelques lignes. Rien de bloquant côté outillage ou architecture.
