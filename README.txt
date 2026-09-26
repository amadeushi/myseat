=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-=                                       =-=
=-=           mySeat README               =-=
=-=                                       =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-= Version: 2.4.3                         =-=
=-= Date:    25.09.2026                   =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=


mySeat - Restaurant Reservation software.

Beautifully simple restaurant reservations.
Collaborate effortlessly on reservations.
mySeat will help you keep track of your reservations with ease.


News
====

 * Current fork (PHP 8 port, new booking form and backend theme) - http://github.com/amadeushi/myseat
 * Runs on PHP 8.x with mysqli (tested on PHP 8.5, MariaDB 12.3); no database changes since v0.2160
 * New Repo - http://github.com/apmuthu/myseat
 * Get the latest tarball at: https://nodeload.github.com/apmuthu/myseat/tar.gz/master
 * Add Property Vulnerability Workaround - rename and disable web/properties.php when not needed

 
VERSIONING
==========

From v1.0.0 this fork uses Semantic Versioning (MAJOR.MINOR.PATCH). The old 0.2xxx counter
came from the upstream project (mySeat, last seen at v0.2166) and was raised with every small
change; it said nothing about scope. The fork has since grown far beyond it, so the numbering
starts fresh. The 0.2xxx entries below stay as history.

 * MAJOR (X.0.0)  a large theme, or a change that needs action on the server: new required
                  settings in config.general.php, a new cron job, a database step
 * MINOR (1.X.0)  a new feature that needs nothing from you: a new mail, a new backend area
 * PATCH (1.0.X)  a fix or polish, nothing to do

Every release: update $sw_version in web/main_page.php, add a changelog entry here, tag the
commit (git tag vX.Y.Z). Based on mySeat by Bernd Orttenburger and contributors, GPL v3.


CHANGELOG
=========

Versions 0.2161 - 2.4.3 are maintained in http://github.com/amadeushi/myseat.
No manual database update is needed for any of them (the table plan (v0.2171, v0.2172) creates its own
tp_* tables on first use). Optional new settings for
config/config.general.php (defaults apply when missing):
  $settings['lastBookingMinutes'] = 60;   (v0.2165)  last online booking, minutes before closing
  $settings['brandName'] = 'Amadeus';     (v0.2166)  name shown in the backend header and login

2026-09-26 == mySeat v2.4.3 == amadeushi - http://github.com/amadeushi/myseat

 * Backend, new reservation: a confirmation by SMS also works with only a mobile number (the tick box was
   only usable with an email address before). With SMS on, the box "Bestätigung per E-Mail oder SMS senden"
   is ticked by default as soon as it is possible; unticking by hand is respected
 * Cancel link: the short link is created with the Expiry plugin (expiry=clock, age in minutes, ageMod=min) and
   the answer of YOURLS is checked; "Verbindung prüfen" in Einstellungen > SMS-Versand says whether an expiry
   was really set. No post-expiry redirect any more: an expired link is deleted by YOURLS, and the cron
   sms_flush.php asks YOURLS once an hour to prune all expired links (action=prune), so unclicked links do not pile up

2026-09-25 == mySeat v2.4.2 == amadeushi - http://github.com/amadeushi/myseat

 * Booking form: the phone number gets its country code when the guest leaves the field (017622726369 ->
   +49 176 22726369; German/Austrian mobile numbers are grouped, other numbers keep their spacing, 00 -> +).
   While SMS is on, a hint under the field says whether the number is a mobile number (SMS possible) or a
   landline (no SMS)
 * Settings > SMS-Versand: the result of "Verbindung prüfen" and "Test-SMS senden" now shows right below
   the buttons (it appeared above the form, out of sight, since the cancel-link section was added)

2026-09-25 == mySeat v2.4.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: a reservation cancelled online (link in the mail or SMS, api/cancel.php) now gets the status
   "cancelled" (CXL) like a cancellation in the backend; before it moved to the cancelled list but the
   status selector still said "confirmed"
 * Settings > SMS-Versand: the list of last errors shows the SMS text, its length and any non-ASCII
   characters, to find out quickly why the gateway refused a text

2026-09-25 == mySeat v2.4.0 == amadeushi - http://github.com/amadeushi/myseat

 * Cancel link in the SMS: the SMS carries a short link (own YOURLS with the plugin "Expiry") instead of the
   restaurant's phone number; it expires on the morning after the reservation day, with the random 8-character
   keyword nobody can guess. If YOURLS is down the SMS still goes out with the phone number. Settings:
   Einstellungen > SMS-Versand (YOURLS address, signature token stored encrypted, connection test)
 * api/cancel.php: a signed token (t=...) proves the right to cancel, so bookings entered by hand that only
   have a mobile number (or nothing) can be cancelled too; the lookup form now also takes the mobile number
   ("0151 ..." = "+49 151 ..."). The cancellation still needs the confirmation click (POST)
 * SMS for booking confirmation and day-before reminder, through the own SMS gateway (https://sms.amds.at,
   the same one the shift planner uses). OFF by default. web/classes/sms.class.php:
   - confirmation: right after a firm booking (online or in the backend) and when a pending large-party
     request is approved; the reminder: with the day-before reminder run, in addition to the mail. A guest
     with a phone number but no email address now gets the reminder by SMS too
   - only mobile numbers (Germany +49 15x/16x/17x, Austria +43 6xx); landlines are skipped; a leading 0
     means the default country +49
   - texts have up to 160 characters and use only the GSM 03.38 alphabet (the gateway sends 160 instead of 70
     characters then): umlauts and ß are fine, typographic quotes/dashes/"…" are made plain, accents outside
     the set are dropped, emoji and other characters are left out, ^ { } \ [ ~ ] | and € count twice. Long
     restaurant names are shortened first. E.g. "Amadeus: Deine Reservierung ist bestätigt! Fr 27.11. um 18:30
     Uhr, 4 Personen. Buchungsnummer VnZClq. Fragen oder Absage: 05121 69816060. Bis bald!" (147 characters);
     the reservations on the tp_mail_optout list get no SMS either
   - queue tp_sms_outbox (created automatically): the gateway takes 10 new jobs per minute and can be down
     for a moment, so an SMS that is not accepted stays queued and is retried with growing gaps and the
     same Idempotency-Key (never twice), dropped after 8 attempts or 6 hours; a wrong key (401) is not
     retried. The same SMS is never queued twice per reservation. Finished entries are deleted after 30 days
   - the booking form shows one line under the phone field ("Mit einer Mobilnummer ...") only while SMS is on
 * Settings tab "SMS-Versand" (?p=6&q=9, needs the right for general settings): switch SMS on/off, enter the
   gateway key, remove it, "Verbindung prüfen" (tests gateway and key, sends nothing), "Test-SMS senden",
   counters and the last errors. The key is stored ENCRYPTED (AES-256-GCM, secret derived from the database
   login in config.general.php, table tp_sms_settings), is never shown again (only the last 4 characters)
   and never logged; a key entered there wins over $settings['smsApiKey'] in config.general.php.
   The gateway is reachable from the hosting server (checked). To switch it on: (1) on the SMS Pi create a
   key of its own: sudo sms-project create-project myseat 100; (2) paste it in the settings tab, tick
   "SMS-Versand aktivieren", save, test with "Test-SMS senden"; (3) add a webcron job every minute:
   https://<domain>/web/cron/sms_flush.php?key=<feedbackCronKey> (retries; with &health=1 it only tests
   connection and key). Optional in config.general.php: smsBaseUrl, smsDefaultCountryCode. The privacy
   policy should mention SMS to the guest's mobile number for the reservation

2026-09-25 == mySeat v2.3.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix offer dialog in the widget: "Schließen" did nothing because the dialog sat inside the booking
   form (a form within a form is ignored, the button then acted on the booking form). It is now a
   plain button, and the dialog is moved out of the form when opened. It carries its own colors and
   fonts, since outside the widget container it lost its background and used a system font
 * Fix offer chip in the widget: the old system styles put a white text shadow on buttons, which read as
   a white outline around the title and time on the dark background. Removed for the chip

2026-09-25 == mySeat v2.3.0 == amadeushi - http://github.com/amadeushi/myseat

 * Offer times ("Angebotszeiten"): new tab in the settings (?p=6&q=8). An offer has weekdays, a time
   range (or all day), an optional date range, a title (emoji allowed, quick-pick buttons), an
   optional text (links only with http:// or https://) and an optional picture (JPG/PNG/WebP/GIF,
   up to 2 MB, stored in uploads/offers/). In the booking widget the matching time slots are
   highlighted and a chip above them shows the title and the time range; "Mehr erfahren" opens a
   dialog with text and picture. An offer can be switched off without deleting it. Table tp_offers
   (utf8mb4, created automatically), saving by web/ajax/save_offer.php. The end time of an offer
   counts as part of it (12:00 - 14:45 highlights 14:45 too). Applies to the radio-style time picker

2026-09-25 == mySeat v2.2.0 == amadeushi - http://github.com/amadeushi/myseat

 * Booking source tag: a link like https://reservierung.amds.at/api/reserve.php?outletID=1&quelle=google
   stores "google" as the source of the booking (reservation_referer), instead of the referring
   website. Only letters, digits, - and _ are kept (30 characters). Without the parameter the
   referring host is stored as before. The source is now shown in the reservation details
   ("Herkunft") and counted in the statistics. Meant for the reservation link in the Google business
   profile, newsletters, QR codes etc.

2026-09-25 == mySeat v2.1.1 == amadeushi - http://github.com/amadeushi/myseat

 * New table tp_mail_optout (created automatically): reservations listed there get neither the
   day-before reminder nor the feedback request. Used for the Resmio import: 59 upcoming bookings
   (25.09. - 30.12.2026) were imported from a Resmio CSV export, marked in reservation_referer as
   "resmio-import:<Resmio booking number> (<source>)" and in the change history as "Resmio-Import".
   Cancelled bookings and one duplicate were skipped. The reservations of the first two days
   (25.09. and 26.09.) are on the opt-out list because Resmio still mails those guests; all others
   are treated like any other reservation. To release the opt-out later:
   DELETE FROM tp_mail_optout WHERE reason LIKE 'resmio-import%'; To undo the whole import:
   delete the reservations whose reservation_referer starts with 'resmio-import:' (and their rows in
   tp_reservation_tables, res_history, tp_mail_optout)

2026-09-25 == mySeat v2.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * Group pre-order: new button "Einladung erneut senden" in the reservation details sends the
   guest the same invitation once more (same links, no new group), for a lost mail. If creating a
   group finds one from an earlier attempt whose invitation never went out (for example because
   Gmail failed), mySeat sends it right away. n8n now remembers whether an invitation was sent
   (new interface action resend_invitation). Nothing to do on the server.

2026-09-25 == mySeat v2.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * ACTION NEEDED: new setting $settings['groupOrderApiKey'] in config/config.general.php on the
   server. The n8n workflow "Gruppenbestellung" no longer lets anyone create groups: mySeat now
   uses its machine interface with the header X-Api-Key (n8n only keeps the key's SHA-256) and
   gets JSON back instead of a page. Without the key the block "Gruppenbestellung" reports that it
   is missing and creates nothing. The key belongs only in the server's copy of the file, not in git.
 * Group pre-order: the reservation details now show the participant link and the confidential
   organizer link the guest received. The guest's name is used for the greeting of the invitation;
   booking number and reservation id go along, so n8n never creates a second group for the same
   reservation (a retry after a lost answer links the existing group and sends no second mail).
 * Group pre-order: when a reservation with a group is moved to another date or time, the group
   moves with it; the order deadline moves by the same number of days and keeps its time of day.
   Groups created with v1.1.0 have no stored link and are not moved. tp_group_orders gets the
   columns group_token, participant_url and organizer_url automatically on first use.
 * n8n side (not part of this repo): the create form of the webhook only opens from the team's
   management link, and the invitation and the finished list for the restaurant now use the
   wording and layout of the mySeat mails; replies to the finished list go to the organizer.

2026-09-25 == mySeat v1.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * Group pre-order from the reservation details: the new block "Gruppenbestellung" (right column,
   under the email) creates a group in the n8n workflow "Gruppenbestellung" for this reservation.
   The guest becomes the organizer and receives the participant link and the confidential organizer
   link by mail; the pickup time is the reservation's date and time. Available for every reservation
   with an email address, whatever the party size. When creating, staff are asked whether to set an
   order deadline (suggested: three days before at noon; must lie before the visit and in the
   future). A reservation gets only one group (table tp_group_orders, created automatically); the
   block then shows when it was created. The block is hidden for cancelled reservations and for
   visits that are already over (unless a group exists). web/ajax/group_order.php calls the webhook
   https://n8n.amds.at/webhook/gruppenbestellung; another address can be set with
   $settings['groupOrderUrl'] in config.general.php. The webhook itself is public: anyone who knows
   the address can create groups and trigger mails

2026-09-25 == mySeat v1.0.6 == amadeushi - http://github.com/amadeushi/myseat

 * SECURITY: the server had no .htaccess at all and a full .git folder (source code and history)
   was downloadable from the web root. Added .htaccess files: no directory listings and no .git
   or README.txt over the web (root); config/, plugins/, web/classes/, web/includes/ and install/
   are closed to browsers, only PHP includes them; uploads/ can no longer execute scripts; the
   root also sets X-Content-Type-Options and Referrer-Policy. The widget is unaffected (no
   X-Frame-Options, it may be embedded). To run the installer or updater again, temporarily
   remove install/.htaccess. On the server tmp_deploy/ (old deploy leftovers) is blocked too and
   can be deleted, as can the server's own .git folder (deployment is by copy, not by git)

2026-09-25 == mySeat v1.0.5 == amadeushi - http://github.com/amadeushi/myseat

 * SECURITY: web/properties.php could be opened without logging in (it granted every visitor a
   valid session and only turned people away when a non-admin was logged in). It now needs a real
   login as admin (role 1 or 2); only a fresh installation without any admin can still open it
 * SECURITY: these backend AJAX endpoints worked without any login and handed out guest data
   (name, email, phone) or changed data: activate_user, autocomplete, autocomplete_res,
   check_password, check_username, cxllist, delete, guest_detail, inline_edit, modify_entry,
   modify_plugins, process_reservation, realtime. All now answer 403 without a backend session
   (web/includes/require_login.inc.php). The guest widget (api/) and the user activation link
   (web/confirm.php) stay public on purpose
 * SECURITY: the login cookie was read with a plain unserialize() (PHP object injection risk). It
   is now read by flexibleAccess::cookie_data() in PLC/plc.class.php: no objects allowed, only the
   expected scalar fields, anything else counts as "not logged in". The cookie is now set with
   HttpOnly, SameSite=Lax and Secure (on HTTPS), is deleted with the same path on logout, and the
   session key comes from random_bytes() instead of uniqid()/rand(). Existing logins keep working
 * Backend property page (?p=6&q=5): removed the embedded Google static map (it showed a broken image,
   since Google answers 403 without an API key, and every visit contacted Google). Also fixed the
   mis-nested <p><strong> tags on that page
 * Plugin cleanup: removed the unused plugins email_send (predecessor of the booking mails) and
   debug_session, the dead second hook list web/includes/plugins.init.php and the debug_online call
   in the widget; installer/updater now only register local_email_send. The real hook list stays
   in config/plugins.init.php, now with a note where each hook fires

2026-09-24 == mySeat v1.0.4 == amadeushi - http://github.com/amadeushi/myseat

 * Booking widget: a single closed day set in the backend (day details, "Ruhetag") now shows
   "An diesem Tag haben wir geschlossen" like a weekly closing day, instead of the misleading
   "everything is booked". A day marked open there also opens a normally closed weekday for the
   time selection. Remember: the day setting applies to that one date only; recurring closing
   days belong in the outlet settings
 * Backend day details reworked: comment, extra seats/tables, passerby limit and the single-day
   "Ruhetag" are saved together with the Save button, by AJAX (web/ajax/save_maitre.php), with the
   result shown next to the button. After a successful save the panel closes and a short
   confirmation appears on the day. Before, the closed-day checkbox saved on every click and
   reloaded the page, always stored 'OFF' (so a closed day never got set) and could create
   duplicate rows. Now: one row per outlet and date, a plain comment is swapped in without
   reloading, and the page only reloads when the day list changes, i.e. closed
   day or capacity. Setting a closed day warns if reservations exist for that day. Removed
   web/ajax/modify_dayoff.php

2026-09-24 == mySeat v1.0.3 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: saving the day details in the backend (day comment, extra seats/tables, passerby limit)
   ended on a blank page and stored nothing when "extra seats" was left empty (PHP 8 TypeError in
   abs() in writeForm). An empty or non-numeric value now counts as 0

2026-09-24 == mySeat v1.0.2 == amadeushi - http://github.com/amadeushi/myseat

 * Imprint and privacy links (from $settings['imprintUrl'] / $settings['privacyUrl'], the same
   ones the mails use) now sit under every guest page: booking form, confirmation, cancel page
   and feedback page (api/legal_footer.php). Nothing is shown when a setting is empty
 * Widget notices (closed day, online reservations blocked, group too big, fully booked) reworked:
   a clear headline, a line saying what the guest can do, readable type, and buttons to mail or
   call (number from $settings['mailPhone'] or the property record). No buttons on a closed day.
   Waitlist and error texts now use the informal "du" like the rest
 * Backend day list: assigned tables show as small chips (with many tables only the first two plus
   "+N", full list in the tooltip) instead of one long line that pushed status and buttons out of
   the row. The hint boxes above the list (day comment such as "keine Passanten einbuchen",
   online block, events) share one layout; the passerby warning is one compact box listing all
   times instead of one bright yellow line per time slot

2026-09-24 == mySeat v1.0.1 == amadeushi - http://github.com/amadeushi/myseat

 * Backend search finds partial matches anywhere in the name, booking number, phone number or
   email (e.g. "wald" finds "Anja Finsterwalder"). Several words all have to match, in any
   order ("finster anja"). Before, it only matched the beginning of the name

2026-09-24 == mySeat v1.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * First release under the new versioning scheme (see VERSIONING above). Content: everything up to
   and including v0.2204. Server actions since the old counter's last big steps: the two webcron
   jobs (feedback requests, day-before reminders, same key $settings['feedbackCronKey'])

2026-09-24 == mySeat v0.2204 == amadeushi - http://github.com/amadeushi/myseat

 * Backend datepicker: the "Heute" button now always opens the real current day (it used to jump to
   the selected date because of gotoCurrent); the date comes from the device clock

2026-09-24 == mySeat v0.2203 == amadeushi - http://github.com/amadeushi/myseat

 * The notification mail to the restaurant is now a proper HTML mail (plain-text fallback kept):
   badge "Neue Reservierung" or "Entscheidung nötig", date/time/guests at a glance, guest contact
   as tap-to-call and tap-to-mail links, the guest's note highlighted, and Reply-To set to the guest
   so a reply goes straight to them
 * For a large-party request the mail carries the button "Anfrage ansehen & entscheiden". It opens
   api/request.php, a signed page (HMAC token, no login needed) that shows the request and offers
   Bestätigen / Ablehnen; the decision is a POST, so mail scanners that open links cannot trigger
   it, and the guest gets the matching mail. Every mail also links to the day in the backend
 * Guest mails reordered by importance: reservation details and the cancel link first, then the
   food and drinks menus as two buttons (drinks now https://amds.at/drinks), the arrival info last
   and more compact, with a link to the Hildesheim bus timetable (Fahrplanauskunft)
 * Approve/decline logic and the guest decision mail moved from web/ajax/modify_status.php into
   web/classes/approval.class.php, shared with the new page

2026-09-24 == mySeat v0.2202 == amadeushi - http://github.com/amadeushi/myseat

 * Guest mails rewritten in a warmer, more personal tone, signed "Hamun vom Amadeus-Team"
   (optional $settings['mailSignName'] in config.general.php). The table confirmation now carries
   a "So kommst du gut an" block: bus, parking, accessibility (ramp on request) and links to the
   food and drinks menus. New mail variant "approved" for a request that staff confirmed
   ("Gute Nachrichten ..."); request received and decline mails reworded (decline offers to find
   another date). Feedback request and staff reply mails invite honest criticism instead of just
   praise
 * New "see you tomorrow" reminder mail (arrival, parking, menus) the day before a reservation, sent
   between 10:00 and 20:00, never twice (table tp_reminders, created automatically). Skips cancelled,
   no-show, waitlisted and undecided/declined requests, and bookings made less than 18 hours
   earlier. Needs a second webcron job, same key as the feedback cron, every 30-60 minutes:
   web/cron/send_reminders.php?key=<feedbackCronKey>
 * Feedback form: after 4-5 stars TripAdvisor is offered first (filled button), Google second;
   after 1-3 stars the guest gets a direct "write to us" mail link instead of a review push
 * Reservation requests for large parties (outlet setting "approval_pax_threshold", 0 = off):
   requests show up in the normal list as "Unbestätigt"; picking "Bestätigt" approves (confirmation
   mail, table assignment), picking "Storniert" declines (decline mail) and can be undone from the
   cancelled list. "Storniert" is now stored as status CXL for every cancelled or deleted
   reservation, so the cancelled list no longer shows a stale "Bestätigt"
 * Public reviews page paginated (50 per page), average always over all reviews; the site root and
   /web/ redirect to the guest booking form (outlet 1) instead of the backend login
 * Cormorant Garamond and Raleway are self-hosted in web/fonts/ - no request to Google servers
 * modify_status.php now checks login and the Reservation-Edit right

2026-09-23 == mySeat v0.2201 == amadeushi - http://github.com/amadeushi/myseat

 * New guest feedback/review feature: 24-96h after a reservation took place (not cancelled or a
   no-show) and only when a guest email is on file, a webcron-triggered mail asks the guest to
   rate Speisen & Getränke and Service (1-5 stars each, overall computed as their rounded average).
   A rating of 4 or 5 stars asks the guest to also leave a public review on Google or TripAdvisor
   (links configured per outlet); lower ratings stay in-house. The guest form also asks for
   explicit consent to display the review publicly - without that consent staff cannot publish it,
   enforced at the database level, not just in the UI
 * New backend "Feedback" tab (Page-Feedback capability): date-range filter, average rating with a
   1-5 star distribution chart, per-category averages, and a list of individual reviews where staff
   can reply (the reply is emailed to the guest) and, only once the guest has consented, mark a
   review as publicly visible
 * New public reviews page (api/reviews.php) and an embeddable widget (api/reviews_widget.php, for
   dropping into the restaurant's own website via an iframe) that list every review that both the
   guest (consent) and the restaurant (publish toggle) agreed to show, including the restaurant's
   reply
 * New outlet settings fields for the Google Places and TripAdvisor review page URLs used above
 * Needs a webcron job (this host has no shell crontab) hitting
   web/cron/send_feedback_requests.php?key=<see file> every 30-60 minutes for requests to actually
   go out
 * No manual database update needed - the new tp_feedback table and the outlets/reservations
   columns it depends on are created/migrated automatically on first use
 * Existing resmio guest feedback (1718 historical reviews, 2019-2026) imported into the new
   tp_feedback table and published on the new public reviews page/widget; no guest name or email
   is available from that source, so these show as "Verifizierter Gast" and cannot receive a
   mailed staff reply

2026-09-23 == mySeat v0.2200 == amadeushi - http://github.com/amadeushi/myseat

 * The guest booking form (api/reserve.php) now actually detects a non-German browser and shows
   the English form by default - the existing code compared the browser's whole raw
   Accept-Language header (e.g. "en-US,en;q=0.9,de;q=0.8") against the literal string "en", which
   a real browser's header is never equal to, so the auto-detection never fired in practice. It
   now reads only the first, highest-priority language subtag and falls back to English for any
   browser language other than German (the form only has these two)
 * Fix: once the language was set for the session, a later step of the same booking (choosing a
   table, entering the name) could still send the confirmation mail in German regardless, because
   the "email_type" field the guest mail's language is based on was taken from a request-local
   variable that resets to the German default on every request instead of the session's own,
   already-decided language

2026-09-23 == mySeat v0.2199 == amadeushi - http://github.com/amadeushi/myseat

 * Booking mails (web/classes/booking_mail.class.php, plugins/local_email_send.plugin.php): the
   guest confirmation now comes with a calendar invite (.ics) attached - date, time (using the
   outlet's average stay as the end time), location (property address) and, in the description,
   the booking number, the one-click cancel link and the restaurant's website. Works for both the
   native mail() path (multipart/mixed around the existing plain/HTML alternative) and the
   PHPMailer/SMTP path (AddStringAttachment). The restaurant's own notification mail is unchanged

2026-09-23 == mySeat v0.2198 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the "+Neu" tab (day view) had a glowing box-shadow ring around it, meant to call attention
   to it as the primary action; but the tab strip's tabs sit flush against each other with shared
   borders, so the ring visibly bled onto the two neighbouring tabs instead of framing "+Neu" on
   its own - it does not look like a highlight there, it looks broken. The ring is gone, the gold
   fill and bold weight already make it stand out on their own

2026-09-23 == mySeat v0.2197 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the staff-member field next to Save (added in v0.2196) had two problems - on desktop
   "justify-content: space-between" stretched it away from the button, leaving a wide empty gap
   between them; on the phone it was pulled into the same sticky bottom bar as the Save button,
   so a much taller block stayed permanently pinned over the bottom of the screen while scrolling
   through the rest of the form (name, phone, notes, tables), hiding content behind it. The field
   now sits directly next to Save on desktop with no gap, and on the phone only the Save button
   itself is sticky - the staff field is a normal block right above it, scrolling with the rest of
   the form

2026-09-23 == mySeat v0.2196 == amadeushi - http://github.com/amadeushi/myseat

 * The staff member field moves once more: not next to date/time/guests but right next to the Save
   button - naming who is entering the reservation is the last thing you do before saving it, not
   something that belongs with the booking's own facts at the top of the form

2026-09-23 == mySeat v0.2195 == amadeushi - http://github.com/amadeushi/myseat

 * The staff member field (who took the booking) moves out of the collapsed "Details" section into
   the main part of the reservation form (new and edit), next to date/time/guests - no need to open
   Details to see or set it
 * Fix: the recurring-reservation row ("Serienreservierung") - "Wiederholen bis" - had an 18px-tall
   Bootstrap-era label box (a leftover from before the dark redesign) that squeezed its own text
   onto two lines and, on the phone, an unused empty element sitting in the middle of the row;
   the label is now a proper pill matching the rest of the form, the dead element is gone, and the
   whole row gets the full width of the Details grid instead of sharing half of it with the
   checkbox above it

2026-09-23 == mySeat v0.2194 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the outlet (and reservation) detail/edit pages - Objekt/Name/Kuechenrichtung/Beschreibung
   next to Saison/Ruhetag/Oeffnungszeit/Pause - are a fixed 47%+47% two-column layout with a
   450px-wide input for the online booking links. On the phone the two floated columns did not fit
   side by side, so the right column visually climbed up next to the long description text on the
   left instead of following underneath it, and the page overflowed sideways on top of that. Both
   columns now stack full-width, one after another, on a narrow screen

2026-09-23 == mySeat v0.2193 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the reservation-list "screen scroll" rule from v0.2190 unintentionally un-hid a print-only
   table (blank manual-entry lines at the bottom of the day view) on the phone - excluded it
 * Fix: the reservation cards opened with an empty box before the first card (the list's own empty
   spacer row, invisible as a table row, was rendered as an empty card)
 * Fix: the "recent reservations" card had its own fixed 450px desktop width instead of following
   the width of the other cards, sticking out on a narrow screen
 * The tab row (Reservierungen/+Neu/Storniert, Outlet/Benutzer/..., Erdgeschoss/Obergeschoss...) is
   a strip of flush, flat-bottomed tabs on desktop, made to visually merge into the box it opens
   right underneath; once wrapped onto several lines on the phone that illusion looked like
   floating, clipped rectangles instead. It is now a row of separate rounded pill buttons on the
   phone, the same pattern already used for the table-plan's own area tabs

2026-09-23 == mySeat v0.2192 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation list on the phone (dashboard and the day view) now reads as a stack of cards
   instead of a 9-column table that needed sideways scrolling to reach the status dropdown or the
   edit/delete icons. Each reservation becomes one self-contained card: time + party size, guest
   name, table, status, who booked it and the actions - in the table's own row order, so the
   reading order for screen readers matches what is shown. The shift colour code (morning /
   afternoon / evening) that already marks each row becomes the card's left edge accent, the same
   colour language the table-plan reservation cards already use
 * The settings/dashboard tab row (Outlet/Benutzer/..., Erdgeschoss/Obergeschoss/...) now wraps via
   flexbox instead of relying on floats plus a manual clearfix, so it cannot start overlapping
   again just because a page happens to omit the clearfix

2026-09-22 == mySeat v0.2191 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: on the phone, a card's header (date navigation, page title) had a fixed 30px height with
   floated children (desktop layout); past a certain content width the floated buttons ("Online
   sperren"/"Zurueck" on the dashboard, "Aktiv"/"InAktiv"/"Anlegen"/"Zurueck" in the settings, ...)
   spilled out past that 30px box and overlapped the date field or the table underneath. The header
   is now an auto-height row on the phone, and its action buttons always get their own full-width
   row below the title/date-nav instead of trying to share a line with it

2026-09-22 == mySeat v0.2190 == amadeushi - http://github.com/amadeushi/myseat

 * Backend responsive on the phone: a table wider than the screen (reservation lists, weekly
   occupancy, outlet list, statistics numbers, ...) now scrolls inside itself instead of dragging
   the whole page sideways, so status dropdowns and the edit/delete icons at its right edge stay
   reachable without first scrolling the page back and forth to find them
 * Bigger tap targets on the phone: nav links, the Outlet dropdown, the settings tabs, the
   edit/delete icons in a reservation row and the status dropdown all get a larger tappable area
   (the icons themselves keep their size)
 * Form fields (including the status dropdown) are 16px on the phone so iOS no longer zooms the
   whole page in when a field gets focus
 * The guest-search field gets an aria-label in addition to its title, for screen readers

2026-09-22 == mySeat v0.2189 == amadeushi - http://github.com/amadeushi/myseat

 * Backend login (PLC/index.php) rebuilt as a responsive page in the dark/gold look: a centred card
   that fits phones (16px inputs, no zoom on iOS, safe-area aware) up to desktop, proper labels,
   autocomplete hints for password managers, visible focus, DE/EN switch (?lang=de|en, default = browser
   language, German otherwise)
 * New welcome text ("Willkommen zurück - Melde dich an, um deine Reservierungen zu verwalten." /
   "Welcome back - Sign in to manage your reservations."), all messages (wrong login with the attempts
   left, blocked, password changed) are German or English instead of English only
 * Fix: the entered user name was written into the form unescaped (XSS); it is escaped now

2026-09-22 == mySeat v0.2188 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation lists (dashboard, day view): the guest type (Hausgast / Passant / Walk-in) is no longer
   shown, and a missing salutation prints nothing instead of "--"
 * The table column no longer breaks "Tisch 128" into two lines: it is as wide as its content (and
   never narrower than 150px), the fixed 20% / 30% widths of the name and note columns are gone so the
   table fits the page and every entry stays on one line

2026-09-21 == mySeat v0.2187 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: a floor that is deep rather than wide is shown zoomed onto its tables (bounding box
   of the tables plus margin, at most 1.2 screens tall and 1.4x) instead of the fixed 1200x700
   canvas, so the tables get bigger on screen. Editing and closed floors still show the whole canvas
 * Table labels no longer wrap: the name stays on one line and name, seats and the occupancy line
   scale with the size of the table (CSS container units), so small tables with a booking stay legible

2026-09-21 == mySeat v0.2186 == amadeushi - http://github.com/amadeushi/myseat

 * Booking mails rewritten (web/classes/booking_mail.class.php, used by the active plugin
   local_email_send): friendly "du" text in German and English (English when the guest used the
   English form), subject with weekday, date and time, a clear reservation box (date, time, guests,
   booking number, the guest's note), one-click cancel link, contact line, a real sign-off. No images
   (no logo, dividers or background images) and no attachments (the old code tried to attach
   /data/*.pdf menus that do not exist). The notification mail for the restaurant has a useful
   subject ("Neue Reservierung: name, guests, date, time") and readable lines
 * Legal footer of the mail from config/config.general.php: $settings['mailLegal'] (imprint lines),
   ['imprintUrl'], ['privacyUrl'], ['mailPhone']; empty = property data of the system. Filled for
   Amadeus from https://www.amadeus-hildesheim.de/impressum.html
 * Technical: the text part contained HTML (<br />, &auml;) - now proper plain text; subject and
   sender name are RFC 2047 encoded (umlauts in the subject were sent raw), both parts are UTF-8
   base64; values of the forms are cleaned of SQL/HTML escaping (backslashes, \n, entities); a mail
   error can no longer break a booking; the salutation by title ("Sehr geehrter Herr ...") is gone
   in the mail

2026-09-21 == mySeat v0.2185 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form: the title ("Anrede") is no longer asked. The confirmation mails (both
   mail plugins) greet with "Guten Tag <name>" / "Hello <name>" when there is no title; before,
   every guest without a title got "Sehr geehrter Herr ...". Existing titles still work
 * Online booking form: the arrow icon behind the consent text is gone. The consent text links to
   the terms / privacy page of the restaurant when $settings['termsLink'] is set in
   config/config.general.php (full https address, opens in a new tab); without it the text has no
   link. Before, the link was hard-coded to the terms of the original mySeat project
   (myseat.us/terms.htm)

2026-09-21 == mySeat v0.2184 == amadeushi - http://github.com/amadeushi/myseat

 * New reservation form (backend) redesigned and simplified: date, time, guests, name, phone,
   email, note and a table picker on one page; everything else sits under "Details" (advertising
   consent, staff member - prefilled with the logged in user -, recurring booking). The title
   ("Anrede") is no longer asked. Email: the confirmation mail is an opt-in checkbox ("Bestätigung
   per E-Mail senden", off by default, only active with a valid address); there is no choice of
   language any more, the mail goes out in the local language (German). The address is checked
   in the browser and on the server. Guest type (house guest / passer-by / walk-in) is no longer asked (stored
   as PASS, like the online form). The old fields address, postcode/city, discount ("GdH"),
   parking, paid and paid by are gone from the new and the edit form; their data in old
   reservations is kept and shown in the detail view only when a reservation has values there
 * Phone: "Telefon/Zimmer" is now "Telefon" and is checked (digits, + ( ) - / . and spaces,
   6 - 15 digits) in the browser and again on the server; empty is allowed
 * Table picker: chips of the tables of the table plan for the chosen day and time, with
   seats, area filter, "available / all", closed areas and taken tables marked; several tables
   per reservation; tables that fit the group are outlined, the automatic suggestion is dashed;
   the message under the chips warns about too few seats or a taken table (a manual choice may
   overrule it, like in the table plan). Without a choice a new reservation is placed
   automatically. The edit form has the same picker with the current tables preselected;
   removing all tables there clears the assignment. For a recurring booking only the first day
   gets the chosen tables, the others are placed automatically
 * Responsive: the form is a grid that goes to one column on phones with a fixed save bar, and
   the backend top bar and page container follow the window width below 940px
 * Security: ajax/process_reservation.php only writes known reservation columns (form field
   names were used as column names before); tp.php lets the "new reservation" right read the
   table list (action free_tables)

2026-09-21 == mySeat v0.2183 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan, linking tables: the "Verbinden" mode of the editor now works as a chain - click
   the tables in the order they stand (131, 132, 133, 134, 135): every click links the table to
   the one before and continues from there (before, the first table stayed the starting point,
   so this created a star around it, not a chain). Linking again removes the link, a click on the
   current starting table ends the chain
 * Automatic assignment: groups of up to 6 linked tables (was 4, so 5 chained tables of 4 could
   not seat 20 guests). The search lists every connected group exactly once (it repeated the
   same combinations before and could stop at its limit with larger groups); a chain counts as
   connected in any direction. Least waste first, then fewest tables

2026-09-21 == mySeat v0.2182 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation status dropdown (dashboard and day view): each status has a vector icon and its
   own colour, in the closed field and in the list, with a tick at the current status:
   Bestätigt (calendar, blue), Angekommen (pin, green), Platziert (seated guest, purple),
   An der Bar (glass, amber), Fertig (check, grey), No-Show (dashed guest, slate). The texts
   were "NYA / Angekommen / Platziert / an Bar / Gegangen / No Show" (German) and are changed in
   the language files (de and en); the stored values (NYA, ARR, STD, PKD, DEP, NSW) and the
   change handler are untouched. Browsers with customizable selects (Chrome 135+) show the
   icons and colours in the list, other browsers show the coloured texts in their native list

2026-09-21 == mySeat v0.2181 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: occupied tables are highlighted for the whole day. Gold fill = one reservation,
   gold with a double ring and "2x" badge = several reservations one after another, red with a
   warning badge = overlap (double booking within the stay). Every table with reservations shows
   a strip over the opening hours with one bar per reservation (red where they overlap; times
   outside the opening hours stay visible at the edge), the tooltip lists all reservations of
   the table. Small tables show one compact line (the times when there are several). A legend
   sits above the plan. The selected-reservation view (fits / too small / taken) works together
   with the strips. The day data of tp.php now carries the opening hours

2026-09-21 == mySeat v0.2180 == amadeushi - http://github.com/amadeushi/myseat

 * Shift limits: noon shift 12:00 - 16:00 (sun), from 16:00 evening shift (moon); config values
   $daylight_noon = '12:00' and $daylight_evening = '16:00' in config/config.general.php (were
   14:00 / 18:00 - please adjust the file of your installation). Times after midnight of an
   outlet that closes after midnight count as evening (a 00:00 reservation was counted as
   noon before), exactly 18:00 was counted differently in the week view and in the list.
   The week view sums are computed with one rule (daytimeKind() / daytimeSums())
 * The colour marker at the start of a reservation row is distinguishable now: noon bright
   gold, evening dark bronze (another rule overrode both with the same colour)
 * All remaining pixel icons of the backend are vector icons (uiIcon() in
   web/classes/business.class.php): table, edit, delete, recurring, allow, notices (info,
   warning, error, success, special event), logout, user, dashboard view switch, mail
   (advertise yes/no), outlet help "i", plugins play/pause, user enable/disable, list arrows.
   They follow the theme colours (gold on hover, red for delete) and are sharp at any size.
   Not changed: the login-adjacent pages confirm.php and register/success.inc.php

2026-09-21 == mySeat v0.2179 == amadeushi - http://github.com/amadeushi/myseat

 * Dashboard week view: the sun / moon symbols in front of the noon and evening numbers were
   10px raster images (blurry, white, out of proportion); now crisp vector icons in the gold of
   the theme, aligned with the numbers (daytimeIcon() in web/classes/business.class.php)
 * Reservation list: the clock symbol next to the guest name (booking older than "old days")
   is a vector icon too, muted grey with the tooltip kept

2026-09-21 == mySeat v0.2178 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form in English: the texts that were hard-coded in German are translated
   (subtitle, opening hours block with weekday names, "Closed", step titles, Next/Back, "please
   select a time", the notices for closed days / online block / large groups / no tables left,
   the whole confirmation, waiting-list and error page). They come from bt() in
   api/business.class.php (German and English; other languages fall back to English, like
   cancel.php). Emails and the cancel page were already bilingual

2026-09-21 == mySeat v0.2177 == amadeushi - http://github.com/amadeushi/myseat

 * Backend: the "Änderungen" history dropdown in the reservation detail was a white box (legacy
   .option / .option_xl widgets = white background image with an invisible select on top, black
   text); now a dark field with a gold arrow
 * Backend: dropdown lists are drawn by the page in browsers with customizable selects
   (appearance: base-select, e.g. Chrome 135+): dark list, gold highlight for the current
   choice, gold arrow - the native macOS list stayed white despite color-scheme: dark. Other
   browsers keep the native list

2026-09-21 == mySeat v0.2176 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation lists (day view, dashboard, short view): the "table" column now shows the tables
   assigned in the table plan (e.g. "Tisch 4 + Tisch 5", link to the plan of the day). It used
   to show only the old free text field, so plan assignments were invisible there. Without an
   assignment the free text stays as before and can still be edited inline

2026-09-21 == mySeat v0.2175 == amadeushi - http://github.com/amadeushi/myseat

 * Datepicker (online form on mobile and backend): the cell of today and the hovered cell had a
   light grey background from the old jQuery UI styles - white frame / light on light text;
   now transparent like the other days
 * Backend: the open list of a dropdown (time, title, type ... in the edit form) was drawn in
   the browser's light style; the dark theme now declares color-scheme: dark

2026-09-21 == mySeat v0.2174 == amadeushi - http://github.com/amadeushi/myseat

 * Online availability by table plan (switch in the plan editor, box "Einstellung": "Online-
   Verfügbarkeit: Nach Zählung (bisher) / Nach Tischplan"; default stays the old counter logic).
   With "Nach Tischplan" a time slot is bookable when the party finds free tables: the smallest
   free table that is big enough, otherwise up to four tables marked as linkable, outside closed
   areas, respecting the average stay. Reservations without a table are placed on tables in
   memory first so they block their tables. Full slots are greyed out in the form; a booking
   for a full slot goes to the waiting list, as before. An explicit passer-by limit of the day
   still applies; the seat/table limits of the outlet do not. Falls back to the counter logic
   when there are no tables or on any error. The backend day view keeps its own counters
 * "Online-Vorschau (Tischplan)" in the day view shows, for a party size, which slots the table
   plan would offer (or why the day is not bookable: closed weekday, day off, online block),
   so the switch can be tried in parallel before it is turned on
 * Times after midnight (e.g. open 14:30 - 00:00) are treated as belonging to the same evening
   when checking overlaps at a table

2026-09-21 == mySeat v0.2173 == amadeushi - http://github.com/amadeushi/myseat

 * Block online bookings for a single day (closed party, sold out): button "Online sperren" in
   the dashboard header for the selected day, and "online sperren" / "freigeben" per day in the
   week view, with an optional internal reason. The public form shows "online reservations are
   not possible on this day, contact us", greys the day out in the datepicker, and the booking
   is also refused server-side. Staff can still enter reservations in the backend - unlike the
   existing "day off" of the daily settings, which closes the day completely. A notice is shown
   in the dashboard and day view. New table online_blocks (created automatically)
 * Security: ajax/modify_dayoff.php (day off checkbox) now requires a logged in user with the
   Daily-Outlet-Edit right; it could be called without login before

2026-09-21 == mySeat v0.2172 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: day view with the reservations of a date (date navigation, "occupancy at time"
   filter). Assign a reservation to one or more tables by clicking tables or dragging the
   reservation card onto a table; a table can be given to several reservations at different
   times. Conflicts (capacity too small, table taken within the average stay of the outlet)
   are shown and can be overridden after a confirmation; closed areas can not be used.
   Cancelled, waiting-list, departed and no-show reservations are ignored
 * Automatic assignment (best fit: smallest free table that is big enough, otherwise tables
   that were marked as linkable, up to four): button for the whole day, per reservation, and
   for every new booking (online form and backend form; setting "automatically assign new
   reservations" in the plan editor, on by default). The hook can never break a booking.
   Parties that fit nowhere stay unassigned and are marked in the list
 * Area closures ("Sperrzeiten"): an area can be closed for a date range, open ended, and
   optionally repeating every year (e.g. terrace 30.09. - 01.03.) without deleting it. Closed
   areas are marked on the tab and skipped by the automatic assignment
 * New table tp_area_closures (created automatically). Online availability still uses the
   old counter logic; switching it to table capacity is planned for a later version

2026-09-21 == mySeat v0.2171 == amadeushi - http://github.com/amadeushi/myseat

 * New backend page "Tischplan" (main_page.php?p=7), first step towards table based capacity:
   administrators (Page-System) draw the floor plan of the outlet - add tables, drag them on a
   10px grid, resize, rotate (0/45/90/135 degrees), round or rectangular, seats per table, and mark
   which tables may be pushed together for larger parties. Reservation staff can view it
 * An outlet has several areas (floors, terrace, ...), each with its own plan shown as a tab; areas
   can be added, renamed, reordered and deleted (only when empty), tables can be moved between
   areas, and tables can only be linked inside one area
 * Storage in own tables tp_areas, tp_tables, tp_table_links, tp_reservation_tables, tp_settings (InnoDB,
   created automatically); JSON endpoint web/ajax/tp.php with session, CSRF token and role checks
   and prepared statements. Nothing changes for bookings yet - the existing counter based
   availability stays active (assignment of reservations to tables follows in later versions)

2026-09-21 == mySeat v0.2170 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form: the datepicker showed "&laquo;" / "&raquo;" as text (jQuery UI 1.13 no
   longer renders HTML in the arrow labels) and was transparent - its theme variables were only
   defined inside .booking-shell, but the calendar is appended to <body>; they are now defined on
   the calendar itself, which also lifts it above the time slots

2026-09-21 == mySeat v0.2169 == amadeushi - http://github.com/amadeushi/myseat

 * Backend (web/) runs on jQuery 3.7.1, jQuery UI 1.13.3 and jquery-validate 1.19.5 (was
   jQuery 1.4.4 / UI 1.8.10 / validate 1.7). The new scripts live in web/js/v3/; footer.html.php
   loads them by default. The old stack is still in web/js/ and can be loaded per browser tab
   with ?jq3=0 (?jq3=1 switches back) - it will be removed in a later version
 * plugins.js for jQuery 3: jQuery.browser and jQuery.support.opacity are re-created (needed by
   the bundled WYSIWYG editor and Fancybox 1.3), .live() -> delegated .on(), .unload() and
   .size() replaced; custom.js uses .prop() for checkboxes
 * Datepicker arrows use the real characters (jQuery UI 1.13 no longer renders HTML there);
   autocomplete/menu styling follows the new jQuery UI markup
 * Fixed: the day view crashed with a PHP 8 fatal error (getAvailability, no average duration)
   whenever the outlet data was not loaded yet, e.g. right after a fresh login
 * Note: the WYSIWYG description editor of the outlet form could not be inspected automatically
   during the upgrade - please check it once in the browser (outlet edit page)

2026-09-21 == mySeat v0.2168 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form (api/reserve.php) runs on jQuery 3.7.1 and jQuery UI 1.13.3 (was
   jQuery 1.4.4 / UI 1.7.3, both with known vulnerabilities); event handlers use .on(),
   form validation submits the form natively, the easing plugin is replaced by the one
   built into jQuery UI. The old api/js libraries were removed.

2026-09-21 == mySeat v0.2167 == amadeushi - http://github.com/amadeushi/myseat

 * Backend: editing a reservation works again - the detail page was cut off by a PHP 8
   fatal error (unquoted $_SESSION key in reservation_form.inc.php)
 * Backend: fixed a regression of the dark theme - preloadCssImages() threw a SecurityError
   because of the Google Fonts stylesheet and stopped all page scripts after it (edit button,
   datepicker, realtime updates); the call is now guarded
 * Backend: "+ Neu" tab highlighted as the primary action of the reservation view; the
   "Amadeus" brand link opens the reservation view
 * Backend: table row hover via CSS class (search results no longer turn white), dark
   autocomplete highlight, invalid fields get a red edge instead of a pink background,
   readable guest card (h5/h6), dashboard rows with larger type and a slim time-of-day marker

2026-09-20 == mySeat v0.2166 == amadeushi - http://github.com/amadeushi/myseat

 * One-click cancel link: api/cancel.php?nr=<booking number>&email=<address>
   opens a confirmation page and cancels only after an explicit click; works without a
   session, German/English, themed. Link added to the confirmation page and the emails
   (local_email_send and email_send plugins); manual lookup form as fallback
 * Cancel history entry is written with the correct reservation id, debug output removed
 * Security: processBooking() no longer takes column names from POST (field whitelist,
   plain INSERT - reservation_id can no longer overwrite other bookings); server-side
   checks for name, email, party size (max_menu) and time format; selectedDate, pax and
   outlet id validated at the public entry points; referer escaped in the form
 * Backend (web/): dark/gold theme in web/css/theme-dark.css (screen only, print stays
   light), brand name instead of the logo image, larger centred occupancy bar that scales
   down to a half-width / portrait window, dark modal windows (CXL list, details, confirmations)

2026-09-20 == mySeat v0.2165 == amadeushi - http://github.com/amadeushi/myseat

 * Booking confirmation page redesigned (dark/gold card) with confirmed / waitlist / error
   states; waitlist bookings were shown as an error before
 * Last-booking cutoff: $settings['lastBookingMinutes'] (default 60) hides late time slots,
   also for closing times after midnight, and is enforced server-side
 * Info boxes (.alert_info) in the booking form follow the dark/gold theme
 * style.css is cache-busted with the file time so deployments reach visitors immediately

2026-09-18 == mySeat v0.2164 == amadeushi - http://github.com/amadeushi/myseat

 * Mobile booking form: no nested cards, 3-column time grid, "Online-Reservierung" subtitle,
   centred headings
 * Party size is free text from 1 guest; groups above max_menu and fully booked or closed
   days show a "contact us" message with the property email (closed weekdays now also
   enforced server-side)
 * Date field shows "Today"/"Heute" for the current day; EN/DE picker instead of text links;
   correct active language; fonts from amadeus-hildesheim.de (Cormorant Garamond, Raleway)
 * Wizard keeps its step and all entered data when the language is switched
 * property id is set when entering with ?outletID=

2026-09-18 == mySeat v0.2163 == amadeushi - http://github.com/amadeushi/myseat

 * Public reservation form (api/reserve.php) redesigned in a dark/gold two-column layout
   with sidebar (back link, weekly opening hours, address, contact)
 * Three-step wizard: date/time/guests, notes, contact details with live summary
 * Guest count updates the time slots via AJAX (api/ajax_timeslots.php) without reload;
   time-slot fragment shared in api/timeslot_fragment.inc.php
 * Time slots as clickable pills in a scrollable grid; the last slot of the day (e.g. 00:00)
   is no longer dropped

2026-09-18 == mySeat v0.2162 == amadeushi - http://github.com/amadeushi/myseat

 * Day-specific opening hours work when only the opening or only the closing time is set
 * Midnight (00:00) can be used as a day-specific closing time
 * Confirmation page after booking no longer stays blank (wrong PHPMailer path in
   local_email_send plugin)
 * "Entry added" message no longer reappears on every outlet page view
 * Datepicker language script no longer returns a 500 (wrong session key)

2026-09-18 == mySeat v0.2161 == amadeushi - http://github.com/amadeushi/myseat

 * PHP 8 compatibility: mysql_* functions provided on top of mysqli
   (web/classes/mysql_compat.php), each() and PHP4 constructors replaced,
   get_magic_quotes_gpc() shim, parse errors fixed, deprecations cleared
 * Strict SQL mode (MySQL 5.7+ / MariaDB 10.2+): missing NOT NULL columns added to the
   default settings insert
 * Login works again (constructor of flexibleAccess in PLC/plc.class.php)
 * Plugin hook system no longer fatals (phphooks.class.php)
 * Further PHP 8 fatals fixed in the public form, reservation detail and the translation files

2012-12-08 == mySeat v0.2160 == Ap.Muthu - http://github.com/apmuthu/myseat

 * Multi Property Enabled - when plc_user role = 1
 * Forum Fixes and features incorporated
 * Code cleanup
 * $settings['mailCharset'] in config.general.php
 * Typos corrected
 * PHP Notices fixed - missing variable checks done
 * TimeZone values updated
 * Asia/Singapore TimeZone incorporated
 * tooltip over day off in online reservation datepicker
 * property_grid zip field display
 * export_page, hooks fixed

2012-08-06 == mySeat v0.2150 == Sebastien Fanals - http://github.com/fanals/myseat

 * DB Table Prefixes enables multiple mySeat installs in one DB
 * mail fixed
 * local_mail plugin - GMail and HotMail SMTP enabled and tested working
 * $settings['emailSMTP'] in config.general.php

2012-01-18 == mySeat v0.2134 == Bernd Orttenburger - http://github.com/myseat/myseat
- Dutch language file improvements
- small bugfixes

There is a database update necessary for 0.195 version and up!


INSTALLATION
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
SEE ONLINE DOCUMENTATION FOR MORE DETAILS: http://www.myseat.us/API 
mySeat is easy to install.
Under most circumstances installing mySeat is a very simple process
and takes less than ten minutes to complete.

Before starting the automatic installer follow these instructions:
Create a database for mySeat on your web server.
Create a MySQL user who has all privileges for accessing and modifying it.
Open file WEBROOT/config/config.general.php in a text editor and fill in your database details.
Browse to your new mySeat site to the directory http://IP.OR.DOMAIN/PATH/install
This will take you to the mySeat automatic installer with small explanations.


UPDATE
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
To update mySeat to a newer version, it is not necessary to do a full install.
Just replace the old files on the web server, except the WEBROOT/config folder.
If there is a need to change or extend the database, it is clearly stated.
To update the database, point your web browser to mySeat update script at
http://IP.OR.DOMAIN/PATH/install/update.php


MULTILINGUAL
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
mySeat is actually translated into 9 languages:
English
German
Spanish
French
Dutch
Swedish
Italian
Chinese
Danish


GNU LICENSE
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
Copyright
mySeat is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or any later version.
mySeat is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with mySeat? 
If not, see <http://www.gnu.org/licenses/>.


mySeat? 
If not, see <http://www.gnu.org/licenses/>.


