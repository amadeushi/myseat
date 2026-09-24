<?php
/*
 * This file is part of mySeat.
 *
 *     mySeat is free software: you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation, either version 3 of the License, or
 *     any later version.
 *
 *     mySeat is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *     GNU General Public License for more details.
 *
 *     You should have received a copy of the GNU General Public License
 *     along with mySeat.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Same reasoning as the site-root index.php: send visitors to the guest reservation form rather
 * than exposing the backend login here too.
 */
session_start();
$_SESSION = array();
$_SESSION['forwardPage'] = "../web/main_page.php?p=1";
header('Location: ../api/reserve.php?outletID=1');
exit;