---
target: guest email templates (booking_mail.class.php, feedback.class.php, shop_mail.class.php)
total_score: 27
max_score: 36
na_heuristics: 7
p0_count: 1
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/guest email templates (booking_mail.class.php, feedback.class.php, shop_mail.class.php)"
timestamp: 2026-09-30T09-02-42Z
slug: hp-feedback-class-php-shop-mail-class-php-9f3545de
---
Method: dual-agent (A: general-purpose subagent, design review · B: general-purpose subagent, detector/browser evidence), synthesis + independent spot-verification by the parent session, which also rendered and screenshotted the real templates itself before delegating.

All three files are current and actually used in guest/restaurant communication - not to be confused with web/classes/confirmation.class.php, an unused, generic legacy template for backend account activation (unrelated social links, myseat.us domain), reachable only from save_usr and not a guest touchpoint - deliberately excluded.

One correction/addition to both assessments: neither A nor B tested with different date values per language. I checked the three real call sites of bm_build() directly (plugins/local_email_send.plugin.php:49, web/classes/approval.class.php:82, web/cron/send_reminders.php:64) - all three format date_text via $general['dateformat'] (default d.m.Y) regardless of guest language. An English-speaking guest gets e.g. "05.10.2026" instead of an English-readable format - see P2 below, a finding neither agent surfaced.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Subject lines well differentiated; guest mail has no preheader text (only the admin mail has one) |
| 2 | Match System / Real World | 4 | Hyper-local arrival info (real bus stops, parking garages), reads like written by someone who runs the floor themselves |
| 3 | User Control and Freedom | 3 | Cancel link present in confirmed/pending/reminder/approved; declined has no "find a new date" button, prose only |
| 4 | Consistency and Standards | 2 | shop_mail.class.php is an uncoordinated third visual system (see P1) |
| 5 | Error Prevention | 3 | Approval links use HMAC token against mail-scanner prefetch - unusually careful |
| 6 | Recognition Rather Than Recall | 2 | A declined request shows the same bold "booking number" as a confirmed reservation |
| 7 | Flexibility and Efficiency | n/a | Not meaningfully applicable to a one-shot transactional email |
| 8 | Aesthetic and Minimalist Design | 3 | Clean, no images, but 5 gold stars before any rating is distracting (see P0) |
| 9 | Error Recovery | 3 | Decline copy offers an alternative date, but no CTA button |
| 10 | Help and Documentation | 2 | Imprint/privacy links exist in code but have no backend UI, so effectively never set (see Minor) |
| **Total** | | **27/36** | **Good** (75%, 1 heuristic n/a) |

## Design Specificity Verdict

One of the least generic restaurant-mail sequences you'll see - with one real outlier. The arrival/accessibility section is concrete down to the street corner ("two steps at the entrance... we'll set up a wheelchair ramp"), the admin notification reads like written by someone who has run a Friday-night service themselves. But: $closing/$sign are set once outside the mode branch (booking_mail.class.php:185-186) and reused verbatim across all 5 moods - "Deine Anfrage" is the literal, identical head for both pending and declined (lines 192, 199, 236, 243). A guest skimming just the big serif headline cannot tell an acceptance from a rejection.

Deterministic scan: impeccable detect --json actually ran (exit 2, 15 findings) - works technically on email HTML, but every finding's line is 0 (single-line PHP-concatenated strings have no meaningful line structure), and two of the three fired rules (overused-font, cream-palette) are taste heuristics, not hard defects. The one objectively useful hit: low-contrast, which matches the independently recomputed contrast analysis below.

Browser evidence: both agents and I actually rendered the templates with synthetic data (no DB, no real mail sent) and inspected real browser screenshots at ~600px and 375px - no guessed layouts. An injected <b>keine</b>/<script> test payload in the name/notes fields appeared everywhere as literal visible text, never executed markup - escaping is consistently correct (see Strengths).

## Overall Impression

Noticeably above-average craft for reservation software - but built in at least two uncoordinated passes: booking_mail.class.php and feedback.class.php share a coherent system, shop_mail.class.php is a separate, uncoordinated third appearance with no bilingual support. And the one mail that asks for honest feedback shows five gold stars before any answer is given.

## What's Working

1. **Arrival/accessibility block** (booking_mail.class.php:125-139): real bus stops, concrete parking-garage time rules, a concrete wheelchair-ramp offer - rare care for this category.
2. **Admin notification as its own well-built task tool**: status badge, 3 large Georgia numerals (date/time/pax), one CTA matching the actual needed action, a note that replies go straight to the guest.
3. **Escaping is consistently correct throughout**: every guest-supplied value (name, notes, phone, email, address) verifiably passes through the file's own $h() escaping helper - confirmed with injected test payloads, no XSS vector found.

## Priority Issues

**[P0] Five pre-filled gold stars contradict the mail's own "honest feedback" promise.**
feedback.class.php:288,294: $stars = str_repeat('&#9733;', 5); - always 5 full stars, directly above the greeting, before the guest has rated anything. The text right below it explicitly says "we honestly want to know how it was for you... if something wasn't right, that's exactly what we want to know" - but the first thing the eye lands on is a 5-star anchor. This is a classic anchoring bias working directly against the stated goal of getting honest negative feedback too. Fix: empty/outlined stars, or drop the star glyph from the email entirely and rate only on the linked form page. Suggested command: /impeccable polish

**[P1] shop_mail.class.php is an uncoordinated third system - no language switch, different colors, and one path isn't even valid HTML.**
Confirmed independently by both agents: no $lang/email_type branch anywhere in the file (every string hardcoded German, shop_mail.class.php:38-72), page background #f4efe6 instead of #f4f1ea used everywhere else, card radius 10px instead of the square 0-radius card in the other two files, no imprint/privacy footer. The restaurant notification (:80) additionally isn't a complete HTML document at all - just a bare <div> with no <!DOCTYPE>/<html>/<head>/<body>. Why it matters: an English-speaking guest ordering food gets a 100% German receipt, exactly at the moment (money, delivery time) where clarity matters most. Fix: route through the same $lang/legal-footer scaffold as bm_build(), align color values, add <html lang="">. Suggested command: /impeccable harden

**[P1] The decline mail visually reads like a confirmed receipt.**
booking_mail.class.php:192,199: $head = 'Deine Anfrage' is identical for pending and declined. Below it, both show the identical tinted, bordered fact box with a bold "Buchungsnummer" (line 182-183: $rows is populated before the mode branch and rendered unconditionally regardless of mode) - confirmed via screenshot. A guest who was just declined gets the same visual "receipt" treatment as a confirmed booking. Fix: give declined its own heading, drop bold/tint on the fact box or remove it for that mode. Suggested command: /impeccable clarify

**[P2] Four concrete WCAG AA contrast failures on secondary text.**
Computed directly (flat hex-on-hex pairs, no alpha compositing needed): #777777 on white ≈ 4.48:1 (just under 4.5), #8a8577 on white ≈ 3.68:1 (clear failure, affects the imprint/privacy footer in all three files), #888888/#777 in shop_mail.class.php:64-65,50,54 also fail. Independently corroborated by impeccable detect's own low-contrast finding. Fix: darken the secondary gray colors to ≥4.5:1. Suggested command: /impeccable typeset

**[P2] Date format doesn't follow the guest's chosen language.**
All three real callers of bm_build() format date_text via $general['dateformat'] (default d.m.Y) regardless of whether the guest chose German or English. An English-speaking guest sees "05.10.2026", which in US date conventions could be misread as May 10th. Fix: use a language-neutral or English date format (e.g. "l, F j, Y") when email_type==='en'. Suggested command: /impeccable clarify

## Persona Red Flags

**Jordan (first-timer, may not read German)**: booking_mail.class.php/feedback.class.php are well translated - but ordering through delivery gets a 100% German receipt with no language signal at all, and "call us" as the only error-recovery path.

**Sam (accessibility)**: No images anywhere -> no alt-text problem, every link is a real <a> with visible text. But: the 5 star glyphs have no aria-hidden/label (a screen reader likely announces "black star" 5 times with no context), plus the contrast failures above.

**Casey (mobile, quick glance)**: The core date/time/pax block stays legible at 375px. The fixed width:104px label column (:341) makes "Buchungsnummer" wrap across 3 lines in the value column instead of stacking cleanly. Missing preheader text means the lock-screen notification preview carries none of the actual news.

## Minor Observations

- The admin mail is fully built for declined/reminder/approved but, per tracing every real caller, never actually sent for those - live but dead code for 3 of 5 modes (booking_mail.class.php:394-476).
- The footer phone number is a tappable tel: link in booking_mail.class.php but plain text in feedback.class.php - same information, different treatment.
- Imprint/privacy links exist in code but config/config.general.php:66-67 defaults them empty, and no backend settings page exposes a field for them - the only way to set them is direct server file access. In practice they're almost always missing.
- The admin mail footer says "mySeat" instead of "Amadeus" (booking_mail.class.php:412,419) - the one brand break in an otherwise consistently Amadeus-branded system.
- border-radius (pill buttons, badges) is known to be ignored by Outlook desktop - likely degrades to square corners, not tested in a real Outlook client.

## Questions to Consider

1. Do pending and declined intentionally share a heading to avoid scaring the guest at a glance, or is it unintentional reuse?
2. The admin mail is the best-designed artifact in this set (large numerals, one clear CTA) - why doesn't the guest confirmation borrow that layout for date/time/pax, given the guest needs it just as urgently at a glance?
