# نشر المتجر على DigitalOcean

يعمل المتجر على Droplet واحد عبر Docker بأربع حاويات: التطبيق (FrankenPHP مع HTTPS تلقائي)، والـ queue، والـ scheduler، وMySQL 8.4. تُبنى الصورة على جهازك ثم تُرسل إلى الخادم، فلا يحتاج الخادم إلى بناء شيء.

| الملف | وظيفته |
|---|---|
| `Dockerfile` | بناء صورة الإنتاج |
| `docker/` | إعدادات Caddy وPHP وبدء التشغيل |
| `deploy/compose.yaml` | تعريف الحاويات على الخادم |
| `deploy/.env.example` | نموذج إعدادات الإنتاج |
| `deploy/deploy.sh` | البناء والإرسال والترحيل والتشغيل |
| `deploy/backup.sh` | نسخ احتياطي يومي لقاعدة البيانات والملفات |

## 1. قبل البدء

تحتاج إلى:

- نطاق (Domain) يمكنك تعديل سجلات DNS له.
- خدمة SMTP لإرسال رسائل تأكيد الحساب.
- مفتاح SSH على جهازك.
- Docker Desktop وGit Bash على جهازك.

## 2. إنشاء الخادم

أنشئ Droplet بهذه المواصفات:

- **الصورة:** Ubuntu 24.04 LTS.
- **الحجم:** Basic بمعالجين و4 GB ذاكرة (24 دولارًا شهريًا عند آخر مراجعة للأسعار).
- **المنطقة:** Frankfurt، وهي خيار مناسب لقربها من عملائك.
- **الدخول:** مفتاح SSH فقط.
- **النسخ الاحتياطي:** فعّل Droplet Backups، فهي النسخة الوحيدة خارج القرص.

ثم وجّه سجل `A` للنطاق إلى عنوان الخادم، وأنشئ Cloud Firewall يسمح فقط بهذه المنافذ الواردة:

| المنفذ | البروتوكول | المصدر |
|---|---|---|
| 22 | TCP | عنوانك فقط إن أمكن |
| 80 | TCP | الجميع |
| 443 | TCP وUDP | الجميع |

استخدم Cloud Firewall وليس `ufw`، لأن Docker يتجاوز قواعد `ufw`. لا يُنشر منفذ MySQL خارج Docker أصلًا.

## 3. تجهيز الخادم

اتصل بالخادم ونفذ مرة واحدة:

```bash
curl -fsSL https://get.docker.com | sh

fallocate -l 2G /swapfile && chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab

mkdir -p /opt/store
```

## 4. إعداد الأسرار

انسخ `deploy/.env.example` إلى `/opt/store/.env` على الخادم وأكمل قيمه:

```bash
# على الخادم
nano /opt/store/.env
chmod 600 /opt/store/.env

openssl rand -hex 24                    # لكل من DB_PASSWORD و MYSQL_ROOT_PASSWORD و REDIS_PASSWORD
echo "base64:$(openssl rand -base64 32)" # APP_KEY
```

- عند نقل حسابات موظفين فعّلوا المصادقة الثنائية، انسخ `APP_KEY` من `.env` المحلي، لأن أسرار المصادقة مشفرة به. غيّر المفتاح لاحقًا فقط بعد إعادة ضبطها.
- احفظ `.env` في مدير كلمات مرور. فقدان `APP_KEY` يبطل هذه الأسرار، وفقدان كلمات المرور يصعّب استعادة القاعدة.
- لا تضع قيمًا فيها `$`، لأن Docker Compose يفسرها. القيم الناتجة من الأوامر أعلاه آمنة.

### خدمات اختيارية ينصح بها

| المتغير | ما يفعله | من أين |
|---|---|---|
| `SENTRY_LARAVEL_DSN` | يرسل كل خطأ في الموقع إلى بريدك مع تفاصيله، دون بيانات العملاء. | حساب مجاني في sentry.io، مشروع Laravel. |
| `TELEGRAM_BOT_TOKEN` و`TELEGRAM_CHAT_ID` | تنبيه فوري في مجموعة الموظفين عند كل طلب جديد أو مخزون منخفض. | أنشئ بوتًا عبر @BotFather، وأضفه إلى المجموعة، واقرأ رقم المجموعة من `https://api.telegram.org/bot<TOKEN>/getUpdates`. |
| بريد التنبيهات | التنبيهات نفسها بالبريد. | لوحة الإدارة ← الإعدادات ← «بريد التنبيهات». |
| `TRUSTED_PROXIES=cloudflare` | ضروري عند تفعيل Cloudflare (السحابة البرتقالية)، وإلا تحسب حدود الطلبات كل الزوار عنوانًا واحدًا. | انظر «Cloudflare» أدناه. |

تعرض لوحة المعلومات للمالك والمدير «فحص النظام»، وفيه ما ينقص من هذه الإعدادات، وتنبّه إذا توقف معالج الطابور أو المهام المجدولة.

### Cloudflare (مجاني، ينصح به)

1. أضف النطاق إلى Cloudflare وانقل خوادم الأسماء إليه.
2. فعّل الوكيل (السحابة البرتقالية) لسجلي `example.com` و`www`.
3. SSL/TLS ← **Full (strict)**. شهادة Let's Encrypt على الخادم تبقى كما هي.
4. ضع `TRUSTED_PROXIES=cloudflare` في `.env` ثم `docker compose up -d`.
5. اختياري: قيّد Cloud Firewall على المنفذين 80 و443 بعناوين Cloudflare فقط (https://www.cloudflare.com/ips/)، فلا يصل أحد إلى الخادم مباشرة.

الصور المرفوعة وملفات `/build` تحمل `Cache-Control` طويلًا، فيخزنها Cloudflare تلقائيًا.

## 5. النشر

من جذر المشروع على جهازك، في Git Bash:

```bash
DEPLOY_HOST=root@203.0.113.10 bash deploy/deploy.sh
```

يرفض السكربت العمل إن وُجدت تغييرات غير محفوظة في Git، حتى تطابق الصورة رقم الإصدار. ثم يبني الصورة ويرسلها، وينفذ الترحيلات، ويشغل الحاويات، وينتظر نجاح فحص الصحة. أعد الأمر نفسه عند كل تحديث.

في أول تشغيل يحصل الخادم تلقائيًا على شهادة HTTPS، بشرط أن يشير DNS إليه وأن يكون المنفذان 80 و443 مفتوحين.

### النشر التلقائي من GitHub

النشر التلقائي متوقف حتى تضيف متغير المستودع `STORE_URL` (مثل `https://example.com`) في GitHub ← المستودع ← Settings ← Secrets and variables ← Actions ← Variables. بعدها كل `git push` إلى الفرع `main` يُنشر تلقائيًا، لكن فقط بعد نجاح الفحوص واختبارات المتصفح. يتولى ذلك الجزء `deploy` في `.github/workflows/tests.yml`، ويشغّل السكربت نفسه `deploy/deploy.sh`، ثم يتأكد أن `STORE_URL/up` يعمل. إذا فشل أي اختبار لا يُنشر شيء ويبقى الموقع على النسخة السابقة.

يحتاج مرة واحدة إلى هذه الأسرار في GitHub ← المستودع ← Settings ← Secrets and variables ← Actions ← New repository secret:

| الاسم | القيمة |
|---|---|
| `DEPLOY_HOST` | `root@<عنوان IP الخادم>` |
| `DEPLOY_SSH_KEY` | المفتاح الخاص في سطر واحد: نفّذ في Git Bash الأمر `base64 -w0 ~/.ssh/id_ed25519 \| clip` ثم الصق (Ctrl+V). ويُقبل أيضًا الملف كما هو، من `-----BEGIN` إلى `-----END` |
| `DEPLOY_SSH_PASSPHRASE` | كلمة سر المفتاح، إن كان له كلمة سر |
| `DEPLOY_KNOWN_HOSTS` | ناتج `ssh-keyscan -t ed25519 <عنوان IP الخادم>` (السطر الذي يبدأ بالعنوان) |

تابع كل نشر من تبويب Actions في المستودع. لإعادة نشر نسخة دون تغيير اختر آخر تشغيل ثم Re-run all jobs. ويبقى النشر اليدوي بالأمر أعلاه متاحًا.

### توجيه النطاق إلى الخادم

في لوحة مزوّد النطاق (أو Cloudflare) ← DNS:

| النوع | Host | القيمة |
|---|---|---|
| A Record | `@` | عنوان IP الخاص بالـ Droplet |
| CNAME Record | `www` | `example.com.` |

- إن كان النطاق يشير إلى موقع قديم، اخفض TTL إلى 5 دقائق قبل الانتقال بيوم، ليكون الرجوع سريعًا إن احتجت إليه.
- انتظر حتى يشير `example.com` إلى الخادم (`nslookup example.com`) ثم انشر، حتى ينجح إصدار الشهادة.
- `www.example.com` يحوَّل تلقائيًا إلى `example.com` مع الحفاظ على المسار.
- بعد النشر أضف النطاق إلى Google Search Console وأرسل `https://example.com/sitemap.xml` من Sitemaps.

تحقق من النتيجة:

```bash
curl -I https://example.com/up
curl -I https://www.example.com/   # 301 إلى https://example.com/
ssh root@203.0.113.10 'cd /opt/store && docker compose ps'
```

## 6. نقل البيانات الحالية

تخطَّ هذا القسم إن أردت البدء بقاعدة فارغة. لاحظ أن القاعدة المحلية فيها طلبات وحسابات تجربة.

**قاعدة البيانات:** نفذ من جهازك. عدّل مسار `mysqldump` إن اختلف، وأضف كلمة المرور في `MYSQL_PWD` إن وُجدت:

```bash
MYSQL="/path/to/mysql/bin"

"$MYSQL/mysqldump.exe" -h127.0.0.1 -uroot --single-transaction --no-tablespaces \
  --default-character-set=utf8mb4 --set-gtid-purged=OFF store > dump.sql

ssh root@203.0.113.10 "cd /opt/store && docker compose exec -T mysql sh -c \
  'MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot --default-character-set=utf8mb4 \"\$MYSQL_DATABASE\"'" < dump.sql

rm dump.sql
```

**صور المنتجات:**

```bash
tar -C storage/app/public -cf - . | ssh root@203.0.113.10 \
  'cd /opt/store && docker compose exec -T app tar -xf - -C /app/storage/app/public'
```

**بعد النقل:**

```bash
ssh root@203.0.113.10 'cd /opt/store \
  && docker compose exec -T app php artisan migrate --force \
  && docker compose exec -T app php artisan store:reindex-search \
  && docker compose exec -T app php artisan cache:clear'
```

ملف `dump.sql` يحتوي بيانات العملاء، فلا تضعه في Git ولا في مكان مشترك.

## 7. حساب المدير

```bash
ssh -t root@203.0.113.10 'cd /opt/store && docker compose exec app php artisan store:create-admin you@example.com'
```

يطلب الأمر الاسم وكلمة مرور قوية. عند أول دخول سيُطلب من الموظف إعداد المصادقة الثنائية، لأنها إلزامية في الإنتاج. ادخل من `/admin/login`.

## 8. النسخ الاحتياطي

```bash
# على الخادم
crontab -e
30 2 * * * /opt/store/backup.sh >> /var/log/store-backup.log 2>&1
```

تُحفظ النسخ في `/opt/store-backups` لمدة 14 يومًا. هذا المجلد على القرص نفسه، فإذا فُقد الخادم فُقدت معه.

**النسخة الخارجية المشفرة (ضرورية):**

1. أنشئ في DigitalOcean Spaces حاوية في منطقة أخرى (مثل `fra1`)، ومفتاح وصول لها فقط.
2. انسخ `deploy/backup.env.example` إلى `/opt/store/backup.env` (يُرسل مع كل نشر)، وأكمل القيم، ثم `chmod 600 /opt/store/backup.env`.
3. احفظ `RESTIC_PASSWORD` في مدير كلمات مرور خارج الخادم. **بدونها لا يمكن لأحد قراءة النسخ، ولا أنت.**

بعدها يرفع `backup.sh` كل نسخة مشفرة عبر restic، ويحتفظ بـ 14 يومية و8 أسبوعية و12 شهرية. إذا غاب `backup.env` يطبع السكربت تحذيرًا في السجل.

**كل شهر:** `/opt/store/backup.sh verify` يقرأ عُشر البيانات المخزنة ويتحقق من فك تشفيرها. لاستعادة نسخة من الخارج إلى خادم جديد:

```bash
docker run --rm --env-file backup.env -v /opt/store-backups:/restore restic/restic:0.17.3 restore latest --target /restore
```

لا تعدّ النسخ موثوقة قبل تجربة استعادتها:

```bash
gunzip -c /opt/store-backups/db-YYYYMMDD-HHMMSS.sql.gz | docker compose exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --default-character-set=utf8mb4 "$MYSQL_DATABASE"'

cat /opt/store-backups/files-YYYYMMDD-HHMMSS.tar.gz | docker compose exec -T app tar -xzf - -C /app/storage/app
```

## 9. قائمة ما قبل الإطلاق

- [ ] `APP_DEBUG=false` و`APP_ENV=production`.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] إعدادات SMTP صحيحة، وجرّب إنشاء حساب ووصول رسالة التأكيد.
- [ ] رقم الواتساب صحيح في إعدادات لوحة الإدارة.
- [ ] تجربة طلب كامل على الدومين الحقيقي.
- [ ] جدولة `backup.sh`، و`backup.env` مكتمل، وتجربة استعادة نسخة من Spaces.
- [ ] Cloud Firewall مفعّل.
- [ ] `REDIS_PASSWORD` مضبوط (Redis للجلسات والكاش والطابور).
- [ ] `SENTRY_LARAVEL_DSN`، وبريد التنبيهات أو Telegram، ومراقب توقف خارجي (UptimeRobot مجاني) على `https://example.com/up`.
- [ ] «فحص النظام» في لوحة المعلومات بلا تحذيرات.
- [ ] بعد أول نشر: `docker compose exec app php artisan store:make-thumbnails` لصور البطاقات المصغّرة.
- [ ] مناطق التوصيل ورسومها في الإدارة ← مناطق التوصيل، وفروع الاستلام.
- [ ] صفحة «سياسة الخصوصية» في الإدارة ← الصفحات (نصها مسؤولية صاحب المتجر).

## 10. التشغيل اليومي

نفذ هذه الأوامر داخل `/opt/store`:

| المهمة | الأمر |
|---|---|
| حالة الحاويات | `docker compose ps` |
| سجلات التطبيق | `docker compose logs -f --tail=100 app` |
| الذاكرة والمعالج | `docker stats --no-stream` |
| وضع الصيانة | `docker compose exec app php artisan down` |
| إنهاء الصيانة | `docker compose exec app php artisan up` |

**الرجوع إلى إصدار سابق:** عدّل `APP_IMAGE` في `.env` إلى الإصدار السابق ونفذ `docker compose up -d --wait`. افعل ذلك فقط إن لم يتضمن الإصدار الجديد ترحيلًا يغيّر البيانات، وإلا فاستعد نسخة احتياطية.

## 11. عند زيادة الحمل

بقياس محلي على معالجين يتشاركهما التطبيق وMySQL، تحمّل المتجر 40-45 طلب صفحة في الثانية دون فشل. هذا قياس لأسوأ حالة بزوار جدد، وليس قياسًا على DigitalOcean نفسه.

عند الحاجة إلى المزيد، اتبع هذا الترتيب:

1. تفعيل Cloudflare إن لم يكن مفعّلًا (انظر أعلاه).
2. رفع حجم الـ Droplet إلى 4 معالجات و8 GB، ورفع `MYSQL_BUFFER_POOL` و`REDIS_MAXMEMORY`.
3. نقل MySQL إلى قاعدة مُدارة.

الجلسات والكاش والطابور على Redis منذ 3 نوفمبر 2026، والصفحة الرئيسية وقائمة الأقسام مخزنة مؤقتًا وتتجدد فور أي تعديل في الكتالوج.
