<?php
/**
 * tools/agentpro-browser-helper.php — what the browser test needs from the
 * server side of a TEST copy of the website (never the live site):
 *
 *   php tools/agentpro-browser-helper.php <site> mint <username>      → "<cookie> <csrf>" for that staff member
 *   php tools/agentpro-browser-helper.php <site> wa <from> <text…>     → what the WhatsApp desk answers (JSON)
 *   php tools/agentpro-browser-helper.php <site> idphoto <out.png>     → a SPECIMEN "ID photo" with an Aadhaar-style QR
 *   php tools/agentpro-browser-helper.php <site> super                 → makes (or finds) the test super-admin, prints its username
 *   php tools/agentpro-browser-helper.php <site> cleanup               → removes what the browser test created
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$site = $argv[1] ?? '';
$cmd = $argv[2] ?? '';
if (!is_file($site . '/includes/bootstrap.php')) { fwrite(STDERR, "no site at $site\n"); exit(2); }
define('SHG_APP', true);
chdir($site);
require $site . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';
if (!str_contains((string) DB_NAME, 'test')) { fwrite(STDERR, "refusing: not a test database\n"); exit(1); }

switch ($cmd) {
    case 'mint':
        $a = Database::fetch('SELECT * FROM admins WHERE username = :u', ['u' => (string) ($argv[3] ?? '')]);
        if ($a === null) { fwrite(STDERR, "no such user\n"); exit(1); }
        $sid = bin2hex(random_bytes(16));
        $csrf = bin2hex(random_bytes(32));
        $data = ['_created' => time(), 'lock_token' => bin2hex(random_bytes(12)), CSRF_TOKEN_NAME => $csrf,
            ADMIN_SESSION_KEY => ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
                'role' => (string) $a['role'], 'permissions' => [], 'must_change_pw' => false, 'via_otp' => true, 'logged_in_at' => time(), 'last_seen' => time()]];
        $dir = (string) ini_get('session.save_path');
        $dir = $dir !== '' ? (string) preg_replace('/^.*;/', '', $dir) : sys_get_temp_dir();
        $enc = '';
        foreach ($data as $k => $v) { $enc .= $k . '|' . serialize($v); }
        file_put_contents($dir . '/sess_' . $sid, ini_get('session.serialize_handler') === 'php_serialize' ? serialize($data) : $enc);
        echo SESSION_NAME . '=' . $sid . ' ' . $csrf . "\n";
        break;

    case 'wa':
        require_once INCLUDE_PATH . '/agentpro-wa.php';
        Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'whatsapp', false);
        echo json_encode(ApWa::reply('+' . preg_replace('/\D/', '', (string) $argv[3]), implode(' ', array_slice($argv, 4))), JSON_UNESCAPED_UNICODE) . "\n";
        break;

    case 'idphoto':
        require_once INCLUDE_PATH . '/qr.php';
        // The older Aadhaar QR is this XML element (kept short: the site's encoder stops at version 10).
        $xml = '<PrintLetterBarcodeData uid="234567890124" name="Bikash Rana Magar" gender="M" dob="12/03/1994" vtc="Vadodara" state="Gujarat" pc="390001"/>';
        $W = 1600; $H = 1000;
        $im = imagecreatetruecolor($W, $H);
        imagefill($im, 0, 0, imagecolorallocate($im, 244, 241, 232));
        $ink = imagecolorallocate($im, 40, 40, 50);
        $font = ASSET_PATH . '/agentpro/fonts/Outfit-Bold.ttf';
        imagettftext($im, 34, 0, 60, 90, imagecolorallocate($im, 180, 30, 40), $font, 'SPECIMEN · TEST ONLY · NOT AN IDENTITY DOCUMENT');
        imagettftext($im, 30, 0, 60, 220, $ink, $font, 'Bikash Rana Magar');
        imagettftext($im, 26, 0, 60, 290, $ink, $font, 'DOB: 12/03/1994   MALE');
        $m = QrCode::matrix($xml, QrCode::ECC_L);
        $n = count($m);
        $cell = (int) floor(760 / ($n + 8));
        $x0 = $W - 60 - $cell * ($n + 8);
        $y0 = 140;
        imagefilledrectangle($im, $x0, $y0, $x0 + $cell * ($n + 8), $y0 + $cell * ($n + 8), imagecolorallocate($im, 255, 255, 255));
        $blk = imagecolorallocate($im, 0, 0, 0);
        foreach ($m as $r => $row) {
            foreach ($row as $c => $on) {
                if ($on) { imagefilledrectangle($im, $x0 + ($c + 4) * $cell, $y0 + ($r + 4) * $cell, $x0 + ($c + 5) * $cell - 1, $y0 + ($r + 5) * $cell - 1, $blk); }
            }
        }
        imagepng($im, (string) $argv[3]);
        echo "ok\n";
        break;

    case 'super':
        $u = 'apbr_super';
        if (!Database::exists('SELECT 1 FROM admins WHERE username = :u', ['u' => $u])) {
            Database::insert('admins', ['username' => $u, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                'full_name' => 'Sher Bahadur (test)', 'phone' => '7000000590', 'role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0]);
        }
        echo $u . "\n";
        break;

    case 'cleanup':
        foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'apbr_%' OR phone LIKE '70000005%'") as $r) {
            $id = (int) $r['id'];
            try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
            foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                      'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ap_passkeys' => 'admin_id'] as $t => $c) {
                try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
            }
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
        Database::delete('ap_applications', "phone LIKE '70000005%' OR full_name LIKE 'APBR %'");
        Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'ap%' OR bucket LIKE 'otp_%'");
        echo "ok\n";
        break;

    default:
        fwrite(STDERR, "usage: see the file header\n");
        exit(2);
}
