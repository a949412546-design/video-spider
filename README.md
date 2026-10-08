# 视频去水印下载站

把开源项目 [5ime/video_spider](https://github.com/5ime/video_spider) 封装成了一个可以直接在手机微信里打开的网页。抖音解析因为原项目的实现已经失效，另外重写了一套。

后端仍是原项目的 PHP 解析逻辑，本仓库在其之上补了前端界面、下载通道和若干修复。

---

## 一、这套东西包含什么

| 文件 | 作用 |
| --- | --- |
| `public/index.html` | 网页界面，手机优先，微信内置浏览器可直接打开 |
| `public/api.php` | 解析接口，`POST url=分享链接` 返回视频信息与下载地址 |
| `public/dl.php` | 下载通道，把远端视频以「附件」形式吐给浏览器，点一下就是真正的下载 |
| `public/src/video_spider.php` | 原项目的解析逻辑（已修复若干 PHP 8 崩溃问题） |
| `public/src/douyin.php` | 抖音解析，走移动端 Feed 通道（原项目的抖音解析已失效，这里是重写的） |
| `public/src/xiaohongshu.php` | 小红书解析，走分享页 SSR（原项目不支持小红书） |
| `public/config.php` | 可选配置：接入第三方解析源 |
| `public/src/util.php` | 下载地址签名、文件名处理 |
| `Dockerfile` / `docker/` | 容器化部署，适配 Render 等平台 |
| `render.yaml` | Render 一键部署蓝图 |

---

## 二、部署到 Render（推荐，免费且无需信用卡）

Render 提供免费的容器托管，有新加坡节点，国内访问速度可以接受。

### 第 1 步：把代码传到 GitHub

先建一个空仓库（私有也可以），然后在 `app` 目录里执行：

```bash
git init
git add .
git commit -m "视频去水印下载站"
git branch -M main
git remote add origin https://github.com/你的用户名/你的仓库名.git
git push -u origin main
```

### 第 2 步：在 Render 上创建服务

1. 打开 https://render.com ，用 GitHub 账号登录。
2. 点 `New` → `Blueprint`。
3. 选中刚才推上去的仓库，Render 会自动读取 `render.yaml`。
4. 点 `Apply`，等构建完成（首次大约 3~5 分钟）。
5. 完成后你会拿到一个网址，形如 `https://xxxx.onrender.com`。

### 第 3 步：验证

浏览器打开 `你的网址/api.php?ping=1`，看到下面这样的内容就说明部署成功：

```json
{"code":200,"msg":"ok","php":"8.2.x","curl":true,"url_fopen":true}
```

如果 `curl` 是 `false`，说明镜像有问题，重新构建一次即可。

### 第 4 步：在微信里用

把 `https://xxxx.onrender.com` 发给微信好友或存到微信收藏，点开就能用。

建议在微信里点右上角「…」→「浮窗」或「添加到桌面」，下次一点就进。

---

## 三、部署到自己的服务器 / 虚拟主机

这套东西是纯 PHP，不依赖数据库，只要能跑 PHP 7.4 以上并开启 cURL 扩展就行。

- 虚拟主机 / 宝塔面板：把 `public/` 目录里的所有文件上传到网站根目录即可。
- Docker：`docker compose up -d`，然后访问 `http://localhost:8080`。

需要的 PHP 扩展：`curl`（必需）、`mbstring`（建议）。

---

## 四、微信里使用的几个注意点

1. **首次打开可能有安全提示**。微信对未备案的域名有时会拦截，点「继续访问」即可；如果被拦得太狠，可以换个域名或给域名做备案。
2. **下载按钮**。点了会调起微信自己的下载/预览流程，iPhone 上表现为顶部出现下载按钮，安卓上会提示用浏览器打开，都是正常的。
3. **电脑上打开会弹出「另存为」**。用的是浏览器的文件保存接口，可以自己选文件夹。Firefox、Safari 不支持这个接口，会直接下到默认下载目录。
4. **Render 免费套餐会休眠**。闲置 15 分钟后第一次访问要等十几秒才出页面，属于正常现象。

---

## 五、接口说明

| 接口 | 说明 |
| --- | --- |
| `POST /api.php`，表单字段 `url` | 解析视频，返回 JSON |
| `GET /api.php?ping=1` | 健康检查 |
| `GET /dl.php?u=&e=&s=&n=` | 下载通道，参数由 `api.php` 自动生成，不要手写 |

解析成功返回：

```json
{
  "code": 200,
  "msg": "解析成功",
  "data": {
    "title": "视频标题",
    "author": "作者",
    "cover": "封面地址",
    "url": "无水印视频直链",
    "filename": "作者 标题.mp4",
    "download": "dl.php?u=...&e=...&s=...&n=...",
    "download_cover": "dl.php?u=...&e=...&s=...&n=..."
  }
}
```

`download` 字段就是页面上那个下载按钮真正请求的地址，带签名和 6 小时有效期，过期后重新解析即可。

---

## 六、平台支持现状

原项目写的 23 个平台里，有一部分因为平台自身改版已经失效了。实测情况：

| 平台 | 状态 |
| --- | --- |
| 抖音 | ✅ 实测可用（含图集） |
| 小红书 | ✅ 实测可用（视频 + 图集原图） |
| AcFun | ✅ 实测可用 |
| 梨视频 | ✅ 实测可用 |
| 其他平台 | ⚠️ 未逐一实测 |

**关于抖音**：原项目的抖音解析已经失效了，原因是抖音的分享页不再内嵌作品数据，而网页版详情接口位于 `Argus` 风控网关之后（缺 Uifid 时直接返回 `Blocked by ArgusSecurityPlugin`）。

本项目的抖音改用**移动端 Feed 通道**（`aweme/v1/feed/`），用抖音安卓客户端的 User-Agent 请求。这条通道不在风控网关后面，免 Cookie、免签名就能拿到完整作品数据，包括原画地址、封面、作者、点赞数和图集。

两个细节：

1. 取的是 `video.play_addr_h264`，也就是原画流。**不要用 `download_addr` 或 `misc_download_addrs`**，那两个是抖音 App「保存到相册」走的通道，URL 里带 `watermark=1`，出来的是带水印的版本。
2. 抖音的接口地址和参数会变。如果哪天抖音又解析不了，先确认 `src/douyin.php` 里的 `feedHosts` 是否还有效。

---

## 七、关于小红书

小红书用的是 Nuxt/Vue 服务端渲染，笔记详情、高清媒体流、作者信息都内嵌在页面 HTML 的 `window.__INITIAL_STATE__` 里，所以不需要签名算法就能解析。但有两个绕不开的点：

1. **必须保留分享链接里的 `xsec_token` 等参数。** 只拿笔记 ID 去拼地址，服务端会直接返回 404 或拦截。所以页面上一定要用 App 里「分享 → 复制链接」得到的完整内容，不要只复制笔记地址。
2. **服务器 IP 会被风控。** 小红书对机房 IP 的匿名抓取很敏感，容易 302 跳到登录页。如果遇到「风险访问」的提示，在 `public/config.php` 里配置 Cookie 即可，拿法：

   - 浏览器打开 xiaohongshu.com 并登录（不登录也行）
   - 按 F12 打开开发者工具 → Network → 刷新页面 → 点任意一个请求
   - 在 Request Headers 里找到 `Cookie:`，复制整行值
   - 填到 `config.php` 的 `'xiaohongshu' => array('cookie' => '粘贴到这里')`

   只需要 `a1` 和 `webId` 这两个访客凭据就够了，不一定要登录态的 `web_session`。访客凭据不含账号身份，比登录 Cookie 安全。

解析出来的视频优先取作者原始上传的原画（`consumer.originVideoKey`），其次取 `mediaV2` 投屏流，最后才从码率列表里挑。码率列表里 `streamType` 为 259 / 309 的是小程序带水印版本，代码里已经排除。

图片同样取作者上传的原图：用 `fileId` 拼 `https://sns-img-qc.xhscdn.com/{fileId}`（不带参数）。实测同一条笔记，页面里的 `!h5_1080jpg` 版本是 1080×1440 / 165KB，而这样取到的是 1644×2192 / 391KB 的真原图。

三个踩过的坑，改代码时注意：

- **不要剥掉图片地址的 `!` 后缀**，剥掉之后 CDN 直接返回 403。
- **`xhslink.cn` 不支持 HEAD 请求**（返回 404），解析短链必须退回 GET。
- **不能用 `<title>` 判断登录页**，正常笔记页的标题也是「小红书」，会误判成风控。
---

## 八、免责声明

本项目仅供个人学习与技术研究使用。请勿用于商业用途，下载的视频版权归原作者所有，请尊重创作者权益。