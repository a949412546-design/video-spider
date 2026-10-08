<?php
/**
 * 小红书解析 —— 分享页 SSR 数据提取
 *
 * 小红书用的是 Nuxt/Vue 服务端渲染，笔记详情、高清媒体流、作者信息
 * 都已经内嵌在页面 HTML 的 window.__INITIAL_STATE__ 里。
 *
 * 两个关键点（踩过坑，别改）：
 *  1. 必须保留分享链接里的 xsec_token / xsec_source / source 等参数。
 *     只拿笔记 ID 去拼地址，服务端会直接返回 404 或拦截。
 *  2. __INITIAL_STATE__ 不是严格 JSON，里面混着 undefined、new Map()、
 *     new Set()、new Date()，必须先清洗再 json_decode。
 *
 * 实现思路参考了开源项目 ucmao/media-parser 的公开技术文档。
 */

class XiaohongshuSpider
{
    /** App 分享链接走移动端 H5，桌面复制链接走 PC 端 */
    private $mobileUa = 'Mozilla/5.0 (Linux; Android 13; Pixel 6) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
    private $pcUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private $cookie = '';

    public function setCookie($cookie)
    {
        $this->cookie = trim((string) $cookie);
    }

    public function parse($url)
    {
        $url = trim($url);
        if ($url === '') {
            return array('code' => 400, 'msg' => '请输入小红书链接');
        }

        $real = $this->resolveUrl($url);
        $id = $this->extractId($real);
        if (!$id) {
            return array('code' => 400, 'msg' => '无法从链接中识别小红书笔记 ID');
        }

        $hasToken = (strpos($real, 'xsec_token=') !== false);
        $mobile = (strpos($real, 'app_platform=') !== false
                || strpos($real, 'xhslink.') !== false
                || strpos($url, 'xhslink.') !== false);

        $order = $mobile ? array('mobile', 'pc') : array('pc', 'mobile');
        $note = null;
        $redirectedToLogin = false;

        foreach ($order as $mode) {
            $res = $this->fetch($real, $mode);
            $html = $res['body'];
            if ($html === '') {
                continue;
            }
            /*
             * 判断是否被风控跳到登录页。
             * 注意不能用 <title> 判断——正常笔记页的标题也是「小红书」，
             * 之前就是这里把成功的请求误判成了风控。
             */
            $looksLogin = (strpos($res['url'], '/login') !== false)
                || (strpos($html, 'class="login-container"') !== false);
            $hasNote = (strpos($html, 'noteData') !== false)
                || (strpos($html, 'noteDetailMap') !== false);
            if ($looksLogin && !$hasNote) {
                $redirectedToLogin = true;
                continue;
            }
            $note = $this->extractNote($html, $id);
            if ($note) {
                break;
            }
        }

        if (!$note) {
            if (!$hasToken) {
                return array('code' => 201, 'msg' => '小红书需要分享链接里的签名参数。请在 App 里点「分享 → 复制链接」，直接粘贴完整内容，不要只复制笔记地址。');
            }
            if ($redirectedToLogin) {
                return array('code' => 201, 'msg' => '小红书把这次请求判定为风险访问（服务器 IP 常见）。请稍后重试，或在 config.php 里配置小红书 Cookie 以提高成功率。');
            }
            return array('code' => 201, 'msg' => '没有取到笔记数据，笔记可能已删除、仅自己可见，或链接已过期');
        }

        return $this->buildResult($note);
    }

    /* ---------------- 请求与解析 ---------------- */

    private function resolveUrl($url)
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== '' && substr($host, -15) === 'xiaohongshu.com') {
            return $url;
        }

        /* 短链：跟随跳转，并且必须保住 xsec_token 等参数 */
        $loc = $this->headLocation($url);
        if ($loc !== '') {
            return $loc;
        }

        /* xhslink.cn 不支持 HEAD（会返回 404），只能退回 GET 拿最终地址 */
        return $this->finalUrl($url);
    }

    /** 用 HEAD 取 Location，省流量 */
    private function headLocation($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => $this->mobileUa,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
        ));
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $next = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if (is_string($next) && $next !== '') {
            return $next;
        }
        if (is_string($resp) && !empty($info['header_size'])) {
            $raw = substr($resp, 0, $info['header_size']);
            if (preg_match_all('#^Location:\s*(.+)$#im', $raw, $m) && !empty($m[1])) {
                return trim(end($m[1]));
            }
        }
        return '';
    }

    /** 用 GET 跟随跳转，取最终地址 */
    private function finalUrl($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => $this->mobileUa,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => 'gzip,deflate',
        ));
        curl_exec($ch);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return (is_string($final) && $final !== '') ? $final : '';
    }
    private function fetch($url, $mode)
    {
        $ua = ($mode === 'mobile') ? $this->mobileUa : $this->pcUa;
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_HTTPHEADER     => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9',
            ),
        );
        if ($this->cookie !== '') {
            $opts[CURLOPT_COOKIE] = $this->cookie;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($code !== 200 || !is_string($body) || strlen($body) < 500) {
            return array('body' => '', 'url' => $final);
        }
        return array('body' => $body, 'url' => $final);
    }

    private function extractId($url)
    {
        foreach (array(
            '#/explore/([0-9a-fA-F]{24})#',
            '#/discovery/item/([0-9a-fA-F]{24})#',
            '#/user/profile/[0-9a-fA-F]+/([0-9a-fA-F]{24})#',
            '#/item/([0-9a-fA-F]{24})#',
            '#[?&]note_id=([0-9a-fA-F]{24})#',
        ) as $pat) {
            if (preg_match($pat, $url, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /** 从页面里把笔记数据挖出来 */
    private function extractNote($html, $id)
    {
        $json = $this->extractState($html);
        if (!$json) {
            return null;
        }

        /* PC 端结构 */
        if (isset($json['note']['noteDetailMap']) && is_array($json['note']['noteDetailMap'])) {
            $map = $json['note']['noteDetailMap'];
            $first = isset($json['note']['firstNoteId']) ? $json['note']['firstNoteId'] : '';
            foreach (array($id, $first) as $key) {
                if ($key && isset($map[$key]['note']) && is_array($map[$key]['note'])) {
                    return $map[$key]['note'];
                }
            }
            foreach ($map as $entry) {
                if (isset($entry['note']) && is_array($entry['note']) && !empty($entry['note'])) {
                    return $entry['note'];
                }
            }
        }

        /* 移动端 H5 结构 */
        if (isset($json['noteData']['data']['noteData'])
            && is_array($json['noteData']['data']['noteData'])
            && !empty($json['noteData']['data']['noteData'])) {
            return $json['noteData']['data']['noteData'];
        }

        return null;
    }

    /** 提取 __INITIAL_STATE__ 并清洗成合法 JSON */
    private function extractState($html)
    {
        $raw = '';
        if (preg_match('#window\.__INITIAL_STATE__\s*=\s*(\{.*?\})\s*</script>#is', $html, $m)) {
            $raw = $m[1];
        } elseif (preg_match('#window\.__INITIAL_STATE__\s*=\s*(\{.*\})#is', $html, $m)) {
            $raw = $m[1];
        }
        if ($raw === '') {
            return null;
        }

        /* 清洗 JavaScript 特有的写法 */
        $raw = preg_replace('#\bundefined\b#', 'null', $raw);
        $raw = preg_replace('#\bNaN\b#', 'null', $raw);
        $raw = preg_replace('#new\s+Map\s*\([^)]*\)#i', '{}', $raw);
        $raw = preg_replace('#new\s+Set\s*\([^)]*\)#i', '[]', $raw);
        $raw = preg_replace('#new\s+Date\s*\([^)]*\)#i', 'null', $raw);

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    /* ---------------- 组装结果 ---------------- */

    private function buildResult($note)
    {
        $data = array(
            'author' => $this->pickAuthor($note),
            'avatar' => $this->pickStr($note, array('user', 'avatar')),
            'title'  => $this->pickTitle($note),
            'cover'  => $this->cleanImage($this->pickCover($note), $this->coverFileId($note)),
            'time'   => $this->pickStr($note, array('time')),
            'like'   => $this->pickLike($note),
        );
        $data['avatar'] = $this->ensureHttps((string) $data['avatar']);

        /* 图集 */
        $images = array();
        if (!empty($note['imageList']) && is_array($note['imageList'])) {
            foreach ($note['imageList'] as $img) {
                if (!is_array($img)) {
                    continue;
                }
                $u = '';
                if (!empty($img['urlDefault'])) {
                    $u = $img['urlDefault'];
                } elseif (!empty($img['urlPre'])) {
                    $u = $img['urlPre'];
                } elseif (!empty($img['infoList'][0]['url'])) {
                    $u = $img['infoList'][0]['url'];
                }
                if ($u !== '') {
                    $fid = isset($img['fileId']) ? (string) $img['fileId'] : '';
                    $images[] = $this->cleanImage($u, $fid);
                }
            }
        }
        if ($images) {
            $data['images'] = $images;
            if (empty($data['cover'])) {
                $data['cover'] = $images[0];
            }
        }

        /* 视频：取全部可选清晰度，第一个作为默认 */
        $qualities = $this->collectQualities($note);
        if ($qualities) {
            $data['url'] = $qualities[0]['url'];
            if (count($qualities) > 1) {
                $data['qualities'] = $qualities;
            }
        }

        if (empty($data['url']) && empty($data['images'])) {
            return array('code' => 201, 'msg' => '笔记里没有找到视频或图片');
        }

        return array('code' => 200, 'msg' => '解析成功', 'data' => $data);
    }

    /**
     * 无水印视频地址，按可靠度从高到低找：
     * 1. 作者原始上传的原画 key
     * 2. mediaV2 里的投屏流
     * 3. 码率流列表（排除 259 / 309，那是小程序带水印的）
     */
    /**
     * 收集可选清晰度。
     *
     * 原画是作者上传的原始文件，画质最好但体积可能很大（实测有 126MB 的），
     * 所以同时把网页播放流也带上，让用户自己选。
     * streamType 为 259 / 309 的是小程序带水印流，必须排除。
     */
    private function collectQualities($note)
    {
        $out = array();
        $video = isset($note['video']) && is_array($note['video']) ? $note['video'] : null;
        if (!$video) {
            return $out;
        }

        $key = $this->pickStr($video, array('consumer', 'originVideoKey'));
        if ($key !== '') {
            $out[] = array(
                'label' => '原画',
                'url'   => 'https://sns-video-bd.xhscdn.com/' . ltrim($key, '/'),
                'size'  => 0,
            );
        }

        $streams = $this->pick($video, array('media', 'stream'));
        if (is_array($streams)) {
            foreach (array('h264', 'h265', 'av1') as $codec) {
                if (empty($streams[$codec]) || !is_array($streams[$codec])) {
                    continue;
                }
                foreach ($streams[$codec] as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $type = isset($s['streamType']) ? (int) $s['streamType'] : 0;
                    if ($type === 259 || $type === 309) {
                        continue;
                    }
                    $u = isset($s['masterUrl']) ? (string) $s['masterUrl'] : '';
                    if ($u === '') {
                        continue;
                    }
                    $w = isset($s['width']) ? (int) $s['width'] : 0;
                    $h = isset($s['height']) ? (int) $s['height'] : 0;
                    /* 竖屏视频取短边，720x1280 习惯上叫 720P */
                    $short = ($w > 0 && $h > 0) ? min($w, $h) : $h;
                    $out[] = array(
                        'label' => $short > 0 ? $short . 'P' : strtoupper($codec),
                        'url'   => $this->ensureHttps($u),
                        'size'  => isset($s['size']) ? (int) $s['size'] : 0,
                    );
                }
            }
        }

        if (!$out) {
            $v2 = $this->pickStr($video, array('mediaV2'));
            if ($v2 !== '') {
                $mv2 = json_decode($v2, true);
                if (!is_array($mv2)) {
                    $mv2 = json_decode(preg_replace('#\bundefined\b#', 'null', $v2), true);
                }
                if (is_array($mv2)) {
                    $found = $this->searchStream($mv2);
                    if ($found !== '') {
                        $out[] = array('label' => '播放流', 'url' => $this->ensureHttps($found), 'size' => 0);
                    }
                }
            }
        }

        /* 去重，保持原画在前的顺序 */
        $seen = array();
        $uniq = array();
        foreach ($out as $q) {
            if (isset($seen[$q['url']])) {
                continue;
            }
            $seen[$q['url']] = 1;
            $uniq[] = $q;
        }
        return $uniq;
    }
    /** 在 mediaV2 这类嵌套结构里递归找投屏流地址 */
    private function searchStream($arr, $depth = 0)
    {
        if ($depth > 6 || !is_array($arr)) {
            return '';
        }
        foreach (array('hd_screencast_stream', 'default_screencast_stream') as $want) {
            if (!empty($arr[$want])) {
                $v = $arr[$want];
                if (is_string($v) && preg_match('#^https?://#i', $v)) {
                    return $v;
                }
                if (is_array($v)) {
                    foreach (array('masterUrl', 'url', 'backupUrl') as $k) {
                        if (!empty($v[$k]) && is_string($v[$k])) {
                            return $v[$k];
                        }
                        if (!empty($v[$k][0]) && is_string($v[$k][0])) {
                            return $v[$k][0];
                        }
                    }
                }
            }
        }
        if (isset($arr['consumer']['originVideoKey']) && is_string($arr['consumer']['originVideoKey'])
            && $arr['consumer']['originVideoKey'] !== '') {
            return 'https://sns-video-bd.xhscdn.com/' . ltrim($arr['consumer']['originVideoKey'], '/');
        }
        foreach ($arr as $v) {
            if (is_array($v)) {
                $r = $this->searchStream($v, $depth + 1);
                if ($r !== '') {
                    return $r;
                }
            }
        }
        return '';
    }

    /**
     * 取原图地址。
     *
     * 用 fileId 拼 sns-img-qc.xhscdn.com/{fileId} 且不带任何参数，拿到的是作者上传的原图，
     * 实测 1644x2192、391KB；而页面里的 !h5_1080jpg 版本只有 1080x1440、165KB。
     * 注意：不能把 ! 后缀剥掉，剥掉后 CDN 会直接返回 403。
     */
    private function cleanImage($url, $fileId = '')
    {
        $fileId = trim((string) $fileId);
        if ($fileId !== '') {
            return 'https://sns-img-qc.xhscdn.com/' . ltrim($fileId, '/');
        }
        return $this->ensureHttps(trim((string) $url));
    }

    private function ensureHttps($url)
    {
        $url = str_replace(array('\u002F', '\u002f'), '/', (string) $url);
        if (strpos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }
        return $url;
    }

    private function pickAuthor($note)
    {
        foreach (array('nickname', 'nickName', 'nick_name') as $k) {
            if (!empty($note['user'][$k])) {
                return $note['user'][$k];
            }
        }
        return '';
    }

    private function pickTitle($note)
    {
        $title = isset($note['title']) ? trim((string) $note['title']) : '';
        $desc = isset($note['desc']) ? trim((string) $note['desc']) : '';
        if ($title !== '' && $desc !== '' && $title !== $desc) {
            return $title . "\n" . $desc;
        }
        return $title !== '' ? $title : $desc;
    }

    /** 封面对象里的 fileId，没有就退回第一张图的 */
    private function coverFileId($note)
    {
        if (!empty($note['cover']['fileId'])) {
            return (string) $note['cover']['fileId'];
        }
        if (!empty($note['imageList'][0]['fileId'])) {
            return (string) $note['imageList'][0]['fileId'];
        }
        return '';
    }

    private function pickCover($note)
    {
        if (is_string($note['cover'] ?? null)) {
            return $note['cover'];
        }
        if (!empty($note['cover']['urlDefault'])) {
            return $note['cover']['urlDefault'];
        }
        if (!empty($note['cover']['url'])) {
            return $note['cover']['url'];
        }
        if (!empty($note['imageList'][0]['urlDefault'])) {
            return $note['imageList'][0]['urlDefault'];
        }
        return '';
    }

    private function pickLike($note)
    {
        foreach (array('interactInfo', 'interact_info') as $group) {
            foreach (array('likedCount', 'liked_count') as $k) {
                if (!empty($note[$group][$k])) {
                    return (string) $note[$group][$k];
                }
            }
        }
        return '';
    }

    /** 按路径取值，找不到返回 null。注意返回的是原值，可能是数组 */
    private function pick($arr, $path)
    {
        $cur = $arr;
        foreach ($path as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    /** 按路径取字符串 */
    private function pickStr($arr, $path)
    {
        $v = $this->pick($arr, $path);
        return is_scalar($v) ? (string) $v : '';
    }
}