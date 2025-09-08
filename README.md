# SMS → Telegram Forwarder (PHP)


این اسکریپت `sms.php` پیام‌های دریافتی از اپ **[SmsForwarder](https://github.com/pppscn/SmsForwarder)** را گرفته و به ربات تلگرام شما ارسال می‌کند.  
هم `application/x-www-form-urlencoded` (فیلدهای: `from`, `content`, `timestamp`) و هم `application/json` را پشتیبانی می‌کند.

## راه‌اندازی
1. فایل `sms.php` را روی سرور PHP آپلود کنید.
2. مقدارهای زیر را ست کنید (یا به صورت متغیر محیطی):
   - `TG_BOT_TOKEN` → توکن ربات تلگرام
   - `TG_CHAT_ID` → آیدی عددی مقصد
   - `SHARED_SECRET` (اختیاری) → اگر بگذارید، درخواست‌ها باید همین سکرت را داشته باشند.
   - `LOCAL_TZ` (اختیاری) → مثلا `Asia/Tehran`
3. پوشه‌ی `logs/` به صورت خودکار ساخته می‌شود (برای لاگ ورودی و پاسخ تلگرام).

## پیکربندی در SmsForwarder
- نوع درخواست: `POST`
- URL وبهوک: `https://YOUR_DOMAIN/sms.php`
- `Content-Type`: پیش‌فرض اپ فرم‌انکُد (`application/x-www-form-urlencoded`) است.
- فیلدها: `from`, `content`, `timestamp` به طور خودکار ارسال می‌شود.  
  (در حالت JSON، فیلدهای `from`, `text`, `sentStamp`, `receivedStamp`, `sim`, `secret` پشتیبانی می‌شوند.)

---


This `sms.php` forwards incoming messages from **[SmsForwarder](https://github.com/pppscn/SmsForwarder)** to your Telegram bot.  
It supports both `application/x-www-form-urlencoded` (`from`, `content`, `timestamp`) and `application/json`.

## Setup
1. Upload `sms.php` to a PHP-capable server.
2. Configure (or set environment variables):
   - `TG_BOT_TOKEN` → your Telegram bot token
   - `TG_CHAT_ID` → numeric chat id (user/group/channel)
   - `SHARED_SECRET` (optional) → if set, requests must include this secret
   - `LOCAL_TZ` (optional) → e.g., `Europe/Paris`
3. A `logs/` directory will be created automatically for request/Telegram logs.

## SmsForwarder configuration
- Method: `POST`
- Webhook URL: `https://YOUR_DOMAIN/sms.php`
- `Content-Type`: the app sends `application/x-www-form-urlencoded` by default.
- Fields: `from`, `content`, `timestamp` are sent automatically.  
  JSON payloads with `from`, `text`, `sentStamp`, `receivedStamp`, `sim`, `secret` are also supported.

---

## Test (curl)
```bash
curl -X POST https://your.domain/sms.php \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'from=19999999999' \
  --data-urlencode 'content=Hello from SmsForwarder\nSIM1_YourCarrier\n2025-09-08 03:50:23' \
  --data-urlencode 'timestamp=1757290823432'
