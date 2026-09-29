---
target: web/content/showday.page.php
total_score: 22
max_score: 36
na_heuristics: 10
p0_count: 1
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/web/content/showday.page.php"
target_fingerprint: "sha256:f49e1030cf169a8992419f684274530951f0a4cd4900ab4604e4f944f6d057bf"
target_path: /Users/hamunhirbod/claude_code/myseat/web/content/showday.page.php
timestamp: 2026-09-29T18-57-30Z
slug: web-content-showday-page-php
---
Method: dual-agent (A: design review · B: detector/browser evidence), synthesis + independent spot-verification of every load-bearing claim by the parent session.

All checked core claims confirmed in code: `.send-button` class exists in markup but has zero matching CSS rule anywhere in the codebase; the status dropdown has exactly 6 progression statuses plus "Storniert" in one flat unseparated list (`web/classes/business.class.php:411-441`); every status change triggers `location.reload()` (`web/js/custom.js:407-419`); `#modaltabletrigger` is an empty, tab-reachable link with no accessible name (`web/ajax/modal.inc.php`, confirmed as a programmatic fancybox trigger at `custom.js:422`, but still in the keyboard tab path); the reservation table's card breakpoint sits only at 600px (`web/css/theme-dark.css:1448`) with no intermediate treatment before 820/900px.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Live poll/banner is excellent; a status change gives no visible "saving" state before the full reload |
| 2 | Match System / Real World | 4 | Status vocabulary matches floor language exactly |
| 3 | User Control and Freedom | 2 | No undo after delete; reopening a cancelled reservation is not discoverable |
| 4 | Consistency and Standards | 2 | The delete-confirmation modal breaks entirely from the gold/dark design system |
| 5 | Error Prevention | 1 | Single-vs-all delete buttons are visually identical, no danger color despite a defined `--danger` token |
| 6 | Recognition Rather Than Recall | 3 | Good row hierarchy; the delete modal's "Autor" field has zero context |
| 7 | Flexibility and Efficiency | 2 | No inline edit for pax/table despite an existing AJAX pattern for status |
| 8 | Aesthetic and Minimalist Design | 3 | Row layout is disciplined; the modal inconsistency drags the score down |
| 9 | Error Recovery | 2 | Good `role=alert` pattern in forms; the table-print failure path is a raw `alert()` |
| 10 | Help and Documentation | n/a | Correctly not applicable to an Operate-mode staff tool |

**Total: 22/36 (Acceptable band, 61%)** — nine heuristics scored, #10 excluded as inapplicable.

## Design Specificity Verdict

**Clearly authored for this restaurant's real operational rhythm.** The hourly capacity timeline computes a pax-vs-table bottleneck independently, picking whichever constraint is tighter — a real restaurant-math insight, not a stock chart. The live 20s poll with a one-hour "new" highlight is built for a host who leaves the tab open all service and needs to notice a phone booking land while seating a walk-in. The daypart color strip is deliberately reused as a mobile accent edge, per the code's own comments. But the delete-confirmation modal (`web/ajax/modal.inc.php`) is unstyled legacy Fancybox markup — it reads like a scaffolded generic admin dialog at exactly the most consequential action on the page.

**Deterministic scan**: `impeccable detect --json` across all six reviewed files (`showday.page.php`, `dashboard.page.php`, `reservations_table.inc.php`, `reservation_row_render.inc.php`, `new.inc.php`, `reservation_form.inc.php`) — **0 findings, exit 0**. Clean.

**Browser evidence** (an authenticated session was already present in the shared browser tool, so no login wall was hit): all four measured contrast ratios (status text, guest-name link, timeline label, status badge) came in at 6.5-7.8:1, comfortably passing AAA. A reproducible HTTP 500 fires on every page load: `GET /web/css/%27../images/ajax-loader.gif` — a stray quote character leaking into a CSS `url()` reference. Harmless in effect (the correctly-formed request for the same image also fires and succeeds), but real and consistent. The nav-link focus ring measured as the unmodified browser-default blue (1px), not the documented gold focus treatment.

## Overall Impression

This screen is genuinely built for real restaurant operations - live updates, dual-constraint capacity math, and a deliberately reasoned mobile card layout are all real, considered work. But the one truly destructive action on the entire page - deleting a recurring series - is exactly where the design falls out of the system: unstyled, unwarned, one misclick away from losing an entire series with no undo.

## What's Working

- **Live poll + insertable rows + one-hour "new" highlight**: solves "did I miss a phone booking while seating someone" without forcing a manual refresh habit, and doesn't reload the page to do it.
- **The capacity timeline's dual-constraint model** (pax vs. tables, whichever is tighter): reflects actual restaurant math a generic booking-count bar chart would get wrong.
- **The 600px card-breakpoint rewrite of the table**: reasoned in its own code comments (why the color strip becomes an accent edge) - real responsive craftsmanship, not just `overflow-x: scroll`.

## Priority Issues

**[P0] Undifferentiated single-vs-all delete buttons in the confirmation modal.**
Why it matters: a host cancelling one occurrence of a recurring reservation mid-rush can delete the entire series in one misclick - no visual cue distinguishes the two buttons, no danger coloring despite `--danger` existing in the token set, no undo.
Fix: give "Alle Einträge löschen" the danger-red treatment, keep single-delete as the default, add a plain-language consequence statement ("löscht alle N Termine der Serie"), require the destructive action as a deliberate second step.
Suggested command: `/impeccable harden`, then `/impeccable polish` for the button styling.

**[P1] "Storniert" costs the same click as a routine status change.**
Why it matters: 6 progression statuses plus Storniert sit in one flat dropdown, firing immediate AJAX + a full reload, with zero visual separation for the one truly irreversible option.
Fix: visually separate Storniert (divider, or move it out of the dropdown into the existing delete-icon flow only) and replace the full reload with the same inline-update pattern already built for the live poll.
Suggested command: `/impeccable harden`

**[P1] The table layout has a gap between 600px and 820/900px.**
Why it matters: confirmed live at 797px - the note/table/status cluster visually detaches from the guest's name/time/pax, exactly the tablet width band staff actually use during service.
Fix: extend the card treatment (or an intermediate layout) up to ~820px, matching DESIGN.md's own documented breakpoint anchor.
Suggested command: `/impeccable layout`

**[P2] No inline editing for pax/table from the list itself.**
Why it matters: every pax/table correction requires the full edit form, even though the same AJAX pattern already exists for status changes.
Fix: apply the existing inline-update pattern to the pax and table cells.
Suggested command: `/impeccable optimize`

**[P3] Dead placeholder content shipped in production markup.**
Why it matters: `#cxllist` literally contains "This is a test." - unreachable today, but a latent risk if the trigger wiring ever changes.
Fix: delete the dead block.
Suggested command: `/impeccable distill`

## Persona Red Flags

**Alex (power user, all shift)**: every status change triggers a full page reload - for someone marking 40 tables "Angekommen" over a dinner service, that's 40 reloads instead of the instant inline update the same codebase already built for new-reservation arrivals.

**Riley (deliberate stress tester)**: the delete modal's "Autor" field shows no visible client-side validation; the "Alle löschen" button for a non-recurring reservation is only hidden client-side (`$('#button_al').css('display','none')`) - server-side enforcement independent of that hide was not confirmed in the reviewed code.

**Casey (distracted tablet user, mid-service)**: directly confirmed live at 797px - the note/table/status cluster detaches from the row's identity information at exactly the width a tablet-holding host is likely to be at. The table-print failure path also blocks with a raw `alert()` instead of an inline toast.

## Minor Observations

- Independently confirmed: `#modaltabletrigger` is an empty, tab-reachable link with no accessible name - it's a programmatic Fancybox trigger (`custom.js:422`), but stays in the keyboard/screen-reader tab path regardless.
- A reproducible HTTP 500 fires on every page load from a malformed CSS `url()` reference - harmless (the correct image also loads), but real and consistent; worth a look outside this design pass.
- "CXL" is a hardcoded label rather than a translated string, inconsistent with the page's otherwise-followed localization discipline.
- The waitlist "allow" button has the same reload-instead-of-inline pattern as the status dropdown.

## Questions to Consider

1. If the live-insert pattern already exists for new reservations, why was it never extended to status changes on existing rows?
2. DESIGN.md explicitly defines a `danger` color, and this page has exactly one truly destructive, hard-to-reverse action - what would it take to treat that one button as seriously as the design system treats warning red everywhere else?
3. The screen already logs who booked/cancelled - could that audit trail surface inline next to a reservation about to be deleted, instead of an unexplained "Autor" text field?
