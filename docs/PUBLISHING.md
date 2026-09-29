# Publishing to Community Applications — quick path

Goal: `unraid-vitals` installable from **Apps → search "Vitals"** on any
Unraid box. Three one-time steps, then routine updates are just "cut a
release."

## Where things stand right now

- ❌ Forums support thread — not created yet. **This is what's blocking submission.**
- ❌ `assets/screenshot-dashboard.png` — missing (only `icon.png` exists).
- ✅ Everything else CA checks (public repo, MIT `LICENSE`, `ca_profile.xml`, `.plg` template) is already in place.

Do the two ❌ items below, cut one release, submit. That's it.

## 1. Create the forums thread (5 min, needs your forums account)

Post in **Plugin Support**: <https://forums.unraid.net/forum/61-plugin-support/>

- Title: `[Plugin] unraid-vitals — AI-powered analytics, sensors & knowledge base`
- Body: paste the `<Profile>` text from `ca_profile.xml`, the install URL, a screenshot, and a one-line privacy note ("default LLM endpoints are the developer's; point `LLM_STUDIO_PRIMARY`/`_BACKUP` at your own Ollama and nothing leaves your network").
- Copy the thread URL — you need it next.

## 2. Add the dashboard screenshot

Capture the live Dashboard tab at 1600×900 and save it as:
```
assets/screenshot-dashboard.png
```

## 3. Wire the thread URL in and cut a release

```bash
THREAD='https://forums.unraid.net/topic/<id>-unraid-vitals/'   # from step 1
sed -i "s#https://forums.unraid.net/topic/TODO-vitals/#$THREAD#" \
  build/plugin.plg.template ca_profile.xml
grep -rn "TODO-vitals" . --exclude-dir=.git --exclude-dir=node_modules   # must print nothing

VER=$(date +%Y.%m.%d)
(cd src/usr/local/emhttp/plugins/unraid-vitals/agent && npm ci --omit=dev)  # bundles agent deps — skip this and AI features ship dead
./build/build.sh "$VER"
cp dist/unraid-vitals.plg plugins/unraid-vitals.plg
git add -A && git commit -m "release: $VER" && git tag "v$VER" && git push origin main --tags

gh release create "v$VER" \
  dist/unraid-vitals-"$VER"-x86_64-1.txz dist/unraid-vitals-"$VER".md5 \
  --title "v$VER" --notes "unraid-vitals $VER"
```

Verify before submitting:
```bash
curl -fsSI https://raw.githubusercontent.com/BeinnoLLC/unraid-vitals/main/plugins/unraid-vitals.plg | head -1
curl -fsSI https://raw.githubusercontent.com/BeinnoLLC/unraid-vitals/main/assets/screenshot-dashboard.png | head -1
```
Both must return `200`. Then install the raw `.plg` URL on a real box and confirm it works.

## 4. Submit to CA

1. <https://ca.unraid.net/submit>
2. Paste `https://github.com/BeinnoLLC/unraid-vitals`
3. Validate → Scan → Submit. Review is ~48h; you get a forum DM/email.

Once approved, CA re-scans the repo periodically — future releases (step 5) show up automatically, no resubmission.

## 5. Every release after that (routine)

```bash
VER=$(date +%Y.%m.%d)
$EDITOR build/plugin.plg.template          # add a ###VER changelog block at the top
(cd src/usr/local/emhttp/plugins/unraid-vitals/agent && npm ci --omit=dev)
./build/build.sh "$VER"
cp dist/unraid-vitals.plg plugins/unraid-vitals.plg
git add -A && git commit -m "release: $VER" && git tag "v$VER" && git push origin main --tags
gh release create "v$VER" dist/unraid-vitals-"$VER"-x86_64-1.txz dist/unraid-vitals-"$VER".md5 --title "v$VER"
```
Push the `.plg` and create the release **together** — if the `.plg` on `main` says version X before release X's `.txz` exists, every install/update 404s.

Users see it under **Plugins → Check for Updates**; CA shows an update badge automatically.

## Gotchas that bite if skipped

- **Version is `YYYY.MM.DD`**, not semver — the plugin manager string-compares it.
- **`node_modules` isn't reproducible from git** — always `npm ci --omit=dev` in `agent/` before `build.sh`, or the `.txz` installs but AI features (Findings/Knowledge/Research) are silently inert. Sanity-check the `.txz` size: a few hundred KB = deps missing, tens of MB = real build.
- **No CDN scripts** — Preact/uPlot are vendored in `js/vendor/`; a `<script src="https://cdn…">` gets a CA listing pulled.
- **Never remove data on upgrade** — `remove.sh` keeps flash rollups + `vitals.db`; `install.sh` backfills new config keys into an existing `vitals.cfg`, never overwrites it.

## Local iteration (no release needed)

```bash
rsync -a --delete --exclude node_modules \
  src/usr/local/emhttp/plugins/unraid-vitals/ root@<tower>:/usr/local/emhttp/plugins/unraid-vitals/
ssh root@<tower> /usr/local/emhttp/plugins/unraid-vitals/scripts/install.sh --reapply
```
`/usr/local` is tmpfs — survives until reboot only. Before any release, install the built `.plg` for real and reboot the box once to confirm it persists.
