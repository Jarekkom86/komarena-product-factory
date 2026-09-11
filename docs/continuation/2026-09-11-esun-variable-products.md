# KomArena continuation context — eSUN variable products

Date: `2026-09-11`
Branch: `feature/esun-variable-products`
Base: `main` @ `264d285f0871d0f5c602411d551a0d34e6de0405`
Draft PR: `#2` — Add safe WooCommerce variation migration for eSUN PLA+

## Goal

Consolidate eSUN filament colour products into WooCommerce variable products so the catalogue is not flooded with near-identical cards.

Recommended structure:

- one variable parent per material/range (for example eSUN PLA+, PETG, ABS+, TPU, PLA Matte, eSilk)
- child variations primarily by colour
- keep diameter/weight out of the selector when identical across all children
- every variation retains its own SKU, EAN, price, stock state and image

## Safety rules

- NEXUS FIRST
- REUSE FIRST
- EVIDENCE FIRST
- FAIL CLOSED
- never mutate `main` directly for this feature
- preview before execution
- explicit staging before activation
- missing or duplicate SKU => block
- missing, invalid or duplicate EAN/GTIN => block
- manifest EAN differing from stored EAN => block
- missing price => block
- ambiguous stock state => block
- do not archive source products until the variable parent and staged variations validate
- preserve historic source product IDs for old WooCommerce orders
- preserve old public URLs with 301 redirects after activation
- rollback must restore source status/visibility/SKU/EAN/redirect state
- rollback of an active migration is blocked once a generated variation is referenced by an order
- production deployment must satisfy Nexus runtime health, heartbeat, guard and recovery/rollback contracts; CI success alone is not authority to mutate production

## Important discovery

A previous chat reported that a variation migration engine and manifests had already been implemented. Verification on 2026-09-11 showed they were NOT present in `main`; the last main commit remained the MASTER PRO 1.3 commit from 2026-09-09. Therefore this branch is the first verified implementation path and is the source of truth for this feature.

The first generic product search also surfaced misleading numeric IDs such as 3861/3872/3891. Direct WooCommerce pagination was used to correct this. Real PLA+ product records are in the verified product sequence ending before ABS+ begins at product 3911; do not reuse IDs from the misleading search result without direct product verification.

## Implemented on feature branch

- `includes/class-komarena-pf-variation-migration-engine.php`
  - REST preview: `POST /komarena-pf/v1/variations/preview`
  - REST staging: `POST /komarena-pf/v1/variations/execute`
  - REST activation: `POST /komarena-pf/v1/variations/activate`
  - REST rollback: `POST /komarena-pf/v1/variations/rollback`
  - fail-closed SKU/EAN/price/stock validation
  - GTIN-8/12/13/14 checksum validation
  - optional manifest EAN with drift check against stored EAN
  - temporary staging SKU to avoid WooCommerce uniqueness collisions
  - no inheritance of legacy source descriptions into variations
  - explicit source drift check between staging and activation
  - source URL -> parent 301 redirect map after activation
  - active rollback guard when variations are already used in orders
- plugin bootstrap wired to the new engine
- branch plugin version bumped from 2.5.23 to 2.6.0
- `.github/workflows/php-lint.yml` added for PHP lint + JSON manifest validation
- `manifests/esun-pla-plus-pilot.json` added

## Verified pilot

Pilot parent:

- `eSUN PLA+ 1,75 mm – 1 kg`
- slug `esun-pla-plus-175-mm-1-kg`
- attribute `Farba`
- staging only by default (`activate: false`)

Verified pilot children:

| Product ID | Colour | EAN |
| ---: | --- | --- |
| 3777 | Black | 6922572219021 |
| 3778 | White | 6922572219038 |
| 3779 | Blue | 6922572219045 |
| 3798 | Cold White | 6922572219229 |

All four EANs passed a GTIN checksum smoke test; source IDs, colour values and EANs are unique in the pilot manifest.

## Known blocker

- Product 3802 `eSUN PLA+ Purple 1,75 mm – 1 kg` does not yet have a safely verified EAN in the collected source data. It MUST remain outside migration until EAN is independently verified and/or stored on the WooCommerce product. FAIL CLOSED.

## Public-content P0 finding

Several existing eSUN PLA+ simple products publicly contain internal supplier information in long/short descriptions (supplier name/index/link, procurement price/margin notes, or verification date). The new variable parent/variations must never inherit those texts. The migration engine now copies only operational product data such as SKU, resolved EAN, price, stock, dimensions/tax and image; variation descriptions are blank at staging and parent copy comes only from the clean manifest.

Separate site-wide public-content cleanup remains necessary for legacy products that stay publicly accessible before migration activation, and for any other products that expose supplier/verification data.

## Intended production flow

1. `preview(manifest)` — read-only validation.
2. `execute(manifest)` with `activate=false` — create a draft variable parent and staged variations; source simple products remain untouched.
3. QA the draft parent, variation selector, images, prices, stock and cart behavior.
4. `activate(migration_id)` — move final SKU/EAN ownership to variations, hide/draft legacy simple products, publish parent according to manifest status and install 301 redirects.
5. `rollback(migration_id)` — available while safe; blocked after generated variations are referenced by orders.

## Validation status — VERIFIED

- draft PR `#2` is open from `feature/esun-variable-products` to `main`
- PR mergeability: `true` at latest check
- PR remains draft intentionally; it has NOT been merged or deployed
- GitHub Actions run `34629034212`: `success`
- job `103361067437`: `success`
- CI ran against the PR merge ref on Ubuntu 24.04 with PHP 8.3.6
- `php -l`: PASS for every PHP file, including the new migration engine
- JSON manifest validation: PASS
- pilot manifest smoke test: PASS for JSON roundtrip, unique IDs, unique colours, unique EANs and GTIN checksums

## Live KomArena environment — VERIFIED

KomArena Agent Bridge reports:

- WordPress `7.1`
- PHP `8.2.30`
- WooCommerce `10.6.1`
- Agent Bridge `0.1.0`
- bridge is read-only
- 5 exposed KomArena abilities

The generic WP Agent connection is NOT KomArena; it currently exposes Tiptopkuchyne. Do not use it to deploy KomArena.

The KomArena Agent Bridge can read status/products/categories/audit data but does not expose PHP/plugin deployment.

## Deployment-path audit — CURRENT STATE

- `komarena-webops-lab/ops/komarena` does not currently contain a direct product-factory deploy script; only sanitation material was found there
- `jaro-os-bridge` is the existing durable GitHub transport between JARO OS and the local PC Agent
- the bridge intentionally disallows arbitrary PowerShell and requires allow-listed task kinds/targets
- Nexus contracts are fail-closed for production mutation
- production mutation is allowed only after trusted-public boundaries, runtime health, required PC path, fresh heartbeat, guard/incident state and recovery/rollback path are all proven healthy
- therefore the feature MUST remain unmerged/undeployed until a supported plugin-deploy task and current green runtime evidence are identified

## Next steps

1. Inspect `jaro-os-bridge`/Nexus contracts and worker allow-list for an existing WordPress/plugin deployment task.
2. Verify current heartbeat, guard/incident state, runtime health and rollback/backup capability.
3. If and only if an existing allow-listed deploy path exists and all required gates are green, prepare the deployment through that path; do not invent a generic shell/PowerShell bypass.
4. Keep PR #2 draft until the deployment/runtime path is proven.
5. After deployment, run REST preview against the pilot manifest first. Preview is read-only.
6. Only after clean preview, stage with `activate=false`.
7. QA parent, colour selector, images, prices, stock and cart behavior.
8. Activate only after post-stage QA.
9. After pilot success, build the complete verified PLA+ colour manifest, excluding unresolved Purple until its EAN is confirmed.
