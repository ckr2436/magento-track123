# AGENTS.md

This repository is a **Magento&nbsp;2 module repository**, not a full Magento application.

## Repository identity

- Module name: `Pynarae_Tracking`
- PHP namespace root: `Pynarae\\Tracking`
- Composer package type: `magento2-module`

The repository root is the module root.

When deployed into Magento, this repository should live at:

```
app/code/Pynarae/Tracking
```

## Primary goals

This module implements:

- guest order tracking UI
- order lookup by order number + email or phone
- tracking‑number lookup with store ownership validation
- Track123 integration
- webhook ingestion
- adaptive carrier verification using postal code / phone suffix when required

## Architecture rules

Preserve Magento&nbsp;2 module conventions:

- keep `composer.json`
- keep `registration.php`
- keep `etc/module.xml`
- respect Magento DI, routing, layout, templates, and config conventions

This repo is not a generic PHP project:

- do not convert it to Symfony/Laravel/plain PHP architecture
- do not move module root files into a `/src` folder
- do not remove Magento layout XML or template usage

Prefer backward‑compatible changes:

- avoid breaking public configuration paths
- avoid renaming routes unless required
- avoid changing DI constructor signatures without updating related classes and recompilation assumptions

Treat Magento production mode as important:

- changes must be compatible with `setup:di:compile`
- changes must be compatible with static content deploy
- avoid reflection hacks and runtime‑only shortcuts

## Tracking logic rules

- Never allow arbitrary external tracking lookup for numbers that are not owned by this store
- Always validate store ownership locally before external tracking requests in tracking‑number lookup mode
- Prefer adaptive additional verification:
  - first try normal query
  - if Track123 requires additional fields:
    - retry automatically using Magento order‑derived values
    - if unresolved, request manual user input
    - if the response is unclear, try `postalCode` first
- Do not blindly expose customer/order privacy in tracking‑number‑only mode

## UI rules

Frontend should feel:

- modern
- minimal
- premium
- clean
- not admin‑like
- not overly technical

Prefer:

- generous whitespace
- restrained typography
- clear error states
- simple forms
- elegant result cards

Avoid:

- loud gradients
- heavy dashboards
- cluttered badges
- developer‑looking forms

## Code style rules

- PHP&nbsp;8.1+ compatible
- strict types
- typed properties where appropriate
- meaningful exceptions
- no dead code
- no hidden magic behavior
- avoid one‑letter variables
- keep methods reasonably small
- prefer explicit logic over clever tricks

## When editing tracking logic

Always inspect these areas together if changing behavior:

- `Controller/Lookup/*`
- `Model/LookupService.php`
- `Model/TrackingSynchronizer.php`
- `Model/Track123Client.php`
- `view/frontend/templates/form.phtml`
- `view/frontend/templates/result.phtml`
- `view/frontend/web/css/tracking.css`

## Before declaring work complete

Confirm at minimum:

- route still resolves
- form posts to a valid action
- no obvious constructor/DI mismatch
- code is production‑mode safe
- tracking‑number lookup still validates store ownership
- UI still works in both:
  - order lookup mode
  - tracking lookup mode

Avoid:

- committing secrets
- changing module name
- changing namespace root
- changing repository structure away from Magento module layout
- adding unrelated frameworks
- adding generated or vendor‑like artifacts