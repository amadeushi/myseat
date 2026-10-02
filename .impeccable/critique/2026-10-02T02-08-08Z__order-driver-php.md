---
target: driver.php
total_score: 18
max_score: 40
na_heuristics: 
p0_count: 2
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/order/driver.php"
target_fingerprint: "sha256:57ca6ea8f066e5233e66e84693ac289728c45e756c4ff519e36a81b838107757"
target_path: /Users/hamunhirbod/claude_code/myseat/order/driver.php
timestamp: 2026-10-02T02-08-08Z
slug: order-driver-php
---
Method: dual-agent (A: aed92517180486ffe · B: a4ed96589a2bb1bb8)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | 20s silent poll with no timestamp/countdown; per-button transient states exist |
| 2 | Match System / Real World | 3 | Operationally correct German, but "Link ungültig" points to a backend path the driver can't reach |
| 3 | User Control and Freedom | 2 | No undo, no "all my deliveries today" view, no way out of an armed two-tap state except waiting |
| 4 | Consistency and Standards | 2 | Destructive (Fehlgeschlagen) and success (Zugestellt) share identical pill weight; .dv-section/.dv-big sit off the documented type scale |
| 5 | Error Prevention | 2 | Two-tap arm helps, but nothing blocks the 20s poll from wiping an in-progress fail-reason or armed state |
| 6 | Recognition Rather Than Recall | 3 | All needed info shown on-card, no recall required |
| 7 | Flexibility and Efficiency | 0 | Zero shortcuts, no bulk actions, no sort/filter on the pool — genuinely applicable to a staff tool used 20x/shift, scored, not n/a'd |
| 8 | Aesthetic and Minimalist Design | 3 | Clean single column; the 40px greeting is the one decorative misstep crowding task data |
| 9 | Error Recovery | 0 | "Link ungültig" is a dead end: no phone number, no link, nothing actionable |
| 10 | Help and Documentation | 0 | No help affordance anywhere; nothing explains Pausieren vs. Zurück in den Pool to a new driver |
| Total | | 18/40 | Poor |

Both heuristics 7 and 10 were deliberately scored, not marked n/a — this is a daily-use staff tool, not a Persuade/Experience surface, so both genuinely apply, and both score at the floor.

## Design Specificity Verdict

LLM assessment: Generic delivery-app boilerplate wearing the restaurant's palette. The one place real operational knowledge shows through is the data model — Kassieren as its own box, zone/ZIP/item-count/time on queue cards, the note field ("Hund bellt aber ist lieb") — but the presentation doesn't capitalize on it: a 40px Display-Hero "Hallo Markus" greeting (a scale DESIGN.md reserves for checkout/ETA hero moments) outweighs the actual job-critical information below it. This is a staff tool wearing the guest-shop's warm voice, which DESIGN.md itself says should stay terse and functional for the "Personal" register.

Deterministic scan: impeccable detect --json order/driver.php order/driver.js order/shop.css ran cleanly, exit code 0, zero findings. The detector doesn't catch any of this page's real problems — none of them are the kind of literal-value drift the mechanical scan looks for, except one: .dv-section/.dv-big both compute to exactly 20px, independently confirmed live via getComputedStyle, which sits orphaned between the documented 18px and 22px steps (and the 28/32/40/52px Display-Hero steps don't cover it either). The detector missed this itself — it was caught by Assessment A reading DESIGN.md's Six-Step Rule and confirmed mechanically by Assessment B. No false positives to report, since there were no findings at all.

Visual overlays: no detect.js overlay injection was run — the detector's own zero-finding result means there's nothing it would highlight, and the real issues here are judgment calls, not markup-rule violations. Both agents instead captured direct screenshots at desktop and 375x812 mobile width against a static harness reproducing driver.js's real render output (loading the real shop.css), plus the live production "Link ungültig" state.

## Overall Impression

The data modeling is genuinely good — this page knows what a driver needs to see. But the visual hierarchy actively works against that good thinking: a decorative greeting outranks the address and cash-to-collect, a destructive action is styled as prominently as the primary success action, and the only safety net this page has — the two-tap arm pattern — gets wiped out by its own 20-second poll loop with no guard. The single biggest opportunity is making the UI survive its own refresh cycle without destroying what the driver is in the middle of doing; everything else here is refinement, but that one is a trust problem.

## What's Working

- The cash-to-collect box gets its own card (Kassieren / 32,40 €) — the single most decision-critical number for a driver juggling change gets dedicated real estate instead of being buried in a receipt list. Real, earned design decision.
- Inline tel: and maps-deep-link affordances on the active card ("Route öffnen", "<name> anrufen") correctly anticipate the driver's next two actions after reading an address — no digging through a separate contacts app.
- The two-tap arm pattern (armable()) is a legitimate, lightweight guard against fat-finger taps on a bouncing phone screen, applied consistently across all five committing/destructive actions rather than inconsistently.

## Priority Issues

[P0] Full DOM rebuild on every 20-second poll silently wipes in-progress driver input. refresh() -> render(r) -> root.innerHTML = parts.join('') runs unconditionally every 20s with no diffing and no guard. A driver mid-sentence in #dv-fail-reason, or who has just armed "Zugestellt"/"Fehlgeschlagen" and is still deciding, silently loses that state on the next tick. This exact bug class was already found and fixed this session in three other pages (orders.js, disposition.js, kitchen_screen.js) via a diff-rendering pattern — it's worse here because this driver is alone outside, with no "Entwurf gespeichert" affordance and no visible warning.
Fix: port the same cardNodes/signature diff-rendering pattern used in the other three files; at minimum, skip the rebuild while #dv-fail-form is open or a button is armed.
Command: /impeccable harden

[P0] "Link ungültig" is an unrecoverable dead end — Nielsen #9 scores 0. No phone number, no link, no way to self-serve; the copy sends the driver to a backend admin path ("Einstellungen > Lieferservice") he has no access to. Confirmed live: document.body.scrollHeight === window.innerHeight — there is genuinely nothing else on the page, just two lines of text on black. A driver with a stale bookmark or a device-id typo is simply stuck, holding food, outside.
Fix: add a tel: link to the restaurant's own number (already known server-side via $settings) directly on this screen.
Command: /impeccable harden

[P1] Fehlgeschlagen (destructive) and Zugestellt (success) are visually equal-weight siblings. Computed-style comparison confirms they're identical in every respect except hue: both rgb(26,20,8) text on a solid 999px pill, both min-height: 60px, both font-size: 18px — only the background (rgb(226,134,124) vs rgb(143,191,122)) differs. A driver scanning fast is one misread away from confirming the wrong outcome, and it inverts DESIGN.md's own Ampel philosophy, which treats danger as the sparing exception, not an equal partner to the primary CTA.
Fix: demote Fehlgeschlagen to the .dv-alt outline treatment already used for Pausieren/Zurück, keeping Zugestellt as the sole filled pill.
Command: /impeccable bolder

[P1] Disabled "Starten" is a 60%-opacity multiply on the exact same gold, not a distinct disabled state. Confirmed via computed style: disabled and active buttons share identical background-color: rgb(201,162,89) and color: rgb(26,20,8); the only difference is opacity: 0.6 (.dv-card .cart-go:disabled). The screenshot confirms this is visually perceptible but still reads as a bright, fully gold, fully button-shaped control sitting directly above an identical full-brightness "Annehmen" button — weak in direct sunlight, and the only explanation ("Erst die aktive Lieferung abschließen…") lives in a title attribute that never surfaces on touch.
Fix: swap to a neutral surface-3/muted-border disabled state (no fill) and surface the blocking reason as visible inline text, not a hover-only tooltip.
Command: /impeccable clarify

[P2] Typography drift: a 40px greeting outranks the task, and .dv-section/.dv-big sit off DESIGN.md's documented scale. .st-title ("Hallo Markus") computes to 40px — a Display-Hero value DESIGN.md reserves for checkout/ETA hero moments — while .dv-section/.dv-big both compute to exactly 20px, which matches none of the eight documented steps (12/13/14/15/18/22-26/28/32/40/52). This is drift, not a deliberate step, per DESIGN.md's own Six-Step Rule.
Fix: drop the greeting to Title (18px) or Display (22-26px) so the address/cash information reads as the actual hero; consolidate .dv-section/.dv-big onto 18px (Title).
Command: /impeccable typeset

## Persona Red Flags

Casey (Distracted, One-Handed, Outdoors): The 4-second arm window has zero visual countdown — only a text swap. If Casey glances away and taps again at second 5, the button silently reverts with no explanation; this reads as a bug, not a safety feature. She's also the one most likely to have the fail-reason textarea open when the 20s poll wipes it (P0).

Riley (Stress-Tester): Nothing caps or surfaces the open-pool size — during a dinner rush with 15+ open deliveries, .dv-list just keeps stacking cards with no count, no "showing top N," no sort-by-zone. Riley also hits the dead-end error page with zero recovery path (P0).

Sam (Accessibility-Dependent): Touch targets are genuinely good (52-60px, confirmed via computed style, clears 44x44). But the only signal that "Starten" is locked is a flat opacity drop on an otherwise-identical gold button — no icon, no strikethrough, no texture — a real problem for reduced color/contrast perception, not just bright sunlight.

## Minor Observations

- Desktop-width pill buttons have no max-width cap (.cart-go only sets width: 100%) — they stretch edge-to-edge of the content column even at ~800px, producing oddly elongated shapes. Would reproduce on a phone mounted sideways, not just a desktop browser.
- o.phone.replace(/[^0-9+]/g, '') has no guard for an empty/null phone — the tel: link would silently become just tel: with nothing after it.
- The empty-state copy ("Gerade keine offenen Lieferungen. Diese Seite aktualisiert sich von selbst.") is genuinely good — reassuring and explains the self-refresh.
- role="status" aria-live="polite" on #dv-msg is a correct accessibility touch already in place.
- --text-muted zone/ZIP/time lines looked borderline-low-contrast against near-black in the screenshots; worth an actual WCAG contrast check given this is read outdoors in daylight.
- The fail-form's "Abbrechen" doesn't clear the typed reason — inconsistent with the lack of state-preservation everywhere else, but arguably a small accidental positive.

## Questions to Consider

- What if the driver never browsed the full open pool at all, and the system just surfaced the single next-best delivery by zone/route — does "Offene Lieferungen" as a list solve driver choice at the cost of the thing that actually matters here (speed, fewer taps, less reading while holding food)?
- What if marking a delivery "Fehlgeschlagen" required a quick call to the restaurant to confirm, turning an uncomfortable silent admission into a moment where a human reassures the driver, instead of a solo pill-tap into a textarea?
- What if this page dropped Cormorant Garamond entirely and read as a terse dispatch terminal — would that match DESIGN.md's own stated "Personal/Backend voice: Scanbarkeit, Tempo und Dichte schlagen Atmosphäre" better than the guest-shop's warmth?
