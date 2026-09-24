<?php
// Imprint and privacy links under every guest page, from $settings['imprintUrl'] / ['privacyUrl'].
$lf_lang = isset($lang) ? substr($lang, 0, 2) : (isset($_SESSION['lang']) ? substr($_SESSION['lang'], 0, 2) : 'de');
$lf_links = array();
if (!empty($settings['imprintUrl']) && preg_match('#^https?://#i', $settings['imprintUrl'])) { $lf_links[] = array($lf_lang === 'en' ? 'Legal notice' : 'Impressum', $settings['imprintUrl']); }
if (!empty($settings['privacyUrl']) && preg_match('#^https?://#i', $settings['privacyUrl'])) { $lf_links[] = array($lf_lang === 'en' ? 'Privacy policy' : 'Datenschutz', $settings['privacyUrl']); }
if ($lf_links): ?>
<div class="legal-footer" role="group" aria-label="<?php echo $lf_lang === 'en' ? 'Legal' : 'Rechtliches'; ?>">
	<?php foreach ($lf_links as $i => $l): ?><?php if ($i) { echo '<span aria-hidden="true">&middot;</span>'; } ?><a href="<?php echo htmlspecialchars($l[1]); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($l[0]); ?></a><?php endforeach; ?>
</div>
<?php endif; ?>
