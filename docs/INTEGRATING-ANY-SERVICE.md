# Integrating another service

How to point Unraid Vitals at something that is not Unraid, or add a second
system to watch. No agent, viewmodel or provider code changes.

## The idea

The agent used to know about Unraid directly: `sources.mjs` shelled out to
`docker` and `virsh` and read `/var/tmp/unraid-vitals/*.json`. Everything
above it inherited that knowledge, so "watch a Proxmox box instead" meant
rewriting the specialists.

Now the code is four layers, and only two of them know anything about the
host:

```
  agents/      cron entry points, one file per specialist
  viewmodels/  pure questions over a source   (health, peaks, changes…)
  sources/     a named data source + the registry
  providers/   the ONLY code that shells out  (docker, virsh, journald)
  core/        ports + typed errors: pure contracts, no I/O
```

Arrows point down only. `core/` has no `fs`, no `child_process`, no
`process.env` — enforced by a test, so the boundary cannot rot silently.

## Turn it on

```bash
# watch one source (default)
VITALS_SOURCE=unraid

# watch two boxes and merge them
VITALS_SOURCE=unraid,proxmox

# test entirely offline, no host tools needed
VITALS_SOURCE=fixture
```

That is the whole knob. `lib/sources.mjs` resolves it once per process and
every legacy `latestSnapshot()` / `history()` / `vmList()` call routes
through it.

## Write a new source

Copy `agent/sources/fixture.mjs` — it is the smallest complete example.
A source is any object with an `id` and these methods:

| Method                  | Returns                                   | Required |
|-------------------------|-------------------------------------------|----------|
| `snapshot()`            | whole-system facts as of now              | yes      |
| `history()`             | array of samples                          | yes      |
| `alerts()`              | array of alert objects                    | yes      |
| `tail(path, lines)`     | string                                    | yes      |
| `containers()`          | array of containers                       | yes      |
| `vms()`                 | array of VMs                              | yes      |
| `containerImages()`     | `[{name, image, localDigest}]`             | yes      |
| `registryDigest(ref)`   | string or null                            | yes      |
| `syslogWarnings(min,max)` | string                                  | yes      |

Optional: `historyWindow(hours)`, `sensors()`, `capabilities()`,
`dockerLogs()`. Anything a method cannot support should throw
`UnsupportedError` — callers degrade to "this source has no X" instead of
crashing.

### 1. Shape your data into the port

```js
import { makeSnapshot, makeDisk, UnsupportedError } from '../core/ports.mjs';

export function createProxmoxSource({ baseUrl, token }) {
  return {
    id: 'proxmox',
    label: 'Proxmox (pve-01)',
    kind: 'hypervisor',

    async snapshot() {
      const [nodes, vms] = await Promise.all([get('/nodes'), get('/cluster/resources?type=vm')]);
      return makeSnapshot({
        time: Math.floor(Date.now() / 1000),
        cpu:  { total: avg(nodes.map(n => n.cpu * 100)), cores: nodes[0].maxcpu },
        mem:  { pct: pctOf(nodes, 'mem', 'maxmem') },
        disks: vms.filter(v => v.type === 'storage').map(d => makeDisk({
          name: d.name, temp: d.temp ?? null, role: 'data'
        })),
        vms: vms.map(v => ({ name: v.name, state: v.status }))
      });
    },

    history()      { return []; },                        // or read a RRD
    alerts()       { return []; },
    tail()         { return ''; },
    containers()   { throw new UnsupportedError('no containers in Proxmox'); },
    vms()          { /* ... */ },
    containerImages() { throw new UnsupportedError('not applicable'); },
    registryDigest()  { throw new UnsupportedError('not applicable'); },
    syslogWarnings()  { throw new UnsupportedError('use journal forwarding'); }
  };
}
```

### 2. Register it

```js
// agent/sources/registry.mjs
import { createProxmoxSource } from './proxmox.mjs';
registerSource(createProxmoxSource({ baseUrl: 'https://pve:8006', token: '...' }));
```

Register inside `registerBuiltins()` if it should be available by name.

### 3. Run it

```bash
VITALS_SOURCE=proxmox node agent/analyze.mjs disks
```

## Rules that keep it honest

- **Never import `docker`/`virsh`/a host path above `providers/`.** A test
  fails the build if a non-provider shells out.
- **Throw, don't return empties,** for a capability you don't have. Empty
  array and "I don't know" are different answers; conflating them is how an
  agent ends up reporting "no disks" on a healthy box.
- **Keep the native keys.** The existing specialists still read
  `snap.array`, `snap.temp_max`, `snap.sensors`, `snap.shares`,
  `snap.smart`, `snap.net`, `snap.flash`, `snap.system.md_state` and
  `snap.mem.swap_pct`. `makeSnapshot()` copies your object's own keys
  through alongside the normalized ones; a guard test pins the list. If you
  return a renamed shape, add the native key too.
- **Add a test that uses your source.** The fixture source in
  `tests/layers.test.mjs` is the pattern: build the source against
  in-memory fixtures, assert the port, assert the fused registry entry.

## Watching two systems at once

`VITALS_SOURCE=unraid,proxmox` returns a fused source whose disks, VMs and
containers are the union. Container and disk names are namespaced per
source so two `disk1`s don't collapse into one row in the UI.

## Testing without a NAS

The fixture source is the fastest way to develop an agent:

```js
import { createFixtureSource } from './sources/fixture.mjs';
import { __setSource } from './lib/sources.mjs';

__setSource(createFixtureSource({
  fixtures: { latest: {...}, history: [...], containers: [...] }
}));
```

No network, no docker, no LLM studio — the suite runs in ~100ms.
