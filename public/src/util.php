<?php
/**
 * 公共工具：下载链接签名、文件名处理
 * 下载地址带 HMAC 签名与有效期，避免本站被当作任意文件的免费代理。
 */

function vs_secret()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $dir = getenv('VS_DATA_DIR');
    if (!$dir) {
        $dir = sys_get_temp_dir();
    }
    $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'video_spider_secret.key';

    if (is_file($file)) {
        $s = trim((string) @file_get_contents($file));
        if (strlen($s) >= 32) {
            $cached = $s;
            return $cached;
        }
    }

    $new = bin2hex(random_bytes(32));
    @file_put_contents($file, $new, LOCK_EX);

    if (is_file($file)) {
        $s = trim((string) @file_get_contents($file));
        if (strlen($s) >= 32) {
            $cached = $s;
            return $cached;
        }
    }

    $cached = $new;
    return $cached;
}

function vs_sign($url, $exp)
{
    return hash_hmac('sha256', $url . '|' . $exp, vs_secret());
}

/**
 * 生成带签名的下载地址
 */
function vs_download_url($url, $filename = '', $ttl = 21600)
{
    if (empty($url)) {
        return '';
    }
    $exp = time() + $ttl;
    return 'dl.php?' . http_build_query(array(
        'u' => $url,
        'e' => $exp,
        's' => substr(vs_sign($url, $exp), 0, 32),
        'n' => $filename ? base64_encode($filename) : '',
    ));
}

/**
 * 把标题清成安全的文件名（保留中文，去掉路径与非法字符）
 */
function vs_safe_name($text, $fallback = 'video')
{
    $text = (string) $text;
    $text = preg_replace('#[\x00-\x1F\x7F]#u', '', $text);
    $text = str_replace(array('\\', '/', ':', '*', '?', '"', '<', '>', '|', "\r", "\n", "\t"), ' ', $text);
    $text = preg_replace('#\s+#u', ' ', $text);
    $text = trim($text, " .\t\n\r\0\x0B");

    if (preg_match('#^.{0,50}#u', $text, $m)) {
        $text = trim($m[0]);
    }

    if ($text === '') {
        $text = $fallback;
    }
    return $text;
}