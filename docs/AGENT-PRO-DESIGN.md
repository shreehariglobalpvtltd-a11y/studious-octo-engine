# Agent Pro — system design (Oct 2026)

ID card · visiting card with WhatsApp QR · join form with auto-fill · member wallet ·
WhatsApp desk for agents and staff on the company's own AI · admin Control Center.

> **Ek line ma (Nepali):** Har staff ra agent ko premium **ID card** (QR scan garda "yo manche hamro ho" bhanera
> website ma dekhcha) ra **visiting card** (QR scan garda sidhai WhatsApp ma message jancha, *WA: +91 …* print huncha).
> Naya agent le **form** bharda Aadhaar / Nagarikta ko photo halepachi naam, janma miti, thegana **aafai bharincha**;
> WhatsApp bata number verify, selfie (yahi photo ID card ma jancha), agadi–pachadi document, bank (optional).
> Admin le **ek button** thichepachi agent code, ID card, login sabai ready ra WhatsApp ma pugcha.
> Har manche le mobile ma **/me/** kholera aafno ID, visiting card, document, **wallet** (Paytm/BHIM jasto — baki,
> paisa nikalne request, jamma gareko) herna ra photo badalna sakcha; fingerprint le login.
> WhatsApp ma agent le ek choti **code + OTP** le number jodepachi "2 seat bholi Mehsana, Ram 98…" lekhe matra
> ticket katcha, "statement", "balance", "withdraw 2000", "SHG-… ko naam Sita banau" — sabai WhatsApp bata,
> **Nepali / Hindi / English** ma, **aafno VPS ko AI** le (Ollama), kunai paid API bina.

---

## 1. Requirements

### Functional (from the owner's message, item by item)

| # | Ask (owner's words) | What it becomes |
|---|---|---|
| 1 | *ID card visiting card premium* | CR80-size ID card (front + back) and 3.5″ × 2″ visiting card (front + back), rendered live from the database as SVG (screen, print) and PNG (download, WhatsApp). |
| 2 | *jun number hlx tei number … scanner print* · *WA: +91 …* · *QR generate garne direct msg janx* | The visiting card prints exactly the WhatsApp number the member sets ("WA: +91 98765 43210") and a QR of `https://wa.me/<that number>?text=<greeting>` — scanning opens a chat with that number. Change the number → the card and its QR change at once. |
| 3 | *agent code … update hun sath, id ni update* | Cards are never stored images: every render reads the agent code (`AgentWallet::agentCodeLabel`), name, photo and status now. A code change, a new photo or a suspension shows on the next view/print. |
| 4 | *wallet … UPI pay paytm bhim jasto … withdrawal request … jama* | `/me/` wallet screen over the existing `agent_ledger`: balance card, *Request withdrawal* (`agent_payout_requests`), *Pay the company / deposit* (UPI intent + QR with the amount, UTR + screenshot → `ap_deposit_requests`), passbook, PDF statement. Office settles each in one tap. |
| 5 | *form … simple fast effective … Aadhaar / Nagarita = auto filled* | `/join.php`: 6 short steps. The ID photo is read on the server (Aadhaar secure QR → MRZ → local vision model → Tesseract), the fields are filled for the applicant to check. |
| 6 | *ask bank, KYC optional* | Bank / UPI / eSewa / Khalti step, skippable. Stored encrypted. |
| 7 | *face id, fingerprint* | Live selfie from the camera (becomes the profile and ID photo). Fingerprint: a **passkey** (the phone's own fingerprint / face unlock, WebAuthn) to sign in to `/me/`, plus an optional thumb-impression photo. Browsers never hand a website the fingerprint image itself — that is by design and cannot be worked around. |
| 8 | *docs agadi paxadi extra doc* | Front, back and any number of extra documents (≤ 8). |
| 9 | *admin approve one button, no extra verification* | Team → Approvals: selfie next to the ID photo, auto-read fields next to what the applicant typed; one **✓ Approve** activates the login, issues the agent code + ID number, marks KYC verified, copies the selfie to the profile and sends the welcome with the ID card on WhatsApp. |
| 10 | *WhatsApp verification API* | Two ways, both on the company's existing WhatsApp sender: a 6-digit OTP sent **to** WhatsApp, or *reverse verify* — the applicant taps a wa.me link that sends `JOIN 482913` **from** their WhatsApp (free, no template, proves they own that number). |
| 11 | *hamro VPS jun model tei model … no extra API … afnai AI free* | `ApAi` talks to the model server on the VPS (Ollama, or any OpenAI-compatible server such as llama.cpp / vLLM). Claude / Gemini are not needed; when the local brain is on, it replaces them on WhatsApp. |
| 12 | *aile ko model ramro xaina bhane update … version mero lagi k thik* | Admin → AI (VPS): shows the installed models, the VPS's RAM / CPU, recommends a model for that hardware, pulls it in one click, and runs a Nepali / Hindi / English benchmark so the owner sees speed and correctness before switching. |
| 13 | *WhatsApp automation advanced, Nepali Hindi English* | `ApWa`: language detected per message and remembered per number; every reply template exists in all three. |
| 14 | *har kura WhatsApp bata — statement, details, sabko name number* | Commands + AI: balance, statement (with PDF link), withdraw, deposit, my ID card, my visiting card, team directory (office sees everyone; agents see the office only), company facts (CIN, address, counters), today's buses. |
| 15 | *ticketing agent le afno code OTP le register, tespachi code dine bas ticket katne* | `LINK SHG-0027` → OTP to the agent's registered mobile → reply with the OTP → that WhatsApp number now sells on SHG-0027's code. From then on "2 seat bholi Mehsana, Ram Thapa 9812345678" quotes, "ho" sells, the ticket goes to the passenger and the commission to the agent. |
| 16 | *AI dhilo bhaye ni ramro, 1 min lagos tara sahi ticket* | Slow work goes to a queue (`ap_jobs`) worked by **one** background worker; the sender gets "⏳ …" at once and the answer within about a minute. Every sale is still quoted first and needs "ho" — the model never sells on its own. |
| 17 | *ticket har kura edit WhatsApp bata* | Name, age, gender, phone, pickup, berth, date — preview → "ho" → applied with the same functions the admin ticket page uses, ticket re-issued and re-sent. Agents: their own tickets; office: any; passengers: their own (name / phone / date / pickup). |
| 18 | *bus details, office details, CIN …* | Company facts from `Settings::company()`; buses from the fleet tables; Live Fleet passenger link when that module is installed. |
| 19 | *admin panel category-wise cards, mobile bata ni* | `admin/hub.php` Control Center: every admin screen as a tile, grouped by category with live counts; `admin/team.php`: people grouped as cards (Approvals · Agents · Staff · KYC · Money · Cards · Invites). Mobile-first, works on desktop Chrome. |
| 20 | *live gardeu* | The add-on installs like Live Fleet: upload + `/admin/agentpro-install.php` → **Install all**. (This repository cannot reach the VPS; see §10.) |
| 21 | *focus on testing* | Engine tests, WhatsApp tests against a mock model server, HTTP tests, a real-browser run with screenshots — all in `tools/test-local.sh` and GitHub Actions. |

### Non-functional

- **Runs on the existing VPS** (nginx + PHP 8.3-FPM + MySQL), no new service other than the model server the owner already runs.
- **Never breaks the live site:** every file is new; the installer edits two existing files (menu + WhatsApp hook) with a backup and a parse check, exactly like Live Fleet.
- **Phones first:** every new screen is designed at 360 px and scales up to a desktop Chrome window.
- **Privacy:** ID documents are never on a public URL; Aadhaar numbers are stored masked (`XXXX XXXX 1234`) unless the owner turns full storage on; bank and ID numbers are encrypted at rest.
- **CPU-only AI:** a 4–9 B model on a 4–8 vCPU VPS answers in 10–60 s. The design accepts that latency (owner: 1 min is fine) and never lets two model calls run at once.

### Load estimate

~50 agents, ~10 staff, a few hundred WhatsApp messages a day, a handful of applications a week. The model server is
the only scarce resource (one request at a time on CPU); everything else is trivial for MySQL.

---

## 2. High-level design

```
                           ┌──────────────────────────── VPS ─────────────────────────────┐
  APPLICANT (phone)        │                                                              │
  /join.php ──── fetch ───▶│ api/agentpro.php ── ApDocRead ── Aadhaar QR / MRZ / OCR ─┐   │
   camera, ID photo        │   otp · reverse-verify · upload · read-doc · submit      │   │
                           │        │                                                 ▼   │
                           │        ▼                                    ┌─────────────────┐
                           │  ap_applications ──(one tap)──▶ admins +    │ Model server    │
  OFFICE (admin panel)     │  (pending)        AgentPro::approve  admin_ │ Ollama :11434   │
  /admin/hub.php  ────────▶│                    │             profiles + │ qwen3.5 / gemma4│
  /admin/team.php          │                    ▼             ap_members │ (one at a time) │
  /admin/ai-local.php      │             welcome on WhatsApp  + agent    └────────▲────────┘
                           │             (ID card PNG)          code             │
  MEMBER (phone)           │                                                     │
  /me/  ID · card · wallet │ ApCard (SVG/PNG) ◀── live data                      │
  passkey / OTP login ────▶│ AgentWallet (existing ledger) ◀── withdraw/deposit  │
                           │                                                     │
  WHATSAPP (anyone)        │ whatsapp/webhook.php ─▶ WaBot::reply ─▶ ApWa::reply │
  Meta / Twilio / Gupshup ▶│   instant: LINK/OTP, balance, statement, card,      │
                           │            directory, company, JOIN-verify          │
                           │   slow:    ap_jobs ─▶ cron/agentpro-worker.php ─────┘
                           │            ApBrain (AiTurn + AiTools, local model)
                           │            ─▶ QuickTicket / BookingService / ApEdit
                           │            ─▶ Notify::whatsapp (the answer + ticket)
                           └──────────────────────────────────────────────────────────────┘
  ANYONE scanning a card:  /id.php?c=TOKEN  (is this person really ours?)   /c.php?u=TOKEN (digital card, vCard)
```

### Components (all new files under `site/`)

| File | Role |
|---|---|
| `database/upgrade-2026-10-agent-pro.sql` | 9 tables (`ap_*`) + settings. Additive, idempotent. |
| `includes/agentpro.php` | `AgentPro` — members, ID numbers, card tokens, applications, approve / reject, invites, deposits, encryption, photos. |
| `includes/agentpro-card.php` | `ApCard` — one layout (a list of shapes) drawn two ways: SVG and GD PNG. ID front/back, visiting card front/back, A4 print sheets. |
| `includes/agentpro-docs.php` | `ApDocRead` — Aadhaar secure QR + old XML QR, passport MRZ, Tesseract (eng+hin+nep), local vision model, field parsers, merge. |
| `includes/agentpro-ai.php` | `ApAi` — local model client (Ollama native + OpenAI-compatible), structured output, vision, model list / pull, hardware advisor, benchmark. |
| `includes/agentpro-wa.php` | `ApWa` — WhatsApp desk: language, commands, agent link, money, cards, directory, company, queue. |
| `includes/agentpro-brain.php` | `ApBrain` — the model conversation: compact prompt, existing `AiTools` as tools, `AiTurn` loop. |
| `includes/agentpro-edit.php` | `ApEdit` — ticket edits (preview → confirm → apply → reissue → resend). |
| `includes/agentpro-passkey.php` | `ApPasskey` — WebAuthn (fingerprint / face unlock) registration and sign-in, no library. |
| `includes/agentpro-install.php` | Installer (SQL, menu lines, two WhatsApp hooks). |
| `join.php`, `id.php`, `c.php`, `me/` | Public form, card verification, digital visiting card, member portal. |
| `api/agentpro.php`, `api/agentpro-card.php` | JSON API, card images. |
| `admin/hub.php`, `team.php`, `team-member.php`, `team-print.php`, `team-doc.php`, `ai-local.php`, `agentpro-install.php` | Admin screens. |
| `cron/agentpro-worker.php` | Works the AI queue (every minute + kicked on demand). |

---

## 3. Data model

Existing tables are reused, not copied: `admins` (login, role, active), `admin_profiles` (phone, WhatsApp, address,
ID type, photo, KYC paths and status), `agent_ledger` (wallet), `agent_payout_requests` (withdrawals),
`settings['agent_codes']` (SHG-0027). New tables hold only what did not exist:

```
ap_members          admin_id PK · staff_no (non-agents: SHG-S-0005) · designation · department · dob · gender
                    blood_group · guardian · emergency_name/phone · card_token (24, unique, rotatable)
                    card_issued_at · card_valid_until · card_status (active|suspended|revoked)
                    vc_whatsapp · vc_phone · vc_email · vc_tagline · vc_greeting · vc_theme · photo_ver
                    bank_holder · bank_name · bank_account_enc · bank_ifsc · bank_branch · upi_id · esewa_id
                    khalti_id · lang · created_at · updated_at
ap_applications     id · ref (JN-7KQ2M) · status (draft|submitted|approved|rejected) · kind (agent|staff)
                    agent_kind (org|person) · role_wanted · invite_id · full_name · org_name · phone · whatsapp
                    wa_verified_at · wa_verified_via (otp|reverse) · email · dob · gender · guardian · address
                    city · district · state · country (IN|NP) · pincode · id_type · id_number_enc · id_number_masked
                    id_expires_on · doc_front · doc_back · selfie · thumb · extras JSON · bank_* (enc)
                    readout JSON (what was auto-read, from which source) · admin_id · decided_by/at/note
                    draft_key · ip · user_agent · created_at · submitted_at
ap_invites          token · role · designation · created_by · expires_at · used_at · application_id
ap_wa_links         phone PK · admin_id · verified_at · via (otp|registered) · last_seen_at · revoked_at
ap_wa_state         phone PK · lang · turn · pending JSON (what "ho" would confirm) · history JSON · updated_at
ap_jobs             id · phone · kind · text · status (queued|running|done|failed) · attempts · reply · media
                    error · created_at · started_at · finished_at
ap_deposit_requests id · admin_id · amount · purpose (handover|security) · method · utr · proof_path
                    status (open|confirmed|declined) · decided_by/at/note · ledger_id · created_at
ap_passkeys         id · admin_id · credential_id · public_key_pem · alg · sign_count · label · created_at · last_used_at
ap_ai_calls         id · purpose · model · ms · ok · tokens_in · tokens_out · note · created_at
```

**ID numbers.** Agents keep their existing code (SHG-0027) as their ID number, so the card and every ticket agree.
Other staff get `SHG-S-0001…` from `ap_members.staff_no`. Nothing is stored that can drift from the agent code.

**Card token.** 24 random characters. The ID card's QR points to `/id.php?c=TOKEN`; the page shows the photo, name,
role, ID number and a big **VALID / SUSPENDED / EXPIRED** stamp read live — a lost or fake card is caught by scanning.
*Re-issue* rotates the token, so an old printed card reads **REVOKED**.

---

## 4. Key flows

### 4.1 Join → one-tap approval

```
applicant                       server                                   office
─────────                       ──────                                   ──────
1 Who: agent person / agency    draft (ap_applications, draft_key)
2 Mobile ─▶ OTP on WhatsApp ─▶  Auth::issueOtp('apjoin:<phone>')
  or "Verify by WhatsApp" ───▶  JOIN 482913 arrives in WaBot ─▶ ApWa marks wa_verified
3 ID type + front/back photo ─▶ ApDocRead: QR → MRZ → vision model → OCR → parsers
  ◀── fields filled (editable, each tagged "read from QR / photo")
4 Selfie (camera, face oval)    stored private (agents-kyc/)
5 Bank / UPI (skip)             encrypted
6 Extra docs, thumb (skip) ──▶  submit: inactive admins row (role agent) + admin_profiles
                                 + application 'submitted'  ─────────────▶ Team → Approvals card
                                                                           [✓ Approve]  [✕ Reject…]
                                 AgentPro::approve():
                                   admins.is_active = 1, agent code (band by kind),
                                   ID number + card token, KYC verified, selfie → profile photo,
                                   bank → ap_members, login: code + mobile + WhatsApp OTP,
                                   WhatsApp: welcome + ID card PNG + /me/ link
```

### 4.2 Agent on WhatsApp

```
"LINK SHG-0027"          → OTP to the mobile registered for SHG-0027 (WhatsApp, SMS fallback)
"482913"                 → ap_wa_links: this WhatsApp = SHG-0027 ✓
"2 seat bholi Mehsana, Ram Thapa 9812345678"
                         → instant "⏳ …" ; queue ; worker: ApBrain (local model)
                           → AiTools plan_ticket → quote: date, bus, pickup, berths, ₹ total
"ho"                     → AiTools staff_sell → QuickTicket::sell(agent) → ticket to passenger,
                           commission on SHG-0027's wallet, ticket PNG back to the agent
"balance" / "statement" / "withdraw 2000" / "id card" / "visiting card"   → answered instantly
"SHG-2026-01234 ko pickup Surat banau"  → ApEdit preview → "ho" → applied, ticket re-sent
```

The deterministic commands never wait for the model. Anything they do not recognise goes to the model.

### 4.3 Why the model cannot sell by mistake

The model only picks *buttons* (`AiTools`), and the buttons keep their own rules: a sale must be quoted in one
message and confirmed by the **sender's own words** ("ho / yes / हो") in the next; the fresh plan must match the
quote (date, pickup, seats, total) or it refuses; agents see only their own bookings. `ApEdit` follows the same
pattern: every change is a preview first, applied only after "ho", with an audit row.

---

## 5. The local AI

**Client.** `ApAi::chat()` speaks Ollama's native `/api/chat` (tools, `format` JSON schema, images, `think:false`,
`keep_alive`) and falls back to OpenAI-compatible `/v1/chat/completions` for llama.cpp / vLLM / LM Studio. Base URL
and model are settings; the default is `http://127.0.0.1:11434` and *auto* (the best installed model).

**One at a time.** On CPU, two parallel requests are each slower than one after the other. All model work goes
through `ap_jobs` and a single worker (`flock`), so a burst of messages is a short queue, not a stalled VPS.

**Prompt size.** CPU prompt processing (~50–150 tokens/s) dominates latency, so the WhatsApp prompt is compact
(~700 tokens), its fixed part comes first (Ollama reuses the cached prefix), and only the tools that role may use
are sent.

**Model choice (Oct 2026, Ollama library):**

| VPS RAM | Recommended | Why |
|---|---|---|
| 4–6 GB | `qwen3.5:2b` | fits beside MySQL + PHP; short answers, slower Nepali |
| 8 GB (e.g. KVM 2) | `qwen3.5:4b` | best quality that fits; tools + vision in one model |
| 16 GB (KVM 4) | `qwen3.5:9b` | clearly better Nepali / Hindi and tool use; ~2× slower than 4b |
| 32 GB+ (KVM 8) | `qwen3.5:9b` + `gemma4:e4b` (documents) | second model reads ID photos while the first answers WhatsApp |

Qwen3.5 and Gemma 4 both carry *vision* and *tools* in Ollama's library; Qwen is the stronger multilingual family.
Neither has a published Nepali benchmark — hence the built-in benchmark, which the owner runs on their own VPS before
switching. (No model was run in this repository's sandbox: model downloads are blocked here. The tests use a mock
Ollama server that speaks the same API.)

---

## 6. Security and privacy

| Risk | Control |
|---|---|
| Someone links a WhatsApp number to another agent's code | OTP goes to the mobile **registered** for that code, never to the sender; 5 tries then 15 min lock; the agent is told on WhatsApp that a new number was linked. |
| A forged / old ID card | QR → `/id.php` shows live status; re-issue rotates the token. The page shows only name, photo, role, ID number, validity. |
| ID documents leaking | Stored under `uploads/agents-kyc/` (never linked as /uploads/…), streamed by `admin/team-doc.php` to `staff.manage` or the member, every view audited; file names carry 64 random bits. Recommended on nginx: `location ^~ /uploads/agents-kyc/ { deny all; }`. Aadhaar masked unless `ap_aadhaar_store_full = 1`. |
| Bank / ID numbers in a DB dump | `sodium_crypto_secretbox`, key derived from `APP_KEY`. |
| Join form spam | 3 applications / IP / hour, 1 / phone / day, CSRF, phone must be verified before submit, upload limits (type sniffed, ≤ 8 MB, ≤ 12 files). |
| Model says something wrong | Facts come only from tools; replies about money / tickets are templates filled from tool results; sales and edits need "ho". |
| Passkey replay / phishing | Challenge single-use (5 min), origin + RP ID checked, signature verified with OpenSSL, sign counter checked. |
| Card images used to phish | Card PNG URLs are HMAC-signed with expiry (24 h) for WhatsApp; the visiting card is public by design (it is meant to be shared) and shows only what the member chose to print. |

---

## 7. Trade-offs (decisions made)

| Decision | Alternative | Why this one |
|---|---|---|
| Render cards live (SVG + GD PNG) from one layout | Store a PNG per member | "Update together" is automatic; no stale card can exist. GD can't shape complex Devanagari, so Nepali on the PNG is limited to short labels through the site's `dev_shape()`; long Nepali text lives on the SVG/web version. |
| Reuse `AiTools` as the model's tools | New tools for the local model | One set of rules for selling and changing tickets, whichever brain chose the button. |
| Queue + single worker | Answer inside the webhook | CPU model is slow and single-file; webhooks (Twilio: 15 s) must return quickly; FPM workers stay free for the website. |
| Passkey for "fingerprint" | Capture fingerprints | Browsers do not expose fingerprint data (by design). A passkey uses the phone's own fingerprint sensor and is phishing-proof. |
| Reverse WhatsApp verification next to OTP | OTP only | Free (user-initiated), needs no approved template, works while the WABA template is broken (see the site's own Sept notes). |
| Create the inactive `admins` row at submit | Create it at approval | Matches the existing `agent-signup.php`, so the existing Staff & Approvals page sees the applicant too. |
| Aadhaar stored masked | Full number | UIDAI rules: a full Aadhaar number belongs only in an Aadhaar Data Vault. The office still sees the full photo of the card. |

---

## 8. Failure handling

- Model server down → instant commands still work; AI-needing messages get "office will reply" in the sender's
  language and an alert on Admin → AI (VPS); the job retries twice.
- Document reading finds nothing → the form simply stays empty for typing; nothing blocks submission.
- WhatsApp send fails → `Notify::whatsapp` already logs and the existing retry cron retries.
- Installer cannot find an anchor in `admin/_guard.php` / `includes/wabot.php` → it changes nothing in that file and
  shows the exact lines to paste (same pattern as Live Fleet).

---

## 9. What to revisit as it grows

1. **GPU or a second VPS for the model** once WhatsApp traffic regularly queues for more than a minute — `ap_ai_base`
   just points elsewhere.
2. **Face match** (selfie vs ID photo) with a small local embedding model, as a hint on the approval card.
3. **DigiLocker / Aadhaar offline e-KYC XML** when the company registers as a KUA/AUA-adjacent partner — replaces OCR
   with signed data.
4. **Nepal NID QR** once its format is public.
5. **Move ID-card printing to PVC printers** (Evolis / Zebra take the same PNG at 300 dpi).
6. **Per-agent WhatsApp numbers** (Meta multi-number) if agents want the bot under their own name.

---

## 10. Deploying

The sandbox that built this has no route to the VPS (the Hostinger connector and SSH are not reachable from it), so
"live" is the owner's two steps, the same as Live Fleet: upload the package's `1-upload-to-public_html/`, open
`/admin/agentpro-install.php` → **⚡ Install all**, add the one cron line it shows. Then Admin → AI (VPS) → pick /
pull a model → turn the WhatsApp brain on. See `docs/AGENT-PRO-NEPALI.md`.
