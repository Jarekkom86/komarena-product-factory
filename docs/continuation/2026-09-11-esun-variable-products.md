# KomArena continuation context — eSUN variable products

Date: 2026-09-11
Branch: `feature/esun-variable-products`
Base: `main` @ `264d285f0871d0f5c602411d551a0d34e6de0405`

## Goal

Consolidate eSUN filament colour products into WooCommerce variable products so the catalogue is not flooded with near-identical cards.

Recommended structure:

- one variable parent per material/range (for example eSUN PLA+, PETG, ABS+, TPU, PLA Matte, eSilk)
- child variations primarily by colour
- keep diameter/weight out of the selector when identical across all children
- every variation retains its own SKU, EAN, price, stock state and image

## Pilot

Start with eSUN PLA / PLA+ and use Cold White as the first migration candidate once live product IDs and metadata are verified.

## Safety rules

- NEXUS FIRST
- REUSE FIRST
- EVIDENCE FIRST
- FAIL CLOSED
- never mutate `main` directly for this feature
- preview before execution
- missing or duplicate SKU => block
- missing or duplicate EAN => block
- missing price => block
- ambiguous stock state => block
- do not archive source products until the variable parent and all required variations validate
- preserve historic source product IDs for old WooCommerce orders
- preserve old public URLs with 301 redirects after activation
- rollback must remove generated objects and restore source status/visibility/redirect state

## Important discovery

A previous chat reported that a variation migration engine and manifests had already been implemented. Verification on 2026-09-11 showed they were NOT present in `main`; the last main commit remained the MASTER PRO 1.3 commit from 2026-09-09. Therefore this branch is the first verified implementation path and must be treated as the source of truth.

## Intended engine flow

1. `preview(manifest)` validates WooCommerce, source product IDs, simple-product type, SKU/EAN/price/stock completeness and uniqueness, colour values, parent slug and collisions.
2. `execute(manifest, activate=false)` creates a draft variable parent and new variations copied from source products. Source products remain untouched while staged.
3. `execute(..., activate=true)` is allowed only after a clean preview/staged validation; it publishes/activates the parent as requested, archives/hides source catalogue cards, and registers 301 redirects from old product URLs.
4. `rollback(migration_id)` removes generated parent/variations and restores source product status/visibility plus redirect map from a stored snapshot.

## Public-content rule

Do not expose supplier names, supplier SKU/codes, sourcing notes, verification timestamps, procurement prices, or other internal supplier metadata on public KomArena pages.

## Next implementation steps

- add `KomArena_PF_Variation_Migration_Engine`
- integrate engine into the plugin bootstrap
- self-register REST routes for preview / execute / rollback to avoid risky edits to the large existing REST controller
- add a pilot manifest template for eSUN PLA+
- add static/smoke validation fixtures
- validate PHP syntax and branch diff before PR
