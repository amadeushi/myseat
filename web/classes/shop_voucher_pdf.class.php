<?php
/*
 * The picture of a voucher and its PDF. The card (3:2, 190 x 127 mm) is drawn with GD in the fonts of the shop (Cormorant Garamond, Raleway: web/fonts/gutschein, SIL Open Font License):
 * photo or drawing as the background, a dark fade for the text, wordmark, value, names, message, number, security code, validity and a QR code. The PDF is one A4 page made of
 * a 300 dpi picture of the page (card + how to redeem + short terms): that keeps the typography exactly as drawn and needs no PDF library.
 * Own pictures of the guests are stored re-encoded (no EXIF, no metadata) in uploads/gutschein, which is closed for the web; they are only shown through the preview and the PDF.
 */

require_once __DIR__.'/qrcode.class.php';

const GV_CARD_W_MM = 148;   // A6 landscape: the same card is the PDF, the preview and the front of the postcard sent by EchtPost
const GV_CARD_H_MM = 105;

// the motifs the guest can choose: key => label, group, file (in order/gutschein/motive). The owner can switch single ones off with the setting gv_motifs_off (keys, comma separated).
function gv_motifs() {
	return array(
		'geburtstag' => array('label' => 'Geburtstag', 'group' => 'Zum Anlass'), 'jubilaeum' => array('label' => 'Jubiläum', 'group' => 'Zum Anlass'), 'umschlag' => array('label' => 'Brief', 'group' => 'Zum Anlass'),
		'schnitzel' => array('label' => 'Schnitzel', 'group' => 'Von unserem Tisch'), 'steak' => array('label' => 'Steak', 'group' => 'Von unserem Tisch'), 'pizza' => array('label' => 'Pizza', 'group' => 'Von unserem Tisch'),
		'salat' => array('label' => 'Salat', 'group' => 'Von unserem Tisch'), 'kuchen' => array('label' => 'Kuchen und Kaffee', 'group' => 'Von unserem Tisch'));
}
function gv_motifs_active() {
	$off = array_filter(array_map('trim', explode(',', (string)shop_setting('gv_motifs_off'))));
	return array_diff_key(gv_motifs(), array_flip($off));
}
function gv_motif_dir() { return dirname(__DIR__, 2).'/order/gutschein/motive'; }
function gv_upload_dir() { return dirname(__DIR__, 2).'/uploads/gutschein'; }
function gv_font($name) { return dirname(__DIR__).'/fonts/gutschein/'.$name.'.ttf'; }

// the file of a motif: a key of the list, or "own:<name>" for the picture of a guest; '' if unknown
function gv_motif_file($motif) {
	$motif = (string)$motif;
	if (preg_match('/^own:([a-f0-9]{24})$/', $motif, $m)) { $f = gv_upload_dir().'/'.$m[1].'.jpg'; return is_file($f) ? $f : ''; }
	$all = gv_motifs();
	if (isset($all[$motif])) { $f = gv_motif_dir().'/'.$motif.'.jpg'; return is_file($f) ? $f : ''; }
	return '';
}

// keep a picture of a guest: checked, turned upright, scaled down to at most 2400 px on the long side, saved as JPEG without metadata. Returns array(ok, key|error)
function gv_upload_store($bin) {
	if (!function_exists('imagecreatefromstring')) { return array('ok' => false, 'error' => 'Auf dem Server fehlt die Bildbearbeitung.'); }
	if (strlen($bin) < 1000) { return array('ok' => false, 'error' => 'Das ist kein Bild.'); }
	if (strlen($bin) > 12 * 1024 * 1024) { return array('ok' => false, 'error' => 'Das Bild ist zu groß (höchstens 12 MB).'); }
	$info = @getimagesizefromstring($bin);
	if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP), true)) { return array('ok' => false, 'error' => 'Bitte ein Bild als JPG, PNG oder WebP wählen.'); }
	if ($info[0] * $info[1] > 40000000) { return array('ok' => false, 'error' => 'Das Bild hat zu viele Pixel. Bitte verkleinern.'); }
	if ($info[0] < 1500 || $info[1] < 1000) { return array('ok' => false, 'error' => 'Das Bild ist zu klein für einen scharfen Druck (mindestens 1500 x 1000 Pixel).'); }
	$im = @imagecreatefromstring($bin);
	if (!$im) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gelesen werden.'); }
	if (!imageistruecolor($im)) { imagepalettetotruecolor($im); }
	if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
		$ex = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bin)); $ang = array(3 => 180, 6 => -90, 8 => 90);
		$o = isset($ex['Orientation']) ? (int)$ex['Orientation'] : 1;
		if (isset($ang[$o])) { $r = imagerotate($im, $ang[$o], 0); if ($r) { $im = $r; } }
	}
	$w = imagesx($im); $h = imagesy($im); $long = max($w, $h);
	if ($long > 2400) { $s = 2400 / $long; $r = imagescale($im, (int)round($w * $s), (int)round($h * $s), IMG_BICUBIC); if ($r) { $im = $r; } }
	$dir = gv_upload_dir();
	if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
	if (!is_file($dir.'/.htaccess')) { @file_put_contents($dir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"); }
	if (!is_dir($dir) || !is_writable($dir)) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.'); }
	$key = bin2hex(random_bytes(12));
	$white = imagecreatetruecolor(imagesx($im), imagesy($im)); imagefill($white, 0, 0, 0xFFFFFF); imagecopy($white, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
	if (!imagejpeg($white, $dir.'/'.$key.'.jpg', 86)) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.'); }
	return array('ok' => true, 'key' => $key);
}
// pictures nobody ordered with disappear after two days
function gv_upload_gc() {
	$used = array();
	foreach (fb_rows("SELECT motif FROM ".fb_t('tp_gv_orders')." WHERE motif LIKE 'own:%'") as $r) { $used[substr($r['motif'], 4)] = 1; }
	foreach ((array)glob(gv_upload_dir().'/*.jpg') as $f) { if (filemtime($f) < time() - 2 * 86400 && empty($used[basename($f, '.jpg')])) { @unlink($f); } }
}

function gv_money_short($cents) { return ($cents % 100 === 0 ? number_format($cents / 100, 0, ',', '.') : number_format($cents / 100, 2, ',', '.')).' €'; }
function gv_hex($im, $hex, $alpha = 0) { return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha); }
function gv_text_w($size, $font, $s) { $b = imagettfbbox($size, 0, $font, $s); return $b ? $b[2] - $b[0] : 0; }
// text with letter spacing (GD has none); returns the width
function gv_text_spaced($im, $size, $x, $y, $col, $font, $s, $space) {
	$w = 0;
	foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
		imagettftext($im, $size, 0, (int)round($x + $w), (int)round($y), $col, $font, $ch);
		$w += gv_text_w($size, $font, $ch) + $space;
		if ($ch === ' ') { $w += $size * 0.18; }
	}
	return $w - $space;
}
// word wrap to a width in pixels; returns lines (at most $maxLines, the last one cut with an ellipsis when the text is longer)
function gv_wrap($text, $font, $size, $maxw, $maxLines = 99) {
	$lines = array();
	foreach (preg_split('/\R/u', trim((string)$text)) as $para) {
		$cur = '';
		foreach (preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY) as $word) {
			$try = $cur === '' ? $word : $cur.' '.$word;
			if (gv_text_w($size, $font, $try) <= $maxw || $cur === '') { $cur = $try; } else { $lines[] = $cur; $cur = $word; }
		}
		$lines[] = $cur;
	}
	if (count($lines) > $maxLines) {
		$lines = array_slice($lines, 0, $maxLines);
		$last = $lines[$maxLines - 1];
		while ($last !== '' && gv_text_w($size, $font, $last.' …') > $maxw) { $last = mb_substr($last, 0, -1); }
		$lines[$maxLines - 1] = rtrim($last).' …';
	}
	return $lines;
}

// the logo files of the restaurant in white (for dark pictures) and black (for bright ones); '' when missing
function gv_logo_file($dark) {
	$f = dirname(__DIR__, 2).'/order/gutschein/logo-'.($dark ? 'schwarz' : 'weiss').'.png';
	return is_file($f) ? $f : '';
}
// the provider lines (name, address, contact) of the restaurant, as in the mails
function gv_legal_lines() {
	$p = fb_row("SELECT * FROM ".fb_t('properties')." ORDER BY id LIMIT 1");
	return bm_legal_lines($p ? $p : array());
}

// what the QR code on the voucher opens: the page of the voucher for the team (order/gutschein_karte.php, found by the token that only the card carries); without a token (sample cards) the guest page
function gv_qr_url($number, $token = '') {
	return rtrim(shop_site_url(), '/').($token !== '' ? '/order/gutschein_karte.php?t='.rawurlencode($token) : '/order/gutschein.php?g='.rawurlencode($number));
}

// word wrap in which the available width depends on the line: $widthFor(lineIndex) gives it in pixels (a text that runs past a QR code first and then below it). More than $maxLines lines: cut with an ellipsis.
function gv_wrap_var($text, $font, $size, $widthFor, $maxLines = 99) {
	$words = preg_split('/\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY); $lines = array(); $cur = '';
	foreach ($words as $wd) {
		$try = $cur === '' ? $wd : $cur.' '.$wd;
		if ($cur !== '' && gv_text_w($size, $font, $try) > $widthFor(count($lines))) { $lines[] = $cur; $cur = $wd; } else { $cur = $try; }
	}
	if ($cur !== '') { $lines[] = $cur; }
	if (count($lines) > $maxLines) {
		$lines = array_slice($lines, 0, $maxLines); $last = $lines[$maxLines - 1];
		while ($last !== '' && gv_text_w($size, $font, $last.' …') > $widthFor($maxLines - 1)) { $last = mb_substr($last, 0, -1); }
		$lines[$maxLines - 1] = rtrim($last).' …';
	}
	return $lines;
}

// The part of a picture the front of the card shows. The area above the band has the ratio 148 : 81 (GV_CARD_W_MM x (GV_CARD_H_MM - 24)). Window of a source picture of $sw x $sh at zoom 1: array(width, height) in source pixels.
function gv_crop_window($sw, $sh) { $aw = GV_CARD_W_MM / (GV_CARD_H_MM - 24); $cw = min($sw, $sh * $aw); return array($cw, $cw / $aw); }
// Clean values for an own picture: position x, y 0..1000 and zoom 1000..zmax (the part must stay at least 1250 px wide, at most 4x). For every other motif the defaults. Returns array(x, y, z, zmax).
function gv_crop_norm($motif, $x, $y, $z) {
	$zmax = 1000;
	if (strpos((string)$motif, 'own:') === 0) {
		$f = gv_motif_file($motif); $i = $f !== '' ? @getimagesize($f) : false;
		if ($i) { $w = gv_crop_window($i[0], $i[1]); $zmax = max(1000, min(4000, (int)floor(1000 * $w[0] / 1250))); }
		return array(max(0, min(1000, (int)$x)), max(0, min(1000, (int)$y)), max(1000, min($zmax, (int)$z)), $zmax);
	}
	return array(500, 500, 1000, 1000);
}

/*
 * The front of the card: the picture as it is (cut to the format, never stretched, no fade or text on it) above a solid dark band with the logo, the word GUTSCHEIN and the amount.
 * Text sits only on solid colours, so it reads the same on every motif, also on a picture a guest uploads. Everything with the guest's words, number, code and QR code is on the back (gv_render_back).
 * $o: motif, amount_cents, label (default GUTSCHEIN), brand. Returns a GD image of $w x ($w * 105 / 148) pixels; sizes are real points on a card of GV_CARD_W_MM.
 */
function gv_render_card($o, $w) {
	$H = (int)round($w * GV_CARD_H_MM / GV_CARD_W_MM); $pxMm = $w / GV_CARD_W_MM;
	$mm = function ($v) use ($pxMm) { return $v * $pxMm; }; $P = function ($pt) use ($pxMm) { return $pt * 25.4 / 72 * $pxMm; };
	$im = imagecreatetruecolor($w, $H); imagealphablending($im, true);
	$band = (int)round($mm(24)); $phH = $H - $band; $dark = '14100c';
	imagefill($im, 0, 0, gv_hex($im, $dark));
	$file = gv_motif_file($o['motif'] ?? ''); $src = $file !== '' ? @imagecreatefromjpeg($file) : false;
	if ($src) {   // the picture fills the area above the band (cut at the edges, never stretched); an own picture can be moved and zoomed: $o['crop'] = array(x, y, zoom) as stored with the order
		$sw = imagesx($src); $sh = imagesy($src);
		$cr = gv_crop_norm($o['motif'] ?? '', $o['crop'][0] ?? 500, $o['crop'][1] ?? 500, $o['crop'][2] ?? 1000);
		$win = gv_crop_window($sw, $sh); $cw = $win[0] * 1000 / $cr[2]; $ch = $win[1] * 1000 / $cr[2];
		imagecopyresampled($im, $src, 0, 0, (int)round($cr[0] / 1000 * ($sw - $cw)), (int)round($cr[1] / 1000 * ($sh - $ch)), $w, $phH, (int)round($cw), (int)round($ch));
	} else {   // no picture (for example a voucher of the old system): a quiet warm gradient
		for ($y = 0; $y < $phH; $y++) { $t = $y / max(1, $phH); imagefilledrectangle($im, 0, $y, $w, $y, gv_hex($im, sprintf('%02x%02x%02x', 58 - 38 * $t, 44 - 28 * $t, 30 - 18 * $t))); }
	}
	imagefilledrectangle($im, 0, $phH, $w, $H, gv_hex($im, $dark));
	imagefilledrectangle($im, 0, $phH, $w, $phH + max(1, (int)round($mm(0.5))) - 1, gv_hex($im, 'c9a45c'));   // a fine gold line between picture and band
	$serif = gv_font('CormorantGaramond-SemiBold'); $sansB = gv_font('Raleway-SemiBold');
	$gold = gv_hex($im, 'efd08a'); $cream = gv_hex($im, 'fffaf0'); $m = $mm(9);
	$logoFile = gv_logo_file(false);   // white logo on the dark band
	if ($logoFile !== '' && ($lg = @imagecreatefrompng($logoFile))) {
		$lw = (int)round($mm(38)); $lh = (int)round($lw * imagesy($lg) / imagesx($lg));
		imagecopyresampled($im, $lg, (int)$m, (int)($phH + ($band - $lh) / 2), 0, 0, $lw, $lh, imagesx($lg), imagesy($lg));
	} else {
		imagettftext($im, $P(22), 0, (int)$m, (int)($phH + $band / 2 + $P(8)), $cream, $serif, (string)($o['brand'] ?? 'Amadeus'));
	}
	// right: GUTSCHEIN (10 pt) and the amount (30 pt), right aligned
	$label = (string)($o['label'] ?? 'GUTSCHEIN'); $ls = $P(10); $sp = $mm(1.1);
	$lwid = gv_text_w($ls, $sansB, $label) + (mb_strlen($label) - 1) * $sp;
	gv_text_spaced($im, $ls, $w - $m - $lwid, $phH + $mm(8.2), $gold, $sansB, $label, $sp);
	$amount = gv_money_short((int)($o['amount_cents'] ?? 0)); $as = $P(30);
	imagettftext($im, $as, 0, (int)($w - $m - gv_text_w($as, $serif, $amount)), (int)($phH + $mm(19.6)), $cream, $serif, $amount);
	return $im;
}
function gv_card_jpeg($o, $w, $q = 86) {
	$im = gv_render_card($o, $w);
	ob_start(); imagejpeg($im, null, $q); $b = ob_get_clean();
	return $b;
}

// The front of the postcard EchtPost prints: A6 landscape (148 x 105 mm) plus 2 mm bleed = 152 x 109 mm = exactly 1795 x 1287 px (their upload size, 300 dpi). The card is drawn at the
// trimmed size, the bleed is made by repeating its edge pixels (text and QR code sit more than 5 mm inside the edge). Returns a JPEG.
const GV_POSTCARD_W = 1795;
const GV_POSTCARD_H = 1287;
function gv_postcard_front_jpeg($o, $q = 90) {
	$bleed = 23; $cw = GV_POSTCARD_W - 2 * $bleed; $ch = GV_POSTCARD_H - 2 * $bleed;
	$card = gv_render_card($o, $cw);
	if (imagesy($card) !== $ch) { $c2 = imagecreatetruecolor($cw, $ch); imagecopyresampled($c2, $card, 0, 0, 0, 0, $cw, $ch, imagesx($card), imagesy($card)); $card = $c2; }
	$im = imagecreatetruecolor(GV_POSTCARD_W, GV_POSTCARD_H);
	imagecopy($im, $card, $bleed, $bleed, 0, 0, $cw, $ch);
	imagecopyresampled($im, $card, 0, $bleed, 0, 0, $bleed, $ch, 1, $ch);                                          // left
	imagecopyresampled($im, $card, GV_POSTCARD_W - $bleed, $bleed, $cw - 1, 0, $bleed, $ch, 1, $ch);               // right
	imagecopyresampled($im, $im, 0, 0, 0, $bleed, GV_POSTCARD_W, $bleed, GV_POSTCARD_W, 1);                         // top (corners included)
	imagecopyresampled($im, $im, 0, GV_POSTCARD_H - $bleed, 0, GV_POSTCARD_H - $bleed - 1, GV_POSTCARD_W, $bleed, GV_POSTCARD_W, 1);   // bottom
	ob_start(); imagejpeg($im, null, $q); $b = ob_get_clean();
	return $b;
}

// the small print at the foot of the PDF and of the back of a printed card, like the FirstVoucher original: reference (voucher, order, purchase date), where the terms are, address, contact, website. Array of lines.
function gv_footer_lines($o) {
	$ref = array('Gutschein '.($o['number'] ?? ''));
	if (!empty($o['order_number'])) { $ref[] = 'Bestellung '.$o['order_number'].(!empty($o['bought']) ? ' vom '.date('d.m.Y', strtotime($o['bought'])) : ''); }
	$lines = array(implode('  ·  ', $ref), 'Bedingungen: '.rtrim(shop_site_url(), '/').'/order/gutschein_bedingungen.php');
	$site = trim((string)shop_setting('gv_site_host'));
	$contact = array_merge(gv_legal_lines(), $site !== '' ? array($site) : array());
	if ($contact) { $lines[] = implode('  ·  ', $contact); }
	return $lines;
}

// the texts under the card on the PDF (draft after AGB 5 and 6; the owner approves the wording): array of array(heading, text)
function gv_pdf_terms($o) {
	$shop = trim((string)shop_setting('gv_shop_host'));   // the public address of the web shop as printed (amadeus.delivery); the QR code leads to the order system itself
	return array(
		array('Bei uns am Tisch.', 'Nenn uns die Gutscheinnummer oder zeig den QR-Code. Du kannst alles auf einmal einlösen oder nur einen Teil, der Rest bleibt auf dem Gutschein.'),
		array('Von zu Hause.', 'Im Webshop '.($shop !== '' ? $shop.' ' : '').'gibst du Nummer und Sicherheitscode ein. Der Betrag wird von deiner Bestellung abgezogen. Geliefert wird im Stadtgebiet Hildesheim.'),
		array('Gut zu wissen.', 'Gültig bis '.(!empty($o['valid_until']) ? date('d.m.Y', strtotime($o['valid_until'])) : 'zum aufgedruckten Datum').'. Eine Barauszahlung gibt es nicht. Der Gutschein gilt wie Bargeld: Bewahr ihn sorgfältig auf, bei Verlust gibt es keinen Ersatz. Mehrzweck-Gutschein: Die Umsatzsteuer entsteht erst beim Einlösen.'),
	);
}

// Clickable spots for the PDF: every web address, website and e-mail address in a drawn line of text. Returns array(array(x0, y0, x1, y1, uri), ...) in picture pixels
// ($base = baseline of the line, $size = GD point size of the line which is the pixel height at the 300 dpi page).
function gv_links_in($line, $x, $base, $size, $font) {
	$out = array();
	if (!preg_match_all('~https?://[^\s]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|(?:www\.)?[A-Za-z0-9-]+\.(?:de|delivery|at|com)\b(?:/[^\s]*)?~u', $line, $m, PREG_OFFSET_CAPTURE)) { return $out; }
	foreach ($m[0] as $hit) {
		$t = rtrim($hit[0], '.,;:)'); if ($t === '') { continue; }
		$pre = substr($line, 0, $hit[1]);
		$x0 = $x + gv_text_w($size, $font, $pre); $x1 = $x0 + gv_text_w($size, $font, $t);
		$uri = strpos($t, '@') !== false ? 'mailto:'.$t : (preg_match('~^https?://~', $t) ? $t : 'https://'.$t);
		$out[] = array((int)$x0, (int)($base - $size * 1.05), (int)$x1, (int)($base + $size * 0.3), $uri);
	}
	return $out;
}

/*
 * The PDF for guests: three A4 pages, each with one job and a heading that says which.
 * 1 "Dein Gutschein": front and back upright and large, to read and show on a screen (the way the PDF travels by mail).
 * 2 "Zum Ausdrucken": three short steps, then the card as a folding card of 148 x 210 mm: the front on top, the fold line, the back below it turned by 180 degrees (so that it stands upright
 *   after folding), cut marks at the four corners. Printed at 100 % on firm paper, cut out and folded with the pictures outside, it is a finished card of A6 (148 x 105 mm).
 * 3 "So löst du ihn ein": the three blocks of the redeem text and the small print with the links (clickable).
 * Real points: headings 18, steps and block headings 12, text 11, small print 9; dark ink on white (at least 9:1). Returns array of pages array(jpeg, w, h, links).
 */
function gv_guest_pages($o) {
	$W = 2480; $H = 3508; $cw = 1748; $ch = 1240; $x = (int)(($W - $cw) / 2);
	$S = function ($pt) { return $pt * 300 / 72; };   // real printed points -> GD size at 300 dpi
	$serif = gv_font('CormorantGaramond-SemiBold'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$mk = function () use ($W, $H) { $im = imagecreatetruecolor($W, $H); imagefill($im, 0, 0, 0xFFFFFF); return $im; };
	$jpeg = function ($im) { ob_start(); imagejpeg($im, null, 80); return ob_get_clean(); };
	$pages = array();

	// ---- page 1: the voucher on the screen ----
	$p0 = $mk(); $ink = gv_hex($p0, '1f1a14'); $mut = gv_hex($p0, '2e2921'); $edge = gv_hex($p0, 'cfc8b8');
	$sw = 2000; $sh = (int)round($sw * GV_CARD_H_MM / GV_CARD_W_MM); $sx = (int)(($W - $sw) / 2); $ty = 190;
	imagettftext($p0, $S(18), 0, $sx, $ty, $ink, $serif, 'Dein Gutschein'); $ty += 100;
	foreach (gv_wrap('Vorderseite und Rückseite zum Lesen und Zeigen am Bildschirm. Zum Verschenken auf Papier: Seite 2.', $sans, $S(12), $sw) as $line) { imagettftext($p0, $S(12), 0, $sx, $ty, $mut, $sans, $line); $ty += 68; }
	$y1 = $ty + 50; $y2 = $y1 + $sh + 90;
	imagecopyresampled($p0, gv_render_card($o, $cw), $sx, $y1, 0, 0, $sw, $sh, $cw, $ch);
	imagecopyresampled($p0, gv_render_back($o, $cw), $sx, $y2, 0, 0, $sw, $sh, $cw, $ch);
	foreach (array($y1, $y2) as $yy) { imagerectangle($p0, $sx - 1, $yy - 1, $sx + $sw, $yy + $sh, $edge); }
	$pages[] = array($jpeg($p0), $W, $H, array());

	// ---- page 2: to print, cut out and fold ----
	$p1 = $mk(); $ink = gv_hex($p1, '1f1a14'); $mut = gv_hex($p1, '2e2921'); $gray = gv_hex($p1, '6f685c');
	$ty = 190;
	imagettftext($p1, $S(18), 0, $x, $ty, $ink, $serif, 'Zum Ausdrucken und Falten'); $ty += 110;
	$steps = array('Drucke diese Seite in Originalgröße (100 %), am besten auf festem Papier.', 'Schneide an den Schnittmarken aus und falte an der gestrichelten Linie, die Bilder nach außen. Zukleben, fertig.', 'Oder leite das PDF per Mail weiter: Die Person druckt es selbst aus.');
	foreach ($steps as $k => $t) {
		imagettftext($p1, $S(12), 0, $x, $ty, $ink, $sansB, ($k + 1).'.');
		foreach (gv_wrap($t, $sans, $S(12), $cw - 90) as $line) { imagettftext($p1, $S(12), 0, $x + 90, $ty, $mut, $sans, $line); $ty += 68; }
		$ty += 14;
	}
	$by = $ty + 60;   // top of the folding card
	imagecopy($p1, gv_render_card($o, $cw), $x, $by, 0, 0, $cw, $ch);
	imagecopy($p1, imagerotate(gv_render_back($o, $cw), 180, 0), $x, $by + $ch, 0, 0, $cw, $ch);
	foreach (array(array($x, $by, -1, -1), array($x + $cw, $by, 1, -1), array($x, $by + 2 * $ch, -1, 1), array($x + $cw, $by + 2 * $ch, 1, 1)) as $c) {   // cut marks at the four corners of the block
		imageline($p1, $c[0] + $c[2] * 16, $c[1], $c[0] + $c[2] * 66, $c[1], $gray); imageline($p1, $c[0], $c[1] + $c[3] * 16, $c[0], $c[1] + $c[3] * 66, $gray);
	}
	$fy = $by + $ch;   // the fold line: dashed across the block and a little beyond, named at the right
	for ($xx = $x - 50; $xx < $x + $cw + 50; $xx += 36) { imageline($p1, $xx, $fy, min($x + $cw + 50, $xx + 18), $fy, $gray); imageline($p1, $xx, $fy + 1, min($x + $cw + 50, $xx + 18), $fy + 1, $gray); }
	imagettftext($p1, $S(9), 0, $x + $cw + 70, $fy + 14, $mut, $sans, 'falten');
	$pages[] = array($jpeg($p1), $W, $H, array());

	// ---- page 3: how to redeem, small print ----
	$p2 = $mk(); $ink = gv_hex($p2, '1f1a14'); $mut = gv_hex($p2, '2e2921'); $foot = gv_hex($p2, '3f392f'); $links = array();
	$x2 = 118; $tw = $W - 2 * $x2; $ty = 220;
	imagettftext($p2, $S(18), 0, $x2, $ty, $ink, $serif, 'So löst du ihn ein'); $ty += 130;
	foreach (gv_pdf_terms($o) as $t) {
		imagettftext($p2, $S(12), 0, $x2, $ty, $ink, $sansB, $t[0]); $ty += 66;
		foreach (gv_wrap($t[1], $sans, $S(11), $tw) as $line) { imagettftext($p2, $S(11), 0, $x2, $ty, $mut, $sans, $line); $links = array_merge($links, gv_links_in($line, $x2, $ty, $S(11), $sans)); $ty += 68; }
		$ty += 70;
	}
	$lf = gv_logo_file(true);   // black logo on the white page
	if ($lf !== '' && ($lg = @imagecreatefrompng($lf))) { $lw = 520; $lh = (int)round($lw * imagesy($lg) / imagesx($lg)); imagecopyresampled($p2, $lg, $x2 + $tw - $lw, $H - 120 - $lh, 0, 0, $lw, $lh, imagesx($lg), imagesy($lg)); }
	$yy = $H - 150; $fl = array(); foreach (gv_footer_lines($o) as $part) { foreach (gv_wrap($part, $sans, $S(9), $tw - 600) as $line) { $fl[] = $line; } }
	foreach (array_reverse($fl) as $line) { imagettftext($p2, $S(9), 0, $x2, $yy, $foot, $sans, $line); $links = array_merge($links, gv_links_in($line, $x2, $yy, $S(9), $sans)); $yy -= 54; }
	$pages[] = array($jpeg($p2), $W, $H, $links);
	return $pages;
}

// one-page PDF (A4) around a JPEG; no library needed
function gv_pdf_wrap_jpeg($jpeg, $px, $py, $title) { return gv_pdf_wrap_jpegs(array(array($jpeg, $px, $py)), $title); }
// PDF (A4) with one full-page JPEG per page: $pages = array(array(jpeg, px, py, links), ...); links = array(array(x0, y0, x1, y1, uri)) in picture pixels become clickable areas
function gv_pdf_wrap_jpegs($pages, $title, $pw = 595.28, $ph = 841.89) {
	$objs = array(); $annots = array(); $n = count($pages);
	$objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
	$kids = array(); for ($i = 0; $i < $n; $i++) { $kids[] = (3 + 3 * $i).' 0 R'; }
	$objs[2] = "<< /Type /Pages /Kids [".implode(' ', $kids)."] /Count $n >>";
	foreach ($pages as $i => $pg) {
		$pn = 3 + 3 * $i; $cn = $pn + 1; $in = $pn + 2;
		$ann = array();
		foreach ((isset($pg[3]) ? $pg[3] : array()) as $lk) {
			$an = 4 + 3 * $n + count($annots);   // annotation objects come after the info object
			$sx = $pw / $pg[1]; $sy = $ph / $pg[2];
			$uri = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), preg_replace('/[^\x21-\x7E]/', '', $lk[4]));
			$annots[$an] = sprintf("<< /Type /Annot /Subtype /Link /Rect [%.2f %.2f %.2f %.2f] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>", $lk[0] * $sx, $ph - $lk[3] * $sy, $lk[2] * $sx, $ph - $lk[1] * $sy, $uri);
			$ann[] = $an.' 0 R';
		}
		$objs[$pn] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Resources << /XObject << /Im0 $in 0 R >> /ProcSet [/PDF /ImageC] >> /Contents $cn 0 R".($ann ? ' /Annots ['.implode(' ', $ann).']' : '')." >>";
		$content = "q $pw 0 0 $ph 0 0 cm /Im0 Do Q";
		$objs[$cn] = "<< /Length ".strlen($content)." >>\nstream\n$content\nendstream";
		$objs[$in] = "<< /Type /XObject /Subtype /Image /Width {$pg[1]} /Height {$pg[2]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($pg[0])." >>\nstream\n".$pg[0]."\nendstream";
	}
	$info = 3 + 3 * $n;
	$t = preg_replace('/[^\x20-\x7E]/', '', (string)$title);
	$objs[$info] = "<< /Title (".str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $t).") /Producer (mySeat) /CreationDate (D:".date('YmdHis').") >>";
	foreach ($annots as $an => $body) { $objs[$an] = $body; }
	$out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = array();
	foreach ($objs as $n => $body) { $off[$n] = strlen($out); $out .= "$n 0 obj\n$body\nendobj\n"; }
	$xref = strlen($out);
	$out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
	foreach ($objs as $n => $_) { $out .= sprintf("%010d 00000 n \n", $off[$n]); }
	$out .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
	return $out;
}
function gv_pdf($o) {
	return gv_pdf_wrap_jpegs(gv_guest_pages($o), 'Gutschein '.($o['number'] ?? ''));
}

/*
 * The print file for a card the restaurant prints itself (orders "Per Post" and "Abholung"): an A4 PDF of two pages, the card at its true size of A6 landscape (148 x 105 mm, cut marks)
 * on page 1 and the way to redeem it on the back of the card on page 2 (same place on the sheet, print double-sided on card stock, flip on the long edge). The value stays inside an envelope.
 */
// the short version of how to redeem, for the back of the card (the full text is on the PDF for guests and on the page of the conditions): array(heading, text)
function gv_card_short($o) {
	$shop = trim((string)shop_setting('gv_shop_host'));
	return array('Einlösen', 'Bei uns am Tisch mit Nummer oder QR-Code, im Webshop '.($shop !== '' ? $shop.' ' : '').'mit Nummer und Code. Teilbeträge sind möglich, eine Barauszahlung gibt es nicht. Wie Bargeld: bei Verlust gibt es keinen Ersatz.');
}

/*
 * The back of the card (A6 landscape, on paper): for / from, the words of the giver, number, code and validity, the QR code (for the team) and the short version of how to redeem, with the
 * small print at the foot. Everything sits on the plain paper colour in dark ink (at least 9:1), sizes are real points: names 12, message 12 to 15, number and code 10, short text 9, small print 8.
 */
function gv_render_back($o, $w) {
	$H = (int)round($w * GV_CARD_H_MM / GV_CARD_W_MM); $pxMm = $w / GV_CARD_W_MM;
	$mm = function ($v) use ($pxMm) { return $v * $pxMm; }; $P = function ($pt) use ($pxMm) { return $pt * 25.4 / 72 * $pxMm; };
	$im = imagecreatetruecolor($w, $H); imagefill($im, 0, 0, gv_hex($im, 'fffdf8'));
	$serif = gv_font('CormorantGaramond-SemiBold'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$ink = gv_hex($im, '1f1a14'); $body = gv_hex($im, '2e2921'); $foot = gv_hex($im, '3f392f'); $qink = gv_hex($im, '1a140e');
	$m = $mm(9); $full = (int)$mm(130);
	// QR code (26 mm) top right, with its caption
	$qr = qr_matrix(gv_qr_url($o['number'] ?? '', $o['token'] ?? '')); $qrBottom = $mm(9);
	if ($qr) {
		$n = count($qr); $mod = max(1, (int)floor($mm(26) / ($n + 2))); $side = ($n + 2) * $mod; $qx = (int)round($w - $m - $side); $qy = (int)round($mm(7));
		for ($r = 0; $r < $n; $r++) { for ($c = 0; $c < $n; $c++) { if ($qr[$r][$c]) { $x0 = $qx + ($c + 1) * $mod; $y0 = $qy + ($r + 1) * $mod; imagefilledrectangle($im, $x0, $y0, $x0 + $mod - 1, $y0 + $mod - 1, $qink); } } }
		$cx = $qx + $side / 2; $cy = $qy + $side + $P(8) * 0.9;
		foreach (array('QR-Code', 'für das Team') as $line) { imagettftext($im, $P(8), 0, (int)round($cx - gv_text_w($P(8), $sansB, $line) / 2), (int)round($cy), $ink, $sansB, $line); $cy += $P(8) * 1.35; }
		$qrBottom = $cy;
	}
	$narrow = (int)($qr ? $mm(148 - 9 - 9 - 26 - 6) : $full);
	// small print at the foot (8 pt, full width) and the short text above it
	$fl = array(); foreach (gv_footer_lines($o) as $part) { foreach (gv_wrap($part, $sans, $P(8), $full, 99) as $line) { $fl[] = $line; } }
	$footTop = $H - $mm(6) - count($fl) * $P(8) * 1.35 - $mm(2);
	$short = gv_card_short($o); $ss = $P(9); $sl = gv_wrap($short[1], $sans, $ss, $full, 99);
	$shortH = $P(9.5) * 1.45 + count($sl) * $ss * 1.38;
	// names, the words of the giver (largest size that fits), then number / code / validity
	$names = array();
	if (trim((string)($o['recipient'] ?? '')) !== '') { $names[] = 'Für '.trim($o['recipient']); }
	if (trim((string)($o['from'] ?? '')) !== '') { $names[] = 'Von '.trim($o['from']); }
	$ns = $P(12); $nl = array();
	if ($names) { $joined = implode('   ·   ', $names); $nl = gv_text_w($ns, $sansB, $joined) <= $narrow ? array($joined) : $names; }
	$yNames = $mm(14); $yMsg0 = $nl ? $yNames + (count($nl) - 1) * $ns * 1.45 + $ns * 1.45 + $mm(1) : $yNames;
	$msg = trim((string)($o['message'] ?? '')); $lines = array(); $ms = 15; $info = array('Nr. '.($o['number'] ?? '').(($o['code'] ?? '') !== '' ? '     Code '.$o['code'] : ''));
	if (!empty($o['valid_until'])) { $info[] = 'gültig bis '.date('d.m.Y', strtotime($o['valid_until'])); }
	$is = $P(10);
	foreach (array(15, 14, 13, 12) as $ms) {
		$lh = $P($ms) * 1.3; $lines = array();
		if ($msg !== '') { $lines = gv_wrap_var($msg, $serif, $P($ms), function ($i) use ($yMsg0, $lh, $qrBottom, $narrow, $full, $P, $ms) { return ($yMsg0 + $i * $lh - $P($ms) * 0.75) < $qrBottom ? $narrow : $full; }, 99); }
		$yLast = $lines ? $yMsg0 + (count($lines) - 1) * $lh : $yMsg0 - $lh;
		$yInfo = $yLast + $mm(7.5);
		$yShort = $yInfo + (count($info) - 1) * $is * 1.45 + $mm(6);
		$bottom = $yShort + $shortH;
		if ($bottom <= $footTop || $ms === 12) { break; }
	}
	if ($msg !== '' && $bottom > $footTop) {   // still too long at 12 pt: cut the words visibly
		$maxL = max(2, (int)floor(($footTop - $shortH - $mm(6) - (count($info) - 1) * $is * 1.45 - $mm(7.5) - $yMsg0) / $lh) + 1);
		$lines = gv_wrap_var($msg, $serif, $P($ms), function ($i) use ($yMsg0, $lh, $qrBottom, $narrow, $full, $P, $ms) { return ($yMsg0 + $i * $lh - $P($ms) * 0.75) < $qrBottom ? $narrow : $full; }, $maxL);
		$yLast = $yMsg0 + (count($lines) - 1) * $lh; $yInfo = $yLast + $mm(7.5); $yShort = $yInfo + (count($info) - 1) * $is * 1.45 + $mm(6);
	}
	$y = $yNames; foreach ($nl as $line) { imagettftext($im, $ns, 0, (int)$m, (int)$y, $ink, $sansB, mb_strimwidth($line, 0, 70, '…')); $y += $ns * 1.45; }
	foreach ($lines as $k => $line) { imagettftext($im, $P($ms), 0, (int)$m, (int)($yMsg0 + $k * $lh), $ink, $serif, $line); }
	$yShort = max($yShort, $footTop - $shortH + $P(9.5) * 0.4);   // the redeem block rests on the small print, the free room lies between the info and this block
	$y = $yInfo; foreach ($info as $line) { imagettftext($im, $is, 0, (int)$m, (int)$y, $ink, $sansB, $line); $y += $is * 1.45; }
	imagettftext($im, $P(9.5), 0, (int)$m, (int)$yShort, $ink, $sansB, $short[0]); $y = $yShort + $P(9.5) * 1.45;
	foreach ($sl as $line) { imagettftext($im, $ss, 0, (int)$m, (int)$y, $body, $sans, $line); $y += $ss * 1.38; }
	$fy = $H - $mm(6); foreach (array_reverse($fl) as $line) { imagettftext($im, $P(8), 0, (int)$m, (int)$fy, $foot, $sans, $line); $fy -= $P(8) * 1.35; }
	return $im;
}
function gv_print_pdf($o) {
	$W = 2480; $H = 3508; $cw = 1748; $ch = 1240; $x = (int)(($W - $cw) / 2); $y = 330;   // 148 x 105 mm at 300 dpi
	$pages = array();
	foreach (array('front', 'back') as $side) {
		$page = imagecreatetruecolor($W, $H); imagefill($page, 0, 0, 0xFFFFFF);
		$card = $side === 'front' ? gv_render_card($o, $cw) : gv_render_back($o, $cw);
		imagecopy($page, $card, $x, $y, 0, 0, $cw, $ch);
		$gray = gv_hex($page, '9a9488');   // cut marks at the corners of the card
		foreach (array(array($x, $y, -1, -1), array($x + $cw, $y, 1, -1), array($x, $y + $ch, -1, 1), array($x + $cw, $y + $ch, 1, 1)) as $c) {
			imageline($page, $c[0] + $c[2] * 20, $c[1], $c[0] + $c[2] * 70, $c[1], $gray); imageline($page, $c[0], $c[1] + $c[3] * 20, $c[0], $c[1] + $c[3] * 70, $gray);
		}
		$sans = gv_font('Raleway-Regular'); $ny = $y + $ch + 150;
		foreach (gv_wrap(($side === 'front' ? 'Vorderseite' : 'Rückseite').': auf Karton (mindestens 250 g) drucken, an den Marken ausschneiden. Beidseitig drucken, Wenden an der langen Kante.', $sans, 37.5, $cw) as $line) { imagettftext($page, 37.5, 0, $x, $ny, gv_hex($page, '3f392f'), $sans, $line); $ny += 54; }
		ob_start(); imagejpeg($page, null, 90); $pages[] = array(ob_get_clean(), $W, $H);
	}
	return gv_pdf_wrap_jpegs($pages, 'Gutschein-Karte '.($o['number'] ?? ''));
}
