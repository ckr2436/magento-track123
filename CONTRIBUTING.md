# Contributing

## Project type

This repository is a **Magento&nbsp;2 module** repository.

Do not restructure it as a generic PHP project.

## Required knowledge before changes

Please read:

- `README.md`
- `AGENTS.md`
- `.github/copilot-instructions.md`

## Local deployment target

Deploy this repository into Magento as:

```text
app/code/Pynarae/Tracking
```

## Standard post‑change commands

After changing PHP, DI, routes, layout, or templates, run:

```
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f en_US
php bin/magento cache:flush
```

## Coding rules

- Keep strict typing
- Keep Magento conventions
- Avoid broad refactors unless required
- Prefer backward‑compatible changes
- Do not rename module identity
- Do not commit secrets
- Do not commit generated files

## Review checklist

Before opening a PR, verify:

- module still compiles in production mode
- routes still resolve
- forms still submit to valid actions
- no constructor / DI mismatch
- no privacy regression in tracking‑number‑only mode
- UI still looks coherent on desktop and mobile