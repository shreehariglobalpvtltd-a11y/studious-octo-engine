# Research: how shreehariglobal.in tracks buses today

*(Reverse-engineered from the website's own code — repo `shreehariglobalpvtltd-a11y/shreehari`,
branch `codex/ai-company-manager-20260921`, commit `08f48fc`, 25 Sep 2026.)*

> **सारांश (नेपालीमा):** अहिलेको वेबसाइटमा पूरै कम्पनीका लागि **एउटै** "लाइभ बस" ठाउँ छ।
> दुईवटा बस एकैपटक चल्दा एउटाले अर्कोको लोकेसन मेटाउँछ, १०–१५ सेकेन्ड ढिलो देखिन्छ,
> र ड्राइभरलाई स्टाफ (admin) लगइन चाहिन्छ। डाटाबेसमा `live_trips`, `trip_locations` र
> `drivers.pin_hash` बनाइएको थियो तर कहिल्यै प्रयोग भएन। Live Fleet ले यही कमी पूरा गर्छ।

## 1. The stack

| Part | What it is |
|---|---|
| Server | nginx + **PHP 8.3** (plain PHP, no framework) + **MySQL** database `shari` |
| Public app | one big single-page app: `index.php` → `app.template.html` + `assets/js/01…18-*.js` |
| Admin panel | `admin/*.php`, every page starts with `require '_guard.php'; admin_boot('<permission>')` |
| Roles | `includes/auth.php` → `ROLE_PERMISSIONS` (superadmin, manager, accountant, support, counter, agent, scanner, official) |
| Maps | MapLibre GL 4.7.1 from unpkg + OpenFreeMap tiles (no API key) |
| Offline | `sw.js` service worker — `/assets/*` is **cache-first by URL**, `/api/*` and `/admin/*` are never cached |
| Security headers | `includes/security.php` → a strict Content-Security-Policy on every page |

## 2. The "map navigation" that already exists

| File | What it does |
|---|---|
| `assets/js/15-nav.js` | The navigator in the public app. A signed-in **staff** user can tap **"📡 I'm the driver"**; the phone then writes its GPS to the shared key `livebus` every **10 s**. |
| `api/kv.php` | Stores that key in the `kv_store` table. `livebus` is public-read; any staff session may write it. |
| `admin/map.php` | "Live Map" admin page: route lines, stops, head office, and the **one** `livebus` dot, refreshed every **15 s**. |
| `assets/js/16-lazy.js` | Passenger "Trip Companion" — shows the same `livebus` banner. |
| `api/eta.php`, `includes/etaalerts.php`, `cron/eta-alerts.php` | "Bus is ~30 min from your stop" messages, computed from `livebus`. |

### What that design cannot do

1. **One slot for the whole company.** `livebus` is a single row. Two buses on the road → the last phone to write wins, and the dot jumps between them. (`etaalerts.php` even has a rule to stay silent when two coaches of one route are out, for exactly this reason.)
2. **The driver needs a staff (admin) account.** There is no driver login; the driver's phone must hold an agent/scanner session.
3. **Slow.** 10 s writes + 15 s map polling → the office sees the bus up to ~25 s late, and the dot hops instead of moving.
4. **No history.** No trail, no distance, no "who drove which bus when".
5. **No CCTV** anywhere, and the page CSP (`frame-src`/`media-src` fall back to `'self'`) would block camera players anyway.

### Built but never used (found in `database/schema.sql`)

- `live_trips` (trip, driver, bus, last lat/lng, share token) and `trip_locations` (history) — **no PHP or JS file reads or writes them.**
- `drivers.pin_hash` — commented *"Driver PWA login PIN"* — **never set or checked anywhere.**
- Permissions `liveops.view` / `liveops.edit` are granted to managers but **no page uses them.**

So the original plan clearly was a per-driver live system; it was replaced by the quicker single-key shortcut.

## 3. What Live Fleet does with these findings

| Finding | Live Fleet |
|---|---|
| One shared slot | One row per **trip** (`fleet_trips`), so every bus has its own live position |
| Driver needs staff login | **Driver ID + PIN** (`fleet_driver_logins`), a per-phone token (`fleet_devices`) the office can revoke |
| 10–25 s delay | Phone sends **every 1 s**; admin + TV refresh **every 1 s**; markers glide smoothly between fixes |
| No history | Trail (`fleet_points`, one point / 5 s), distance, battery, accuracy |
| No CCTV | `fleet_cameras` per bus (HLS / MJPEG / snapshot / vendor embed) + per-page CSP widened only for the camera hosts you add |
| Unused `liveops.edit` | Now gates cameras + the TV link |
| Old `livebus` consumers | Still fed: while exactly **one** bus is live, the engine also writes `livebus` every 10 s, so the Trip Companion, `api/eta.php` and ETA alerts keep working unchanged |
| Service worker caches `/assets/*` forever by URL | Live Fleet assets carry a **content-hash** `?v=` so a new version is never served stale |
| Old `live_trips` tables | Left untouched (new `fleet_*` tables instead) — nothing that exists is altered |

## 4. Things noticed while testing (not changed — outside this feature)

- `database/upgrade-2026-09-schedule-coach.sql` depends on the `schedules.slot` column added by
  `upgrade-2026-09-schedule-slot.sql`, which sorts *after* it — on a brand-new database the first
  pass fails and a second pass is needed (`tools/test-local.sh` runs the upgrades twice).
- `tests/launch-fixes-test.php` fails on today's date on an untouched copy too ("Booking is open up to …").
- `tests/role-gates-test.php` connects to `127.0.0.1:3307` as `root` with no password (a developer laptop), so it cannot run elsewhere.
- `tests/eta-alerts-test.php` refuses to run while any `livebus` row exists — after trying the
  simulator on a test database, clear it: `DELETE FROM kv_store WHERE kkey = 'livebus';`
