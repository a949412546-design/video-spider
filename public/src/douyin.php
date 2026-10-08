<?php
/**
 * 抖音解析 —— 走移动端 Feed 通道
 *
 * 抖音的网页分享页已不再内嵌作品数据，PC/Web 详情接口又位于 Argus 风控网关之后
 * （缺 Uifid 时直接返回 403 Blocked by ArgusSecurityPlugin）。但客户端使用的
 * aweme/v1/feed/ 接口不在该网关后面，免 Cookie、免签名即可拿到完整作品数据，
 * 因此这里走这条通道。
 *
 * 思路参考了开源项目 ucmao/media-parser 对抖音链路的公开记录。
 */

class DouyinSpider
{
    /** 抖音安卓客户端的 User-Agent，这是走通 Feed 通道的关键 */
    private $appUa = 'com.ss.android.ugc.aweme/290101 (Linux; U; Android 10; zh_CN; Pixel 4; '
        . 'Build/QQ3A.200805.001; Cronet/TTNetVersion:5f9037be 2023-01-13 QuicVersion:4668bb42 2022-11-21)';

    /** 移动端 Feed 接口，按顺序尝试 */
    private $feedHosts = array(
        'https://api5-normal-c-hl.amemv.com/aweme/v1/feed/',
        'https://aweme.snssdk.com/aweme/v1/feed/',
        'https://api3-normal-c-lf.amemv.com/aweme/v1/feed/',
        'https://api-hl.amemv.com/aweme/v1/feed/',
    );

    public function parse($url)
    {
        $id = $this->resolveId($url);
        if (!$id) {
            return array('code' => 400, 'msg' => '无法从链接中识别抖音作品 ID');
        }

        $item = $this->fetchItem($id);
        if (!$item) {
            return array('code' => 201, 'msg' => '抖音接口没有返回数据，请稍后重试');
        }

        $data = array(
            'author' => $this->pick($item, array('author', 'nickname')),
            'uid'    => $this->pick($item, array('author', 'unique_id')),
            'avatar' => $this->firstUrl($item, array('author', 'avatar_thumb', 'url_list')),
            'like'   => $this->pick($item, array('statistics', 'digg_count')),
            'time'   => $this->pick($item, array('create_time')),
            'title'  => $this->pick($item, array('desc')),
            'cover'  => $this->firstUrl($item, array('video', 'cover', 'url_list')),
        );

        $music = $this->pick($item, array('music'));
        if (is_array($music)) {
            $data['music'] = array(
                'title'  => isset($music['title']) ? $music['title'] : '',
                'author' => isset($music['author']) ? $music['author'] : '',
            );
        }

        /* 图集作品：返回图片列表，前端会渲染成画廊 */
        if (!empty($item['images']) && is_array($item['images'])) {
            $images = array();
            foreach ($item['images'] as $img) {
                $u = $this->firstUrl($img, array('url_list'));
                if ($u) {
                    $images[] = $u;
                }
            }
            if ($images) {
                $data['images'] = $images;
                $data['cover'] = isset($data['cover']) && $data['cover'] ? $data['cover'] : $images[0];
            }
        }

        $video = $this->pickVideoUrl($item);
        if ($video) {
            $data['url'] = $video;
        } elseif (empty($data['images'])) {
            return array('code' => 201, 'msg' => '没有取到无水印视频地址，作品可能已删除或设为私密');
        }

        /* 尽量给出所有可用清晰度，前端不启用，但方便排查 */
        $fallbacks = $this->allVideoUrls($item);
        if (count($fallbacks) > 1) {
            $data['url_fallback'] = array_slice($fallbacks, 1, 4);
        }

        return array('code' => 200, 'msg' => '解析成功', 'data' => $data);
    }

    /* ---------------- 内部实现 ---------------- */

    /** 解开短链并取出作品 ID */
    private function resolveId($url)
    {
        $candidates = array($url);

        if (strpos($url, 'v.douyin.com') !== false || !$this->extractId($url)) {
            $final = $this->followRedirect($url);
            if ($final) {
                $candidates[] = $final;
            }
        }

        foreach ($candidates as $c) {
            $id = $this->extractId($c);
            if ($id) {
                return $id;
            }
        }
        return null;
    }

    private function extractId($url)
    {
        foreach (array('#/video/(\d+)#', '#/note/(\d+)#', '#/slides/(\d+)#', '#modal_id=(\d+)#',
                       '#[?&]aweme_id=(\d+)#', '#/share/video/(\d+)#', '#/share/slides/(\d+)#') as $pat) {
            if (preg_match($pat, $url, $m)) {
                return $m[1];
            }
        }
        if (preg_match('#\b(\d{18,20})\b#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function followRedirect($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
        ));
        $resp     = curl_exec($ch);
        $info     = curl_getinfo($ch);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if (is_string($redirect) && $redirect !== '') {
            return $redirect;
        }
        if (is_string($resp) && !empty($info['header_size'])) {
            $raw = substr($resp, 0, $info['header_size']);
            if (preg_match_all('#^Location:\s*(.+)$#im', $raw, $m) && !empty($m[1])) {
                return trim(end($m[1]));
            }
        }
        return '';
    }

    /** 依次尝试各 Feed 节点，取回作品 */
    private function fetchItem($id)
    {
        foreach ($this->feedHosts as $host) {
            $body = $this->http($host . '?aweme_id=' . $id);
            if (!$body) {
                continue;
            }
            $json = json_decode($body, true);
            if (empty($json['aweme_list']) || !is_array($json['aweme_list'])) {
                continue;
            }
            foreach ($json['aweme_list'] as $item) {
                if (isset($item['aweme_id']) && (string) $item['aweme_id'] === (string) $id) {
                    return $item;
                }
            }
            /* 没精确命中时退回第一条，避免接口结构调整导致整体不可用 */
            foreach ($json['aweme_list'] as $item) {
                if (!empty($item['video']) || !empty($item['images'])) {
                    return $item;
                }
            }
        }
        return null;
    }

    private function http($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => $this->appUa,
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_HTTPHEADER     => array('Accept: application/json'),
        ));
        $r    = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($r) || $r === '') {
            return '';
        }
        return $r;
    }

    /**
     * 取无水印原画地址。
     *
     * 注意不要用 download_addr 和 misc_download_addrs：那两个是抖音 App
     * “保存到相册”走的通道，URL 里带 watermark=1，出来的是带水印版本。
     */
    private function pickVideoUrl($item)
    {
        $url = $this->firstUrl($item, array('video', 'play_addr_h264', 'url_list'));
        if ($url) {
            return $url;
        }
        return $this->firstUrl($item, array('video', 'play_addr', 'url_list'));
    }

    private function allVideoUrls($item)
    {
        $out = array();
        $paths = array(
            array('video', 'play_addr_h264', 'url_list'),
            array('video', 'play_addr', 'url_list'),
            array('video', 'play_addr_265', 'url_list'),
        );
        foreach ($paths as $path) {
            $list = $this->pick($item, $path);
            if (is_array($list)) {
                foreach ($list as $u) {
                    if (is_string($u) && $u !== '' && !in_array($u, $out, true)) {
                        $out[] = $u;
                    }
                }
            }
        }
        return $out;
    }

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

    private function firstUrl($arr, $path)
    {
        $list = $this->pick($arr, $path);
        if (is_array($list)) {
            foreach ($list as $u) {
                if (is_string($u) && $u !== '') {
                    return $this->httpsify($u);
                }
            }
        }
        return '';
    }

    /** 抖音返回的封面常是 http，微信里会被拦，统一升级成 https */
    private function httpsify($url)
    {
        if (strpos($url, 'http://') !== 0) {
            return $url;
        }
        $hosts = array('douyinpic.com', 'douyinvod.com', '365yg.com', 'amemv.com', 'snssdk.com',
                       'byteimg.com', 'bytecdn.com', 'ixigua.com', 'douyin.com', 'zjcdn.com');
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return $url;
        }
        foreach ($hosts as $h) {
            if (substr($host, -strlen($h)) === $h) {
                return 'https://' . substr($url, 7);
            }
        }
        return $url;
    }
}