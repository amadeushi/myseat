<?php
/*
 * The picture of a voucher and its PDF. The card (3:2, 190 x 127 mm) is drawn with GD in the fonts of the shop (Cormorant Garamond, Raleway: web/fonts/gutschein, SIL Open Font License):
 * photo or drawing as the background, a dark fade for the text, wordmark, value, names, message, number, security code, validity and a QR code. The PDF is one A4 page made of
 * a 300 dpi picture of the page (card + how to redeem + short terms): that keeps the typography exactly as drawn and needs no PDF library.
 * Own pictures of the guests are stored re-encoded (no EXIF, no metadata) in uploads/gutschein, which is closed for the web; they are only shown through the preview and the PDF.
 */

require_once __DIR__.'/qrcode.class.php';

const GV_CARD_W_MM = 190;
const GV_CARD_H_MM = 127;

// the motifs the guest can choose: key => label, group, file (in order/gutschein/motive). The owner can switch single ones off with the setting gv_motifs_off (keys, comma separated).
function gv_motifs() {
	return array(
		'geburtstag' => array('label' => 'Geburtstag', 'group' => 'Anlass'), 'jubilaeum' => array('label' => 'Jubiläum', 'group' => 'Anlass'), 'umschlag' => array('label' => 'Brief', 'group' => 'Anlass'),
		'schnitzel' => array('label' => 'Schnitzel', 'group' => 'Aus der Küche'), 'steak' => array('label' => 'Steak', 'group' => 'Aus der Küche'), 'pizza' => array('label' => 'Pizza', 'group' => 'Aus der Küche'),
		'salat' => array('label' => 'Salat', 'group' => 'Aus der Küche'), 'kuchen' => array('label' => 'Kuchen und Kaffee', 'group' => 'Aus der Küche'));
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
	if ($info[0] < 900 || $info[1] < 600) { return array('ok' => false, 'error' => 'Das Bild ist zu klein für einen scharfen Druck (mindestens 900 x 600 Pixel).'); }
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

function gv_qr_url($number) { return rtrim(shop_site_url(), '/').'/order/gutschein.php?g='.rawurlencode($number); }

/*
 * Draw the card. $o: motif, amount_cents, recipient, from, message, number, code, valid_until (Y-m-d or ''), brand. Returns a GD image of $w x ($w * 2 / 3) pixels.
 */
function gv_render_card($o, $w) {
	$H = (int)round($w * GV_CARD_H_MM / GV_CARD_W_MM); $u = $w / 1000;
	$im = imagecreatetruecolor($w, $H); imagealphablending($im, true);
	imagefill($im, 0, 0, gv_hex($im, '14100c'));
	$file = gv_motif_file($o['motif'] ?? '');
	if ($file !== '') {   // the picture fills the card (cut at the edges, never stretched)
		$src = @imagecreatefromjpeg($file);
		if ($src) {
			$sw = imagesx($src); $sh = imagesy($src); $s = max($w / $sw, $H / $sh);
			$cw = (int)round($w / $s); $ch = (int)round($H / $s);
			imagecopyresampled($im, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $w, $H, $cw, $ch);
		}
	}
	// the logo is white on a dark picture and black on a bright one: measured where it sits (before the fades)
	$lum = 0; $cnt = 0;
	for ($yy = (int)(20 * $u); $yy < 150 * $u; $yy += max(2, (int)(8 * $u))) { for ($xx = (int)(30 * $u); $xx < 300 * $u; $xx += max(2, (int)(8 * $u))) { $c = imagecolorat($im, $xx, $yy); $lum += (0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255)) / 255; $cnt++; } }
	$bright = $cnt > 0 && $lum / $cnt > 0.6;
	for ($y = 0; $y < $H; $y++) {   // fades: at the top (logo) light on a bright picture, else dark; dark at the bottom (text)
		$t = $y / $H; $a = 0.0; $top = 0.0;
		if ($t < 0.28) { $top = ($bright ? 0.5 : 0.62) * (1 - $t / 0.28); }
		if ($top > 0.004) { imagefilledrectangle($im, 0, $y, $w, $y, gv_hex($im, $bright ? 'fbf4e2' : '120d09', (int)round(127 * (1 - $top)))); }
		if ($t > 0.34) { $a = min(0.93, 0.93 * pow(($t - 0.34) / 0.5, 0.9)); }
		if ($a > 0.004) { imagefilledrectangle($im, 0, $y, $w, $y, gv_hex($im, '120d09', (int)round(127 * (1 - $a)))); }
	}
	$serif = gv_font('CormorantGaramond-SemiBold'); $serifM = gv_font('CormorantGaramond-Medium'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$gold = gv_hex($im, 'e6c57e'); $cream = gv_hex($im, 'fbf4e2'); $soft = gv_hex($im, 'fbf4e2', 30);
	$px = function ($v) use ($u) { return $v * $u * 0.75; };   // design size in "u" -> GD point size (points are 4/3 pixels)
	$m = 44 * $u;

	$logoFile = gv_logo_file($bright);
	if ($logoFile !== '' && ($lg = @imagecreatefrompng($logoFile))) {   // the logo of the restaurant (order/gutschein/logo-weiss.png / logo-schwarz.png)
		$lw = (int)round(215 * $u); $lh = (int)round($lw * imagesy($lg) / imagesx($lg));
		imagealphablending($im, true); imagecopyresampled($im, $lg, (int)$m, (int)(34 * $u), 0, 0, $lw, $lh, imagesx($lg), imagesy($lg));
	} else {
		imagettftext($im, $px(46), 0, (int)$m, (int)(76 * $u), $bright ? gv_hex($im, '1a140e') : $cream, $serif, (string)($o['brand'] ?? 'Amadeus'));
		gv_text_spaced($im, $px(11.5), $m + 2 * $u, 104 * $u, $bright ? gv_hex($im, '5b4520') : $gold, $sansB, 'CAFÉ · RESTAURANT · BAR', 3.2 * $u);
	}

	// bottom block, built from the bottom up
	$y = $H - 40 * $u;
	$info = 'Nr. '.($o['number'] ?? '').'     Code '.($o['code'] ?? '').(!empty($o['valid_until']) ? '     gültig bis '.date('d.m.Y', strtotime($o['valid_until'])) : '');
	imagettftext($im, $px(14.5), 0, (int)$m, (int)$y, $soft, $sans, $info);
	$y -= 52 * $u;
	$msg = trim((string)($o['message'] ?? ''));
	if ($msg !== '') {
		$lines = gv_wrap($msg, $serifM, $px(27), 560 * $u, 3);
		for ($i = count($lines) - 1; $i >= 0; $i--) { imagettftext($im, $px(27), 0, (int)$m, (int)$y, $cream, $serifM, $lines[$i]); $y -= 33 * $u; }
		$y -= 6 * $u;
	}
	$names = array();
	if (trim((string)($o['recipient'] ?? '')) !== '') { $names[] = 'Für '.trim($o['recipient']); }
	if (trim((string)($o['from'] ?? '')) !== '') { $names[] = 'Von '.trim($o['from']); }
	if ($names) { imagettftext($im, $px(16.5), 0, (int)$m, (int)$y, $gold, $sansB, implode('   ·   ', $names)); $y -= 36 * $u; }
	imagettftext($im, $px(112), 0, (int)($m - 4 * $u), (int)$y, $cream, $serif, gv_money_short((int)($o['amount_cents'] ?? 0)));
	$y -= 112 * $u;
	gv_text_spaced($im, $px(13), $m + 2 * $u, $y, $gold, $sansB, 'GUTSCHEIN', 5.5 * $u);

	// QR code on a light square
	$qr = qr_matrix(gv_qr_url($o['number'] ?? ''));
	if ($qr) {
		$n = count($qr); $mod = max(1, (int)floor(138 * $u / ($n + 2))); $side = ($n + 2) * $mod; $pad = 9 * $u;
		$bx = $w - $m - $side - 2 * $pad; $by = $H - 40 * $u - $side - 2 * $pad;
		imagefilledrectangle($im, (int)$bx, (int)$by, (int)($bx + $side + 2 * $pad), (int)($by + $side + 2 * $pad), gv_hex($im, 'fbf4e2'));
		$ink = gv_hex($im, '1a140e');
		for ($r = 0; $r < $n; $r++) { for ($c = 0; $c < $n; $c++) { if ($qr[$r][$c]) {
			$x0 = (int)($bx + $pad + ($c + 1) * $mod); $y0 = (int)($by + $pad + ($r + 1) * $mod);
			imagefilledrectangle($im, $x0, $y0, $x0 + $mod - 1, $y0 + $mod - 1, $ink);
		} } }
	}
	return $im;
}
function gv_card_jpeg($o, $w, $q = 86) {
	$im = gv_render_card($o, $w);
	ob_start(); imagejpeg($im, null, $q); $b = ob_get_clean();
	return $b;
}

// the texts under the card on the PDF (draft after AGB 5 and 6; the owner approves the wording): array of array(heading, text)
function gv_pdf_terms($o) {
	$shop = trim((string)shop_setting('gv_shop_host'));   // the public address of the web shop as printed (amadeus.delivery); the QR code leads to the order system itself
	return array(
		array('Im Restaurant einlösen', 'Nenne uns die Gutscheinnummer oder zeige den QR-Code. Du kannst den ganzen Betrag oder einen Teil einlösen. Das Restguthaben bleibt auf dem Gutschein.'),
		array('Online einlösen', 'Beim Bestellen im Webshop '.($shop !== '' ? $shop.' ' : '').'gibst du die Gutscheinnummer und den Sicherheitscode ein. Der Betrag wird von der Bestellung abgezogen, ein Rest bleibt erhalten.'),
		array('Wichtig', 'Der Gutschein ist bis '.(!empty($o['valid_until']) ? date('d.m.Y', strtotime($o['valid_until'])) : 'zum aufgedruckten Datum').' gültig. Eine Barauszahlung ist nicht möglich. Er gilt wie Bargeld: Bitte bewahre ihn gut auf, bei Verlust gibt es keinen Ersatz. Es handelt sich um einen Mehrzweck-Gutschein, die Umsatzsteuer entsteht erst bei der Einlösung.'),
	);
}

// the A4 page as a picture (300 dpi): card at the top, instructions below
function gv_page_jpeg($o) {
	$W = 2480; $H = 3508; $cw = 2244; $ch = (int)round($cw * GV_CARD_H_MM / GV_CARD_W_MM);
	$page = imagecreatetruecolor($W, $H); imagefill($page, 0, 0, 0xFFFFFF);
	$card = gv_render_card($o, $cw);
	$x = (int)(($W - $cw) / 2); $y = 177;
	imagecopy($page, $card, $x, $y, 0, 0, $cw, $ch);
	$gray = gv_hex($page, '9a9488'); $ink = gv_hex($page, '2a2118'); $mut = gv_hex($page, '5b5346');   // cut marks at the corners of the card
	foreach (array(array($x, $y, -1, -1), array($x + $cw, $y, 1, -1), array($x, $y + $ch, -1, 1), array($x + $cw, $y + $ch, 1, 1)) as $c) {
		imageline($page, $c[0] + $c[2] * 16, $c[1], $c[0] + $c[2] * 56, $c[1], $gray); imageline($page, $c[0], $c[1] + $c[3] * 16, $c[0], $c[1] + $c[3] * 56, $gray);
	}
	$serif = gv_font('CormorantGaramond-SemiBold'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$ty = $y + $ch + 150;
	imagettftext($page, 50 * 0.75, 0, $x, $ty, $ink, $serif, 'So löst du deinen Gutschein ein'); $ty += 96;
	foreach (gv_pdf_terms($o) as $t) {
		imagettftext($page, 34 * 0.75, 0, $x, $ty, $ink, $sansB, $t[0]); $ty += 58;
		foreach (gv_wrap($t[1], $sans, 34 * 0.75, $cw) as $line) { imagettftext($page, 34 * 0.75, 0, $x, $ty, $mut, $sans, $line); $ty += 52; }
		$ty += 46;
	}
	$lf = gv_logo_file(true);   // black logo on the white page
	if ($lf !== '' && ($lg = @imagecreatefrompng($lf))) { $lw = 520; $lh = (int)round($lw * imagesy($lg) / imagesx($lg)); imagecopyresampled($page, $lg, $x + $cw - $lw, $H - 120 - $lh, 0, 0, $lw, $lh, imagesx($lg), imagesy($lg)); }
	$fy = $H - 190; foreach (array_reverse(gv_wrap(implode('  ·  ', gv_legal_lines()), $sans, 28 * 0.75, $cw - 600)) as $line) { imagettftext($page, 28 * 0.75, 0, $x, $fy, $gray, $sans, $line); $fy -= 44; }
	ob_start(); imagejpeg($page, null, 88); $b = ob_get_clean();
	return array($b, $W, $H);
}

// one-page PDF (A4) around a JPEG; no library needed
function gv_pdf_wrap_jpeg($jpeg, $px, $py, $title) {
	$pw = 595.28; $ph = 841.89; $objs = array();
	$objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
	$objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
	$objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Resources << /XObject << /Im0 5 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 4 0 R >>";
	$content = "q $pw 0 0 $ph 0 0 cm /Im0 Do Q";
	$objs[4] = "<< /Length ".strlen($content)." >>\nstream\n$content\nendstream";
	$objs[5] = "<< /Type /XObject /Subtype /Image /Width $px /Height $py /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream";
	$t = preg_replace('/[^\x20-\x7E]/', '', (string)$title);
	$objs[6] = "<< /Title (".str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $t).") /Producer (mySeat) /CreationDate (D:".date('YmdHis').") >>";
	$out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = array();
	foreach ($objs as $n => $body) { $off[$n] = strlen($out); $out .= "$n 0 obj\n$body\nendobj\n"; }
	$xref = strlen($out);
	$out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
	foreach ($objs as $n => $_) { $out .= sprintf("%010d 00000 n \n", $off[$n]); }
	$out .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R /Info 6 0 R >>\nstartxref\n$xref\n%%EOF\n";
	return $out;
}
function gv_pdf($o) {
	list($jpeg, $w, $h) = gv_page_jpeg($o);
	return gv_pdf_wrap_jpeg($jpeg, $w, $h, 'Gutschein '.($o['number'] ?? ''));
}
