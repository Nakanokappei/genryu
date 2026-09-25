# fail2ban: 探しに来た IP を BAN する

`.env` や `.git` を探すスキャナーを、1日 iptables で遮断します。nginx は
それらのパスに 404 を返しています（`/etc/nginx/sites-available/genryu`）。
ここでは、同じ IP が探し続けることを止めます。

| ジェイル | 読むログ | 検出するもの | 閾値 | BAN |
|---|---|---|---|---|
| `sshd` | journald | SSH の総当たり | 5回/30分 | 1日 |
| `nginx-badprobe` | `access.log` | `/.env` `/.git` `/.aws` `/etc/passwd` `/wp-*` `phpunit` などの攻撃パス | 2回/30分 | 1日 |
| `recidive` | `fail2ban.log` | 1日に3回 BAN された IP | 3回/日 | 1週間・全ポート |

## 配置

```bash
sudo apt install -y fail2ban
sudo install -m 644 /var/www/genryu/deploy/fail2ban/jail.local /etc/fail2ban/jail.local
sudo install -m 644 /var/www/genryu/deploy/fail2ban/filter.d/nginx-badprobe.conf \
  /etc/fail2ban/filter.d/nginx-badprobe.conf
# 自分を BAN しないよう、自宅の IP 帯を入れる。自宅の IP は動的に変わるので、
# /24（256個）の幅で入れる。リポジトリは公開なので、実際の値はここに書かない
sudo sed -i "s|<YOUR_ADMIN_IP>|<自宅のIP帯>/24|" /etc/fail2ban/jail.local
sudo fail2ban-client -t && sudo systemctl enable --now fail2ban && sudo fail2ban-client reload
```

## 確かめる

```bash
sudo fail2ban-client status                   # sshd, nginx-badprobe, recidive
sudo fail2ban-regex /var/log/nginx/access.log /etc/fail2ban/filter.d/nginx-badprobe.conf
```

## 運用

```bash
sudo fail2ban-client status nginx-badprobe              # いま BAN している IP
sudo fail2ban-client set nginx-badprobe unbanip <IP>    # 誤 BAN を解く
```
