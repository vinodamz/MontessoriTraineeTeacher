# Admissions Automation — Design (for sign-off)

Fully automated WhatsApp admissions flow across three systems:
- **n8n bot** — reads each parent message with an LLM, decides intent, moves the stage, fires sends/reminders.
- **WhatsApp CRM (WACRM)** — sends messages (free text in the 24h window, Meta template outside), mirrors the chat.
- **MTT pipeline** — stages, per-stage messages, lead record, audit log, follow-up tasks.

Decisions locked: AI reads the chat · auto-send stage messages · reminders by email + WhatsApp-to-you + CRM task · call nudge after **3 days** quiet.

---

## 1. Stage flow

```
        ┌─────────── parent messages ───────────┐
        ▼                                        │
   [ New ] ──interested──► [ Engaged ] ──wants visit──► [ Visit scheduled ]
        │                       │                              │
        │                       │                       (you set date,
        │                       │                        you run visit)
        │                       │                              ▼
        │                       │                        [ Visited ] ──3 days──► REMIND YOU
        │                       │                              │                 to trigger
        │                       │                              ▼                 school follow-up
        │                       └──ready────► [ Admission / Offered ] ──► [ Enrolled ]
        │
        └──"not interested"──► bot asks reason ──► [ Lost ] (+ reason)

   Any open lead, no reply for 3 days ──► REMIND YOU to call (with summary)
```

New stage to add: **`visited`** — anchors the 3-day post-visit timer. Everything else maps to your existing pipeline.

## 2. Intent → action (the AI "brain" in n8n)

Each inbound parent message is classified into one intent. The LLM returns a small JSON: `{intent, confidence, reason, suggested_stage}`.

| Intent | Trigger phrases (examples) | Action |
|---|---|---|
| `info` | "what are the fees", "timings?" | Bot answers; **no stage move** |
| `interested` | "sounds good", "tell me more", "yes interested" | Move → **Engaged**; auto-send stage msg |
| `wants_visit` | "can I visit", "I'd like to see the school" | Move → **Visit scheduled**; notify you to set a date |
| `ready` | "how do I admit", "want to enroll" | Move → **Admission**; auto-send next-steps |
| `not_interested` | "not now", "we chose another", "too far" | Bot asks reason → on reply, set **Lost** + lost_reason |
| `unclear` | anything low-confidence | Bot answers normally; **no move** (avoids wrong auto-moves) |

Guardrails: only move **forward** automatically; never auto-move out of `Enrolled`/`Lost`; low confidence = no move; every move writes a CRM audit entry.

## 3. Per-stage messages

`{parent_name}` `{child_name}` `{school_name}` `{stage}` are filled automatically. In-window = free text; out-of-window = the Meta template named in the last column.

| Stage | In-window text | Template (out-of-window) |
|---|---|---|
| **New** | Hi {parent_name}! 🌟 Thanks for reaching out to {school_name}. I can share our programmes and fees, or help you book a school visit — what would you like? | `welcome_admissions` |
| **Engaged** | So glad you're interested, {parent_name}! Would you like to visit and see the classrooms? We can arrange a convenient time for you and {child_name}. | `visit_invitation` |
| **Visit scheduled** | Wonderful! 🎉 Our team will confirm a date for your visit. We look forward to welcoming you and {child_name} to {school_name}! | *(usually in-window)* |
| **Visited** | *(no message — starts the 3-day timer)* | — |
| **Post-visit follow-up** *(you trigger after the 3-day reminder)* | Hi {parent_name}, it was lovely having you at {school_name}! 😊 Do you have any questions about admission for {child_name}? We're happy to help with the next steps. | `post_visit_followup` |
| **Admission / Offered** | Great news, {parent_name}! Here are the next steps to secure {child_name}'s place at {school_name}. Shall I guide you through the form? | `admission_next_steps` |
| **Enrolled** | Welcome to the {school_name} family, {parent_name}! 🎓 We're thrilled to have {child_name} with us. | *(in-window)* |
| **Lost (ask reason)** | No problem at all, {parent_name}. So we can keep improving — may I ask what made you decide not to proceed? | — |

## 4. Reminders to you (email + WhatsApp + CRM task)

| Reminder | Fires when | Contents |
|---|---|---|
| **Post-visit follow-up** | Lead in `Visited` for **3 days** with no further inbound message and no stage advance | "Trigger the school WhatsApp follow-up for {parent_name} ({child}). [one-tap send]" + chat summary |
| **Gone quiet → call** | Any **open** lead with no inbound message for **3 days** (not Enrolled/Lost/already reminded) | Lead details + phone + AI summary of the conversation so far + "Call them" |

Mechanics: an n8n **scheduled** workflow (every ~3h) calls a new MTT read endpoint `/crm/api/attention.php` (secret-authed) that returns leads needing each reminder; n8n then emails (mini), WhatsApps your number, and creates a CRM follow-up. A `reminded_at` marker prevents repeats.

## 5. Meta templates to submit (do this first — approval takes time)

Submit these in **WhatsApp Manager → Message templates**. Language **English (en)**. Each has one or two body variables; sample values given for Meta's review form.

### `welcome_admissions` — category: UTILITY
```
Hi {{1}}! Thanks for reaching out to {{2}}. We'd love to help you find the right start for your child. I can share our programmes and fees, or help you book a school visit — what would you prefer?
```
Samples: {{1}}=Priya, {{2}}=The Little Graduates

### `visit_invitation` — category: MARKETING
```
Hi {{1}}, we'd love to show you around {{2}}! Seeing the classrooms and meeting our teachers is the best way to decide. Would you like to book a short visit this week?
```
Samples: {{1}}=Priya, {{2}}=The Little Graduates

### `post_visit_followup` — category: UTILITY
```
Hi {{1}}, it was lovely having you visit {{2}}! Do you have any questions about admission for {{3}}? We're happy to help you with the next steps whenever you're ready.
```
Samples: {{1}}=Priya, {{2}}=The Little Graduates, {{3}}=Aarav

### `admission_next_steps` — category: UTILITY
```
Great news, {{1}}! Here are the next steps to secure {{2}}'s place at {{3}}. Reply here and we'll guide you through the simple admission form.
```
Samples: {{1}}=Priya, {{2}}=Aarav, {{3}}=The Little Graduates

### `staff_reminder` — category: UTILITY  *(this one is sent to YOU, not parents)*
```
You have {{1}} admissions lead(s) needing attention at The Little Graduates — post-visit follow-ups and parents to call back. Open the CRM to follow up.
```
Samples: {{1}}=3
> Powers the "WhatsApp reminder to you" (to 917028915026). Once approved, set `REMINDER_WA_TEMPLATE=staff_reminder` in `~/wacrm/ops/alerts/.reminders.env` on the mini and it turns on automatically.

> Note on categories: Meta may reclassify nurture wording as MARKETING (needs the parent to have opted in, which an inbound enquiry generally satisfies). UTILITY templates are approved faster and can be sent more freely. If `visit_invitation` gets rejected as MARKETING, we soften it or move that nudge inside the 24h window only.

## 6. Build phases (after sign-off)

- **Phase A** (no template dependency): add the `visited` stage + a "Mark visit done" action; write the per-stage messages into Stage config; build the MTT `/crm/api/attention.php` + stage-move-from-bot endpoints. → deploy (your approval).
- **Phase B**: n8n — add the AI intent classifier, wire auto-move + auto-send (reusing `/send-to-lead`), and the "ask reason → Lost" sub-flow.
- **Phase C**: n8n scheduled reminder workflow → email + WhatsApp-to-you + CRM follow-up; the one-tap post-visit trigger.
- **Phase D**: swap the out-of-window sends to the real template names once Meta approves them.

Phases A–C can be built **while templates are in Meta review**; only Phase D needs the approvals.
