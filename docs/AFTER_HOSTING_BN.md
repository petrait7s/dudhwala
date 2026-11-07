# হোস্টিং কেনার পর: দুধওয়ালা চালু করার ধাপ

সব কোড তৈরি। শুধু নিচের ধাপগুলো ক্রমানুসারে করুন। বিস্তারিত ইংরেজি গাইড: `docs/DEPLOY_HOSTING.md`।

## ১. হোস্টিং কেনার সময় যা দেখবেন

- cPanel
- PHP 8.2 বা নতুন
- MySQL / MariaDB
- Terminal বা SSH
- Cron jobs
- ফ্রি SSL (AutoSSL)

## ২. ডোমেইন জোড়া

1. ডোমেইন যেখানে কেনা, সেখানকার DNS-এ একটি **A record** দিন: Name `admin`, Value হোস্টিংয়ের সার্ভার IP (ওয়েলকাম ইমেইলে থাকে)।
2. cPanel → Domains → `admin.আপনারডোমেইন.com` তৈরি করুন।
3. cPanel → SSL/TLS Status → **Run AutoSSL** চালান।

## ৩. অ্যাপ আপলোড ও ইনস্টল

1. `dudhwala-app.zip` cPanel File Manager দিয়ে `/home/USER/dudhwala/` ফোল্ডারে আপলোড করে Extract করুন।
2. সাবডোমেইনের Document Root দিন `/home/USER/dudhwala/app/public`।
   Document Root বদলানো না গেলে `dudhwala-public_html.zip` সাবডোমেইনের ফোল্ডারে Extract করুন।
3. cPanel → MySQL Databases: একটি ডাটাবেস ও ইউজার তৈরি করে ইউজারকে সব অনুমতি দিন।
4. `app/.env.production.example` কপি করে `app/.env` নাম দিন। এরপর পূরণ করুন:
   - `APP_URL=https://admin.আপনারডোমেইন.com`
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   - `CORS_ALLOWED_ORIGINS=https://dudhwalaweb.vercel.app`
5. cPanel → Terminal খুলে চালান:

```bash
cd ~/dudhwala/app
php artisan dudhwala:install --admin-email=আপনার@ইমেইল.com --admin-name="Owner"
```

Document Root বদলানো না গেলে শেষে যোগ করুন: `--public-path=/home/USER/<সাবডোমেইনের ফোল্ডার>`।

ইনস্টল কমান্ড একটি অস্থায়ী পাসওয়ার্ড একবারই দেখায়। সেটি লিখে রাখুন।

6. cPanel → Cron Jobs → প্রতি মিনিটে:

```
cd /home/USER/dudhwala/app && php artisan schedule:run >> /dev/null 2>&1
```

## ৪. ওয়েবসাইট জোড়া (Vercel)

Vercel → Project → Settings → Environment Variables-এ দিন:

```
PUBLIC_API_URL=https://admin.আপনারডোমেইন.com/api
```

এরপর Deployments → **Redeploy** করুন।

## ৫. অ্যাডমিনে ডাটা দেওয়া

`https://admin.আপনারডোমেইন.com/admin` এ লগইন করুন।

1. **অথেনটিকেটর অ্যাপ সেটআপ:** Google Authenticator বা Microsoft Authenticator দিয়ে QR কোড স্ক্যান করুন। রিকভারি কোডগুলো সংরক্ষণ করুন।
2. **আমার অ্যাকাউন্ট:** উপরে আপনার নামে ক্লিক করে অস্থায়ী পাসওয়ার্ড বদলান।
3. **আউটলেট:** প্রতিটি শাখার আসল ঠিকানা, ফোন ও খোলার সময় দিন।
4. **সেটিংস:** দিন:
   - অর্ডারের সময়
   - শোরুম অর্ডারের সীমা
   - ডেলিভারি চার্জ
   - বিকাশ ও নগদ নম্বর
   - কুরিয়ার ট্র্যাকিং লিংক (স্টেডফাস্ট/পাঠাও)
5. **একসাথে আপডেট:**
   1. "স্প্রেডশিট ডাউনলোড" করুন।
   2. Excel বা Google Sheets-এ দাম, `visible` = yes এবং প্রতিটি শাখার স্টক পূরণ করুন।
   3. CSV হিসেবে সেভ করে আপলোড করুন।
6. **ছবি:** "একসাথে অনেক পণ্যের ছবি" অংশে ছবিগুলো একবারে আপলোড করুন। ফাইলের নাম হবে পণ্যের কোড অনুযায়ী: `ds-01.jpg` মূল ছবি, `ds-01-2.jpg` অতিরিক্ত ছবি।
7. **স্টাফ:** ম্যানেজার যোগ করুন। ম্যানেজার শুধু দেখতে পারবেন, কিছু বদলাতে পারবেন না।

## ৬. চালুর আগে শেষ পরীক্ষা

1. Terminal-এ `php artisan dudhwala:preflight` চালান। কোনো **FAIL** থাকলে চলবে না।
2. সাইট থেকে একটি ক্যাশ অন ডেলিভারি অর্ডার এবং একটি বিকাশ অর্ডার দিন।
3. অ্যাডমিনে অর্ডারগুলো ধাপে ধাপে এগিয়ে নিন, TrxID যাচাই করুন এবং কুরিয়ার ও ট্র্যাকিং কোড দিন।
4. পরীক্ষার অর্ডারগুলো বাতিল করুন।
