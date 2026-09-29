---
target: api/reserve.php
total_score: 17
max_score: 36
na_heuristics: 10
p0_count: 2
p1_count: 3
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/api/reserve.php"
target_fingerprint: "sha256:4de65dda98b065a443c040e0f5f9ea95fdffaa7d22a4b14b00be988843b6f64c"
target_path: /Users/hamunhirbod/claude_code/myseat/api/reserve.php
timestamp: 2026-09-29T15-55-55Z
slug: api-reserve-php
closed: true
---
Method: dual-agent (A: design review · B: detector/browser evidence), synthesis + independent spot-verification of every load-bearing claim by the parent session.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Changing party size silently re-fetches the time grid and drops the already-picked slot with no notice |
| 2 | Match System / Real World | 2 | Final CTA reuses the backend admin string "Anlegen"/"Create" instead of guest-voice copy |
| 3 | User Control and Freedom | 3 | Back buttons work on every step; no obvious "start over" besides the ✕ |
| 4 | Consistency and Standards | 1 | Legacy `.error{background:#F7CBCA !important}` breaks the dark theme's own error styling |
| 5 | Error Prevention | 0 | Party-size "−" can reach 0 and it reaches the server (`ajax_timeslots.php?pax=0` observed on the wire) |
| 6 | Recognition Rather Than Recall | 3 | Step-3 summary card (date/time/pax) right before commit |
| 7 | Flexibility and Efficiency | 2 | Direct pax typing + stepper are efficient; editing pax mid-flow punishes the user (see #1) |
| 8 | Aesthetic and Minimalist Design | 3 | Clean, restrained, one-accent system; undercut by the pink error fields and the unbroken 36-button time wall |
| 9 | Error Recovery | 1 | Validation only toggles a CSS class - no text tells the guest what's wrong |
| 10 | Help and Documentation | n/a | Not applicable to a booking widget |

**Total: 17/36 (Poor band, 47%)** — nine heuristics scored, #10 excluded as inapplicable.

## Design Specificity Verdict

**Mostly authored, with two seams that break the illusion at the worst moments.** The Kerzengold theme, the "Mittagstisch" offer dialog that deep-links to the real menu, the live DE/AT phone-number hint, and the step-3 summary card are genuine, restaurant-specific work — not a stock template. But the final commit button says **"Anlegen"** (`api/reserve.php:504`, `_create` from `web/lang/de.php:468` — the same string used everywhere in the *admin* backend for CRUD actions), and a failed validation turns Name/Email/Phone a jarring light pink that has nothing to do with the candlelight-gold system. A guest would describe the happy path as "built for this place," then hit two moments that feel like an unfinished demo.

**Deterministic scan**: `impeccable detect --json api/reserve.php` — exit 0, 10 `design-system-color` findings, **all 10 confirmed false positives**: they sit inside an HTML comment (`api/reserve.php:234-267`, explicitly labeled "Uncomment to define your own color scheme... example is from the Monmarthe DEMO page"), i.e. dead sample markup, not live code. Net real findings from the detector: zero. This is a clean read on the file's actual token discipline — the harden/extract work from earlier this session holds up here too.

**Correction to the browser-evidence pass**: Assessment B additionally reported a 1.11:1 contrast failure on the offer-chip time text and a claim that `#reservation_pax`/`#reservation_date` are entirely unreachable by keyboard (0×0, `offsetParent: null`). I re-measured both directly on the live page and neither holds up:
- The offer-chip background is a *translucent* gold (`rgba(201,162,89,.12)`) over the page's near-black base (`#0c0b0a`); composited correctly, the real rendered background is ≈`rgb(35,29,19)` and the actual contrast against the `rgb(168,158,140)` caption text is **≈6.3:1 — passes WCAG AA**. B's number came from comparing the text to the *un-composited, fully-opaque* gold value, which isn't what actually renders.
- Both `#reservation_pax` and `#reservation_date` measured live as `offsetParent: true`, `tabIndex: 0`, with real dimensions (272×44 and 323.5×24) — fully keyboard-reachable in the normal Tab order. No blocker here.

What *did* independently verify: `#reservation_pax`/`#reservation_date` have **zero associated `<label>`** (`el.labels.length === 0`) and no `aria-label` despite a visible sibling caption ("Personen"/"Datum") that a screen reader never connects to the field; `#reservation_guest_name` (and by the same CSS rule, the other step-3 fields) render with `outline: none` / `box-shadow: none` on focus — no visible focus indicator at all. Also independently reproduced: exactly 20 console `500` errors on every fresh page load (three separate loads, both viewports) - the failing request couldn't be pinned down (network-log buffer evicts the earliest requests before they're readable back), but the volume and consistency are real and worth a backend look outside this design pass.

## Overall Impression

The bones are good — a restaurant actually thought about this booking flow (the offer dialog, the phone hint, the summary card). But the flow's two most consequential moments — adjusting a mistaken party size, and submitting your contact details — are exactly where legacy code shows through: a stepper bug that lets you book a table for zero people, and a CSS specificity fight that paints the form pink right when a guest is already frustrated. Neither is a hard fix. The single biggest opportunity is closing that gap between "the parts someone clearly designed" and "the parts nobody has touched since the last theme."

## What's Working

- **The offer-dialog pattern** (`data-offer-open` + a native `<dialog>`, `api/reserve.php` + `offers.class.php`): today's lunch special surfaced right in the time picker, linking to the real menu — specific, technically clean (moved outside the form to dodge nested-form issues), and genuinely delightful.
- **The phone-hint microcopy** (`api/reserve.php:459-484`): live-parses DE/AT numbers, distinguishes mobile vs. landline, updates the hint text as you type ("Mobilnummer erkannt..."). Thoughtful engineering in service of the warm guest voice DESIGN.md asks for.
- **The step-3 checkout summary card**: reduces working memory load and gives a moment of recognition right before the guest commits contact details.

## Priority Issues

**[P0] Party size can reach 0 and survives to the server.**
Why it matters: a restaurant can't seat "0 people" — this either breaks staff's queue with a nonsense booking or lets a stress-tester submit garbage undetected. Confirmed on the wire (`GET .../api/ajax_timeslots.php?pax=0`).
Fix: `api/reserve.php:616` — change `if (oldValue >= 1)` to `if (oldValue > 1)` in the "−" handler. Server-side `business.class.php` validation should floor pax at 1 too, not just trust the client.
Suggested command: `/impeccable harden`

**[P0] Legacy pink error background overrides the dark theme.**
Why it matters: `.error{background:#F7CBCA !important}` (`api/style/style.css:653`, a decade-old light-admin-theme rule) beats the theme's own `.wizard-step input.error{border-color:var(--danger)}` (`style.css:1205-1208`) purely on `!important`, regardless of specificity. This is the single most visible identity break in the whole flow, and it fires exactly when a guest is already frustrated by a validation error.
Fix: scope the legacy rule out of `.booking-shell` (or give the wizard's own error rule matching `!important` + a background per DESIGN.md's documented error spec: `rgba(226,134,124,.10)`).
Suggested command: `/impeccable harden`

**[P1] No inline error messages — only a color change.**
Why it matters: `functions.js`'s `validateLength`/`validateEmail` only toggle the `error` class; nothing tells the guest *what's* wrong (empty? bad format?). Fails heuristic 9 outright and is invisible to colorblind guests or screen-reader users.
Fix: add a per-field `<p class="field-error">` toggled alongside the class, `aria-describedby`-linked to the input, with a concrete message ("Bitte gib eine gültige E-Mail-Adresse ein.").
Suggested command: `/impeccable clarify`

**[P1] The submit CTA is the generic backend word, not the guest voice.**
Why it matters: `_create` ("Anlegen"/"Create") is shared with every admin CRUD action in the codebase. DESIGN.md explicitly calls for a warm guest voice vs. a terse staff voice, and this is the one word that crosses that line at the single most consequential click in the flow.
Fix: give the guest flow its own string (`bt('reserve_now')` → "Jetzt reservieren" / "Reserve now") instead of reusing `_create` at `api/reserve.php:504`.
Suggested command: `/impeccable clarify`

**[P1] Step-1 fields have no accessible name, and step-3 fields have no visible focus indicator.**
Why it matters: `#reservation_pax`/`#reservation_date` have a visible caption next to them but zero programmatic label (`<label>`, `aria-label`) - a screen reader announces an unlabeled field. `#reservation_guest_name` (and siblings) render `outline:none`/`box-shadow:none` on focus - a keyboard user tabbing through step 3 can't see where they are. Both independently confirmed live.
Fix: wrap or `for=`-associate the existing `.picker-label` captions with their inputs; add a visible focus style (e.g. `border-color: var(--gold)` already exists on `:focus` for text inputs elsewhere — extend it here, plus a focus-visible outline for keyboard users).
Suggested command: `/impeccable harden`

**[P2] Changing party size silently discards the chosen time slot.**
Why it matters (as reported by the design review, not independently re-tested this pass): adjusting pax re-renders `#timeslot-results` via AJAX without restoring the previously-checked radio, so a reasonable "actually, four of us" correction quietly loses the guest's earlier time pick.
Fix: after the AJAX re-render, re-check the previous time value if still available; if it's gone, show a one-line note instead of silence.
Suggested command: `/impeccable harden`

**[P3] Flat 36-slot time grid, no daypart chunking.**
Why it matters: all of 12:00-21:00 in 15-minute steps renders at once with no lunch/afternoon/evening grouping, despite DESIGN.md already defining `daypart-morning`/`daypart-evening` tokens for exactly this concept elsewhere in the system. Forces a scroll past a wall of near-identical buttons before reaching "Weiter."
Fix: group under Mittag/Nachmittag/Abend sub-headers using the existing daypart tokens as accents.
Suggested command: `/impeccable layout`

## Persona Red Flags

**Jordan (confused first-timer)**: reaches step 3 and sees a field labeled only "Name" — no hint whether "Max Mustermann" or "Mustermann, Max" is expected (the backend prefill logic implies the latter). Leaves a field blank, taps "Anlegen," gets three solid-pink fields with zero explanatory text - can't self-diagnose which rule broke.

**Casey (distracted mobile user)**: on a 375-390px viewport, "Weiter" sits after 36 stacked time buttons - a long thumb-scroll before moving forward at all. A one-handed mis-tap on the pax stepper silently drops the already-picked time with no toast; Casey won't notice until confused on step 3 why "Zeit" is empty.

**Riley (deliberate stress tester)**: immediately drives the pax count to 0 via the "−" button, and the interface carries it straight through to the checkout summary in both languages without complaint - exactly the boundary Riley probes first, and it fails immediately.

## Minor Observations

- Offer chip text ("Mittagstisch") is free-text from the offers admin table, not a translation string - it stays German even when the guest switches to English.
- The pax +/- controls are `<a href="javascript:void(0);">` rather than real buttons; they do have `aria-label`s (good) but lack native keyboard Space-activation.
- The `$.fn.nudge` shake animation in `functions.js` animates CSS `right`, but the wizard's inputs have no `position: relative/absolute` set - animating `right` on a statically-positioned element has no visible effect. The only feedback a guest gets on an invalid field is the pink fill (P0 above).
- Reproducible on every load: 20 console `500` errors (confirmed independently, 3 separate loads). Exact failing request not pinned down (network-log eviction), circumstantially tied to the high-volume `web/ajax/realtime.php` polling. Worth a look outside this design pass - possibly `/impeccable audit` or direct server log inspection.
- Date shown as "10.8.27" - confirm this isn't a stray short-year formatting slip; most DE users expect "10.08.2027" or "10.08.27".

## Questions to Consider

1. If the design system already has daypart colors and an offer-highlighting mechanism, why does the *default* time grid still present all 36 slots with zero temporal structure?
2. The submit button is one word away from matching DESIGN.md's own stated intent - what would catch "word choice contradicts our own design doc" at the single most consequential click before it ships?
3. Given pax can hit 0 and time selection can be silently lost on pax change, was party-size editing on step 1 ever tested as something a guest revisits mid-flow, or only as a one-time initial pick?
