<?php
/**
 * 解析源配置
 *
 * 抖音默认走 src/douyin.php 里的移动端 Feed 通道，开箱即用，这里不用填。
 *
 * 这里是可选的覆盖入口：如果你有第三方解析服务想优先使用，
 * 把下面的 endpoint 填上就会接管抖音链接，留空则使用内置解析。
 *
 * 配置示例（以某个返回 JSON 的服务为例）：
 *
 *   'endpoint' => 'https://example.com/api/douyin?url={url}',
 *   'headers'  => array('X-API-Key: 你的密钥'),
 *   'map'      => array(
 *       'url'    => 'data.video.play_addr.url_list[0]',
 *       'title'  => 'data.desc',
 *       'cover'  => 'data.video.cover.url_list[0]',
 *       'author' => 'data.author.nickname',
 *   ),
 *
 * map 里用的是取值路径，支持 a.b.c 和数组下标 list[0] 两种写法。
 * 留空则抖音链接会返回“暂不支持”的提示。
 */

return array(

    /* 小红书：服务器 IP 被风控拦成登录页时，填上 Cookie 能显著提高成功率 */
    'xiaohongshu' => array(
        // 从浏览器开发者工具里复制，形如 "a1=xxx; webId=xxx; web_session=xxx"
        // 只填 a1 和 webId 也能用，属于访客凭据，比登录态安全
        'cookie' => '',
    ),

    'douyin' => array(
        // 用 {url} 占位符表示需要被解析的链接，会自动做 URL 编码
        'endpoint' => '',

        // 需要的请求头，没有就留空数组
        'headers' => array(),

        // 请求方式，一般用 GET
        'method' => 'GET',

        // 从返回 JSON 里取值的路径
        'map' => array(
            'url'    => 'data.url',
            'title'  => 'data.title',
            'cover'  => 'data.cover',
            'author' => 'data.author',
        ),
    ),

);