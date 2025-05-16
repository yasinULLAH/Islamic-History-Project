<div align="center">
  <h1>🕌 Islamic History Portal | اسلامی تاریخ پورٹل 📜</h1>
  <p>
    <strong>Explore Islamic civilization, Quran, and Hadith through an interactive web application.</strong>
    <br/>
    <strong>ایک انٹرایکٹو ویب ایپلیکیشن کے ذریعے اسلامی تہذیب، قرآن اور حدیث کا مطالعہ کریں۔</strong>
  </p>
  <p>
    <em>Developed by Yasin Ullah (Pakistani) | تیار کردہ یاسین اللہ (پاکستانی)</em>
  </p>
</div>

---

## 📖 About The Project | پراجیکٹ کے بارے میں

The Islamic History Portal is a single-file PHP web application designed to provide users with a rich, interactive platform to explore historical events (both Islamic and general), search and read Quranic ayahs with Urdu translation, and browse a collection of Hadiths. The application features role-based access, content management, multi-language support (English/Urdu), and gamification elements.

|

اسلامی تاریخ پورٹل ایک سنگل فائل پی ایچ پی ویب ایپلیکیشن ہے جو صارفین کو تاریخی واقعات (اسلامی اور عمومی دونوں)، اردو ترجمہ کے ساتھ قرآنی آیات کی تلاش اور پڑھنے، اور احادیث کے مجموعہ کو براؤز کرنے کے لیے ایک بھرپور، انٹرایکٹو پلیٹ فارم فراہم کرنے کے لیے ڈیزائن کی گئی ہے۔ ایپلیکیشن میں کردار پر مبنی رسائی، مواد کا انتظام، کثیر لسانی معاونت (انگریزی/اردو)، اور گیمفیکیشن عناصر شامل ہیں۔

---

## ✨ Key Features | اہم خصوصیات

| English                                      | Urdu                                                 |
|----------------------------------------------|------------------------------------------------------|
| 🏛️ **Single PHP File Architecture**          | 🏛️ **سنگل پی ایچ پی فائل آرکیٹیکچر**                     |
| 🗃️ **SQLite Database**                       | 🗃️ **ایس کیو لائیٹ ڈیٹا بیس**                             |
| 📖 **Quran Integration** (from `data.AM`)    | 📖 **قرآن انٹیگریشن** (`data.AM` سے)                     |
| 🔑 **Role-Based Access Control** (Admin, Ulama, User, Public) | 🔑 **کردار پر مبنی رسائی** (ایڈمن، علماء، صارف، پبلک) |
| 🗺️ **Geo-Features & Maps** (Leaflet.js)      | 🗺️ **جیو فیچرز اور نقشے** (Leaflet.js)                  |
| ⏳ **Infographic Timeline** of Events         | ⏳ **واقعات کی انفوگرافک ٹائم لائن**                       |
| 📝 **Content Management** (Approval Workflow) | 📝 **مواد کا انتظام** (منظوری کا نظام)                   |
| 🔗 **Content Linking** (Events to Ayahs/Hadiths) | 🔗 **مواد کا ربط** (واقعات کا آیات/احادیث سے)           |
| 🗣️ **Multi-Language Support** (English/Urdu with RTL) | 🗣️ **کثیر لسانی معاونت** (انگریزی/اردو مع RTL)          |
| 🏆 **Gamification** (Points & Badges)        | 🏆 **گیمفیکیشن** (پوائنٹس اور بیجز)                      |
| 🎨 **UI Themes** (Light/Dark Modes)          | 🎨 **UI تھیمز** (لائٹ/ڈارک موڈز)                        |
| 🛡️ **Security** (Password Hashing, Sanitization) | 🛡️ **سیکیورٹی** (پاس ورڈ ہیشنگ، سینیٹائزیشن)             |
| 🔄 **Backup & Restore** for Admins          | 🔄 **ایڈمن کے لیے بیک اپ اور ریسٹور**                      |

---

## 🚀 Getting Started | آغاز کرنا

### Prerequisites | شرائط

*   🌐 Web Server with PHP (7.4+ recommended) | پی ایچ پی کے ساتھ ویب سرور (7.4+ تجویز کردہ)
*   🐘 PHP `pdo_sqlite` extension enabled | پی ایچ پی `pdo_sqlite` ایکسٹینشن فعال ہو

### Installation | انسٹالیشن

1.  📥 **Download Files:** | **فائلیں ڈاؤن لوڈ کریں:**
    *   `index.php` (the application) | (ایپلیکیشن)
    *   `data.AM` (Quran data) | (قرآن ڈیٹا)
2.  📁 **Placement:** Place `index.php` and `data.AM` in the same web-accessible directory. | **جگہ:** `index.php` اور `data.AM` کو ایک ہی ویب قابل رسائی ڈائرکٹری میں رکھیں۔
3.  ✍️ **Permissions:** Ensure the web server has **write permissions** for the directory (to create `islamic_history_app.sqlite` and `error.log`). | **اجازتیں:** یقینی بنائیں کہ ویب سرور کو ڈائرکٹری کے لیے **لکھنے کی اجازت** حاصل ہے (تاکہ `islamic_history_app.sqlite` اور `error.log` بنائی جا سکیں)۔
4.  🌐 **Access:** Open `index.php` in your browser. | **رسائی:** اپنے براؤزر میں `index.php` کھولیں۔
5.  ✨ **First Run:** The app will auto-import Quran data from `data.AM` and create the database. | **پہلی بار چلانا:** ایپ خود بخود `data.AM` سے قرآن کا ڈیٹا درآمد کرے گی اور ڈیٹا بیس بنائے گی۔
    *   Default Admin: `admin` / `password123` (Change Immediately! | فوراً تبدیل کریں!)

---

## 🖼️ Application Screenshots | ایپلیکیشن اسکرین شاٹس

<p align="center">
  <img src="./pic (1).png" alt="Screenshot 1" width="32%" />
  <img src="./pic (2).png" alt="Screenshot 2" width="32%" />
  <img src="./pic (3).png" alt="Screenshot 3" width="32%" />
</p>
<p align="center">
  <img src="./pic (4).png" alt="Screenshot 4" width="32%" />
  <img src="./pic (5).png" alt="Screenshot 5" width="32%" />
  <img src="./pic (6).png" alt="Screenshot 6" width="32%" />
</p>
<p align="center">
  <img src="./pic (7).png" alt="Screenshot 7" width="32%" />
  <img src="./pic (8).png" alt="Screenshot 8" width="32%" />
  <img src="./pic (9).png" alt="Screenshot 9" width="32%" />
</p>

---

## 🛠️ Usage | استعمال کا طریقہ

*   👤 **Public:** Browse events, timeline, map, Quran, Hadith. Switch language/theme. | **عوام:** واقعات، ٹائم لائن، نقشہ، قرآن، حدیث براؤز کریں۔ زبان/تھیم تبدیل کریں۔
*   ➕ **Registered Users:** Suggest events/Hadiths, bookmark content, earn points/badges. | **رجسٹرڈ صارفین:** واقعات/احادیث تجویز کریں، مواد بک مارک کریں، پوائنٹس/بیجز حاصل کریں۔
*   🕌 **Ulama:** Moderate content, add religious content directly, link content. | **علماء:** مواد کی نگرانی کریں، براہ راست مذہبی مواد شامل کریں، مواد کو لنک کریں۔
*   ⚙️ **Admin:** Full control: user management, content management, badge system, backup/restore. | **ایڈمن:** مکمل کنٹرول: صارف کا انتظام، مواد کا انتظام، بیج سسٹم، بیک اپ/ریسٹور۔

---

## 💻 Tech Stack | ٹیکنالوجی اسٹیک

*   **Backend:** PHP (Single File) | پی ایچ پی (سنگل فائل)
*   **Database:** SQLite | ایس کیو لائیٹ
*   **Frontend:** HTML, CSS, JavaScript, Bootstrap 5 | ایچ ٹی ایم ایل، سی ایس ایس، جاوا اسکرپٹ، بوٹسٹریپ 5
*   **Libraries:** Leaflet.js (Maps), Chart.js (Charts) | لیفلیٹ ڈاٹ جے ایس (نقشے)، چارٹ ڈاٹ جے ایس (چارٹس)

---

## 👨‍💻 Author | مصنف

**Yasin Ullah** (Pakistani) | **یاسین اللہ** (پاکستانی)

---
