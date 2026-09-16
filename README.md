# LUCHADOR

Grade 12 class portal for Bright Side International School.

This application is a public senior-class website plus a private portal for Super Admin, committee members, and students.

## Requirements

- PHP 8.1+
- MySQL / MariaDB
- Apache (XAMPP is fine)

## Setup

1. Copy `.env.example` to `.env` and set database credentials.
2. Open `setup_database.php` in the browser, or visit any page — the app will create the database and tables if they do not exist.
3. Sign in at `login.php`.
4. Change the Super Admin password immediately in **Settings**.

Existing student/uniform rows are preserved. The setup script does not wipe data.

## Main URLs

- Public site: `index.php`
- Sign in: `login.php`
- Admin: `admin/index.php`
- Student portal: `student/dashboard.php`
- Setup: `setup_database.php`

Roles, permissions, and student-account workflows are documented in `docs/ROLES.md`.

## Configuration

Database settings are read from `.env`:

```
DB_HOST
DB_NAME
DB_USER
DB_PASSWORD
```

Do not commit production credentials.
