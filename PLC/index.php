<?php

session_start();
//error_reporting(E_ALL & ~E_NOTICE);
//ini_set("display_errors", 1);

// ** clear all old session variables
$_SESSION = array();
$username = "";

// ** Set redirect page
$forwardPage = "../web/main_page.php?p=1";

// ** set configuration
	include('../config/config.general.php');

$brand = isset($settings['brandName']) && $settings['brandName'] !== '' ? $settings['brandName'] : 'mySeat';

	// ** language of the page: ?lang=de|en, otherwise the browser language (German is the default)
	$lang = 'de';
	if (isset($_GET['lang']) && in_array($_GET['lang'], array('de', 'en'), true)) {
		$lang = $_GET['lang'];
	} else if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && preg_match('/^\s*([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $m) && strtolower($m[1]) === 'en') {
		$lang = 'en';
	}
	$T = array(
		'de' => array(
			'title'     => 'Anmelden',
			'heading'   => 'Willkommen zurück',
			'intro'     => 'Melde dich an, um deine Reservierungen zu verwalten.',
			'user'      => 'Benutzername',
			'pass'      => 'Passwort',
			'submit'    => 'Anmelden',
			'need_user' => 'Bitte gib deinen Benutzernamen ein.',
			'need_pass' => 'Bitte gib dein Passwort ein.',
			'failed'    => 'Anmeldung fehlgeschlagen. Benutzername oder Passwort stimmen nicht. %s',
			'blocked'   => 'Zu viele fehlgeschlagene Anmeldungen. Der Zugang ist für %d Minuten gesperrt.',
			'changed'   => 'Das Passwort wurde geändert. Du kannst dich jetzt anmelden.',
			'unsafe'    => 'Dieses Passwort ist nicht erlaubt. Bitte wähle ein anderes.',
			'secure'    => 'Verschlüsselte Verbindung',
			'insecure'  => 'Unverschlüsselte Verbindung',
		),
		'en' => array(
			'title'     => 'Sign in',
			'heading'   => 'Welcome back',
			'intro'     => 'Sign in to manage your reservations.',
			'user'      => 'Username',
			'pass'      => 'Password',
			'submit'    => 'Sign in',
			'need_user' => 'Please enter your username.',
			'need_pass' => 'Please enter your password.',
			'failed'    => 'Sign-in failed. Username or password is incorrect. %s',
			'blocked'   => 'Too many failed sign-in attempts. Access is blocked for %d minutes.',
			'changed'   => 'Your password has been changed. You can sign in now.',
			'unsafe'    => 'This password is not allowed. Please choose another one.',
			'secure'    => 'Encrypted connection',
			'insecure'  => 'Connection not encrypted',
		),
	);
	$t = $T[$lang];
	$isError = true;

// ** init plc login class	
	require_once '../PLC/plc.class.php';
	$dbAccess = array(
	  'dbHost'			=> $settings['dbHost'],
	  'dbName'			=> $settings['dbName'],
	  'dbUser'			=> $settings['dbUser'],
	  'dbPass'			=> $settings['dbPass'],
	  'dbPort'			=> $settings['dbPort'],
	  'dbTablePrefix'	=> $settings['dbTablePrefix']
	 );

	$user = new flexibleAccess('',$dbAccess);
	
// ** auto checkout when going to loginpage
	$user->logout();

// ** User LOGIN **

    if( isset($_POST['submit']) ){
		
		// ** init variables
		$validate = true;
		$username = $_POST['user'];
		
		// ** Validate username and password
		if( strlen($username) <4 ) {
			$message = $t['need_user'];
			$validate = false;
			
		}else if( strlen($_POST['token']) <4 ) {
			$message = $t['need_pass'];
			$validate = false;
		}
		
		// ** Check if user wants to change the password
		$newpassword = "";
		if ( isset($_POST['nPass1']) && isset($_POST['nPass2']) ) {
			if ( $_POST['nPass1'] == $_POST['nPass2'] ) {
				$newpassword = substr($_POST['nPass1'],0,12);
			}else{
				$user->login_matchFalse();	
				exit; //To ensure security
			}
		}

		// ** User LOGIN process if $validate is true
		if($validate){
			$loginAttempt = $user->login(substr($_POST['user'],0,30),substr($_POST['token'],0,12),$newpassword);
	        if ( $loginAttempt == 1 ){
				$message = "";
				header("Location: ".$forwardPage);
				exit; //To ensure security
	        }else if ( $loginAttempt == 0 ){
				$l = 1 + $user->loginAttempts - $user->fAtmp;
				$left = ($lang === 'de') ? ($l == 1 ? 'Noch 1 Versuch.' : 'Noch '.$l.' Versuche.') : ($l == 1 ? '1 attempt left.' : $l.' attempts left.');
					$message = sprintf($t['failed'], $left);
			}else if ( $loginAttempt == 2 ){
				$message = sprintf($t['blocked'], $user->loginTime);
				$username = "";
	    	}else if ( $loginAttempt == 3 ){
				$message = $t['changed']; $isError = false;
				$username = "";
			}else if ( $loginAttempt == 4 ){
				$message = $t['unsafe'];
			}else{
				$message = "";
				$username = "";
			}
		}
	}else{
			$message = "";
	}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<meta name="color-scheme" content="dark"/>
<meta name="theme-color" content="#0c0b0a"/>
<meta name="robots" content="noindex,nofollow"/>
<title><?php echo htmlspecialchars($t['title'].' – '.$brand); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;500&family=Raleway:wght@400;500;600;700&display=swap"/>
<style>
:root {
	--bg: #0c0b0a; --surface: #151312; --surface-2: #1c1a18; --surface-3: #242220;
	--border: rgba(201, 164, 89, 0.22); --border-soft: rgba(245, 240, 230, 0.08);
	--gold: #c9a259; --gold-strong: #e2c07f; --text: #f3ede1; --text-muted: #a89e8c;
	--danger: #e2867c; --success: #8fbf7a;
	--font-display: 'Cormorant Garamond', Georgia, serif;
	--font-body: 'Raleway', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
	margin: 0; min-height: 100vh; min-height: 100dvh;
	display: flex; flex-direction: column; align-items: center; justify-content: center;
	padding: max(24px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
	background: radial-gradient(120% 80% at 50% 0%, #1a1712 0%, var(--bg) 60%);
	color: var(--text-muted); font-family: var(--font-body); font-size: 15px; line-height: 1.5;
}
.lang { position: absolute; top: max(16px, env(safe-area-inset-top)); right: max(16px, env(safe-area-inset-right)); display: flex; gap: 2px; padding: 3px; border: 1px solid var(--border-soft); border-radius: 999px; background: var(--surface); }
.lang a { padding: 4px 12px; border-radius: 999px; color: var(--text-muted); font-size: 12px; font-weight: 600; letter-spacing: .08em; text-decoration: none; }
.lang a[aria-current="true"] { background: var(--gold); color: var(--bg); }
.lang a:focus-visible { outline: 2px solid var(--gold-strong); outline-offset: 2px; }
.brand { margin: 0 0 28px; font-family: var(--font-display); font-weight: 300; font-size: clamp(40px, 12vw, 56px); line-height: 1; color: var(--text); text-align: center; }
.card { width: 100%; max-width: 420px; padding: clamp(22px, 6vw, 36px); background: var(--surface); border: 1px solid var(--border-soft); border-radius: 16px; }
.card h1 { margin: 0 0 6px; font-family: var(--font-display); font-weight: 500; font-size: 30px; line-height: 1.15; color: var(--text); }
.card .intro { margin: 0 0 24px; color: var(--text-muted); }
.notice { margin: 0 0 20px; padding: 12px 14px; border-radius: 10px; font-size: 14px; border: 1px solid var(--danger); background: rgba(226, 134, 124, .1); color: var(--danger); }
.notice.is-ok { border-color: var(--success); background: rgba(143, 191, 122, .1); color: var(--success); }
.field { margin-bottom: 18px; }
.field label { display: block; margin-bottom: 6px; color: var(--text); font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
.field input { width: 100%; height: 50px; padding: 0 16px; font: inherit; font-size: 16px; color: var(--text); background: var(--surface-2); border: 1px solid var(--border-soft); border-radius: 10px; transition: border-color .15s, box-shadow .15s; }
.field input:hover { border-color: var(--border); }
.field input:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(201, 162, 89, .22); }
.kbdicon { vertical-align: middle; margin-left: 6px; cursor: pointer; }
.submit { width: 100%; height: 52px; margin-top: 6px; font: inherit; font-size: 16px; font-weight: 700; letter-spacing: .04em; color: var(--bg); background: var(--gold); border: 0; border-radius: 999px; cursor: pointer; transition: background .15s, transform .05s; }
.submit:hover { background: var(--gold-strong); }
.submit:active { transform: translateY(1px); }
.submit:focus-visible { outline: 2px solid var(--gold-strong); outline-offset: 3px; }
.secure { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 22px 0 0; font-size: 13px; color: var(--text-muted); }
.secure svg { width: 16px; height: 16px; flex: none; }
.secure.is-warn { color: var(--danger); }
@media (max-width: 380px) { .card h1 { font-size: 26px; } }
@media (max-height: 560px) { body { justify-content: flex-start; } .brand { margin-bottom: 16px; } }
</style>
<?php include_once "../web/includes/onscreenkbd.inc.php"; ?>
</head>
<body class="login">
	<nav class="lang" aria-label="Language">
		<a href="?lang=de" hreflang="de"<?php echo $lang === 'de' ? ' aria-current="true"' : ''; ?>>DE</a>
		<a href="?lang=en" hreflang="en"<?php echo $lang === 'en' ? ' aria-current="true"' : ''; ?>>EN</a>
	</nav>

	<div class="brand" role="banner"><?php echo htmlspecialchars($brand); ?></div>

	<main class="card">
		<h1><?php echo htmlspecialchars($t['heading']); ?></h1>
		<p class="intro"><?php echo htmlspecialchars($t['intro']); ?></p>

		<?php if ($message != '') { ?>
			<div class="notice<?php echo $isError ? '' : ' is-ok'; ?>" role="alert"><?php echo htmlspecialchars($message); ?></div>
		<?php } ?>

		<form name="loginform" id="loginform" action="index.php?lang=<?php echo $lang; ?>" method="post">
			<div class="field">
				<label for="login-user"><?php echo htmlspecialchars($t['user']); ?></label>
				<input type="text" id="login-user" name="user" class="qwerty" maxlength="20" value="<?php echo htmlspecialchars($username); ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required<?php echo $username === '' ? ' autofocus' : ''; ?>/>
				<?php echo $osk_img; ?>
			</div>
			<div class="field">
				<label for="login-pass"><?php echo htmlspecialchars($t['pass']); ?></label>
				<input type="password" id="login-pass" name="token" class="qwerty" maxlength="12" value="" autocomplete="current-password" required<?php echo $username !== '' ? ' autofocus' : ''; ?>/>
				<?php echo $osk_img; ?>
			</div>
			<button type="submit" name="submit" value="1" class="submit"><?php echo htmlspecialchars($t['submit']); ?></button>
		</form>

		<?php $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443); ?>
		<p class="secure<?php echo $https ? '' : ' is-warn'; ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $https ? '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>' : '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/>'; ?></svg>
			<?php echo htmlspecialchars($https ? $t['secure'] : $t['insecure']); ?>
		</p>
	</main>
</body>
</html>
