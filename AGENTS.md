# AGENTS.md

## Design system (UI work)

- All UI work must follow [`/DESIGN.md`](DESIGN.md) and the Beinno family skill `beinno-design-system`.
- Tokens live in the token block at the top of
  `src/usr/local/emhttp/plugins/unraid-vitals/css/vitals.css` (the `.vitals { }` rule, lines 9-48).
- No raw hex, `rgb()`, `hsl()` or `font-family` outside that block (vendor files exempt).
- Keep Unraid webGui theme inheritance: test on black, white, azure and gray.
- Gates before a PR: `npm run check` (includes `lint:tokens`) and `npm run build`.
- Known mismatches: [`docs/design-audit.md`](docs/design-audit.md), tracked in Plane UNRAIDPLUG-201..221.
