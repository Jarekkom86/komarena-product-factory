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

## Validation status

- branch vs `main`: 7 commits ahead and 0 behind at the first diff checkpoint
- manifest smoke test: PASS for JSON roundtrip, unique IDs, unique colours, unique EANs and GTIN checksums
- GitHub Actions workflow file is present, but the connector-created push did not produce a workflow run (`0` runs observed). Do not call PHP syntax CI PASS until a real Actions run or equivalent PHP lint has completed.

## Next steps

- open a pull request from `feature/esun-variable-products` to `main`
- check whether the pull-request event starts the PHP lint workflow
- inspect PR diff/patch and fix any CI or review issue
- do NOT merge/deploy until PHP lint is confirmed
- after plugin deployment, run REST preview against the pilot manifest before any staging
- then stage the pilot only; production activation requires post-stage QA
- after pilot success, build the complete verified PLA+ colour manifest, excluding unresolved Purple until its EAN is confirmed
