<?php
/**
 * Support inbox: ticket list and chat side by side (stacked on phones). The customer and ticket details open in a popup
 * from the chat header. Expects: $base, $filters, $tickets, $active (ticket detail or null), $isAdmin, $meId, $urgencies,
 * $statuses, $categories, $labels, $assignees, $slaHours.
 */
use App\Services\SupportInboxService;
use App\Services\SupportTicketService;

$f = $filters;
$base = $base ?? '/admin';
$ticketUrl = fn(string $no) => url($base . '/tickets/' . $no);
$sel = fn($a, $b) => (string) $a === (string) $b ? ' selected' : '';
$overdueCount = count(array_filter($tickets, fn($t) => $t['overdue']));
$unreadCount = count(array_filter($tickets, fn($t) => $t['unread']));
$urgentCount = count(array_filter($tickets, fn($t) => $t['urgency'] === 'urgent'));
$mineCount = count(array_filter($tickets, fn($t) => (int) $t['assignee_id'] === (int) $meId));
$unassignedCount = count(array_filter($tickets, fn($t) => $t['assignee_id'] === null));
?>
<link rel="stylesheet" href="<?= e(asset('css/support-workspace.css')) ?>">
<style>
  /* The filter bar sits above the workspace, so the workspace gets less height than on the member page. */
  .ws { height: calc(100vh - 340px); min-height: 480px; }
  .msg-page > .msg-head { margin-bottom: 6px; }
  /* The chat fills the screen down to the bottom edge: no extra space under it on this page. */
  .msg-page:has(.ws) { padding-bottom: 0; }
  main.content:has(.ws) { padding-bottom: 12px; }
  .inbox-filters { gap: 6px; margin-bottom: 8px; }
  .inbox-filters label { gap: 2px; }
  .inbox-filters input, .inbox-filters select { min-width: 120px; min-height: 36px; }
  @media (max-width: 899px) {
    .ws { height: auto; min-height: 0; }
    /* A ticket is open on a phone: the chat takes the whole screen, so the heading and filters step aside. */
    .msg-page:has(.ws[data-active="1"]) > .msg-head,
    .msg-page:has(.ws[data-active="1"]) > .inbox-filters { display: none; }
    .ws[data-active="1"] > .ws-main { height: calc(100dvh - 90px); min-height: 420px; }
  }
  .inbox-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; }
  .inbox-filters label { display: grid; gap: 4px; font-size: .8rem; }
    .inbox-bulk { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 8px; border-bottom: 1px solid var(--line, #d1d7db); }
  .inbox-row { display: grid; grid-template-columns: auto 1fr; gap: 8px; align-items: start; padding: 10px 12px; min-height: 44px; border-bottom: 1px solid var(--line, #d1d7db); }
  .inbox-row.is-active { background: rgba(37, 99, 235, .10); box-shadow: inset 3px 0 0 var(--accent, #2563eb); }
  .inbox-row input[type="checkbox"] { margin-top: 4px; width: 18px; height: 18px; }
  .inbox-row a { color: inherit; text-decoration: none; display: block; min-width: 0; }
  .inbox-top { display: flex; justify-content: space-between; gap: 6px; min-width: 0; }
  .inbox-top strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
  .inbox-sub { font-size: .8rem; color: var(--muted, #667781); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .inbox-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
  .chip { font-size: .72rem; padding: 1px 7px; border-radius: 999px; color: #fff; }
  .badge-overdue { font-size: .72rem; color: #fff; background: #d92d20; padding: 1px 7px; border-radius: 999px; flex: none; }
  .inbox-panel { display: grid; gap: 12px; }
  .inbox-panel h3 { margin: 0; font-size: .95rem; }
  .inbox-panel form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .inbox-panel .k { font-size: .78rem; color: var(--muted, #667781); }
  .inbox-panel select, .inbox-panel button { min-height: 40px; }
  .inbox-section { border-top: 1px solid var(--line, #d1d7db); padding-top: 12px; display: grid; gap: 8px; }
  .inbox-section:first-child { border-top: 0; padding-top: 0; }
  .inbox-empty { padding: 40px 16px; text-align: center; color: var(--muted, #667781); }
</style>

<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Support inbox</h1>
            <p class="muted">Customer tickets, most urgent first. Replies go to the customer by email. Internal notes stay with the team.</p>
        </div>
        <a class="s-btn secondary" href="<?= e(url($base . '/support-settings')) ?>">Categories and labels</a>
    </header>

    <form method="get" action="<?= e(url($base . '/tickets')) ?>" class="inbox-filters">
        <label>Status
            <select name="status">
                <option value="">Not closed</option>
                <option value="all"<?= $sel($f['status'], 'all') ?>>All</option>
                <?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Urgency
            <select name="urgency">
                <option value="">Any</option>
                <?php foreach ($urgencies as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($f['urgency'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Category
            <select name="category">
                <option value="">Any</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $sel($f['category'], $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Label
            <select name="label">
                <option value="">Any</option>
                <?php foreach ($labels as $l): ?><option value="<?= (int) $l['id'] ?>"<?= $sel($f['label'], $l['id']) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Assignee
            <select name="assignee">
                <option value="">Anyone</option>
                <option value="me"<?= $sel($f['assignee'], 'me') ?>>Assigned to me</option>
                <option value="none"<?= $sel($f['assignee'], 'none') ?>>Unassigned</option>
                <?php foreach ($assignees as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $sel($f['assignee'], $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Search
            <input name="q" value="<?= e($f['q']) ?>" maxlength="100" placeholder="Ticket, name, email, order">
        </label>
        <button class="btn" type="submit">Filter</button>
    </form>

    <div class="ws" data-ws="1" data-active="<?= $active ? '1' : '0' ?>">
        <aside class="ws-list" aria-label="Tickets">
            <div class="ws-tools">
                <div class="ws-search">
                    <input type="search" placeholder="Search this list" aria-label="Search this list" data-ws-search>
                </div>
                <div class="ws-chips" role="group" aria-label="Quick filters">
                    <button type="button" class="ws-chip" data-ws-filter="all" aria-pressed="true">All <span class="ws-count"><?= count($tickets) ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="unread" aria-pressed="false">Unread <span class="ws-count"><?= $unreadCount ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="overdue" aria-pressed="false">Overdue <span class="ws-count"><?= $overdueCount ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="urgent" aria-pressed="false">Urgent <span class="ws-count"><?= $urgentCount ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="mine" aria-pressed="false">Mine <span class="ws-count"><?= $mineCount ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="unassigned" aria-pressed="false">Unassigned <span class="ws-count"><?= $unassignedCount ?></span></button>
                </div>
            </div>

            <form method="post" action="<?= e(url($base . '/tickets/bulk')) ?>" id="inbox-bulk">
                <?= csrf_field() ?>
                <div class="inbox-bulk">
                    <select name="choice" aria-label="Bulk action">
                        <option value="">Bulk action…</option>
                        <optgroup label="Assign to">
                            <option value="assign:0">Nobody</option>
                            <?php foreach ($assignees as $a): ?><option value="assign:<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Set status">
                            <?php foreach ($statuses as $k => $l): ?><option value="status:<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Add label">
                            <?php foreach ($labels as $l): ?><option value="label:<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
                        </optgroup>
                    </select>
                    <button class="btn secondary" type="submit">Apply to selected</button>
                </div>

                <div class="ws-items" data-ws-items>
                    <?php foreach ($tickets as $t):
                        $flags = [];
                        if ($t['unread']) $flags[] = 'unread';
                        if ($t['overdue']) $flags[] = 'overdue';
                        if ($t['urgency'] === 'urgent') $flags[] = 'urgent';
                        if ((int) $t['assignee_id'] === (int) $meId) $flags[] = 'mine';
                        if ($t['assignee_id'] === null) $flags[] = 'unassigned';
                        if ($t['status'] === 'resolved') $flags[] = 'resolved';
                        $isActive = $active && $active['ticket_no'] === $t['ticket_no'];
                    ?>
                    <div class="inbox-row<?= $isActive ? ' is-active' : '' ?>" data-ws-row data-flags="<?= e(implode(' ', $flags)) ?>">
                        <input type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" aria-label="Select <?= e($t['ticket_no']) ?>">
                        <a href="<?= e($ticketUrl($t['ticket_no'])) ?>"<?= $isActive ? ' aria-current="true"' : '' ?>>
                            <div class="inbox-top">
                                <strong><?php if ($t['unread']): ?><span class="ws-dot" aria-label="Unread"></span><?php endif; ?><?= e($t['subject']) ?></strong>
                                <?php if ($t['overdue']): ?><span class="badge-overdue">Overdue</span><?php endif; ?>
                            </div>
                            <div class="inbox-sub">
                                <?= e($t['ticket_no']) ?> · <?= e($t['customer_name'] ?: ($t['guest_email'] ?: 'Guest')) ?>
                                · <?= e(SupportTicketService::URGENCIES[$t['urgency']] ?? $t['urgency']) ?>
                                · <?= e(SupportInboxService::STATUSES[$t['status']] ?? $t['status']) ?>
                            </div>
                            <div class="inbox-sub">
                                <?= e($t['category_name']) ?><?php if (!empty($t['order_no'])): ?> · <?= e($t['order_no']) ?><?php endif; ?>
                                <?php if ($t['assignee_name']): ?> · <?= e($t['assignee_name']) ?><?php endif; ?>
                                · <?= e(date('j M, H:i', strtotime((string) $t['last_message_at']))) ?>
                            </div>
                            <?php if ($t['labels'] !== []): ?>
                            <div class="inbox-chips">
                                <?php foreach ($t['labels'] as $l): ?><span class="chip" style="background:<?= e($l['colour']) ?>"><?= e($l['name']) ?></span><?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </form>
            <?php if ($tickets === []): ?>
                <p class="ws-none">No tickets match these filters.</p>
            <?php endif; ?>
            <p class="ws-none" data-ws-none hidden>No tickets match the quick filter or search.</p>
        </aside>

        <section class="ws-main" aria-live="polite" aria-label="Ticket">
            <?php if ($active === null): ?>
                <div class="ws-empty">
                    <h2>Choose a ticket</h2>
                    <p>Pick a ticket on the left to read it and reply.</p>
                </div>
            <?php else: ?>
                <div class="ws-main-inner">
                    <a class="ws-back" href="<?= e(url($base . '/tickets')) ?>">← All tickets</a>
                    <?php
                    $urls = [
                        'post' => url($base . '/tickets/' . $active['ticket_no'] . '/reply'),
                        'note' => url($base . '/tickets/' . $active['ticket_no'] . '/note'),
                        'poll' => url($base . '/tickets/' . $active['ticket_no'] . '/messages'),
                        'attach' => url($base . '/tickets/' . $active['ticket_no'] . '/attachments/__ID__'),
                        'resolve' => '',
                    ];
                    $messages = SupportTicketService::messagesAfter((int) $active['id'], 0, true);
                    $ticket = $active;
                    $canReply = true;
                    $staffView = true;
                    require BASE_PATH . '/app/Views/support/_chat.php';
                    ?>
                </div>

                <dialog id="ticket-details" class="ws-dialog" aria-labelledby="ticket-details-title">
                    <div class="ws-dialog-head">
                        <h2 id="ticket-details-title">Customer and ticket</h2>
                        <form method="dialog"><button class="ws-dialog-close" type="submit" aria-label="Close">✕</button></form>
                    </div>
                    <div class="ws-dialog-body">
                        <div class="inbox-section">
                            <div>
                                <div class="inbox-panel-k"><span class="k">Customer</span></div>
                                <strong><?= e($active['customer_name'] ?: 'Guest') ?></strong>
                            </div>
                            <div class="inbox-panel">
                                <div><span class="k">Email</span><br><?= e($active['customer_email'] ?: '—') ?></div>
                                <div><span class="k">Phone</span><br><?= e($active['customer_phone'] ?: '—') ?></div>
                                <div><span class="k">Account</span><br><?= $active['user_id'] !== null ? 'Member since ' . e(date('j M Y', strtotime((string) $active['customer_since']))) : 'Guest (no account)' ?></div>
                                <div><span class="k">Tickets opened by this customer</span><br><?= (int) $active['customer_tickets'] ?></div>
                            </div>
                        </div>

                        <div class="inbox-section">
                            <div class="inbox-panel">
                                <div><span class="k">Category · created</span><br><?= e($active['category_name']) ?> · <?= e(date('j M Y, H:i', strtotime((string) $active['created_at']))) ?></div>
                                <?php if ($active['order']): ?>
                                <div>
                                    <span class="k">Order</span><br>
                                    <a href="<?= e(url($base . '/orders/' . (int) $active['order']['id'])) ?>"><?= e($active['order']['order_no']) ?></a>
                                    · <?= e($active['order']['status']) ?> · <?= e($active['order']['payment_status']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($active['subscription']): ?>
                                <div>
                                    <span class="k">Subscription</span><br>
                                    #<?= (int) $active['subscription']['id'] ?> · <?= e($active['subscription']['product_name']) ?> · <?= e($active['subscription']['status']) ?> · <?= e($active['subscription']['cadence']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($active['status'] === 'waiting_staff' && isset($slaHours[$active['urgency']]) && $active['last_customer_at']): ?>
                                <div>
                                    <span class="k">Reply target</span><br>
                                    <?= e(date('j M, H:i', strtotime((string) $active['last_customer_at']) + $slaHours[$active['urgency']] * 3600)) ?>
                                    <?= $active['overdue'] ? ' <span class="badge-overdue">Overdue</span>' : '' ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="inbox-section">
                            <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/status')) ?>">
                                <?= csrf_field() ?>
                                <span class="k" style="min-width:90px">Status</span>
                                <select name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($active['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
                                <button class="btn secondary" type="submit">Save</button>
                            </form>

                            <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/assign')) ?>">
                                <?= csrf_field() ?>
                                <span class="k" style="min-width:90px">Assignee</span>
                                <select name="assignee"><option value="0">Nobody</option><?php foreach ($assignees as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $sel($active['assignee_id'], $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
                                <button class="btn secondary" type="submit">Save</button>
                            </form>

                            <?php if ($isAdmin): ?>
                            <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/urgency')) ?>">
                                <?= csrf_field() ?>
                                <span class="k" style="min-width:90px">Urgency</span>
                                <select name="urgency"><?php foreach ($urgencies as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($active['urgency'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
                                <button class="btn secondary" type="submit">Save</button>
                            </form>
                            <?php else: ?>
                            <div><span class="k">Urgency</span> <?= e($urgencies[$active['urgency']] ?? $active['urgency']) ?> <span class="k">(set by an admin)</span></div>
                            <?php endif; ?>

                            <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/labels')) ?>">
                                <?= csrf_field() ?>
                                <span class="k">Labels</span>
                                <?php $on = array_map('intval', array_column($active['labels'], 'id')); ?>
                                <?php foreach ($labels as $l): ?>
                                    <label class="check"><input type="checkbox" name="label_ids[]" value="<?= (int) $l['id'] ?>"<?= in_array((int) $l['id'], $on, true) ? ' checked' : '' ?>> <?= e($l['name']) ?></label>
                                <?php endforeach; ?>
                                <button class="btn secondary" type="submit">Save labels</button>
                            </form>
                        </div>
                    </div>
                </dialog>
            <?php endif; ?>
        </section>
    </div>
</div>
<script src="<?= e(asset('js/support-workspace.js')) ?>" defer></script>
