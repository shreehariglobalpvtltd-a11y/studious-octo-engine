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

---

# Round 3 (Oct 2026): what the best tracking systems do, and what Live Fleet took

> **सारांश:** संसारका राम्रा ट्र्याकिङ प्रणाली (भारतीय रेलवेको NTES / Where is my Train, Uber, Flightradar24, Samsara,
> Traccar, redBus, Chalo, Google Maps) हेरेर तिनका राम्रा कुरा यहाँ ल्याइयो: गाडी नम्बर अनुसार एउटा फोन, स्टप-स्टपको
> रेलवे जस्तै स्थिति, SOS, चार्जर निकालिएको सूचना, यात्रुलाई लाइभ लिंक, Replay, र Google Map कै रङ।

## The 25 features looked at, by priority

| Pri | Feature | Seen in | In Live Fleet |
|---|---|---|---|
| 1 | Fleet list by **vehicle number**, one phone per plate | Samsara / Geotab, Traccar, Chalo | ✅ Vehicles & Drivers: regular driver per number, **bus phone** paired by one-time code / QR |
| 1 | Smooth rotating vehicle icon, GPS-quality halo | Uber / Ola, Google Maps, Flightradar24 | ✅ white top-view coach, turns with heading, keeps last heading when parked, halo when the fix is poor |
| 1 | GPS clean-up: spikes, parked jitter | Traccar filters, Uber map-matching | ✅ bad fixes dropped, max speed cap, **parked wobble adds no km**; ⏳ snap-to-road needs road geometry |
| 1 | Station-by-station timeline with delay | **Indian Railways NTES / IRCTC**, Where is my Train | ✅ running status per stop from the fixes (arrival ≤ 1 km, departure > 1.5 km) vs the timetable, ढिलो / छिटो chips |
| 1 | Status headline: left X at, next Y km, late, updated | NTES summary | ✅ `LF.headline` on every card, TV and passenger page |
| 1 | ETA from road distance and moving speed | Chalo, redBus, Uber | ✅ road km (× 1.25) at the moving-average speed |
| 1 | Phone health: battery, **charger**, screen off | Google location sharing, Samsara "Gateway Unplugged" | ✅ battery, charger, network, on-screen → alerts |
| 1 | Store-and-forward without network | Where is my Train, Traccar Client | ✅ queue kept even across a reload; a late batch still records stops and alerts at the right times |
| 1 | Exception alerts: overspeed, long halt, no data, late | Samsara, Geotab, Traccar | ✅ overspeed (15 s over the limit), long stop, no data, late bands; ⏳ off-route |
| 1 | Passenger share link, no login | redBus, Uber "Share my trip" | ✅ `bus-live.php?t=…`, one bus, nothing private, ends 2 h after the trip |
| 2 | Event-driven voice announcements | railway station PA, Samsara in-cab | ✅ Nepali, railway wording, station chime, siren for SOS, driver phone says the speed limit |
| 2 | Click a vehicle → photo card | Flightradar24, Uber | ✅ our white coach + crest + plate |
| 2 | Trip replay | Flightradar24 Playback, Traccar | ✅ 10× / 60× / 300×, seek slider |
| 2 | Departure-board table for the office TV | station boards | ✅ `/admin/live-tv.php?layout=board` |
| 2 | Driver SOS / breakdown buttons | Samsara driver app | ✅ SOS, breakdown, puncture, police check, meal halt |
| 2 | Google-Maps-like colours | Google Maps, Uber custom map | ✅ measured from Google tiles, day + night |
| 2 | Scalable 1-second feed | GTFS-Realtime | ✅ one stateless snapshot per request |
| 2 | Route line done / remaining, geofence halts, WhatsApp "bus is near", service notices, daily report, safety score, on-time history, seat occupancy | various | ⏳ later — the data (`fleet_points`, `fleet_stop_events`, `fleet_alerts`) is already stored for them |

## How the Google colours were matched

Google's own map tiles may not legally be shown inside another map library, so the free OpenFreeMap "liberty" style is
recoloured instead. Its 120 layer ids were matched to colours **measured from Google's tiles** at 20 places (Mumbai, Surat,
Ahmedabad, Vadodara, Delhi, the Thar, Gir, Everest, the Gujarat coast…), light and dark: land `#f5f3f3` (warm `#f5f0e5`
at low zoom), water `#90daee`, parks `#c3f1d5`, national highways slate-blue `#8ba5c1`, streets `#d8e0e7`, labels
`#2f3034` / `#3c4043`; night: land `#1c2a40`, water `#00102e`, highways `#47688a`, town names `#f0f2f5`.
`LiveFleet.applyLook(map, 'day' | 'night')` applies 128 rules; checked against the real liberty style with no style errors.

## Nepali voices — what each screen can do

| Device | Nepali voice | Advice |
|---|---|---|
| **Microsoft Edge** (Windows / Mac) | ✅ *Hemkala*, *Sagar* (online, natural) | best for the office TV PC; start with `--autoplay-policy=no-user-gesture-required` to speak without a tap |
| Chrome on Android (also the bus phone) | ✅ after Settings → Text-to-speech → Speech Services by Google → Install voice data → नेपाली | |
| Chrome on Windows / Mac | ❌ — Hindi voice reads the Devanagari | understandable, Hindi accent |
| Android TV box with Fully Kiosk | no Web Speech — Live Fleet uses Fully's own `fully.textToSpeech` | |
| iPhone / Safari | ❌ Nepali, needs a tap every page load | fine for staff, not for an unattended TV |

Every sentence is spoken as its own utterance (Chrome cuts long ones after ~15 s); a voice that fails (an online voice
with the internet down) is skipped from then on; if the browser refuses to speak, the 🔊 button comes back.

## What a phone in the bus can report (Android Chrome)

| Signal | API | Used for |
|---|---|---|
| position, accuracy, speed, heading, altitude | `geolocation.watchPosition` (only while the page is on screen) | live map, trail, km, ETA, stops |
| battery %, **charging** | `navigator.getBattery()` | low battery, **unplugged** alerts |
| network type | `navigator.connection.effectiveType` / `type` | "weak network" vs "bus stopped" |
| page on screen | `visibilitychange` (+ a last `keepalive` ping when hidden) | "app off screen" alert |
| screen kept awake | `navigator.wakeLock` | keeps GPS flowing |

The one thing a web page cannot do is read GPS with the screen off — for that the driver page offers **Traccar Client**
(`api/fleet-osmand.php`), which keeps sending in the background.
