<?php
/**
 * 下载代理：把远端视频/封面以「附件」形式流式转发给浏览器。
 * 只有携带本站有效签名且未过期的地址才会被转发。
 */

require __DIR__ . '/src/util.php';

@ini_set('display_errors', '0');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 'off');
@set_time_limit(0);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

function bail($code, $msg)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function vs_probe_head($url, $UA, $referer)
{
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => $UA,
        CURLOPT_RETURNTRANSFER => true,
    );
    if ($referer !== '') {
        $opts[CURLOPT_REFERER] = $referer;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return $info;
}

/** 部分源站不支持 HEAD，用 1KB 范围请求兜底 */
function vs_probe_range($url, $UA, $referer)
{
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => $UA,
        CURLOPT_HEADER         => true,
        CURLOPT_RANGE          => '0-1023',
        CURLOPT_RETURNTRANSFER => true,
    );
    if ($referer !== '') {
        $opts[CURLOPT_REFERER] = $referer;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if (is_string($resp) && !empty($info['header_size'])) {
        $raw = substr($resp, 0, $info['header_size']);
        if (preg_match('#Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $raw, $m)) {
            $info['content_length_download'] = (int) $m[1];
        } elseif (preg_match('#Content-Length:\s*(\d+)#i', $raw, $m)) {
            $info['content_length_download'] = (int) $m[1];
        }
        if (preg_match('#Content-Type:\s*([^\s;]+)#i', $raw, $m)) {
            $info['content_type'] = $m[1];
        }
    }
    return $info;
}

$url  = isset($_GET['u']) ? (string) $_GET['u'] : '';
$exp  = isset($_GET['e']) ? (int) $_GET['e'] : 0;
$sign = isset($_GET['s']) ? (string) $_GET['s'] : '';
$name = isset($_GET['n']) ? (string) $_GET['n'] : '';

if ($url === '' || $exp === 0 || $sign === '') {
    bail(400, '下载参数不完整，请回到页面重新解析。');
}
if ($exp < time()) {
    bail(410, '下载链接已过期，请回到页面重新解析。');
}
if (!hash_equals(substr(vs_sign($url, $exp), 0, 32), $sign)) {
    bail(403, '下载链接校验失败，请回到页面重新解析。');
}
if (!preg_match('#^https?://#i', $url)) {
    bail(400, '下载地址不合法。');
}

/* ---- 防 SSRF：拒绝解析到内网/保留地址的主机 ---- */
$host = parse_url($url, PHP_URL_HOST);
if (!$host) {
    bail(400, '下载地址不合法。');
}
$ip = gethostbyname($host);
if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP) === false) {
    bail(400, '无法解析下载地址。');
}
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    bail(403, '该地址不允许下载。');
}

$UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 '
    . '(KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';

$referer = '';
if (strpos($host, 'douyin') !== false || strpos($host, 'iesdouyin') !== false || strpos($host, 'snssdk') !== false) {
    $referer = 'https://www.douyin.com/';
} elseif (strpos($host, 'kuaishou') !== false || strpos($host, 'chenzhongtech') !== false) {
    $referer = 'https://www.kuaishou.com/';
} elseif (strpos($host, 'weibo') !== false || strpos($host, 'sinaimg') !== false) {
    $referer = 'https://weibo.com/';
} elseif (strpos($host, 'ixigua') !== false || strpos($host, 'bytedance') !== false) {
    $referer = 'https://www.ixigua.com/';
}

/* ---- 先探测，确认源站可用并拿到大小，避免给出空文件 ---- */
$info = vs_probe_head($url, $UA, $referer);
$code = (int) $info['http_code'];
$type = (string) $info['content_type'];
$len  = (int) $info['content_length_download'];

if ($code === 0 || $code >= 400) {
    $info = vs_probe_range($url, $UA, $referer);
    $code = (int) $info['http_code'];
    $type = (string) $info['content_type'];
    $len  = (int) $info['content_length_download'];
}

if ($code === 0) {
    bail(502, '连接不上源站，可能已失效或网络受限，请回到页面重新解析。');
}
if ($code >= 400) {
    bail(502, '源站拒绝了下载请求（HTTP ' . $code . '），请回到页面重新解析。');
}
if ($len > 900 * 1024 * 1024) {
    bail(413, '文件过大，已停止转发。');
}

/* ---- 组装文件名 ---- */
$filename = '';
if ($name !== '') {
    $decoded = base64_decode($name, true);
    if ($decoded !== false) {
        $filename = $decoded;
    }
}
$filename = vs_safe_name($filename, 'video');

$isImage = ($type !== '' && stripos($type, 'image/') === 0)
    || preg_match('#\.(jpg|jpeg|png|webp)$#i', $filename);

/* 部分平台返回的是 HLS 播放列表而不是 mp4，按真实格式命名 */
$isStream = stripos((string) $type, 'mpegurl') !== false
    || preg_match('#\.m3u8#i', $url);

if (!preg_match('#\.(mp4|mov|m4v|jpg|jpeg|png|webp|m3u8)$#i', $filename)) {
    $filename .= $isImage ? '.jpg' : ($isStream ? '.m3u8' : '.mp4');
}

if ($type !== '' && preg_match('#^(video/|image/)#i', $type)) {
    $outType = $type;
} else {
    $outType = $isImage ? 'image/jpeg' : ($isStream ? 'application/vnd.apple.mpegurl' : 'video/mp4');
}

/* 兼容老浏览器的 ASCII 回退名 */
$asciiName = preg_replace('#[^\x20-\x7E]#', '_', $filename);
$asciiName = str_replace(array('"', '\\'), '_', $asciiName);
if (trim($asciiName, '_ .') === '') {
    $asciiName = $isImage ? 'cover.jpg' : 'video.mp4';
}

header('Content-Type: ' . $outType);
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
if ($len > 0) {
    header('Content-Length: ' . $len);
}

/* ---- 流式转发 ---- */
$fh = fopen('php://output', 'wb');
$sent = 0;

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 600,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_REFERER        => $referer,
    CURLOPT_HTTPHEADER     => array('Accept: */*'),
    CURLOPT_BUFFERSIZE     => 65536,
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($fh, &$sent) {
        $sent += strlen($chunk);
        $n = fwrite($fh, $chunk);
        fflush($fh);
        if ($n === false || connection_aborted()) {
            return 0;
        }
        return strlen($chunk);
    },
));
curl_exec($ch);
curl_close($ch);
fclose($fh);