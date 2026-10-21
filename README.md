# 🛰️ Live Fleet — live bus tracking for shreehariglobal.in

Every bus **by its vehicle number** on one map, **live, refreshed every second**, from the phone in that bus.
Office staff watch it in the admin panel, on a phone, or full-screen on a **TV like a video channel**, with
**Nepali voice announcements**. Families get a **live link** to their bus. CCTV cameras can be added per bus later.

> **नेपालीमा:** हरेक गाडी नम्बरमा एउटा **बस फोन** जोड्नुहोस् (अफिसले ६ अङ्कको कोड / QR दिन्छ — एक पटक मात्र)।
> त्यो फोन बसमै चार्जरमा राख्ने — आफैं सुरु हुन्छ र हरेक सेकेन्ड GPS पठाउँछ; तोकिएको ड्राइभरको नाम पनि आफैं देखिन्छ।
> वा ड्राइभरले आफ्नै मोबाइलमा **Driver ID + PIN** ले लगइन गर्न सक्छ। अफिसमा **Admin → Map → Live Fleet** मा
> सबै बस गाडी नम्बर अनुसार लाइभ, **Google Map जस्तै रङमा**। हरेक ठाउँ पुग्दा रेलवे स्टेसन जस्तै 🔔 घण्टी र नेपाली आवाज:
> *"बस जी जे, शून्य दुई, टी, चार एक दुई सात, वडोदरा आइपुग्यो, १० मिनेट ढिलो। अर्को स्टप चिलोडा हो …"*,
> मन्दिर नजिक: *"… अहिले सोमनाथ मन्दिर नजिकबाट जाँदै छ। हर हर महादेव!"*। ड्राइभरले 🆘 थिचे टिभीमा रातो
> सूचना र साइरन। फाइल अपलोड गरेर `/admin/live-install.php` मा **Install all** थिचे मात्र पुग्छ।

| Admin — bus by number, Google-style map | TV — SOS takes over the screen | Bus phone (paired to a vehicle) |
|---|---|---|
| ![Admin](docs/screenshots/r3-admin-bus.png) | ![TV SOS](docs/screenshots/r3-tv-sos.png) | ![Bus phone](docs/screenshots/r3-busphone.png) |

| Vehicles & Drivers — pair a phone by code / QR | TV departure board | Passenger live link |
|---|---|---|
| ![Setup](docs/screenshots/r3-setup-vehicles.png) | ![Board](docs/screenshots/r3-tv-board.png) | ![Passenger](docs/screenshots/r3-passenger.png) |

*(Screenshots are from the test setup, where internet map tiles are blocked, so the map shows only land, sea and one road drawn in the
Google colours. On the real site the full street map shows in these colours.)*

---

## ⬇️ Download (ready to upload)

**[live-fleet-shreehari.zip](https://github.com/shreehariglobalpvtltd-a11y/studious-octo-engine/raw/claude/bus-live-tracking-9ergy9/dist/live-fleet-shreehari.zip)**
(works from any account, no login needed). Inside: `1-upload-to-public_html/` (the files), `2-menu/` (menu lines + patches),
`3-database/` (the SQL), `4-tools/` (install + test), and **`PADHNUHOS-PAHILE-YO.md`**, the step-by-step guide in Nepali
(same as [docs/INSTALL-NEPALI.md](docs/INSTALL-NEPALI.md)). Rebuild it any time with `bash tools/make-package.sh dist`.

**Fastest install (no terminal):** upload `1-upload-to-public_html/` into `public_html/`, open
`https://www.shreehariglobal.in/admin/live-install.php` as the super-admin and press **⚡ Install all**.
Then add the cron line it shows.

## 1. What you get

| Screen | Address | Who | What it does |
|---|---|---|---|
| **Bus phone / Driver app** | `/driver/` | the phone in the bus | **Bus phone:** paired once to a vehicle number with a 6-digit code (or QR `/driver/?pair=CODE`), starts by itself, tracks whoever drives. **Driver phone:** Driver ID + PIN → the assigned bus is ticked → Start. GPS **every 1 s**, screen kept awake, no-signal points kept (even across a reload), 🆘 SOS · 🛠️ breakdown · 🛞 puncture · 🚓 police · 🍛 meal buttons, spoken overspeed warning. |
| **Live Fleet** | `/admin/live-fleet.php` | staff (*schedules.view*) | Every bus by number plate, refresh **1 s**, white top-view coach that turns with the road, Google-style colours, search by number or driver, alerts with ✓ resolve, speed / average / max / km, phone battery + charger + network, places + stops ahead in Nepali, **railway-style running status**, ⏪ **replay** of any trip, passenger link, call / WhatsApp. |
| **TV Wall** | `/admin/live-tv.php` | staff, or a TV with a private link | Full screen, night Google colours. **One bus** (rotates), **Grid**, **All**, or **🚉 Board** (station departure board). An SOS takes over every layout with a red bar + siren. |
| **Vehicles & Drivers** | `/admin/live-setup.php` | manager / superadmin | Vehicles by number: assigned driver, 📲 pair a bus phone (code + QR), who is driving now. Driver IDs + PINs, every attached phone, speed limit + long-stop alert, CCTV, TV link. |
| **Passenger link** | `/bus-live.php?t=…` | anyone with the link | One bus, live, in Nepali: where, next stop, km, expected time, running status; call / WhatsApp the office. Nothing private. Dies 2 h after the trip. |
| **Installer** | `/admin/live-install.php` | super-admin | Database, menu and the old Live Map in one click, with backups. |
| **Old Live Map** | `/admin/map.php` | staff | Now also shows every fleet bus, in Google-style colours (added by the installer). |

Status colours: 🟢 **Live** (GPS in the last 20 s) · 🟠 **Weak signal** (20 s – 3 min) · 🔴 **Offline** (trip running, no GPS for 3+ min) · ⚪ **Parked** (trip ended).

### 🌍 Ideas taken from the best tracking systems

| From | What Live Fleet does with it |
|---|---|
| **Indian Railways NTES / "Where is my Train"** | Stop-by-stop running status: timetable time, real arrival / departure, expected time ahead, *१० मिनेट ढिलो* chips; the one-line headline *"वडोदराबाट ११:२० मा छुट्यो · अर्को चिलोडा ३२ किमी · १० मिनेट ढिलो"*; station-style 🔔 chime and wording; departure board on the TV |
| **Uber / Ola** | the vehicle icon glides and turns with the road; the passenger "share trip" link; driver first name only |
| **Flightradar24** | click a bus → photo card (our white coach + crest) with plate, speed, avg, max; ⏪ replay a whole trip at 10× / 60× / 300× |
| **Samsara / Geotab / Traccar** | one phone per vehicle number; alerts for overspeed (15 s over the limit), long stop, low battery, **phone unplugged**, app off screen; driver panic button; background mode with the Traccar Client app |
| **Google Maps** | the colours — measured from Google's own tiles over Gujarat / India: warm-grey land, cyan water, mint parks, slate-blue national highways; the dark navigation palette at night |
| **redBus "Track my bus"** | ETA to every stop from road km + the bus's average moving speed (a tea stop does not spoil it) |

More detail: **[docs/RESEARCH.md](docs/RESEARCH.md)**.

### 📢 Nepali live announcements (घोषणा)

Shown on screen and **spoken after you tap 🔊 once** (browsers only allow sound after a tap), with a railway-style 🔔 *ding-dong-ding* first:

| When | Example (the vehicle number is read letter by letter) |
|---|---|
| a trip starts | एस हरि ग्लोबलको बस GJ-02-T-4127 सुरतबाट रुपैडिहाका लागि प्रस्थान गरेको छ। सम्पूर्ण यात्रुहरूको यात्रा सुखद र सुरक्षित रहोस्। |
| nearly at a stop | बस GJ-02-T-4127 वडोदरा नजिक आइपुगेको छ र करिब ६ मिनेटमा त्यहाँ पुग्नेछ। त्यहाँबाट चढ्ने यात्रुहरू कृपया तयार रहनुहोला। |
| at a stop | बस GJ-02-T-4127 वडोदरा आइपुग्यो, २२ मिनेट ढिलो। अर्को स्टप चिलोडा हो, जुन यहाँबाट करिब १२१ किमी टाढा छ। त्यहाँ पुग्न करिब १ घण्टा ५० मिनेट लाग्नेछ। |
| leaving | बस GJ-02-T-4127 वडोदराबाट प्रस्थान गरेको छ। अब यो चिलोडातर्फ जाँदै छ। |
| a town / village | बस GJ-02-T-4127 भरुच आइपुग्यो। अगाडि करजण — ४० किमी, करिब ४० मिनेट। |
| a temple within 1 km | बस GJ-02-T-4127 अहिले सोमनाथ मन्दिर नजिकबाट जाँदै छ। हर हर महादेव! |
| running late (15, 30, 60, 120 min) | … आफ्नो निर्धारित समयभन्दा करिब ४५ मिनेट ढिला चलिरहेको छ। … असुविधाका लागि हामी क्षमाप्रार्थी छौँ। |
| every 15 min | … अहिले लखनऊ नजिक छ, गति ६२ किमी/घण्टा। रुपैडिहा पुग्न अझै १३५ किमी बाँकी छ … |
| last stop | बस GJ-02-T-4127 रुपैडिहा आइपुगेर आजको यात्रा सकुशल सम्पन्न भयो। |
| 🆘 SOS / breakdown (siren first) | बस GJ-02-T-4127 बाट आपतकालीन सन्देश आएको छ! मद्दत चाहियो — भरुच नजिक। कृपया तुरुन्त चालकसँग सम्पर्क गर्नुहोला। |
| overspeed | सावधान! बस … तोकिएको सीमाभन्दा बढी गतिमा चलिरहेको छ। हालको गति ९२ किलोमिटर प्रतिघण्टा छ, सीमा ८० किलोमिटर प्रतिघण्टा हो। |

**Temple greetings** match the deity: हर हर महादेव · जय श्रीराम · जय श्रीकृष्ण · राधे राधे · जय स्वामिनारायण · जय द्वारकाधीश ·
जय माता दी · जय अम्बे माँ · जय बहुचर माँ · जय माँ काली · जय बजरंगबली · जय श्री गणेश · जय जलाराम बापा · ॐ साईं राम ·
जय जगन्नाथ · ॐ नमो नारायणाय · जय पशुपतिनाथ · जय शनिदेव · … otherwise हरि ॐ.

**Best voice:** on the office PC use **Microsoft Edge** — it has real Nepali voices (*Hemkala*, *Sagar*). On Android:
Settings → Text-to-speech → *Speech Services by Google* → Install voice data → **नेपाली**. Elsewhere a Hindi voice reads it
(same script, clearly understood). ⚙️ next to 🔊 picks the voice, speed, chime and the *"आदरणीय यात्रुहरू, कृपया ध्यान दिनुहोला"* intro.
For an unattended TV, start Edge with `--autoplay-policy=no-user-gesture-required` so it speaks without a tap.

Where place names come from: the server asks **OpenStreetMap** (free, Overpass API) once for each ~20 km square a bus drives into,
saves towns, villages and Hindu temples in `fleet_places`, and reuses them for 90 days. If the server cannot reach `overpass-api.de`,
set another Overpass server in Settings → `fleet_overpass_url`. Route stops always work, with or without it.

## 2. How it works (ground level)

```
 PHONE IN THE BUS (/driver/)              SERVER (PHP + MySQL)                     OFFICE / TV / FAMILY
 ───────────────────────────              ────────────────────                     ────────────────────
 6-digit code ── POST pair  ──▶  api/driver.php ─▶ fleet_pair_codes (hash, 15 min)
  (or ID + PIN ── POST login)   ◀── token ─────  fleet_devices (kind = vehicle | driver)
 Start        ── POST start ──▶  fleet_trips (bus, assigned driver, share token)
 GPS 1/s + battery, charger,
 network, screen ── POST ping ─▶ fleet_trips.last_* ─▶ alerts (fleet_alerts)  ◀─ GET 1/s ─ admin/live-fleet.php
                                 fleet_points (trail)    stops (fleet_stop_events)           admin/live-tv.php
 🆘 SOS       ── POST event ──▶  fleet_alerts (red)                             ◀─ GET 1/s ─ bus-live.php?t=…
 End          ── POST end   ──▶  fleet_trips (ended)
```

- **Why "by vehicle number"?** A phone that stays in the bus (on the charger, paired to its number) is the tracker — like a
  Samsara / Traccar device. It keeps working when drivers change: the office sets the bus's regular driver on
  *Vehicles & Drivers*, and the trip shows that name. The driver's own phone (ID + PIN) still works too.
- **Why 1-second polling and not WebSockets?** The site runs on normal PHP. A small JSON request every second works on any hosting,
  through any firewall, with nothing extra to keep running.
- **Why does the bus move smoothly?** Between two GPS fixes the browser slides the coach over 1 second and turns it to the road's heading.
- **Phone screen must stay on.** A web page cannot read GPS when the phone is locked. The app keeps the screen awake, tells the office when
  it goes off screen, and offers **Traccar Client** for true background tracking (`api/fleet-osmand.php`).
- **What the phone reports:** position, speed, heading, accuracy, altitude every second; battery %, charger in or out, network (4G / Wi-Fi),
  whether the app is on screen. The office gets an alert when the bus phone is unplugged, the battery is low or the app went to the background.
- **Google colours, not Google tiles:** Google's own map tiles may not be shown inside another map library. The free OpenFreeMap tiles are
  recoloured with colours measured from Google's tiles (`LiveFleet.applyLook` in `assets/livefleet/livefleet.js`).
- **The old Live Map still works**, now with every fleet bus on it; while exactly one bus is live, the old `livebus` value is also fed,
  so the passenger Trip Companion and "bus is near" alerts keep working.

## 3. Files (all NEW — nothing existing is replaced; the installer edits two files, with backups)

```
site/                                  ← copy into the website, same paths
├── database/upgrade-2026-10-live-fleet.sql   10 new tables (fleet_*) + 5 settings
├── includes/livefleet.php             the engine: vehicles, phones, trips, GPS, alerts, running status, places, cameras
├── includes/livefleet-install.php     installer logic (database, menu, Live Map hook; backup + parse check)
├── api/driver.php                     phone ↔ server (pair / login / start / ping / event / end)
├── api/fleet-osmand.php               Traccar Client background tracking
├── api/live-fleet.php                 the 1-second map data, passenger link, replay, resolve
├── api/fleet-places.php               town / temple names (OpenStreetMap) for new map squares
├── admin/live-fleet.php               Live Fleet page
├── admin/live-tv.php                  TV wall (one bus / grid / all / board)
├── admin/live-setup.php               Vehicles & Drivers: assign, pair, phones, alerts, CCTV, TV link
├── admin/live-install.php             one-click installer (super-admin)
├── bus-live.php                       passenger live link
├── driver/index.php                   the phone app (+ manifest.webmanifest)
├── assets/livefleet/livefleet.js|css  shared map, markers, Google look, Nepali tracker + voice, cameras
├── assets/livefleet/overlay.js        fleet buses on the old Live Map
├── assets/livefleet/shg-logo.svg      the company crest + "S HARI GLOBAL" wordmark, redrawn from the coach livery
├── assets/livefleet/shg-crest.svg     the crest alone
├── cron/fleet-cleanup.php             hourly: end silent trips, delete old trail points
└── tests/live-fleet-test.php          108 engine checks
    tests/live-fleet-vehicles-test.php  76 checks: vehicles, pairing, phone health, alerts, SOS, running status, link, installer
    tests/live-fleet-http-test.php     56 HTTP checks (phone → server → TV → passenger)
patches/admin-nav.patch                the 3 menu items (what the installer adds to admin/_guard.php)
patches/admin-map.patch                the Live Map hook (what the installer adds to admin/map.php)
tools/install.sh                       terminal install: copies site/ in, adds menu + hook
tools/test-local.sh                    builds a throw-away copy of the website and runs every test (also in GitHub Actions)
tools/fleet-simulator.php              fake buses driving NH48, 1 fix/second
tools/fleet-demo-seed.php              3 demo drivers DEMO1–3 / PIN 1234 + sample NH48 towns & temples (test copy only)
tools/make-package.sh                  builds the download zip
```

## 4. Install on the website

> ⚠️ The website folder **is live** — every saved file is live immediately. Take a backup first and follow the site's own `CLAUDE.md`.

**A. No terminal (Hostinger File Manager / FTP)** — see the Nepali guide [docs/INSTALL-NEPALI.md](docs/INSTALL-NEPALI.md):
1. Upload everything inside `1-upload-to-public_html/` into `public_html/` (same folders).
2. Open `/admin/live-install.php` as the super-admin → **⚡ Install all** (database, menu, old Live Map; backups end in `.bak`, which the site never serves).
3. hPanel → Advanced → Cron Jobs: the line the installer shows (hourly cleanup).

**B. With a terminal (VPS):**
```bash
cd /var/www/shreehariglobal.in/public_html
tar czf /root/backups/before-live-fleet-$(date +%F).tgz --exclude=uploads .
git clone https://github.com/shreehariglobalpvtltd-a11y/studious-octo-engine.git /root/live-fleet
bash /root/live-fleet/tools/install.sh /var/www/shreehariglobal.in/public_html
php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql
# crontab -e
17 * * * * php /var/www/shreehariglobal.in/public_html/cron/fleet-cleanup.php >/dev/null 2>&1
```

**C. Through the `shreehari` GitHub repo:** copy `site/` into that repo at the same paths, `git apply patches/admin-nav.patch patches/admin-map.patch`,
commit and deploy as usual, then run the SQL (or the installer's Database step).

## 5. First use (5 minutes)

1. **Admin → Map → Vehicles & Drivers.**
2. Next to each vehicle number: choose its **regular driver**, then press **📲 Pair a phone** → a 6-digit code + QR appears.
3. On the phone that will stay in that bus: scan the QR (or open `/driver/` → **Bus phone** → type the code) → allow **Location**.
   It starts tracking by itself. Keep it on the charger, screen on.
   *(Or give a driver a Driver ID + PIN in **Driver logins** and let them use their own phone.)*
4. **Admin → Map → Live Fleet** — the bus appears by its number within seconds. Tap it; press 🔊 for Nepali announcements.
5. **TV:** on Vehicles & Drivers press **➕ Make TV link**, open it on the office TV (Edge), **⛶ Full screen**, tap 🔊 once.
6. A family asks where the bus is: on the bus card press **📲 Send link** (WhatsApp) — they see it live, nothing private.

Lost phone? **Sign out** next to it, or pair a new bus phone (the old one stops at once). New PIN for a driver signs all their phones out.

## 6. Test it without a bus

On a **test copy** (never the live site — it would move real buses' dots):

```bash
bash tools/test-local.sh            # fresh copy of the website + test database + every test + simulator
php tools/fleet-demo-seed.php /path/to/test-site
php tools/fleet-simulator.php --base=http://127.0.0.1:8899 \
    --driver=DEMO1:1234:1 --driver=DEMO2:1234:2 --driver=DEMO3:1234:3 --speedup=10
```

What was tested (all passing, also in GitHub Actions on MySQL 8):

| Check | Result |
|---|---|
| `tests/live-fleet-test.php` — login, lock-out, trips, GPS cleaning, trail, distance, status, stops, Nepali names, towns / temples, cameras, TV key, cleanup | **108 / 108** |
| `tests/live-fleet-vehicles-test.php` — vehicle numbers, pairing codes, bus phones, phone health, overspeed, long stop, parked GPS wobble, SOS / puncture, running status per stop, replay, passenger link privacy + expiry, installer (CRLF, backups, run twice, refuses broken code) | **76 / 76** |
| `tests/live-fleet-http-test.php` — real HTTP: phone API, pairing, SOS → TV → cleared, passenger link, replay, map API auth, TV key, Traccar formats, pages | **56 / 56** |
| Real browser (Chromium): pair a bus phone from the QR → tracking → SOS on the TV with siren banner; departure board; admin bus card; passenger link on a phone; the installer on an untouched copy of the website (menu + Live Map, CRLF kept, `php -l` clean); Google colours applied to the real OpenFreeMap style (128 rules, no style errors) | ✅ no console errors |

## 7. Settings & limits

| Setting | Where | Default |
|---|---|---|
| Speed limit (overspeed alert after 15 s) | Vehicles & Drivers → Alerts (`fleet_speed_limit`) | 80 km/h |
| Long-stop alert | Vehicles & Drivers → Alerts (`fleet_stop_alert_min`) | 20 min |
| Live / weak / offline | `LiveFleet::LIVE_SECONDS` / `WEAK_SECONDS` | 20 s / 3 min |
| Trail density / keep | `TRAIL_SECONDS` / Settings → `fleet_keep_days` | 1 point per 5 s / 30 days |
| Pairing code | `createPairCode` | 6 digits, 15 min, single use |
| Passenger link | `shareView` | that trip only, until 2 h after it ends |
| PIN length, lock-out | `PIN_MIN/MAX`, `MAX_FAILS`, `LOCK_MINUTES` | 4–8 digits, 5 tries → 15 min |
| TV rotation / layout | `/admin/live-tv.php?rotate=30&layout=board` | 20 s, one bus |

## 8. CCTV later

Add a camera per bus in **Vehicles & Drivers → CCTV cameras** (HLS, MJPEG, snapshot or the DVR's web player).
Bus DVRs usually give `rtsp://…` — see **[docs/CCTV.md](docs/CCTV.md)** for turning that into a browser link with MediaMTX.
