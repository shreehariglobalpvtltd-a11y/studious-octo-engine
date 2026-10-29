/* =====================================================================
   tools/agentpro-browser-test.cjs — Agent Pro in a real browser.

     node tools/agentpro-browser-test.cjs <base-url> <site-path> [shots-dir]

   A phone (390 × 844) goes through the whole join form: WhatsApp
   verified by sending "JOIN 123456" (simulated through the desk), an ID
   photo whose QR is decoded IN THE BROWSER and fills the form, a selfie
   from the (fake) camera, extra document, consent, submit. The office
   (desktop) approves it in one tap. The new member opens /me/: ID card,
   visiting card, wallet sheets, and turns on fingerprint sign-in with a
   virtual authenticator — then signs out and back in with it.
   Any console error or failed check fails the run. Screenshots go to
   shots-dir (docs/screenshots by default).

   Needs Playwright with Chromium (preinstalled in Claude Code cloud
   sessions; elsewhere: npm i playwright && npx playwright install chromium).
   ===================================================================== */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = (process.argv[2] || 'http://127.0.0.1:8899').replace(/\/$/, '');
const SITE = process.argv[3];
const SHOTS = process.argv[4] || path.join(__dirname, '..', 'docs', 'screenshots');
const HELPER = path.join(__dirname, 'agentpro-browser-helper.php');
if (!SITE) { console.error('usage: node tools/agentpro-browser-test.cjs <base-url> <site-path> [shots-dir]'); process.exit(2); }
fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (label, ok, extra) => { if (ok) { pass++; console.log('  \x1b[32mPASS\x1b[0m  ' + label); } else { fail++; console.log('  \x1b[31mFAIL\x1b[0m  ' + label + (extra ? ' — ' + extra : '')); } };
const php = (...args) => execFileSync('php', [HELPER, SITE, ...args], { encoding: 'utf8' }).trim();
const shot = async (page, name) => page.screenshot({ path: path.join(SHOTS, name), fullPage: false });
const errorsOf = (page) => { const e = []; page.on('console', m => { if (m.type() === 'error' && !/favicon|Failed to load resource: the server responded with a status of 4\d\d/.test(m.text())) e.push(m.text()); }); page.on('pageerror', x => e.push(String(x))); return e; };

/** Every visiting card image on the page shows at the card's own aspect (889 × 508 → 1.75). */
async function cardShapeOk(page) {
  const r = await page.$$eval('.ap-vc img', imgs => imgs.map(i => i.getBoundingClientRect()).map(b => b.width / b.height));
  return r.length > 0 && r.every(x => Math.abs(x - 889 / 508) < 0.03);
}

(async () => {
  php('cleanup');
  const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
  const host = new URL(BASE).host;
  const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-IN',
                  permissions: ['camera'], userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36' };

  /* ---------------- 1. the join form on a phone ---------------- */
  console.log('-- join form (phone) --');
  const ctx = await browser.newContext(phone);
  const page = await ctx.newPage();
  const errs = errorsOf(page);
  ctx.on('page', p => p.close().catch(() => {}));           // the wa.me tab the form opens
  await page.goto(BASE + '/join.php', { waitUntil: 'networkidle' });
  await page.click('[data-lang="en"]');
  await shot(page, 'ap-join-1-who.png');
  await page.click('[data-kind="person"]');
  await page.fill('[name="full_name"]', 'APBR Bikash Rana');
  await page.click('#next');
  await page.waitForSelector('#reverse');
  check('step 2: WhatsApp verification', await page.isVisible('#sendOtp'));
  await page.click('#reverse');
  await page.waitForSelector('#revBox a');
  const joinText = (await page.textContent('#revBox a')).trim();
  const code = (joinText.match(/(\d{6})/) || [])[1];
  check('reverse verify shows a JOIN code', !!code, joinText);
  await shot(page, 'ap-join-2-whatsapp.png');
  const waReply = JSON.parse(php('wa', '917000000501', 'JOIN ' + code));
  check('the WhatsApp desk accepts "JOIN ' + code + '"', /✅/.test(waReply.text || ''), waReply.text);
  await page.waitForSelector('.ap-ok', { timeout: 15000 });
  check('the page sees it verified by itself (polling)', /verified/i.test(await page.textContent('.ap-ok')));
  await page.click('#next');
  await page.waitForSelector('[data-idt="aadhaar"]');
  await page.click('[data-idt="aadhaar"]');
  const idPhoto = path.join(os.tmpdir(), 'apbr-id.png');
  php('idphoto', idPhoto);
  await page.setInputFiles('[data-up="front"]', idPhoto);
  await page.waitForFunction(() => document.querySelector('[name="dob"]') && document.querySelector('[name="dob"]').value === '1994-03-12', null, { timeout: 30000 });
  const dob = await page.inputValue('[name="dob"]');
  const idNo = await page.inputValue('[name="id_number"]');
  check('the QR on the ID photo was read in the browser → DOB filled', dob === '1994-03-12', dob);
  check('→ Aadhaar number filled', idNo.replace(/\s/g, '') === '234567890124', idNo);
  check('→ "from QR" badges shown', (await page.locator('.ap-src.qr').count()) >= 3);
  check('the typed name is kept, the ID\'s name offered', (await page.inputValue('[name="full_name"]')) === 'APBR Bikash Rana' && (await page.locator('[data-use="full_name"]').count()) === 1);
  await page.click('[data-use="full_name"]');
  check('one tap takes the name from the ID', (await page.inputValue('[name="full_name"]')) === 'Bikash Rana Magar');
  await page.fill('[name="address"]', '12 Main Road');
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, 'ap-join-3-id-autofill.png');
  await page.click('#next');
  await page.waitForSelector('#openCam');
  await page.click('#openCam');
  await page.waitForSelector('#shutter');
  await page.waitForTimeout(1200);
  await shot(page, 'ap-join-4-camera.png');
  await page.click('#shutter');
  await page.waitForFunction(() => document.querySelector('.ap-mini .p img'), null, { timeout: 15000 });
  check('selfie taken from the camera, previewed on a mini ID card', await page.isVisible('.ap-mini .p img'));
  await shot(page, 'ap-join-4-photo.png');
  await page.click('#next');
  await page.waitForSelector('[name="upi_id"]');
  await page.fill('[name="upi_id"]', 'bikash@ybl');
  await shot(page, 'ap-join-5-bank.png');
  await page.click('#next');
  await page.waitForSelector('#consent');
  await page.fill('#docLabel', 'PAN card');
  await page.setInputFiles('[data-up="extra"]', idPhoto);
  await page.waitForSelector('[data-drop]', { timeout: 15000 });
  check('an extra document attached', (await page.textContent('#app')).includes('PAN card'));
  await page.check('#consent');
  await shot(page, 'ap-join-6-review.png');
  await page.click('#next');
  await page.waitForSelector('.ap-done', { timeout: 20000 }).catch(async (e) => {
    throw new Error('submit did not finish — the form said: ' + (await page.textContent('#toast')) + ' | ' + e.message.split('\n')[0]);
  });
  const ref = (await page.textContent('.ap-ref')).trim();
  check('submitted → reference shown', /^JN-[A-Z0-9]{5}$/.test(ref), ref);
  await shot(page, 'ap-join-7-done.png');
  check('no console errors on the join form', errs.length === 0, errs.join(' | '));

  /* ---------------- 2. the office approves in one tap ---------------- */
  console.log('-- office: Control Center + one-tap approve (desktop) --');
  const sup = php('super');
  const [cookieS] = php('mint', sup).split(' ');
  const office = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: 1 });
  await office.addCookies([{ name: cookieS.split('=')[0], value: cookieS.split('=')[1], domain: host.split(':')[0], path: '/' }]);
  const op = await office.newPage();
  const oerrs = errorsOf(op);
  await op.goto(BASE + '/admin/hub.php', { waitUntil: 'networkidle' });
  check('Control Center opens with category cards', (await op.locator('.cc-cat').count()) >= 6);
  await shot(op, 'ap-admin-hub.png');
  await op.goto(BASE + '/admin/team.php?tab=approvals', { waitUntil: 'networkidle' });
  const card = op.locator('.tp-app', { hasText: ref });
  check('the application is waiting as one card', (await card.count()) === 1);
  await card.screenshot({ path: path.join(SHOTS, 'ap-admin-approve.png') });
  await card.locator('.tp-approve').click();
  await op.waitForSelector('.flash.ok');
  const flash = await op.textContent('.flash.ok');
  check('✓ Approve → code issued, WhatsApp welcome', /Approved/.test(flash) && /SHG-\d{4}/.test(flash), flash);
  const newCode = (flash.match(/SHG-\d{4}/) || [''])[0];
  await op.goto(BASE + '/admin/team.php?tab=agents', { waitUntil: 'networkidle' });
  await shot(op, 'ap-admin-team.png');
  await op.locator('.tp-person', { hasText: newCode }).click();
  await op.waitForSelector('.mp-cards img');
  await op.waitForTimeout(800);
  await shot(op, 'ap-admin-member.png');
  check('no console errors in the admin screens', oerrs.length === 0, oerrs.join(' | '));

  // Control Center on a phone too.
  const officePhone = await browser.newContext({ ...phone, permissions: [] });
  await officePhone.addCookies([{ name: cookieS.split('=')[0], value: cookieS.split('=')[1], domain: host.split(':')[0], path: '/' }]);
  const opp = await officePhone.newPage();
  await opp.goto(BASE + '/admin/hub.php', { waitUntil: 'networkidle' });
  await shot(opp, 'ap-admin-hub-phone.png');
  await opp.goto(BASE + '/admin/team.php?tab=money', { waitUntil: 'networkidle' });
  await shot(opp, 'ap-admin-money-phone.png');
  await officePhone.close();

  /* ---------------- 3. the new member on /me/ ---------------- */
  console.log('-- member portal (phone) --');
  const user = php('wa', '917000000501', 'me');           // proves the desk knows them too
  check('the new agent is recognised on WhatsApp', JSON.parse(user).text.includes(newCode), JSON.parse(user).text);
  const uname = execFileSync('php', ['-r', `define('SHG_APP',true);chdir('${SITE}');require '${SITE}/includes/bootstrap.php';echo Database::scalar("SELECT username FROM admins WHERE phone='7000000501'");`], { encoding: 'utf8' }).trim();
  const [cookieM] = php('mint', uname).split(' ');
  // WebAuthn will not accept an IP address as the site's identity: the portal runs on "localhost".
  const LBASE = BASE.replace('127.0.0.1', 'localhost');
  const mctx = await browser.newContext(phone);
  await mctx.addCookies([{ name: cookieM.split('=')[0], value: cookieM.split('=')[1], domain: 'localhost', path: '/' }]);
  const mp = await mctx.newPage();
  const merrs = errorsOf(mp);
  await mp.goto(LBASE + '/me/', { waitUntil: 'networkidle' });
  await mp.click('[data-lang="en"]');
  await mp.waitForSelector('#flip img');
  await mp.waitForTimeout(1500);
  check('ID tab: the ID card with the selfie and the new code', (await mp.textContent('#who')).includes(newCode));
  await shot(mp, 'ap-me-id.png');
  await mp.click('#flip');
  await mp.waitForTimeout(900);
  await shot(mp, 'ap-me-id-back.png');
  await mp.click('[data-tab="card"]');
  await mp.waitForSelector('.ap-vc img');
  await mp.waitForTimeout(1200);
  await shot(mp, 'ap-me-card.png');
  check('the visiting card keeps its 3.5 × 2 in shape on a phone', await cardShapeOk(mp));
  await mp.fill('[name="vc_whatsapp"]', '+91 98250 11223');
  await mp.click('#vcForm button[type=submit]');
  await mp.waitForSelector('.ap-toast.on');
  check('changing the WhatsApp number re-prints the card', (await mp.textContent('.ap-toast')).includes('Saved'));
  await mp.waitForSelector('.ap-toast:not(.on)', { state: 'attached' });
  await mp.click('[data-tab="wallet"]');
  await mp.waitForSelector('.ap-money');
  await shot(mp, 'ap-me-wallet.png');
  await mp.click('[data-sheet="pay"]');
  await mp.waitForSelector('#sheet.on');
  await mp.fill('#pa', '1500');
  await mp.dispatchEvent('#pa', 'change');
  await mp.waitForTimeout(800);
  await shot(mp, 'ap-me-pay.png');
  await mp.click('#sheet .scrim', { position: { x: 20, y: 20 } });
  // Fingerprint: a virtual authenticator with user verification (like a phone's sensor).
  const cdp = await mctx.newCDPSession(mp);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
  await mp.click('[data-tab="me"]');
  await mp.waitForSelector('#fpAdd');
  await mp.click('#fpAdd');
  await mp.waitForSelector('.ap-toast.on');
  await mp.waitForTimeout(600);
  check('fingerprint / face sign-in turned on', (await mp.locator('[data-pk]').count()) === 1, await mp.textContent('.ap-toast'));
  await mp.waitForSelector('.ap-toast:not(.on)', { state: 'attached' });
  await shot(mp, 'ap-me-profile.png');
  await mp.click('#out');
  await mp.waitForSelector('#fpLogin');
  await shot(mp, 'ap-me-signin.png');
  await mp.click('#fpLogin');
  const back = await Promise.race([
    mp.waitForSelector('#flip img', { timeout: 15000 }).then(() => ''),
    mp.waitForSelector('.ap-toast.on.bad', { timeout: 15000 }).then(async () => await mp.textContent('.ap-toast')),
  ]).catch(e => String(e.message || e));
  check('…and signs back in with it, no OTP', back === '' && (await mp.textContent('#who')).includes(newCode), back);
  check('no console errors in /me/', merrs.length === 0, merrs.join(' | '));

  /* ---------------- 4. the public pages behind the QR codes ---------------- */
  console.log('-- id.php · c.php (phone) --');
  const tok = execFileSync('php', ['-r', `define('SHG_APP',true);chdir('${SITE}');require '${SITE}/includes/bootstrap.php';echo Database::scalar("SELECT m.card_token FROM ap_members m JOIN admins a ON a.id=m.admin_id WHERE a.phone='7000000501'");`], { encoding: 'utf8' }).trim();
  const pub = await browser.newContext(phone);
  const pp = await pub.newPage();
  await pp.goto(BASE + '/id.php?c=' + tok, { waitUntil: 'networkidle' });
  check('id.php: ✓ VALID', (await pp.textContent('.ap-stamp')).includes('VALID'));
  await shot(pp, 'ap-verify.png');
  await pp.goto(BASE + '/c.php?u=' + tok, { waitUntil: 'networkidle' });
  check('c.php: WhatsApp button to the number on the card', (await pp.getAttribute('a.ap-btn.wa', 'href')).includes('wa.me/919825011223'));
  check('c.php: the card is not squeezed', await cardShapeOk(pp));
  await shot(pp, 'ap-card-page.png');

  await browser.close();
  php('cleanup');
  console.log('\n----------------------------------------\nPASSED: ' + pass + '   FAILED: ' + fail + '\n----------------------------------------');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); try { php('cleanup'); } catch (x) {} process.exit(1); });
