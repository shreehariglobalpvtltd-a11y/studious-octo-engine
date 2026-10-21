# Live Fleet — वेबसाइटमा राख्ने तरिका (सजिलो नेपालीमा)

यो प्याकेज **shreehariglobal.in** मा राख्नका लागि हो। सबै फाइल **नयाँ** हुन् — पुरानो कुनै फाइल मेटिँदैन,
बदलिँदैन (मेनुको ५ लाइन बाहेक)। पहिले **टेस्ट कपी**मा गर्नुहोस्, अनि मात्र लाइभमा।

## प्याकेजभित्र के छ

| फोल्डर | के हो |
|---|---|
| `1-upload-to-public_html/` | वेबसाइटको `public_html` भित्र अपलोड गर्ने फाइलहरू (उही फोल्डर संरचना) |
| `2-menu/` | एडमिन मेनुमा थप्ने ५ लाइन (`MENU-5-LINES.txt`) र patch फाइल |
| `3-database/` | डाटाबेसमा चलाउने SQL (६ नयाँ टेबल) |
| `4-tools/` | टेस्ट गर्ने सिमुलेटर, डेमो ड्राइभर, install स्क्रिप्ट |
| `docs/` | CCTV जोड्ने तरिका, अनुसन्धान, स्क्रिनसट |

---

## चरण ० — ब्याकअप (अनिवार्य)

Hostinger → **File Manager** मा `public_html` को zip ब्याकअप लिनुहोस्, र **phpMyAdmin → Export** बाट डाटाबेसको पनि।
SSH छ भने:

```bash
cd /var/www/shreehariglobal.in/public_html
tar czf /root/backups/before-live-fleet-$(date +%F).tgz --exclude=uploads .
```

## चरण १ — फाइल अपलोड

`1-upload-to-public_html` **भित्रका** सबै फोल्डर (`admin`, `api`, `assets`, `cron`, `database`, `driver`, `includes`, `tests`)
वेबसाइटको `public_html` मा अपलोड गर्नुहोस्। "Replace?" सोध्यो भने — सोध्नु हुँदैन, किनकि सबै नयाँ फाइल हुन्।
(SSH: `bash 4-tools/install.sh /var/www/shreehariglobal.in/public_html` — यसले चरण १ र २ दुवै गर्छ।)

## चरण २ — मेनुमा ३ बटन थप्ने

`public_html/admin/_guard.php` खोल्नुहोस्। यो लाइन खोज्नुहोस् (Ctrl+F मा `'Live Map'`):

```php
        ['href' => 'map.php',          'icon' => 'map-pin',      'label' => 'Live Map',         'perm' => 'schedules.view', 'section' => 'Map'],
```

त्यसको **ठीक तल** `2-menu/MENU-5-LINES.txt` का ५ लाइन पेस्ट गरेर Save गर्नुहोस्।
अब एडमिनको **Map** सेक्सनमा *Live Fleet*, *TV Wall*, *Driver Login & CCTV* देखिन्छ।

## चरण ३ — डाटाबेस

**phpMyAdmin** → बायाँ डाटाबेस (`shari`) छान्नुहोस् → **Import** → `3-database/upgrade-2026-10-live-fleet.sql` → **Go**।
(SSH: `cd public_html && php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql`)
यसले ६ नयाँ टेबल (`fleet_…`) मात्र बनाउँछ। फेरि चलाए पनि केही बिग्रिँदैन।

## चरण ४ — हरेक घण्टा सफाइ (cron)

Hostinger → **Cron Jobs** (वा `crontab -e`):

```
17 * * * * php /var/www/shreehariglobal.in/public_html/cron/fleet-cleanup.php >/dev/null 2>&1
```

## चरण ५ — प्रयोग सुरु

1. **Admin → Map → Driver Login & CCTV** → ड्राइभरको छेउमा **Driver ID** (जस्तै `D101`) र **PIN** (🎲 थिच्दा आफैं बन्छ) → **Give login**।
2. ड्राइभरले त्यहीँको **QR कोड** स्क्यान गर्छ (वा `https://www.shreehariglobal.in/driver/` खोल्छ) → लगइन → बस छान्छ →
   **▶ Start trip** → Location **Allow**। फोन चार्जमा, स्क्रिन खुला।
3. **Admin → Map → Live Fleet** — सबै बस लाइभ, हरेक सेकेन्ड अपडेट। बस थिच्दा: अहिले कहाँ, अगाडि कुन ठाउँ कति किमी / कति मिनेट,
   आउने स्टप र आइपुग्ने समय, नजिकैका मन्दिर, ड्राइभरलाई फोन।
4. **TV:** Setup पेजमा **➕ Make TV link** → त्यो लिंक टिभीमा खोल्ने → **⛶ Full screen** → **🔊 घोषणा सुन्न यहाँ थिच्नुहोस्** एक पटक थिच्ने।
   अब हरेक ठाउँ पुग्दा नेपालीमा बोल्छ: *"भरुच आइपुग्यो। अगाडि करजण — ४० किमी, करिब ४० मिनेट।"*,
   मन्दिर नजिक: *"नजिकै श्री अम्बे माता मन्दिर — ०.९ किमी। जय माता दी!"*

फोन हरायो? त्यो ड्राइभरको **नयाँ PIN** राख्नुहोस् (सबै फोन आफैं लगआउट हुन्छन्) वा फोनको छेउमा **Sign out** थिच्नुहोस्।

## बस नचलाई टेस्ट (टेस्ट कपीमा मात्र)

```bash
php 4-tools/fleet-demo-seed.php /path/to/test-site       # DEMO1–3, PIN 1234 + बाटोका नमुना ठाउँ/मन्दिर
php 4-tools/fleet-simulator.php --base=https://test.example.com \
    --driver=DEMO1:1234:1 --driver=DEMO2:1234:2 --driver=DEMO3:1234:3 --speedup=10
```

तीनवटा नक्कली बस NH48 (सुरत → मेहसाणा) मा चल्छन् — Live Fleet र TV Wall मा हेर्नुहोस्। ⚠️ लाइभ साइटमा नचलाउनुहोस्।

## केही समस्या भए

| समस्या | समाधान |
|---|---|
| ड्राइभरको फोनमा "Location is blocked" | Chrome → साइट सेटिङ → Location → Allow |
| स्क्रिन बन्द हुँदा लोकेसन जाँदैन | स्क्रिन खुला राख्ने, वा ड्राइभर पेजको *Background mode* मा लेखेजस्तै **Traccar Client** एप |
| ठाउँ / मन्दिरको नाम आएन | सर्भरले `overpass-api.de` सम्म पुग्न सकेन — Settings मा `fleet_overpass_url` = `https://overpass.kumi.systems/api/interpreter` |
| आवाज आएन | 🔊 बटन एक पटक थिच्नुहोस् (ब्राउजरले थिचेपछि मात्र बोल्न दिन्छ); टिभीको आवाज खुला छ कि हेर्नुहोस् |
| CCTV | `docs/CCTV.md` हेर्नुहोस् |
