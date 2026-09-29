# Publishing unraid-vitals through Community Applications

How this plugin gets from `git push` to the **Apps** tab in every Unraid
server. Three layers, each usable on its own:

| Layer | Who can install | What it needs |
|---|---|---|
| **1. Raw `.plg` URL** (works today) | anyone you give the URL to | a GitHub release with the `.txz` attached |
| **2. Community Applications listing** | every Unraid user, via Apps → search | layer 1 + forums thread + CA submission (one-time) |
| **3. Updates** | everyone already installed | bump version, cut release, push — CA and the plugin manager pick it up |

## 0. How the pieces fit

```
plugins/unraid-vitals.plg  ──committed──▶ raw.githubusercontent.com/…/main/plugins/unraid-vitals.plg
        │                                        ▲ this URL *is* the plugin (Install Plugin box, CA <PluginURL>)
        │ <URL> points at
        ▼
github.com/BeinnoLLC/unraid-vitals/releases/latest/download/unraid-vitals-<ver>-x86_64-1.txz
        ▲ uploaded as a release asset — never committed to git
        │ md5 in the .plg must match this exact file
build/build.sh <ver>  ──produces both──▶ dist/unraid-vitals.plg + dist/unraid-vitals-<ver>-x86_64-1.txz
```

- The **`.plg` is generated but committed**. Its raw GitHub URL is what users
  and CA install from. Never `.gitignore` it.
- The **`.txz` lives only in GitHub Releases**. The `.plg` template points at
  `releases/latest/download/<pkgname>.txz`, so every release must attach the
  payload under that exact filename or installs 404.
- **Version is `YYYY.MM.DD`**, not semver. Unraid's plugin manager compares the
  `version` entity string in the `.plg`; git tags are `v2026.09.28`.
- `upgradepkg --install-new` requires the `-x86_64-1` suffix in the package
  name. `build.sh` handles it — don't rename the asset.

## 1. Cutting a release (every version, including the first)

From a clean `main` checkout:

```bash
# 1. Pick today's date as the version and update the changelog
VER=$(date +%Y.%m.%d)
$EDITOR build/plugin.plg.template      # add a ###<VER> block at the top of <CHANGES>

# 2. Build — lints every .php/.js/.sh, fails on CRLF, stages src/, tars, md5s, renders the .plg
./build/build.sh "$VER"
ls dist/                               # unraid-vitals.plg  unraid-vitals-<VER>-x86_64-1.txz  unraid-vitals-<VER>.md5

# 3. Commit the regenerated manifest (this is the step that publishes the new version)
cp dist/unraid-vitals.plg plugins/unraid-vitals.plg
git add plugins/unraid-vitals.plg build/plugin.plg.template
git commit -m "release: $VER"
git tag "v$VER"
git push origin main --tags

# 4. Attach the payload to a GitHub release under the tag
gh release create "v$VER" \
  dist/unraid-vitals-"$VER"-x86_64-1.txz \
  dist/unraid-vitals-"$VER".md5 \
  --title "v$VER" --notes-file <(sed -n "/###$VER/,/###/p" build/plugin.plg.template | sed '1d;$d')
```

**Order matters:** push the `.plg` and create the release together. Between
"`.plg` on main says version X" and "release X has the `.txz`", every install
and update attempt 404s. Do step 3 and 4 back-to-back; if a release fails,
either fix it immediately or revert the `.plg` commit.

Verify before announcing:

```bash
curl -fsSI https://raw.githubusercontent.com/BeinnoLLC/unraid-vitals/main/plugins/unraid-vitals.plg | head -1
curl -fsSLo /tmp/v.txz https://github.com/BeinnoLLC/unraid-vitals/releases/latest/download/unraid-vitals-$VER-x86_64-1.txz
md5sum /tmp/v.txz                      # must equal the <!ENTITY md5> in plugins/unraid-vitals.plg
```

Then on a real Unraid box: **Plugins → Install Plugin**, paste the raw `.plg`
URL, confirm the pages route and the collector cron writes a sample. If it is
already installed, **Plugins → Check for Updates** should show the new
version — that is the exact code path CA users will hit.

## 2. Getting listed in Community Applications (one time)

CA is repo-scoped: you submit the repository URL, CA scans it, and lists
every plugin it finds. Hard requirements, all checked by the validator:

1. **Public repo** — CA cannot reach private ones. ✅ `BeinnoLLC/unraid-vitals`
2. **OSI-approved `LICENSE` at repo root.** ✅ MIT
3. **`ca_profile.xml` at repo root** with a non-empty `<Profile>`, `<Icon>`,
   `<Screenshots>`, `<Support>`, `<Project>`. ✅ exists — but see blockers below.
4. **The `.plg` carries a `support=` attribute** and `pluginURL` pointing at
   its own raw URL. ✅ template does this.
5. **An Unraid forums support thread.** ❌ **not created yet — this is the
   gate.**

### Blockers to clear before submitting

Both files still carry the placeholder `https://forums.unraid.net/topic/TODO-vitals/`.
CA's validator rejects placeholder/404 support URLs outright, so the order is:

**a) Create the forums thread** (human step, needs the `hazemhagrass` forums
account). Post in **Plugin Support** at
<https://forums.unraid.net/forum/61-plugin-support/> with:

- Title: `[Plugin] unraid-vitals — AI-powered analytics, sensors & knowledge base`
- Body: the `<Profile>` text from `ca_profile.xml`, the install URL, the
  screenshot, a **clear privacy note** (the default LLM endpoints are the
  developer's; set `LLM_STUDIO_PRIMARY`/`LLM_STUDIO_BACKUP` for a local
  Ollama and nothing leaves the network), and a link to GitHub Issues.
- Copy the resulting thread URL.

**b) Replace the placeholder in three places** and rebuild:

```bash
THREAD='https://forums.unraid.net/topic/<id>-unraid-vitals/'
sed -i "s#https://forums.unraid.net/topic/TODO-vitals/#$THREAD#" \
  build/plugin.plg.template ca_profile.xml
grep -rn "TODO-vitals" . --exclude-dir=.git --exclude-dir=node_modules   # must print nothing
./build/build.sh "$(date +%Y.%m.%d)" && cp dist/unraid-vitals.plg plugins/
```

Then cut a release per §1 so the live `.plg` carries the real URL.

**c) Add the missing screenshot and confirm both assets resolve** — the
validator fetches them. `assets/icon.png` exists; **`assets/screenshot-dashboard.png`
does not yet**, although `ca_profile.xml` and the README already point at it.
Capture the live Dashboard at 1600×900 (e.g. the headless-Chrome preview
harness, or a real browser) and commit it there. Then:

```bash
curl -fsSI https://raw.githubusercontent.com/BeinnoLLC/unraid-vitals/main/assets/icon.png | head -1
curl -fsSI https://raw.githubusercontent.com/BeinnoLLC/unraid-vitals/main/assets/screenshot-dashboard.png | head -1
```

The icon should be a square PNG ≥ 256 px; CA shows it in the Apps grid.

### Submit

1. Go to <https://ca.unraid.net/submit>.
2. Paste `https://github.com/BeinnoLLC/unraid-vitals`.
3. **Validate** → fix anything it flags → **Scan** → it shows the parsed
   profile, icon, screenshots and the detected `.plg`.
4. Submit. Review is typically ~48 h; you get a forum DM / email.

Once approved, the listing updates itself from the repo — CA re-scans
periodically, so future `.plg` version bumps and `ca_profile.xml` edits go
live without resubmitting.

### CA content rules that apply to this plugin specifically

- **No runtime CDN.** We vendor Preact + uPlot into `js/vendor/`. Keep it that
  way; a `<script src="https://cdn…">` gets the listing pulled.
- **Disclose outbound connections.** The default remote LLM endpoints are the
  one thing in this plugin that phones out. `ca_profile.xml` already states
  it in plain words; keep that paragraph accurate whenever the defaults
  change (ticket P13-11 is why it reads the way it does).
- **`node_modules` is NOT reproducible from git today.** `build.sh` does a
  plain `cp -r src/…`, and `agent/node_modules` is gitignored. So the payload
  contains the agent's dependencies (`@smythos/sdk`, `better-sqlite3` — ~370
  MB unpacked) **only if the person building ran `npm ci` in
  `src/…/agent/` first**. Without them the plugin still installs, but
  `install.sh` prints "node/agent deps not found — background AI agents
  disabled" and the Findings/Knowledge/Research features are inert.
  **Always run `(cd src/usr/local/emhttp/plugins/unraid-vitals/agent && npm ci --omit=dev)`
  before `build.sh`**, and check the `.txz` size (a deps-less build is a few
  hundred KB; a real one is tens of MB). Making `build.sh` do this itself is
  ticket P13-10 territory — until then it is a manual step.

## 3. Shipping updates

The plugin manager on every installed box polls the raw `.plg` URL and
compares `version`. Update = §1 exactly: changelog block, `build.sh`, commit
the `.plg`, tag, release with the `.txz`. Users see it under **Plugins →
Check for Updates**, and CA shows an update badge.

Rules that keep updates safe on running servers:

- **Config keys backfill per-key, never per-file.** `scripts/install.sh`
  appends missing `KEY="default"` lines to an existing `vitals.cfg` rather
  than skipping the whole block when the file exists — a key added in a
  later version must reach users who installed an earlier one.
- **Cron files are rewritten, not appended**, so a version that changes a
  schedule doesn't leave two entries.
- **DB schema uses `CREATE TABLE IF NOT EXISTS` + additive `ALTER`** on both
  the PHP and Node sides; downgrade is not supported and the release notes
  should say so if a migration is one-way.
- **Never remove data on upgrade.** `remove.sh` deliberately keeps flash
  rollups and the appdata `vitals.db`; uninstall-then-reinstall must not
  lose the knowledge base.

## 4. Local iteration without releasing

For edit–test loops on a dev server, skip the `.txz` pipeline:

```bash
rsync -a --delete --exclude node_modules \
  src/usr/local/emhttp/plugins/unraid-vitals/ root@<tower>:/usr/local/emhttp/plugins/unraid-vitals/
ssh root@<tower> /usr/local/emhttp/plugins/unraid-vitals/scripts/install.sh --reapply
```

`/usr/local` is tmpfs — this survives until reboot only. Anything you want to
keep must go through a real `.plg` install. **Reboot persistence is the test
people skip**: before a release, install the built `.plg` on a box, reboot
it, and confirm the plugin is still there with its cron entries.

## Checklist for the first CA submission

- [ ] Forums thread created; URL copied
- [ ] `TODO-vitals` replaced in `build/plugin.plg.template` and `ca_profile.xml`; grep clean
- [ ] `assets/screenshot-dashboard.png` committed (currently missing); it and `assets/icon.png` return 200 over the raw URL
- [ ] `npm ci --omit=dev` run in `src/…/agent/` before the build; `.txz` size sanity-checked
- [ ] Release cut with real support URL: `.plg` on main + `.txz` attached, md5 matches
- [ ] Fresh install from the raw URL on a real box works; survives reboot
- [ ] `https://ca.unraid.net/submit` → Validate passes → Scan shows the right profile → Submit
- [ ] After approval: search "Vitals" in Apps on a box that has never had it installed
