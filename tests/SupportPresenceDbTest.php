<?php
// Support emails and member presence: an online member gets no reply email; an offline member and guests do.
// Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\SupportInboxService as I;
use App\Services\SupportNotifier as N;
use App\Services\SupportPresence as P;
use App\Services\SupportTicketService as T;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$pdo = DB::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['support_attachments', 'support_ticket_labels', 'support_messages', 'support_tickets', 'member_presence'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
DB::query("INSERT INTO settings (`key`, `value`) VALUES ('module.support', '1') ON DUPLICATE KEY UPDATE `value` = '1'");

DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', 'Online Ann', 'ann@presence.test', 'x', 'active')");
$ann = (int) $pdo->lastInsertId();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', 'Away Ben', 'ben@presence.test', 'x', 'active')");
$ben = (int) $pdo->lastInsertId();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('staff', 'Siti', 'siti@presence.test', 'x', 'active')");
$staff = (int) $pdo->lastInsertId();
$cat = (int) DB::query("SELECT id FROM support_categories WHERE slug = 'order'")->fetchColumn();

check('a member with no check-in is not online', P::isOnline($ann) === false);
P::touch($ann);
check('a check-in makes the member online', P::isOnline($ann) === true);
DB::query("UPDATE member_presence SET seen_at = NOW(3) - INTERVAL 5 MINUTE WHERE user_id = ?", [$ann]);
check('an old check-in is no longer online', P::isOnline($ann) === false);
P::touch($ann);

$sent = [];
N::$capture = function (string $to, string $subject, string $text) use (&$sent): void { $sent[] = $to; };

$ticketFor = function (int $userId, string $email) use ($cat): string {
    return (string) T::create(['category_id' => $cat, 'urgency' => 'normal', 'subject' => 'Presence', 'body' => 'Hello'], [], ['id' => $userId, 'email' => $email], null)['ticket_no'];
};
$annTicket = $ticketFor($ann, 'ann@presence.test');
$benTicket = $ticketFor($ben, 'ben@presence.test');
$guestTicket = (string) T::create(['category_id' => $cat, 'urgency' => 'normal', 'subject' => 'Guest', 'body' => 'Hi'], [], null, 'guest@presence.test')['ticket_no'];

$sent = [];
I::reply(I::detail($annTicket), $staff, 'Reply to online Ann', []);
check('no reply email to a member who is online', !in_array('ann@presence.test', $sent, true));

$sent = [];
I::reply(I::detail($benTicket), $staff, 'Reply to away Ben', []);
check('a reply email goes to a member who is away', in_array('ben@presence.test', $sent, true));

$sent = [];
I::reply(I::detail($guestTicket), $staff, 'Reply to guest', []);
check('guests always get the reply email', in_array('guest@presence.test', $sent, true));

N::$capture = null;
DB::query("DELETE FROM settings WHERE `key` = 'module.support'");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['support_attachments', 'support_ticket_labels', 'support_messages', 'support_tickets', 'member_presence'] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
