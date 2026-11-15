# Support Tickets (with Subscriptions) — Plan

Status: **Phases 1–5 built (section 14).** Phase 5 review done in one pass; the full three-specialist gate (Argus, Cipher, Aegis) is still to be run before the feature is marked done on the VPS.
Tier: **T2 Full** (schema, customer data, authz, file upload). Full gates apply before done, including Phase 6 security.
Owner: Fakrul | Planner: IRIS
Related: `Docs/feature-plan.md` item 11 (Customer support tickets), `Docs/new-features-plan.md` item 4 (Subscriptions).

## Change Log
| Date | Rev | Change |
|---|---|---|
| 2026-11-15 | 1 | Initial plan from the requested feature list, plus suggestions and open questions |
| 2026-11-15 | 2 | Fakrul's answers recorded as decisions (section 0). Awaiting explicit go-ahead to build |
| 2026-11-15 | 3 | Phases 1–5 built and tested. Results, checklist and deviations in section 14 |

## 0. Decisions (Rev 2, from Fakrul)
| # | Question | Decision |
|---|---|---|
| D1 | Guests may open tickets? | Yes. Guests follow the ticket through a link sent by email |
| D2 | Staff change urgency? | No. Only admin and superadmin can change urgency; staff can add a note |
| D3 | Attachment types and size | JPG, PNG, WebP, PDF; 5 MB each (as planned) |
| D4 | Email on replies | Every staff reply emails the customer |
| D5 | Response-time targets | Accepted as suggested: Urgent 2 h, High 8 h, Normal 24 h |
| D6 | Internal notes in v1 | Yes |
| D7 | Subscription tickets | Active subscriptions only |
| D8 | Staff access | Use the existing module switch and roles. No new `support.manage` permission |

## 1. Requested features (mapped)
| ID | Request | How the plan meets it |
|---|---|---|
| T1 | List of tickets | Inbox list for staff; "My tickets" list for customers and members |
| T2 | Chat layout like WhatsApp | Bubbles (own on right, shop on left), date dividers, time and sender under each message, composer at the bottom with attach |
| T3 | Ticket works like live chat | New messages appear without reload (polling every 8 s in v1); typing is not in v1 |
| T4 | Labels on each ticket | Admin defines labels (name, colour); a ticket can have many; filter by label |
| T5 | Customer/member picks urgency at creation | Low / Normal / High / Urgent, required, default Normal |
| T6 | Link to an order | Optional. Customer picks one of their own orders; guests use order no + tracking token |
| T7 | Attach a file | Images (JPG, PNG, WebP) and PDF, max 5 MB each, 3 per message, private storage |
| T8 | By category | Admin-managed categories (see section 4). Customer picks one when creating |
| T9 | Subscription base | Optional link to one of the customer's subscriptions; category "Subscription" |

## 2. Current state (what already exists)
- `support_messages` (migration 055): one thread per order. Customer and staff messages, text only, no status, no subject, no urgency, no labels, no files.
- `SupportService`: `thread`, `post`, `waiting`, and a customer email (`tellCustomer`).
- Admin pages: `/admin/support` (list of orders with a thread), `/admin/support/{orderId}` (thread + reply). Module key `support`.
- Customer side: a message form on the order page (`orders/_support.php`), route `POST /order/{orderNo}/{token}/support` (guest) and `POST /member/orders/{orderNo}/support`. Both behind `module:support`.
- Subscriptions (migration 075): `subscriptions`, `subscription_runs`; `SubscriptionService` owns the member's list.

The new design **extends** this rather than replacing it. Existing order threads become tickets (section 6).

## 3. Data model (proposed)
New tables. All money-free, but they hold customer data.

**`support_tickets`**
| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| ticket_no | VARCHAR(20) UNIQUE | e.g. `TK-000123`, shown to customer |
| user_id | INT UNSIGNED NULL | member who opened it; NULL for guest |
| guest_email | VARCHAR(190) NULL | guest contact; required when user_id is NULL |
| category_id | INT UNSIGNED NOT NULL | FK support_categories |
| subject | VARCHAR(160) NOT NULL | |
| urgency | ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' | |
| status | ENUM('open','waiting_customer','waiting_staff','resolved','closed') | |
| order_id | INT UNSIGNED NULL | FK orders, ON DELETE SET NULL |
| subscription_id | INT UNSIGNED NULL | FK subscriptions, ON DELETE SET NULL |
| assignee_id | INT UNSIGNED NULL | FK users (staff/admin) |
| customer_read_at / staff_read_at | DATETIME NULL | unread badges |
| last_message_at | DATETIME NOT NULL | sort key for live list |
| resolved_at, created_at, updated_at | DATETIME/TIMESTAMP | |

**`support_categories`**: id, name, slug (unique), position, active. Seeded: Order, Payment, Shipping, Return or refund, Product, Subscription, Digital product, Account, Other.

**`support_labels`**: id, name (unique), colour (hex, validated), active.
**`support_ticket_labels`**: ticket_id, label_id (PK both), FK cascade.

**`support_messages`** (altered): add `ticket_id` (FK cascade), `visibility` ENUM('public','internal') default 'public' (internal = staff-only note, v2 if you prefer). Keep `order_id` for the backfill and for existing queries, nullable after backfill.

**`support_attachments`**: id, message_id (FK cascade), ticket_id, stored_name (random hex), original_name (sanitised, max 190), mime, size, created_at. Files stored in `storage/support/` (outside `public/`), served only by an authorised controller.

Indexes: `support_tickets(user_id, status)`, `(status, urgency, last_message_at)`, `(order_id)`, `(subscription_id)`, `support_messages(ticket_id, id)`.

## 4. Categories and labels
- **Categories** (one per ticket, required): admin can add, rename, reorder, deactivate. Deactivated categories stay on old tickets.
- **Labels** (many per ticket): admin creates them. Staff can add or remove labels on a ticket. Labels are for triage (e.g. "VIP", "Waiting courier", "Refund pending").
- Category is for the customer's choice of topic; labels are for the team's own tracking.

## 5. Screens
**Customer / member**
- `/member/support` and guest `/support/track` (ticket no + email, or order no + token): list of tickets with status, urgency colour, last message time, unread dot.
- `/member/support/new` (and guest form): category, urgency (radio, required), subject, message, optional order (member picks from own orders), optional subscription (member picks from own subscriptions), attach files.
- `/member/support/{ticket_no}`: chat view. Composer with text, attach and send. Status shown as a pill. A "Mark resolved" button for the customer (reopens on new message if within 7 days).

**Staff / admin** (`/admin/support`, `/staff/support`)
- Inbox: live list (left) with filters: status, urgency, category, label, assignee (me / unassigned / all), search by ticket no, customer name, order no. Sorted by urgency then last message.
- Chat (right): same WhatsApp layout. Side panel: customer, order and subscription links, category, urgency, status, assignee, labels, and history (status and assignee changes).
- Bulk actions: assign, set status, add label.

**Admin settings**: categories and labels (add, edit, deactivate).

Live updates: each open view polls `GET /…/support/{id}/messages?after=<id>` every 8 s and when the tab is visible. Lightweight JSON, no websockets in v1.

## 6. Migration of existing data
1. Create the new tables.
2. For each `order_id` that has messages: create one ticket (category "Order", urgency normal, status from the last sender: `waiting_staff` if the customer wrote last, else `waiting_customer`), link `order_id`, copy the messages with the new `ticket_id`.
3. Keep `order_id` on messages for one release, then drop it in a later migration.
Backfill runs in one transaction per order and is safe to re-run (skips orders that already have a ticket).

## 7. Notifications
- Customer gets an email on ticket creation (ticket no, link) and on each staff reply. Transactional email, sent through the existing queue. Not marketing: no unsubscribe requirement, but the link goes to the ticket page only.
- Staff: an unread count badge in the sidebar (the "waiting" count already exists in `SupportService::waiting`). Email to staff for Urgent tickets only (v1).

## 8. Security (T2 — must pass before done)
- **Ownership on every read and write.** A customer can only open a ticket where `user_id` equals their id; a guest only with ticket no + the email on the ticket (case-insensitive, rate-limited). Order and subscription links must belong to the same customer, checked on the server, not by the form. Tests for another member's ticket, order and subscription ids.
- **Attachments**: allowlist by extension and by `finfo` MIME (reuse the `ReceiptService`/`UploadService` checks); max 5 MB; max 3 per message; random stored names; stored outside `public/`; served through a controller that re-checks ownership; `Content-Disposition: attachment` for PDFs; never execute or render uploaded files inline except images.
- **Text**: plain text, escaped on output; max length 2000; no HTML.
- **Rate limits**: ticket creation and messages per user and per IP (reuse `RateLimiter`).
- **CSRF** on all POST routes (existing `csrf` group).
- **Audit**: status, urgency, assignee and label changes written to the audit log with actor and time.
- **Module gate**: every route behind `module:support`; subscription links also need `module:subscriptions` to show the subscription picker.
- **Staff access** (D8): no new permission. Access follows the existing module switch (`module:support`) and the staff/admin roles. Only admin and superadmin can change a ticket's urgency (D2); staff can add a note.
- **Privacy**: guest tickets show only the ticket's own data; no order or customer data in email subjects or URLs (ticket no only).

## 9. Phases and acceptance
| Phase | Scope | Accepted when |
|---|---|---|
| P1 | Migrations, categories, labels, backfill of existing order threads | Every old thread is one ticket; backfill re-run changes nothing |
| P2 | Customer: list, new ticket (category, urgency, order/subscription link, attach), chat view, polling | A member sees only own tickets; a 6 MB or .exe file is refused; a second member's order id is rejected |
| P3 | Staff inbox: list, filters, chat, assign, status, urgency, labels, bulk actions | Filters combine; a status change is in the audit log; staff without `support.manage` get 404/403 |
| P4 | Emails and unread badges | Email sent once per reply; badge clears when read |
| P5 | Tests, security review (Argus/Cipher/Aegis gate), docs | `tests/run.php` passes; ownership tests pass; security checklist in section 8 signed off |

Each phase is a separate commit on `feature/jnt-express` (or a new branch), reviewed before the next starts.

## 10. Suggestions to improve it (for your decision)
1. **SLA timers**: first reply target by urgency (e.g. Urgent 2 h, High 8 h, Normal 24 h); overdue tickets float to the top and show red. Helps the team and gives the customer an honest expectation shown on the ticket.
2. **Canned replies** per category (e.g. "Your parcel is with the courier…"), inserted with one click.
3. **Auto-assign** by category to a staff group, or round-robin among online staff.
4. **Internal notes** in the chat (staff only, grey bubble). Common in live-chat tools; avoids using WhatsApp-style replies for internal talk.
5. **Reopen rule**: a resolved ticket reopens on a new customer message within 7 days; after that a new ticket is opened and linked to the old one.
6. **Satisfaction rating** (1–5) when a ticket is resolved.
7. **Merge tickets** when the same customer opens duplicates about one order.
8. **Attachments scan**: optional ClamAV hook later; v1 uses type and size checks only.
9. **Realtime later**: move polling to Server-Sent Events once volume justifies it.
10. **Reporting**: CSV export and counts by category, urgency and label (fits the Sales reports pattern).
11. **Reply by email** (inbound parse) later — needs a mail provider with inbound support.

## 11. Questions (answered in section 0; kept for reference)
1. **Guests**: may guests open tickets (ticket no + email to track), or members only? The current order form allows guests.
2. **Urgency**: may staff change the customer's urgency, or only add a note? Plan assumes staff can change it, logged.
3. **Attachments**: are JPG, PNG, WebP and PDF enough? Is 5 MB per file right?
4. **Email**: email on every staff reply (plan default) or only on resolved?
5. **SLA hours**: accept the suggested targets in section 10.1, or give your own?
6. **Internal notes** (10.4): include in v1?
7. **Subscriptions**: allow a ticket about a subscription only for active or paused ones, or all?
8. **Staff permission**: new `support.manage` permission, or reuse the existing `module:support` with admin only?

## 12. Risks
- Customer data in tickets and attachments: needs the security gate and retention rule (suggest: delete attachments 180 days after a ticket is closed; ask if you want that).
- Polling load: 8 s per open chat is fine for a small team; revisit if many staff are online.
- Backfill of old threads must not change the old messages' text or order.
- Scope is large (XL-ish). Phase 1–2 alone already give customers a usable ticket flow.

## 13. Out of scope for this plan
Payments inside tickets, refunds issued from a ticket (refunds stay in the Return flow), and inbound email replies.

## 14. Build status and security review (Phase 5)

Commits on `feature/jnt-express`: plan 2f6106e/bc645d0; phase 1 2f6106e, phase 2 3245d5b, phase 3 296fc05, phase 4 d3fbf3d; phase 5 (this section and the test file).

### Migrations (run in order on the VPS, after a database backup)
| File | What |
|---|---|
| 076_support_tickets.sql | Categories, labels, tickets, ticket labels, attachments; messages get `ticket_id` and `visibility`; backfill of old order threads |
| 077_support_ticket_access.sql | `access_hash` for guest tickets (token stored as a hash only) |
| 078_support_timestamps_precision.sql | Millisecond precision for ticket and message times (unread marks) |

### Tests
- `tests/SupportTicketDbTest.php` (repo, 83 checks): ownership, guest tokens, order and subscription links, messages and internal notes, reopen and close rules, attachment checks, inbox filters and actions, bulk, audit, emails (captured), unread badges, module default. Runs on `*_test` databases only.
- Full suite on a brand-new `_test` database: 1,266 pass. The one failure is the existing "defaults" check, which fails only because every module is switched on for that run.
- Over HTTP (local server against a test database): guest flow, member flow, staff and admin flow, module off (404), CSRF (419 without a token), attachments (type, size and count refused; download checks), badges.

### Security checklist (plan section 8)
| Check | Result |
|---|---|
| Ownership on every member read and write | Pass: member routes go through the member's own tickets; another member gets 404 |
| Guest access needs ticket number and token | Pass: token is 128-bit random, stored as SHA-256 only, compared with `hash_equals` |
| Order and subscription links checked on the server | Pass: member's own order; guest needs the order's tracking code; subscription must be active and the member's |
| Attachments: allowlist, content check, size, count | Pass: JPG/PNG/WebP/PDF by MIME and signature, 5 MB, 3 per message, random names |
| Attachments stored outside public/ and served after an ownership check | Pass: `storage/support` (git-ignored); served by controller with `nosniff`; PDFs download, images show inline |
| Text is plain and escaped | Pass: server escapes; the live chat inserts text only, never HTML |
| Rate limits on creating and replying | Pass: per IP (10 tickets and 30 replies a minute) |
| CSRF on every form | Pass: member and guest POSTs return 419 without a token |
| Module gate on every route | Pass: 404 when off, checked over HTTP |
| Audit of status, assignee, urgency and labels | Pass: written without message text |
| Email contents | Pass: ticket number and link only; no message text; no guest secret link in replies |
| Referrer leaks of the guest link | Pass: Referrer-Policy is `strict-origin-when-cross-origin`, so other sites get only the site address |
| Urgency changes are admin-only | Pass: server returns 403 for staff |

### Findings and what was done
1. **Timestamp precision (fixed):** same-second reads and replies could leave the unread badge wrong. Fixed by migration 078.
2. **Portal menu merge (fixed):** `+` on lists dropped menu items. Fixed with `array_merge`.
3. **Icon missing (fixed):** the portal icon table had no `shield` entry; added.
4. **Open, accepted:** the guest link carries its token in the address, as the existing order-tracking links do. Referrer policy limits the exposure.
5. **Open, decision needed:** any active staff member can see every ticket (decision D8: module switch and roles only). If staff should see only their assigned tickets, that is a small change.
6. **Open, accepted:** attachments are not virus-scanned. Type and content checks only (plan section 10.8).
7. **Open:** the full Argus, Cipher and Aegis gate is still to be run on the VPS build.

### Deviations from the plan
- Inbox routes are `/admin/tickets` and `/staff/tickets`, not `/admin/support`, because the old order-messages page uses that path.
- Guests do not get a new link in staff reply emails. They use the first email's link (the token is only stored as a hash, so it cannot be re-sent).
- The customer's order page still has the old per-order message form. It was left as it was and can be switched to tickets later.
- No ticket history panel in the staff chat yet. The audit log has the data.
- The chat refreshes every 8 seconds; the staff list does not refresh on its own.

### Not checked
- Browser layout on phones and in dark mode.
- Real email delivery (email is not configured on this Mac; the notifier was tested with a capture hook).
- VPS deployment.
