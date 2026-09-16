# Luchador roles, permissions, and accounts

Authentication is shared across Super Admin, committee members, and students. There is one login page (`login.php`) and one session system.

## Roles

### Super Admin

Unrestricted access to the admin portal, including users, roles, settings, and the activity log.

The last active Super Admin cannot be disabled or demoted.

### Committee Member

No management permissions by default (dashboard only). A Super Admin assigns permissions per person on **Users**. Additional committee accounts can be added at any time.

If the database had no committee logins, five placeholder accounts are created:

- Committee Member 1–5
- Usernames `committee1` … `committee5`

These are placeholders, not real people. Reset each password from **Users** before distributing access. Temporary passwords are never stored in the database in recoverable form.

### Student

Fixed student-portal access. Each account links to exactly one existing row in `uniforms` (the class student record) via `users.student_id`.

Students cannot access `/admin` or staff APIs.

## Permissions

Checks are server-side (`require_permission()`, `can()`, `require_staff()`, `require_student()`). Hiding a menu item is not the security boundary.

Committee-grantable groups include students, uniforms, events, announcements, gallery, class content, and reports.

These stay Super Admin only:

- `users.manage`
- `roles.manage`
- `settings.manage`
- `activity.view`

Students receive a fixed set (`students.view_own`, `profile.view_own`, published content views, `password.change`). They cannot edit protected student fields in this version.

## Create a committee account

1. Sign in as Super Admin.
2. Open **Users**.
3. Enter name, username, and optional temporary password (leave blank to generate one).
4. Role: Committee Member.
5. Tick only the permissions that person should have.
6. Create the account and copy the one-time password if one was generated.

## Create student accounts

Do not register students publicly. Do not create extra rows in `uniforms`.

**One account**

1. **Users** or **Student accounts**
2. Role: Student
3. Link a student who does not already have an account

**Bulk**

1. Open **Student accounts**
2. Review how many students already have accounts vs. how many do not
3. Confirm and generate
4. Copy or download the one-time CSV, then clear it from the screen

Existing student records are never overwritten. Duplicate accounts for the same student are rejected.

## Assign permissions

- Committee: edit the person on **Users**
- Custom roles: **Roles & permissions**, then assign that role on **Users**
- Super Admin and Student roles are fixed

Committee defaults on the Roles page apply to the role template. Each committee member still has their own assigned ACL.

## Disable an account

On **Users** or **Student accounts**, choose Disable. Disabled accounts cannot sign in and see: “This account is currently disabled. Please contact the class administrator.”

Student and uniform records are not deleted.

## Reset a password

Edit the user and set a new temporary password, or use **Generate temporary password**. The previous password is never shown. `must_change_password` is set so they must choose a new password at next sign-in.

Users change their own password from Settings (staff) or Account (students).

## Add a custom role

**Roles & permissions** → Create a custom role → choose grantable permissions → assign it on **Users**. System roles cannot be deleted.

## How authentication works

- Passwords use `password_hash()` / `password_verify()`
- Sessions regenerate on login (`session_regenerate_id(true)`), HttpOnly, SameSite=Lax, Secure when HTTPS
- Idle sessions expire after 8 hours
- CSRF tokens are required on state-changing POSTs
- Login errors are generic unless the password is correct and the account is disabled
- Temporary passwords cannot be skipped by visiting another URL
- Students are bound to `current_user().student_id`; other student IDs in the URL return HTTP 403

## Database migrations

Additive changes in `includes/migrate.php` (`migrate_auth_portal`):

- `users.student_id` (unique when not null)
- `roles`, `permissions`, `role_permissions`
- `audit_logs.user_id`, `audit_logs.ip`

No `DROP`/`TRUNCATE` of student or uniform data.

## Security notes

- Do not log passwords, session IDs, or CSRF tokens
- Do not export credential CSVs to permanent storage
- Public pages stay public; private phone numbers are not listed for other students
- Staff APIs require a non-student session plus the relevant permission
