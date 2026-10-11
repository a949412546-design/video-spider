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

/**
 * 用 HEAD 探测源站。
 *
 * 注意：一旦开 CURLOPT_NOBODY，curl_getinfo 的长度字段恒为 -1，
 * 必须自己从响应头里解析 Content-Length，否则拿不到文件大小，
 * 下游（尤其是微信）会显示「文件大小 0B」。
 */
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
        CURLOPT_HEADER         => true,
    );
    if ($referer !== '') {
        $opts[CURLOPT_REFERER] = $referer;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $info['content_length'] = 0;
    if (is_string($resp) && !empty($info['header_size'])) {
        $raw = substr($resp, 0, $info['header_size']);
        if (preg_match_all('#^Content-Length:\s*(\d+)#im', $raw, $m) && !empty($m[1])) {
            $info['content_length'] = (int) end($m[1]);
        }
        if (empty($info['content_type']) && preg_match_all('#^Content-Type:\s*([^\s;]+)#im', $raw, $m2) && !empty($m2[1])) {
            $info['content_type'] = end($m2[1]);
        }
    }
    return $info;
}

/** 部分源站不支持 HEAD，用 1KB 范围请求兜底，从 Content-Range 里读总长度 */
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

    $info['content_length'] = 0;
    if (is_string($resp) && !empty($info['header_size'])) {
        $raw = substr($resp, 0, $info['header_size']);
        if (preg_match_all('#Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $raw, $m) && !empty($m[1])) {
            $info['content_length'] = (int) end($m[1]);
        } elseif (preg_match_all('#^Content-Length:\s*(\d+)#im', $raw, $m2) && !empty($m2[1])) {
            $info['content_length'] = (int) end($m2[1]);
        }
        if (empty($info['content_type']) && preg_match_all('#^Content-Type:\s*([^\s;]+)#im', $raw, $m3) && !empty($m3[1])) {
            $info['content_type'] = end($m3[1]);
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

/* ---- 先探测，确认源站可用并拿到真实大小 ---- */
$info = vs_probe_head($url, $UA, $referer);
$code = (int) $info['http_code'];
$type = (string) $info['content_type'];
$len  = (int) $info['content_length'];

if ($code === 0 || $code >= 400 || $len <= 0) {
    $info = vs_probe_range($url, $UA, $referer);
    $code = (int) $info['http_code'];
    $type = (string) $info['content_type'];
    $len  = (int) $info['content_length'];
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
$filename = str_replace(array("\r", "\n", "\t"), ' ', (string) $filename);

/*
 * 判断类型必须在截断之前做。
 * 文件名清洗会把长标题截到 50 字，扩展名会被一起截掉，
 * 那样就会把 jpg 当成 mp4，微信打开就是坏的。
 */
$isImage = preg_match('#\.(jpg|jpeg|png|webp)$#i', $filename)
    || ($type !== '' && stripos($type, 'image/') === 0);

$isStream = stripos((string) $type, 'mpegurl') !== false
    || preg_match('#\.m3u8#i', $url);

/* 去掉原扩展名后再清洗，最后补上正确的扩展名 */
$ext  = $isImage ? '.jpg' : ($isStream ? '.m3u8' : '.mp4');
$base = preg_replace('#\.(mp4|mov|m4v|jpg|jpeg|png|webp|m3u8)$#i', '', $filename);
$filename = vs_safe_name($base, 'video') . $ext;

if ($type !== '' && preg_match('#^(video/|image/)#i', $type)) {
    $outType = $type;
} else {
    $outType = $isImage ? 'image/jpeg' : ($isStream ? 'application/vnd.apple.mpegurl' : 'video/mp4');
}

$asciiName = preg_replace('#[^\x20-\x7E]#', '_', $filename);
$asciiName = str_replace(array('"', '\\'), '_', $asciiName);
if (trim($asciiName, '_ .') === '') {
    $asciiName = $isImage ? 'cover.jpg' : 'video.mp4';
}

/*
 * ---- 解析客户端要的文件区间 ----
 * 手机上的下载器（微信、各类浏览器）普遍会先用 Range 探一下，
 * 拿不到 206 就容易判断成「文件大小 0B」。所以这里把 Range 透传给源站。
 */
$start = 0;
$end   = ($len > 0) ? $len - 1 : 0;
$partial = false;

if ($len > 0 && !empty($_SERVER['HTTP_RANGE'])
    && preg_match('#bytes=(\d*)-(\d*)#i', (string) $_SERVER['HTTP_RANGE'], $rm)) {
    $from = ($rm[1] === '') ? -1 : (int) $rm[1];
    $to   = ($rm[2] === '') ? -1 : (int) $rm[2];

    if ($from < 0) {
        /* bytes=-N：最后 N 字节 */
        $n = $to > 0 ? $to : 0;
        $start = max(0, $len - $n);
        $end   = $len - 1;
    } else {
        $start = $from;
        $end   = ($to >= 0) ? min($to, $len - 1) : $len - 1;
    }

    if ($start > $end || $start >= $len) {
        http_response_code(416);
        header('Content-Range: bytes */' . $len);
        exit;
    }
    $partial = true;
}

header('Content-Type: ' . $outType);
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

if ($len > 0) {
    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $len);
        header('Content-Length: ' . ($end - $start + 1));
    } else {
        /* 一定要给出长度：否则响应会变成 chunked，微信会显示「文件大小 0B」 */
        header('Content-Length: ' . $len);
    }
}

/* ---- 流式转发 ---- */
$fh = fopen('php://output', 'wb');

$ch = curl_init($url);
$curlOpts = array(
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
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($fh) {
        $n = fwrite($fh, $chunk);
        fflush($fh);
        if ($n === false || connection_aborted()) {
            return 0;
        }
        return strlen($chunk);
    },
);
if ($partial) {
    $curlOpts[CURLOPT_RANGE] = $start . '-' . $end;
}
curl_setopt_array($ch, $curlOpts);
curl_exec($ch);
curl_close($ch);
fclose($fh);