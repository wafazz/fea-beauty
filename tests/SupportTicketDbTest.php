<?php
// Support tickets (phases 1 to 4): ownership, guest links, order and subscription links, attachments checks, internal
// notes, reopening, the staff inbox, emails (captured, not sent) and unread badges. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Modules;
use App\Services\SupportAttachmentService as A;
use App\Services\SupportInboxService as I;
use App\Services\SupportNotifier as N;
use App\Services\SupportTaxonomy as X;
use App\Services\SupportTicketService as T;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$pdo = DB::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['support_attachments', 'support_ticket_labels', 'support_messages', 'support_tickets', 'support_labels'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
DB::query("DELETE FROM support_categories WHERE slug LIKE 'test-%'");

// Users and orders
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', 'Anna', 'anna@support.test', 'x', 'active')");
$anna = (int) $pdo->lastInsertId();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', 'Ben', 'ben@support.test', 'x', 'active')");
$ben = (int) $pdo->lastInsertId();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('staff', 'Siti', 'siti@support.test', 'x', 'active')");
$staff = (int) $pdo->lastInsertId();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Ali', 'ali@support.test', 'x', 'active')");
$admin = (int) $pdo->lastInsertId();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('staff', 'Old', 'old@support.test', 'x', 'disabled')");
$disabled = (int) $pdo->lastInsertId();
$product = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Soap', 'slug' => 'soap-support', 'sku' => 'SOAP-S', 'category_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'stock' => 50, 'status' => 'active']);
$order = function (?int $userId, string $no, string $email, string $token): int {
    DB::query(
        "INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status, track_hash, created_at, updated_at)
         VALUES (?, ?, 'Customer', ?, '0123', 'a', 'b', 'Selangor', '40000', 'MY', 20, 0, 20, 'cod', 'paid', 'shipped', ?, NOW(), NOW())",
        [$no, $userId, $email, hash('sha256', $token)]
    );
    return (int) DB::connection()->lastInsertId();
};
$annaOrder = $order($anna, 'SUP-A-1', 'anna@support.test', 'tok-a');
$benOrder = $order($ben, 'SUP-B-1', 'ben@support.test', 'tok-b');
$guestOrder = $order(null, 'SUP-G-1', 'guest@support.test', 'tok-g');
$subActive = (int) (DB::query("INSERT INTO subscriptions (user_id, product_id, address_id, qty, cadence, payment_method, next_due, status) VALUES (?, ?, NULL, 1, 'monthly', 'cod', CURDATE(), 'active')", [$anna, $product]) ? $pdo->lastInsertId() : 0);
$subPaused = (int) (DB::query("INSERT INTO subscriptions (user_id, product_id, address_id, qty, cadence, payment_method, next_due, status) VALUES (?, ?, NULL, 1, 'monthly', 'cod', CURDATE(), 'paused')", [$anna, $product]) ? $pdo->lastInsertId() : 0);
$subBen = (int) (DB::query("INSERT INTO subscriptions (user_id, product_id, address_id, qty, cadence, payment_method, next_due, status) VALUES (?, ?, NULL, 1, 'monthly', 'cod', CURDATE(), 'active')", [$ben, $product]) ? $pdo->lastInsertId() : 0);

Setting::set('module.support', '1');
Setting::set('module.subscriptions', '1');
$cat = (int) DB::query("SELECT id FROM support_categories WHERE slug = 'order'")->fetchColumn();
$base = ['category_id' => $cat, 'urgency' => 'normal', 'subject' => 'Parcel late', 'body' => 'Still not here'];
$annaRow = ['id' => $anna, 'email' => 'anna@support.test'];
$benRow = ['id' => $ben, 'email' => 'ben@support.test'];

echo "  -- ownership and links\n";
$r = T::create($base, [], $annaRow, null);
check('member opens a ticket', $r['ok'] === true && str_starts_with((string) $r['ticket_no'], 'TK-'));
$tk = (string) $r['ticket_no'];
$t = T::forMember($tk, $anna);
check('new ticket waits for staff', $t !== null && $t['status'] === 'waiting_staff');
check('member cannot read another member ticket', T::forMember($tk, $ben) === null);
check('unknown ticket number is not found', T::forMember('TK-999999', $anna) === null);
check('bad urgency refused', T::create(['urgency' => 'extreme'] + $base, [], $annaRow, null)['ok'] === false);
check('unknown category refused', T::create(['category_id' => 999999] + $base, [], $annaRow, null)['ok'] === false);
check('empty subject refused', T::create(['subject' => ' '] + $base, [], $annaRow, null)['ok'] === false);
check('subject over 160 refused', T::create(['subject' => str_repeat('a', 161)] + $base, [], $annaRow, null)['ok'] === false);
check('body over 2000 refused', T::create(['body' => str_repeat('a', 2001)] + $base, [], $annaRow, null)['ok'] === false);
check('member links own order', T::create(['order_id' => $annaOrder] + $base, [], $annaRow, null)['ok'] === true);
check('member cannot link another member order', T::create(['order_id' => $benOrder] + $base, [], $annaRow, null)['ok'] === false);
check('member links own active subscription', T::create(['subscription_id' => $subActive] + $base, [], $annaRow, null)['ok'] === true);
check('paused subscription refused', T::create(['subscription_id' => $subPaused] + $base, [], $annaRow, null)['ok'] === false);
check('another member subscription refused', T::create(['subscription_id' => $subBen] + $base, [], $annaRow, null)['ok'] === false);
Setting::set('module.subscriptions', '0');
check('subscription refused while the module is off', T::create(['subscription_id' => $subActive] + $base, [], $annaRow, null)['ok'] === false);
Setting::set('module.subscriptions', '1');

echo "  -- guests\n";
check('guest needs a valid email', T::create($base, [], null, 'not-an-email')['ok'] === false);
$g = T::create($base, [], null, 'Guest@Support.test');
check('guest opens a ticket and gets a 32-character token', $g['ok'] === true && strlen((string) $g['token']) === 32);
$gt = (string) $g['ticket_no'];
check('guest with the right token can read', T::forGuest($gt, (string) $g['token']) !== null);
check('guest with a wrong token is refused', T::forGuest($gt, 'nope') === null);
check('guest with an empty token is refused', T::forGuest($gt, '') === null);
check('guest token cannot open a member ticket', T::forGuest($tk, (string) $g['token']) === null);
$stored = (string) DB::query('SELECT access_hash FROM support_tickets WHERE ticket_no = ?', [$gt])->fetchColumn();
check('token is stored only as a hash', $stored === hash('sha256', (string) $g['token']));
check('guest may link an order only with the matching email', T::create(['order_id' => $guestOrder] + $base, [], null, 'other@support.test')['ok'] === false);
check('guest links the order whose email matches', T::create(['order_id' => $guestOrder] + $base, [], null, 'guest@support.test')['ok'] === true);

echo "  -- messages and notes\n";
$tid = (int) T::forMember($tk, $anna)['id'];
DB::query("INSERT INTO support_messages (ticket_id, sender, staff_id, visibility, body) VALUES (?, 'staff', ?, 'internal', 'secret note')", [$tid, $staff]);
DB::query("INSERT INTO support_messages (ticket_id, sender, staff_id, visibility, body) VALUES (?, 'staff', ?, 'public', 'We are checking')", [$tid, $staff]);
$customerView = array_column(T::messagesAfter($tid), 'body');
check('customer view has the public reply', in_array('We are checking', $customerView, true));
check('customer view has no internal note', !in_array('secret note', $customerView, true));
check('customer view has no staff names', !array_key_exists('staff_name', T::messagesAfter($tid)[0]));
$all = T::messagesAfter($tid, 0, true);
check('staff view has the note and the staff name', in_array('secret note', array_column($all, 'body'), true) && in_array('Siti', array_column($all, 'staff_name'), true));

echo "  -- customer replies, reopen and close\n";
check('customer can reply', T::reply(T::forMember($tk, $anna), 'Any update?', []) === null);
check('reply moves the ticket to waiting for staff', T::forMember($tk, $anna)['status'] === 'waiting_staff');
check('empty reply refused', T::reply(T::forMember($tk, $anna), '   ', []) !== null);
T::resolve(T::forMember($tk, $anna));
$res = T::forMember($tk, $anna);
check('resolve sets resolved and the time', $res['status'] === 'resolved' && $res['resolved_at'] !== null);
check('resolved ticket can be replied to within 7 days', T::canReply($res));
check('reply after resolve reopens it', T::reply($res, 'Reopening', []) === null && T::forMember($tk, $anna)['status'] === 'waiting_staff');
DB::query("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() - INTERVAL 8 DAY WHERE id = ?", [$tid]);
check('resolved over 7 days cannot be replied to', !T::canReply(T::forMember($tk, $anna)));
DB::query("UPDATE support_tickets SET status = 'closed' WHERE id = ?", [$tid]);
check('closed ticket cannot be replied to', !T::canReply(T::forMember($tk, $anna)));
DB::query("UPDATE support_tickets SET status = 'waiting_staff', resolved_at = NULL WHERE id = ?", [$tid]);

echo "  -- attachments (checks that do not need a real upload)\n";
$many = ['name' => ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'], 'tmp_name' => ['', '', '', ''], 'size' => [1, 1, 1, 1], 'error' => [0, 0, 0, 0]];
try { A::collect($many); check('more than 3 files refused', false); } catch (\InvalidArgumentException $e) { check('more than 3 files refused', str_contains($e->getMessage(), '3')); }
check('empty file slots are skipped', A::collect(['name' => [''], 'tmp_name' => [''], 'size' => [0], 'error' => [4]]) === []);
try { A::check(['name' => 'big.pdf', 'tmp_name' => '/nonexistent', 'size' => 6 * 1024 * 1024, 'error' => 0]); check('over 5 MB refused', false); } catch (\InvalidArgumentException $e) { check('over 5 MB refused', str_contains($e->getMessage(), '5 MB')); }
try { A::check(['name' => 'x.exe', 'tmp_name' => '/nonexistent', 'size' => 10, 'error' => 0]); check('file that was not uploaded is refused', false); } catch (\InvalidArgumentException $e) { check('file that was not uploaded is refused', true); }
check('file names lose folders and control characters', A::safeName("../../etc/a\x01b.pdf") === 'ab.pdf');

echo "  -- staff inbox\n";
$urgent = T::create(['urgency' => 'urgent', 'subject' => 'Urgent parcel'] + $base, [], $annaRow, null)['ticket_no'];
$norm = T::create(['subject' => 'Normal question'] + $base, [], $annaRow, null)['ticket_no'];
$list = I::list(['status' => ''], $staff);
DB::query("UPDATE support_tickets SET status = 'closed' WHERE ticket_no = ?", [$tk]);
check('inbox hides closed tickets by default', !in_array($tk, array_column(I::list([], $staff), 'ticket_no'), true));
check('inbox shows closed tickets with status all', in_array($tk, array_column(I::list(['status' => 'all'], $staff), 'ticket_no'), true));
DB::query("UPDATE support_tickets SET status = 'waiting_staff' WHERE ticket_no = ?", [$tk]);
check('most urgent first', $list[0]['ticket_no'] === $urgent);
check('urgency filter', array_column(I::list(['urgency' => 'urgent'], $staff), 'ticket_no') === [$urgent]);
check('search by ticket number', array_column(I::list(['q' => $norm], $staff), 'ticket_no') === [$norm]);
check('search treats % as text', I::list(['q' => '%'], $staff) === []);
check('search by order number finds the one ticket linked to it', count(I::list(['q' => 'SUP-A-1'], $staff)) === 1);
$uRow = I::detail($urgent);
check('new ticket is unread for staff', $uRow['unread'] === true);
I::markRead((int) $uRow['id']);
check('opening clears the staff unread mark', I::detail($urgent)['unread'] === false);
check('staff reply posts', I::reply(I::detail($urgent), $staff, 'We are checking with the courier', []) === null);
$afterReply = I::detail($urgent);
check('staff reply waits for the customer and assigns the replier', $afterReply['status'] === 'waiting_customer' && (int) $afterReply['assignee_id'] === $staff);
check('internal note saved', I::note($afterReply, $staff, 'Courier says Tuesday') === null);
check('note keeps the status', I::detail($urgent)['status'] === 'waiting_customer');
check('assign to an active staff member', I::assign(I::detail($urgent), $staff, $admin) === null);
check('assign to a disabled staff member refused', I::assign(I::detail($urgent), $disabled, $admin) !== null);
check('assign to a member refused', I::assign(I::detail($urgent), $anna, $admin) !== null);
check('set status resolved records the time', I::setStatus(I::detail($urgent), 'resolved', $admin) === null && I::detail($urgent)['resolved_at'] !== null);
check('unknown status refused', I::setStatus(I::detail($urgent), 'banana', $admin) !== null);
check('set urgency (the controller limits this to admins)', I::setUrgency(I::detail($norm), 'high', $admin) === null && I::detail($norm)['urgency'] === 'high');
DB::query("INSERT INTO support_labels (name, colour, active) VALUES ('VIP', '#112233', 1), ('Old', '#445566', 0)");
$vip = (int) DB::query("SELECT id FROM support_labels WHERE name = 'VIP'")->fetchColumn();
$old = (int) DB::query("SELECT id FROM support_labels WHERE name = 'Old'")->fetchColumn();
I::setLabels(I::detail($urgent), [$vip, $old, 999999], $admin);
check('only active labels are saved', array_column(I::detail($urgent)['labels'], 'name') === ['VIP']);
I::setLabels(I::detail($urgent), [], $admin);
check('labels can be cleared', I::detail($urgent)['labels'] === []);
check('bulk assign updates two tickets', (I::bulk('assign', [(int) I::detail($norm)['id'], (int) I::detail($urgent)['id']], (string) $staff, $admin, true)['count'] ?? 0) === 2);
check('bulk refuses an inactive label', I::bulk('label', [(int) I::detail($norm)['id']], (string) $old, $admin, true)['ok'] === false);
check('bulk refuses more than 100 tickets', I::bulk('status', range(1, 101), 'open', $admin, true)['ok'] === false);
check('bulk refuses an unknown action', I::bulk('delete', [(int) I::detail($norm)['id']], '', $admin, true)['ok'] === false);
check('actions are written to the audit log', (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'support.%'")->fetchColumn() >= 8);
check('audit rows carry no message text', (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'support.%' AND detail LIKE '%Courier%'")->fetchColumn() === 0);

echo "  -- emails (captured, nothing is sent)\n";
$sent = [];
N::$capture = function (string $to, string $subject, string $text) use (&$sent): void { $sent[] = compact('to', 'subject', 'text'); };
$m = T::create($base, [], $annaRow, null)['ticket_no'];
$sent = [];
I::reply(I::detail($m), $staff, 'SECRET-REPLY We refunded RM20', []);
check('a staff reply sends exactly one email', count($sent) === 1);
check('the email goes to the customer', ($sent[0]['to'] ?? '') === 'anna@support.test');
check('the email has a sign-in link, not the reply text', str_contains($sent[0]['text'], '/member/support/' . $m) && !str_contains($sent[0]['text'], 'SECRET-REPLY'));
$sent = [];
I::note(I::detail($m), $staff, 'internal only');
check('an internal note sends no email', $sent === []);
$sent = [];
I::reply(I::detail((string) $g['ticket_no']), $staff, 'hello guest', []);
check('a guest reply goes to the guest email', ($sent[0]['to'] ?? '') === 'guest@support.test');
check('a guest email has no ticket link', !str_contains($sent[0]['text'] ?? '', '/support/t/'));
$sent = [];
$urgentTicket = T::create(['urgency' => 'urgent'] + $base, [], $annaRow, null)['ticket_no'];
$recipients = array_column($sent, 'to');
check('an urgent ticket with no assignee tells every active admin', in_array('ali@support.test', $recipients, true) && !in_array('old@support.test', $recipients, true));
$sent = [];
T::reply(T::forMember($urgentTicket, $anna), 'Still waiting', []);
check('the customer reply on an urgent ticket notifies the team', $sent !== []);
N::$capture = null;

echo "  -- unread badges\n";
T::markRead(T::forMember($m, $anna));
$before = T::unreadCountForMember($anna);
usleep(20000); // a real customer never reads and gets a reply in the same millisecond
I::reply(I::detail($m), $staff, 'latest answer', []);
check('a new staff reply makes exactly one ticket unread', T::unreadCountForMember($anna) === $before + 1);
T::markRead(T::forMember($m, $anna));
check('opening the ticket clears it', T::unreadCountForMember($anna) === $before);
$needs = I::needsReplyCount();
T::reply(T::forMember($m, $anna), 'customer waiting now', []);
check('a customer message counts for the staff badge', I::needsReplyCount() === $needs + 1);
I::reply(I::detail($m), $staff, 'answered', []);
check('a staff reply clears the staff badge for that ticket', I::needsReplyCount() === $needs);
check('a same-second read and reply are told apart', (function () use ($m, $anna, $staff): bool {
    T::markRead(T::forMember($m, $anna));
    usleep(20000);
    I::reply(I::detail($m), $staff, 'same second', []);
    return T::unreadCountForMember($anna) === 1;
})());

echo "  -- module default\n";
DB::query("DELETE FROM settings WHERE `key` = 'module.support'");
check('support is off when never switched on', Modules::enabled('support') === false);
Setting::set('module.support', '1');
check('support is on when switched on', Modules::enabled('support') === true);

// Clean up so other tests see the default module state and empty support tables.
DB::query("DELETE FROM settings WHERE `key` IN ('module.support', 'module.subscriptions')");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['support_attachments', 'support_ticket_labels', 'support_messages', 'support_tickets', 'support_labels'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
