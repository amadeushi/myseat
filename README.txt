=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-=                                       =-=
=-=           mySeat README               =-=
=-=                                       =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-= Version: 0.2167                        =-=
=-= Date:    21.09.2026                   =-=
=-= Time:    18:00 GMT                    =-=
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

 
CHANGELOG
=========

Versions 0.2161 - 0.2167 are maintained in http://github.com/amadeushi/myseat.
No database update is needed for any of them. Optional new settings for
config/config.general.php (defaults apply when missing):
  $settings['lastBookingMinutes'] = 60;   (v0.2165)  last online booking, minutes before closing
  $settings['brandName'] = 'Amadeus';     (v0.2166)  name shown in the backend header and login

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


