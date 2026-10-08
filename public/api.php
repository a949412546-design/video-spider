<?php
/**
 * 短视频去水印解析接口
 * 底层解析逻辑来自开源项目 5ime/video_spider (MIT)，此处仅做路由与容错封装。
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'OPTIONS') {
    exit;
}

require __DIR__ . '/src/video_spider.php';
require __DIR__ . '/src/util.php';

/* 可选配置：第三方解析源、小红书 Cookie 等 */
$vsConfig = @include __DIR__ . '/config.php';
if (!is_array($vsConfig)) {
    $vsConfig = array();
}

use Video_spider\Video;

function respond($payload)
{
    $GLOBALS['__vs_done'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* 无论内部发生什么，都必须返回可读的 JSON —— 不能给用户白屏 */
set_exception_handler(function ($e) {
    respond(array('code' => 201, 'msg' => '解析服务出现异常，请稍后重试'));
});

register_shutdown_function(function () {
    if (!empty($GLOBALS['__vs_done'])) {
        return;
    }
    $err = error_get_last();
    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if ($err && in_array($err['type'], $fatal, true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('code' => 500, 'msg' => '解析服务出现异常，请稍后重试'), JSON_UNESCAPED_UNICODE);
    }
});

function fail($msg, $code = 201)
{
    respond(array('code' => $code, 'msg' => $msg));
}

/* ---------- 自检：部署完成后访问 /api.php?ping=1 应返回 ok ---------- */
if (isset($_GET['ping'])) {
    respond(array(
        'code' => 200,
        'msg' => 'ok',
        'php' => PHP_VERSION,
        'curl' => extension_loaded('curl'),
        'url_fopen' => (bool) ini_get('allow_url_fopen'),
    ));
}

if (!extension_loaded('curl')) {
    fail('服务器未启用 cURL 扩展，无法解析', 500);
}

$input = '';
if (isset($_POST['url'])) {
    $input = (string) $_POST['url'];
} elseif (isset($_GET['url'])) {
    $input = (string) $_GET['url'];
}

if (trim($input) === '') {
    fail('请传入视频分享链接');
}

if (strlen($input) > 4096) {
    fail('链接过长');
}

/* 从整段分享文案里把真正的链接抠出来 */
$url = '';
if (preg_match('#https?://[^\s<>\'"，。、）)】\]]+#iu', $input, $m)) {
    $url = rtrim($m[0], '.,;');
} else {
    fail('没有在内容中识别到有效的视频链接');
}

/* 平台路由表：匹配串 => [方法, 取参方式] */
$routes = array(
    array('pipix', 'pipixia', 'url'),
    array('douyin', 'douyin', 'url'),
    array('xiaohongshu.com', 'xiaohongshu', 'url'),
    array('xhslink.com', 'xiaohongshu', 'url'),
    array('huoshan', 'huoshan', 'url'),
    array('h5.weishi', 'weishi', 'url'),
    array('isee.weishi', 'weishi', 'id'),
    array('weibo.com', 'weibo', 'url'),
    array('oasis.weibo', 'lvzhou', 'url'),
    array('zuiyou', 'zuiyou', 'url'),
    array('xiaochuankeji', 'zuiyou', 'url'),
    array('bbq.bilibili', 'bbq', 'url'),
    array('kuaishou', 'kuaishou', 'url'),
    array('quanmin', 'quanmin', 'url'),
    array('moviebase', 'basai', 'data'),
    array('hanyuhl', 'before', 'url'),
    array('eyepetizer', 'kaiyan', 'url'),
    array('immomo', 'momo', 'url'),
    array('vuevideo', 'vuevlog', 'url'),
    array('xiaokaxiu', 'xiaokaxiu', 'url'),
    array('ippzone', 'pipigaoxiao', 'url'),
    array('pipigx', 'pipigaoxiao', 'url'),
    array('qq.com', 'quanminkge', 'url'),
    array('ixigua.com', 'xigua', 'url'),
    array('doupai', 'doupai', 'url'),
    array('6.cn', 'sixroom', 'url'),
    array('huya.com/play/', 'huya', 'url'),
    array('pearvideo.com', 'pear', 'url'),
    array('xinpianchang.com', 'xinpianchang', 'url'),
    array('acfun.cn', 'acfan', 'url'),
    array('meipai.com', 'meipai', 'url'),
);

$method = null;
$mode = null;
foreach ($routes as $route) {
    if (strpos($url, $route[0]) !== false) {
        $method = $route[1];
        $mode = $route[2];
        break;
    }
}

if ($method === null) {
    fail('暂不支持这个平台的链接。目前支持抖音、小红书、快手、微博、西瓜、皮皮虾、微视、最右、虎牙、美拍等 20+ 平台');
}

if ($method === 'douyin') {
    /* 抖音走移动端 Feed 通道，见 src/douyin.php */
    require_once __DIR__ . '/src/douyin.php';
    $spider = new DouyinSpider();
    $result = $spider->parse($url);
} elseif ($method === 'xiaohongshu') {
    /* 小红书走分享页 SSR，见 src/xiaohongshu.php */
    require_once __DIR__ . '/src/xiaohongshu.php';
    $spider = new XiaohongshuSpider();
    if (!empty($vsConfig['xiaohongshu']['cookie'])) {
        $spider->setCookie($vsConfig['xiaohongshu']['cookie']);
    }
    $result = $spider->parse($url);
} else {
    $api = new Video();

    if ($mode === 'id') {
        if (!preg_match('#feed/(\d+)#', $url, $mm)) {
            fail('无法从链接中识别视频 ID');
        }
        $arg = $mm[1];
    } elseif ($mode === 'data') {
        if (!preg_match('#(\d+)#', $url, $mm)) {
            fail('无法从链接中识别视频 ID');
        }
        $arg = $mm[1];
    } else {
        $arg = $url;
    }

    $result = $api->$method($arg);
}

if (!is_array($result) || empty($result)) {
    fail('解析失败，可能是作品已删除、设为私密或链接已失效');
}

if (isset($result['code']) && $result['code'] != 200) {
    fail(isset($result['msg']) ? $result['msg'] : '解析失败', $result['code']);
}

if (empty($result['data']['url']) && empty($result['data']['images'])) {
    fail('解析失败，未获取到无水印地址');
}

/* 附上本站的下载通道地址（带签名，浏览器点击即存） */
$author = isset($result['data']['author']) ? $result['data']['author'] : '';
$title  = isset($result['data']['title']) ? $result['data']['title'] : '';
$base   = vs_safe_name(trim($author . ' ' . $title), 'video');

if (!empty($result['data']['url'])) {
    $result['data']['filename'] = $base . '.mp4';
    $result['data']['download'] = vs_download_url($result['data']['url'], $base . '.mp4');
}

/* 图集作品：每张图一个下载地址 */
if (!empty($result['data']['images']) && is_array($result['data']['images'])) {
    $imgDownloads = array();
    foreach ($result['data']['images'] as $i => $img) {
        $imgDownloads[] = vs_download_url($img, $base . '-' . ($i + 1) . '.jpg');
    }
    $result['data']['download_images'] = $imgDownloads;
    $result['data']['filename'] = $base . '.jpg';
}

if (!empty($result['data']['cover'])) {
    $result['data']['download_cover'] = vs_download_url($result['data']['cover'], $base . '.jpg');
}

respond($result);