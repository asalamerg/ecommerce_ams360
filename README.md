# متجر إلكتروني مع لوحة تحكم

متجر إلكتروني كامل بالعربية والعبرية والإنجليزية، مع لوحة تحكم لإدارة المتجر:
المنتجات والتصنيفات والعلامات، والمخزون، والطلبات، والعروض والكوبونات، والعملاء،
والمظهر، والإعدادات. مبني بـ Laravel وInertia وReact.

## التشغيل محليًا

قاعدة MySQL باسم `store` (أو غيّر `DB_DATABASE` في `.env`)، ثم:

```powershell
composer run setup
composer run dev
```

ثم أنشئ حساب المالك الأول (يسألك عن الاسم وكلمة السر):

```powershell
php artisan store:create-admin owner@example.com
```

لوحة التحكم على `/admin`.

## إعداد المتجر

- **اسم المتجر:** `APP_NAME` في `.env`، ومن لوحة التحكم ← الإعدادات.
- **الهاتف وواتساب:** من لوحة التحكم ← الإعدادات. حتى تُملأ تُستعمل `STORE_PHONE` و`STORE_WHATSAPP` من `.env` إن وُجدتا.
- **الشعار والألوان:** من لوحة التحكم ← المظهر.

## الاختبارات

```powershell
php artisan test
npm run types:check
```

## النشر

[docs/deployment/digitalocean.md](docs/deployment/digitalocean.md). النشر التلقائي من GitHub متوقف حتى يُضاف متغير المستودع `STORE_URL`.

## التوثيق

[docs/architecture](docs/architecture): الكتالوج، والهوية والصلاحيات، والمخزون، والدفع، وواجهة المتجر، والمظهر.
