<?php
/**
 * Support inbox. Expects: $base, $filters, $tickets, $active (ticket detail or null), $isAdmin, $urgencies, $statuses,
 * $categories, $labels (all), $assignees, $slaHours.
 */
$f = $filters;
$base = $base ?? '/admin';
$ticketUrl = fn(string $no) => url($base . '/tickets/' . $no);
$sel = fn($a, $b) => (string) $a === (string) $b ? ' selected' : '';
?>
<style>
  .inbox-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; }
  .inbox-filters label { display: grid; gap: 4px; font-size: .8rem; }
  .inbox-filters input, .inbox-filters select { min-width: 120px; }
  .inbox-grid { display: grid; grid-template-columns: minmax(260px, 340px) 1fr; gap: 12px; align-items: start; }
  @media (max-width: 900px) { .inbox-grid { grid-template-columns: 1fr; } }
  .inbox-list { border: 1px solid var(--line, #d1d7db); border-radius: 10px; overflow: hidden; background: var(--card, #fff); }
  .inbox-bulk { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 8px; border-bottom: 1px solid var(--line, #d1d7db); }
  .inbox-row { display: grid; grid-template-columns: auto 1fr; gap: 8px; padding: 10px; border-bottom: 1px solid var(--line, #d1d7db); }
  .inbox-row.active { background: rgba(37, 99, 235, .08); }
  .inbox-row a { color: inherit; text-decoration: none; display: block; }
  .inbox-top { display: flex; justify-content: space-between; gap: 6px; }
  .inbox-sub { font-size: .8rem; color: var(--muted, #667781); margin-top: 3px; }
  .inbox-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
  .chip { font-size: .72rem; padding: 1px 7px; border-radius: 999px; color: #fff; }
  .badge-overdue { font-size: .72rem; color: #fff; background: #d92d20; padding: 1px 7px; border-radius: 999px; }
  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #2563eb; margin-right: 4px; }
  .inbox-panel { border: 1px solid var(--line, #d1d7db); border-radius: 10px; padding: 12px; margin-top: 12px; display: grid; gap: 10px; background: var(--card, #fff); }
  .inbox-panel h3 { margin: 0; font-size: .95rem; }
  .inbox-panel form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .inbox-panel .k { font-size: .78rem; color: var(--muted, #667781); }
  .inbox-empty { padding: 40px 16px; text-align: center; color: var(--muted, #667781); border: 1px dashed var(--line, #d1d7db); border-radius: 10px; }
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

    <div class="inbox-grid">
        <section class="inbox-list" aria-label="Tickets">
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

                <?php if ($tickets === []): ?>
                    <p class="muted" style="padding:14px">No tickets match these filters.</p>
                <?php endif; ?>
                <?php foreach ($tickets as $t): ?>
                <div class="inbox-row<?= $active && $active['ticket_no'] === $t['ticket_no'] ? ' active' : '' ?>">
                    <input type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" aria-label="Select <?= e($t['ticket_no']) ?>">
                    <a href="<?= e($ticketUrl($t['ticket_no'])) ?>">
                        <div class="inbox-top">
                            <strong><?php if ($t['unread']): ?><span class="dot" aria-label="Unread"></span><?php endif; ?><?= e($t['subject']) ?></strong>
                            <?php if ($t['overdue']): ?><span class="badge-overdue">Overdue</span><?php endif; ?>
                        </div>
                        <div class="inbox-sub">
                            <?= e($t['ticket_no']) ?> · <?= e($t['customer_name'] ?: ($t['guest_email'] ?: 'Guest')) ?>
                            · <?= e(\App\Services\SupportTicketService::URGENCIES[$t['urgency']] ?? $t['urgency']) ?>
                            · <?= e(\App\Services\SupportInboxService::STATUSES[$t['status']] ?? $t['status']) ?>
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
            </form>
        </section>

        <section aria-label="Ticket">
            <?php if ($active === null): ?>
                <div class="inbox-empty">Choose a ticket on the left to read it and reply.</div>
            <?php else: ?>
                <?php
                $urls = [
                    'post' => url($base . '/tickets/' . $active['ticket_no'] . '/reply'),
                    'note' => url($base . '/tickets/' . $active['ticket_no'] . '/note'),
                    'poll' => url($base . '/tickets/' . $active['ticket_no'] . '/messages'),
                    'attach' => url($base . '/tickets/' . $active['ticket_no'] . '/attachments/__ID__'),
                    'resolve' => '',
                ];
                $messages = \App\Services\SupportTicketService::messagesAfter((int) $active['id'], 0, true);
                $ticket = $active;
                $canReply = true;
                $staffView = true;
                require BASE_PATH . '/app/Views/support/_chat.php';
                ?>

                <div class="inbox-panel">
                    <div>
                        <div class="k">Customer</div>
                        <strong><?= e($active['customer_name'] ?: 'Guest') ?></strong>
                        <div class="k"><?= e($active['customer_email'] ?: '—') ?></div>
                    </div>
                    <div>
                        <div class="k">Category · created</div>
                        <?= e($active['category_name']) ?> · <?= e(date('j M Y, H:i', strtotime((string) $active['created_at']))) ?>
                    </div>
                    <?php if ($active['order']): ?>
                    <div>
                        <div class="k">Order</div>
                        <a href="<?= e(url($base . '/orders/' . (int) $active['order']['id'])) ?>"><?= e($active['order']['order_no']) ?></a>
                        · <?= e($active['order']['status']) ?> · <?= e($active['order']['payment_status']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($active['subscription']): ?>
                    <div>
                        <div class="k">Subscription</div>
                        #<?= (int) $active['subscription']['id'] ?> · <?= e($active['subscription']['product_name']) ?> · <?= e($active['subscription']['status']) ?> · <?= e($active['subscription']['cadence']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($active['status'] === 'waiting_staff' && isset($slaHours[$active['urgency']]) && $active['last_customer_at']): ?>
                    <div>
                        <div class="k">Reply target</div>
                        <?= e(date('j M, H:i', strtotime((string) $active['last_customer_at']) + $slaHours[$active['urgency']] * 3600)) ?>
                        <?= $active['overdue'] ? ' <span class="badge-overdue">Overdue</span>' : '' ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/status')) ?>">
                        <?= csrf_field() ?>
                        <span class="k" style="min-width:70px">Status</span>
                        <select name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($active['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
                        <button class="btn secondary" type="submit">Save</button>
                    </form>

                    <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/assign')) ?>">
                        <?= csrf_field() ?>
                        <span class="k" style="min-width:70px">Assignee</span>
                        <select name="assignee"><option value="0">Nobody</option><?php foreach ($assignees as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $sel($active['assignee_id'], $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
                        <button class="btn secondary" type="submit">Save</button>
                    </form>

                    <?php if ($isAdmin): ?>
                    <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/urgency')) ?>">
                        <?= csrf_field() ?>
                        <span class="k" style="min-width:70px">Urgency</span>
                        <select name="urgency"><?php foreach ($urgencies as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($active['urgency'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
                        <button class="btn secondary" type="submit">Save</button>
                    </form>
                    <?php else: ?>
                    <div><span class="k">Urgency</span> <?= e($urgencies[$active['urgency']] ?? $active['urgency']) ?> <span class="k">(set by an admin)</span></div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url($base . '/tickets/' . $active['ticket_no'] . '/labels')) ?>">
                        <?= csrf_field() ?>
                        <span class="k">Labels</span>
                        <?php $on = array_column($active['labels'], 'id'); ?>
                        <?php foreach ($labels as $l): ?>
                            <label class="check"><input type="checkbox" name="label_ids[]" value="<?= (int) $l['id'] ?>"<?= in_array((int) $l['id'], array_map('intval', $on), true) ? ' checked' : '' ?>> <?= e($l['name']) ?></label>
                        <?php endforeach; ?>
                        <button class="btn secondary" type="submit">Save labels</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
