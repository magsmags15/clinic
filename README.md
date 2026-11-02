# MB Pagaduan Dental Care and Orthodontics: PHP + MySQL (XAMPP)

Online booking page (`index.html`) and staff portal (`users_final.html`) for admin, dentist and receptionist.
The old Firebase backend was removed. Everything now runs on **PHP + MySQL**, with **jQuery AJAX + JSON** in the browser.

## Setup (once)
1. Install XAMPP. In the XAMPP Control Panel start **Apache** and **MySQL**.
2. Copy this whole folder to `C:\xampp\htdocs\clinic` (macOS/Linux: `htdocs/clinic`).
3. Open **http://localhost/clinic/setup.php** and click the button. It creates the database `mb_pagaduan_clinic`,
   all tables, and three starter accounts (admin, dentist, receptionist). **Write the passwords down: they are shown once.**
4. **Delete `setup.php`.**
5. Use it:
   - Patients: http://localhost/clinic/index.html
   - Staff: http://localhost/clinic/users_final.html (log in with a starter account; the admin can add more staff)

Alternative to step 3: import `database/schema.sql` in phpMyAdmin, then create the first admin yourself.
If your MySQL root user has a password, edit `api/config.php`.
Requires PHP 8.0+ (current XAMPP is fine). Open the pages through `http://localhost/...`, not by double-clicking the file.

## Deploying online (GitHub -> web hosting)
The host must run **PHP 8.0+ with pdo_mysql and MySQL** (GitHub Pages, Netlify and Vercel are static only and cannot run PHP).
1. Upload / pull the whole repository to the hosting `public_html` (or a sub-folder).
2. In the hosting panel create a MySQL database + user, then import `database/schema.sql` with phpMyAdmin.
3. On the server, copy `api/config.local.example.php` to `api/config.local.php` and fill in the database host, name, user and password
   (or set the `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` environment variables). This file is git-ignored; never commit passwords.
4. Make sure the dot-files are named exactly `.htaccess` (root and `api/`). Some upload tools hide or rename them.
5. Check `https://YOUR-SITE/api/health.php`: it should show `"db":true`. Then open `index.html`: services and dentists load from the database.
   - Services: if the table is empty the server fills the default list once. Dentists: create staff accounts (role "dentist") in the admin Staff panel.
6. Do not upload `setup.php` to the live server (it is for local XAMPP only).

## Folder map
```
index.html            patient booking page (jQuery AJAX -> api/public.php)
users_final.html      staff portal (js/db.js -> api/auth.php, api/records.php)
js/db.js              AJAX layer: sends/receives JSON, live refresh every 5 s
api/auth.php          login / logout / session check / create staff (password_hash, sessions, lockout)
api/records.php       data endpoint for the staff portal (role rules, validation, prepared statements)
api/public.php        public endpoint for bookings (re-validates everything on the server)
api/config.php        database settings (reads api/config.local.php or DB_* environment variables)
api/health.php        yes/no database check (no secrets)
api/bootstrap.php     shared helpers (PDO, JSON replies, CSRF, rate limit)
database/schema.sql   MySQL tables
setup.php             one-time installer (delete afterwards)
```

## Where each rubric item is implemented
| Rubric item | Where |
|---|---|
| JSON (20) | Every request and reply is JSON (`js/db.js`, all `api/*.php`). Full records are stored in JSON columns and decoded/merged in PHP (`records.php`). |
| AJAX (20) | `jQuery.ajax` in `js/db.js` and `index.html`: services, dentists, schedules, booking, staff data load/save without page reloads; live refresh by polling. |
| PHP (20) | `api/*.php`: sessions, `password_hash`, role-based access, business rules (booking cut-off 6 PM, dentist working days and days off, braces-only services, duplicate booking check). |
| HTML & CSS (10) | Existing responsive layout kept in both pages. |
| jQuery bonus (+5) | jQuery performs all AJAX calls. |
| Database (10) | MySQL via PDO prepared statements; indexed key columns + JSON `data`; schema in `database/schema.sql`. |
| Integration (10) | Booking page -> `public.php` -> MySQL -> staff portal sees it within seconds. |
| UX (5) | Same design; clearer error messages from the server (for example "dentist does not work that day"). |
| Security (5) | Prepared statements (no SQL injection), bcrypt hashes, session fixation protection, CSRF token, same-origin check, login lockout (5 fails / 15 min), rate limits on public endpoints, server-side validation, allow-listed table/column names, HttpOnly + SameSite cookies, direct access to config files blocked. |

## Notes
- Data that was in Firebase is **not** migrated; the new database starts empty (default services are re-created automatically the first time the portal opens).
- To keep the live refresh light, the portal checks one small "what changed?" request every 5 seconds and only reloads what changed.
