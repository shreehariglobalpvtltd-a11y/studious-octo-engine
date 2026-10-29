<?php
/**
 * tools/agentpro-demo-seed.php — demo members for a TEST copy of the
 * website (screenshots, the browser test, trying the screens out).
 *
 *     php tools/agentpro-demo-seed.php /path/to/test-site            add
 *     php tools/agentpro-demo-seed.php /path/to/test-site --remove   take them away again
 *
 * Adds: two agents (a person and an agency) with agent codes, a counter
 * staff member, a little wallet history, one pending application, a
 * withdrawal request and a "paid the company" request. The portraits are
 * drawn here as flat illustrations — never photos of real people.
 *
 * Never run this on the live site.
 */
declare(strict_types=1);

$site = $argv[1] ?? '';
if ($site === '' || !is_file($site . '/includes/bootstrap.php')) {
    fwrite(STDERR, "usage: php tools/agentpro-demo-seed.php /path/to/test-site [--remove]\n");
    exit(2);
}
define('SHG_APP', true);
chdir($site);
require $site . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

if (str_contains((string) DB_NAME, 'shari') && !str_contains((string) DB_NAME, 'test')) {
    fwrite(STDERR, "Refusing: " . DB_NAME . " does not look like a test database.\n");
    exit(1);
}

$people = [
    ['username' => 'demo_ap_ramesh', 'name' => 'Ramesh Kumar Thapa', 'phone' => '9876500011', 'role' => 'agent', 'kind' => 'person',
     'city' => 'Mehsana', 'skin' => [196, 140, 104], 'hair' => [32, 26, 24], 'suit' => [28, 47, 107], 'blood' => 'O+'],
    ['username' => 'demo_ap_himalaya', 'name' => 'Himalaya Tours & Travels', 'contact' => 'Sita Gurung', 'phone' => '9876500022',
     'role' => 'agent', 'kind' => 'org', 'city' => 'Surat', 'skin' => [214, 165, 128], 'hair' => [52, 34, 28], 'suit' => [120, 32, 48], 'long' => true],
    ['username' => 'demo_ap_anita', 'name' => 'Anita Sharma', 'phone' => '9876500033', 'role' => 'counter', 'kind' => '',
     'city' => 'Ahmedabad', 'skin' => [186, 128, 96], 'hair' => [20, 18, 22], 'suit' => [18, 96, 88], 'long' => true, 'blood' => 'B+'],
];

$remove = in_array('--remove', $argv, true);
foreach ($people as $p) {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $p['username']], 0);
    if ($id > 0) {
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ap_passkeys' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
}
Database::delete('ap_applications', "full_name = 'Bikash Rana Magar' OR ref LIKE 'JN-DEMO%'");
if ($remove) {
    echo "demo members removed\n";
    exit(0);
}

/** A flat illustrated portrait (head, hair, collar) — no real person. */
function demo_avatar(array $p, string $file): void
{
    $S = 1280;
    $im = imagecreatetruecolor($S, $S);
    for ($y = 0; $y < $S; $y++) {
        $t = $y / $S;
        imageline($im, 0, $y, $S, $y, imagecolorallocate($im, (int) (232 - 30 * $t), (int) (236 - 26 * $t), (int) (244 - 16 * $t)));
    }
    [$sr, $sg, $sb] = $p['skin'];
    [$hr, $hg, $hb] = $p['hair'];
    [$cr, $cg, $cb] = $p['suit'];
    $skin = imagecolorallocate($im, $sr, $sg, $sb);
    $shade = imagecolorallocate($im, (int) ($sr * .86), (int) ($sg * .84), (int) ($sb * .82));
    $hair = imagecolorallocate($im, $hr, $hg, $hb);
    $suit = imagecolorallocate($im, $cr, $cg, $cb);
    $white = imagecolorallocate($im, 250, 250, 252);
    if (!empty($p['long'])) {
        imagefilledellipse($im, 640, 640, 620, 820, $hair);
        imagefilledrectangle($im, 330, 640, 950, 1000, $hair);
    }
    imagefilledellipse($im, 640, 1380, 1180, 760, $suit);                    // shoulders
    imagefilledpolygon($im, [520, 1000, 640, 1160, 760, 1000, 760, 1280, 520, 1280], $white);   // collar
    imagefilledrectangle($im, 560, 820, 720, 1060, $shade);                    // neck
    imagefilledellipse($im, 640, 1040, 160, 110, $shade);
    imagefilledellipse($im, 640, 600, 460, 560, $skin);                        // face
    imagefilledellipse($im, 418, 640, 70, 120, $skin);                         // ears
    imagefilledellipse($im, 862, 640, 70, 120, $skin);
    imagefilledarc($im, 640, 520, 500, 420, 180, 360, $hair, IMG_ARC_PIE);     // hair
    imagefilledellipse($im, 640, 420, 480, 220, $hair);
    $eye = imagecolorallocate($im, 40, 30, 30);
    imagefilledellipse($im, 560, 620, 36, 24, $eye);
    imagefilledellipse($im, 720, 620, 36, 24, $eye);
    imagesetthickness($im, 10);
    imagearc($im, 640, 720, 130, 70, 20, 160, imagecolorallocate($im, (int) ($sr * .62), (int) ($sg * .45), (int) ($sb * .45)));
    $out = imagecreatetruecolor(640, 640);
    imagecopyresampled($out, $im, 0, 0, 0, 0, 640, 640, $S, $S);
    imagejpeg($out, $file, 90);
}

$made = [];
foreach ($people as $i => $p) {
    $id = Database::insert('admins', [
        'username' => $p['username'], 'password_hash' => password_hash('demo-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT),
        'full_name' => $p['name'], 'phone' => $p['phone'], 'role' => $p['role'], 'is_active' => 1, 'must_change_pw' => 0,
        'email' => strtolower(str_replace('demo_ap_', '', $p['username'])) . '@example.com',
    ]);
    AgentWallet::saveProfile($id, array_filter([
        'display_phone' => $p['phone'], 'whatsapp' => '91' . $p['phone'], 'counter_name' => $p['city'],
        'agent_kind' => $p['kind'] ?: null, 'contact_person' => $p['contact'] ?? null, 'country_code' => '91',
        'address' => $p['city'] . ', Gujarat', 'id_type' => 'Aadhaar', 'id_number' => 'XXXX XXXX 40' . $i . '1',
        'kyc_status' => 'verified',
    ], static fn($v): bool => $v !== null));
    if ($p['role'] === 'agent') {
        AgentWallet::setAgentCode($id, AgentWallet::nextFreeAgentCode($p['kind']));
    }
    $tmp = sys_get_temp_dir() . '/apdemo-' . $i . '.jpg';
    demo_avatar($p, $tmp);
    AgentPro::setPhotoFromFile($id, $tmp);
    @unlink($tmp);
    AgentPro::saveCard($id, ['blood_group' => $p['blood'] ?? '', 'emergency_name' => 'Office', 'emergency_phone' => '9104801507',
        'vc_theme' => ['royal', 'gold', 'night'][$i % 3]]);
    $made[] = $id;
}

// A little money history for the first agent.
$a = $made[0];
foreach ([[ 'commission', 640.0, 'Commission SHG-DEMO-1'], ['commission', 420.0, 'Commission SHG-DEMO-2'],
          ['cash_due', 4000.0, 'Cash collected at the counter'], ['commission', 860.0, 'Commission SHG-DEMO-3']] as [$type, $amt, $note]) {
    AgentWallet::record($a, $type === 'commission' ? 'commission' : 'cash_due', $amt, ['note' => $note]);
}
AgentWallet::record($a, 'cash_handover', 2500, ['note' => 'Handed over at Mehsana office', 'ref' => 'UTR4471200345']);
AgentWallet::requestPayout($a, 900, 'Please send to my UPI');
AgentPro::saveBank($a, ['upi_id' => 'ramesh.thapa@okaxis', 'bank_holder' => 'Ramesh Kumar Thapa', 'bank_account' => '50100234567890', 'bank_ifsc' => 'HDFC0001234']);
Database::insert('ap_deposit_requests', ['admin_id' => $a, 'amount' => 1500, 'purpose' => 'handover', 'method' => 'upi', 'utr' => '430987651234', 'note' => 'Today cash']);

// One application waiting for the office.
Database::insert('ap_applications', [
    'ref' => 'JN-DEMO1', 'draft_key' => bin2hex(random_bytes(16)), 'status' => 'submitted', 'kind' => 'agent', 'agent_kind' => 'person',
    'full_name' => 'Bikash Rana Magar', 'phone' => '9876500044', 'wa_verified_at' => date('Y-m-d H:i:s'), 'wa_verified_via' => 'reverse',
    'dob' => '1994-03-12', 'gender' => 'Male', 'city' => 'Vadodara', 'state' => 'Gujarat', 'country' => 'IN', 'id_type' => 'aadhaar',
    'id_number_masked' => 'XXXX XXXX 7731', 'submitted_at' => date('Y-m-d H:i:s'),
    'readout' => json_encode(['front' => ['fields' => ['full_name' => 'Bikash Rana Magar', 'dob' => '1994-03-12', 'gender' => 'Male'], 'sources' => ['aadhaar_qr']]]),
]);

echo 'demo members: ' . implode(', ', $made) . "\n";
