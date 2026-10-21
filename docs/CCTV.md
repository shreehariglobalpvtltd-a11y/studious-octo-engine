# Adding CCTV cameras later · पछि सीसी क्यामेरा जोड्ने

The system is **already ready** for cameras: every bus can have any number of camera feeds,
and they play on **Live Fleet** (pick the bus) and on the **TV wall** (beside the map / in each tile).
Until a camera is added, the screens simply say *"No camera on this bus yet"*.

> **नेपालीमा छोटकरीमा:** बसको DVR ले प्रायः `rtsp://` लिंक दिन्छ — ब्राउजरले त्यो सिधै चलाउन सक्दैन।
> त्यसैले सर्भरमा एउटा सानो "गेटवे" (MediaMTX) राख्ने, जसले RTSP लाई `https://…/index.m3u8` (HLS) बनाइदिन्छ।
> त्यो लिंक **Admin → Map → Vehicles & Drivers → CCTV cameras** मा हाल्ने। बस, भयो।

## 1. Which camera type to choose

| Type in the form | Use it when your camera / DVR gives you… | Example link |
|---|---|---|
| **HLS video (.m3u8)** | a live stream link ending in `.m3u8` (best: smooth video, works on phones and TVs) | `https://cam.shreehariglobal.in/bus1-front/index.m3u8` |
| **MJPEG stream** | a "motion JPEG" link (older IP cameras, `…/video.mjpg`) | `https://cam.example.com/mjpg/video.mjpg` |
| **Snapshot JPEG** | only a still-picture link (`…/snapshot.jpg`) — it is re-loaded every second | `https://cam.example.com/snap.jpg?token=…` |
| **Web player page** | the DVR company's own web viewer page (CMSV6, iVMS, etc.) that works in a browser | `https://dvr-cloud.example.com/player?dev=123&ch=1` |

Rules the form enforces (so nothing unsafe can be saved):

- the link must start with `https://` (or `http://` — but an **http** camera will **not** play on the https website; browsers block it)
- **no `user:password@`** inside the link — use the gateway's token link instead (passwords in links would be visible to every viewer)

## 2. The usual case: bus DVR with RTSP → MediaMTX → HLS

Most bus DVRs / IP cameras give **RTSP** (`rtsp://user:pass@ip:554/…`). Browsers cannot play RTSP,
so run **[MediaMTX](https://github.com/bluenviron/mediamtx)** (free, one file) on the VPS. It pulls
RTSP from the bus and serves HLS.

```
 Bus DVR (4G SIM) ──RTSP──▶ MediaMTX on the VPS ──HLS (https)──▶ Live Fleet page / TV wall
```

### 2.1 MediaMTX config (`mediamtx.yml`, only the parts you change)

```yaml
hls: yes
hlsAddress: 127.0.0.1:8888        # only nginx talks to it
hlsAllowOrigin: https://www.shreehariglobal.in   # newer MediaMTX versions: hlsAllowOrigins: ['https://www.shreehariglobal.in']
hlsVariant: lowLatency

paths:
  bus1-front:
    source: rtsp://admin:CHANGE@BUS1-DVR-IP:554/ch1/sub   # the SUB stream: smaller, cheaper on 4G
    sourceOnDemand: yes            # only pulls from the bus while someone is watching
  bus1-cabin:
    source: rtsp://admin:CHANGE@BUS1-DVR-IP:554/ch2/sub
    sourceOnDemand: yes
```

The password stays **inside the server config** — viewers only ever see the HLS link.

### 2.2 nginx: give it an https address

```nginx
server {
    listen 443 ssl http2;
    server_name cam.shreehariglobal.in;
    # ssl_certificate … (certbot --nginx -d cam.shreehariglobal.in)

    location / {
        proxy_pass http://127.0.0.1:8888;
        proxy_set_header Host $host;
    }
}
```

### 2.3 Add it in the admin

**Admin → Map → Vehicles & Drivers → 📹 CCTV cameras**

- Bus: *SHG Himalaya Express*
- Camera name: *Front road*
- Type: *HLS video (.m3u8)*
- Stream link: `https://cam.shreehariglobal.in/bus1-front/index.m3u8`

Open **Live Fleet**, tap the bus → the video plays in the Cameras box. The TV wall shows it next to the map.

## 3. Good to know

- **Mobile data cost:** every camera that is being watched pulls video over the bus's 4G SIM.
  Use the DVR's *sub-stream* (low resolution) and `sourceOnDemand: yes`, so the bus only sends video while someone looks.
- **Who can see it:** anyone who can open Live Fleet (staff with *schedules.view*) or who has the TV link.
  Keep the TV link private; make a new one (Setup page → 🔄 New link) if it leaks.
- **Page security (CSP):** the website blocks videos/frames from unknown hosts. Live Fleet automatically allows
  **only the hosts of the cameras you added** on its own pages — nothing else on the site changes.
- **DVRs that only speak a vendor protocol** (e.g. JT/T 1078 "CMSV6"-style 4G MDVRs): use the vendor's web viewer
  link as type *Web player page*, or ask the vendor for an RTSP/HLS output and use MediaMTX as above.
