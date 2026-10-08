#!/bin/sh
set -e

# 适配 Render / Railway 等平台动态分配的端口
PORT="${PORT:-80}"
sed -ri "s/^Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf
sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf

# 签名密钥存放目录（容器内持久于本次运行周期）
export VS_DATA_DIR="${VS_DATA_DIR:-/tmp}"

exec "$@"
