# Hotel Booking Management System — Project Specification

**Academic Project** · PHP · MySQL · HTML · CSS · JavaScript
**Version:** 1.0
**Scope note:** Payment processing and "forgot password" are explicitly out of scope for this iteration.

---

## 1. Project Concept

The Hotel Booking Management System replaces a manual, paper/register-based hotel booking process with aQ web application. The primary goals are:

- **Eliminate manual record-keeping** for bookings and client details.
- **Reduce data-entry errors** through form validation and clear inline error messaging.
- **Require no technical/formal training** for either the admin or the end user to operate the system.
- **Provide a clean separation of concerns** between the administrative back-office (Admin Module) and the public-facing guest experience (User Module).

The system is deliberately scoped down for an academic setting: no payment gateway integration, no password-recovery flow, and a single administrator account rather than a multi-role staff hierarchy.

---

## 2. Technology Stack

| Layer | Technology |
|---|---|
| Markup / Styling | HTML5, CSS3 |
| Client-side behavior | JavaScript (vanilla — form validation, UI interactions) |
| Server-side logic | PHP (procedural/OOP mix, PDO for database access) |
| Database | MySQL (InnoDB engine, utf8mb4) |
| Security patterns | Prepared statements (PDO), CSRF tokens, output escaping, PRG (Post/Redirect/Get) |

---

## 3. Folder Structure

```
hotel-booking-system/
│
├── admin/                         # Admin Module (auth-gated)
│   ├── login.php
│   ├── logout.php
│   ├── index.php                  # Home / dashboard (counts & summaries)
│   ├── room-categories.php        # Add / soft-delete categories
│   ├── rooms.php                  # Add / update / soft-delete rooms
│   ├── bookings.php               # View New/Approved/Cancelled + add remark
│   ├── registered-users.php       # View registered users
│   ├── enquiries.php              # View / mark read / manage enquiries
│   ├── search.php                 # Search by mobile no. / booking no.
│   ├── reports.php                # Enquiry + booking reports by date range
│   └── profile.php                # Admin profile update
│
├── user/                          # User Module (public + auth-gated account area)
│   ├── index.php                  # Home / welcome page
│   ├── about.php
│   ├── services.php
│   ├── rooms.php                  # Browse available rooms
│   ├── gallery.php
│   ├── book-room.php              # Room booking form
│   ├── contact.php                # Enquiry / contact form
│   ├── signup.php
│   ├── login.php
│   ├── logout.php
│   └── my-account.php             # Profile update + booking history
│
├── config/
│   ├── paths.php                  # Path constants
│   └── database.php               # PDO singleton connection
│
├── core/
│   ├── Http.php                   # Request / Response classes
│   ├── Auth.php                   # Session/auth helpers (admin + user)
│   ├── Validator.php              # Reusable input validation
│   └── Csrf.php                   # CSRF token generation/verification
│
├── includes/                      # Shared partials
│   ├── admin-header.php / admin-footer.php / admin-nav.php
│   └── user-header.php / user-footer.php / user-nav.php
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│       ├── rooms/
│       └── gallery/
│
├── database/
│   └── schema.sql
│
├── bootstrap.php                  # App entry bootstrap (loads config, autoload, session)
├── .htaccess
└── index.php                      # Front router → redirects to user/index.php
```

**Rationale:** `config/`, `core/`, and `bootstrap.php` are infrastructure shared by both modules — they don't belong inside `admin/` or `user/`. Each module directory stays flat and maps 1:1 to a menu item, which keeps navigation code simple and avoids over-engineering routing for a project this size.

---

## 4. Modules & Features

### 4.1 Admin Module

| # | Section | Description |
|---|---|---|
| 1 | Home | Dashboard cards: total new bookings, approved bookings, cancelled bookings, total registered users, total read enquiries, total unread enquiries. |
| 2 | Room Category | Add new categories, soft-delete existing ones (blocked if active rooms still use the category). |
| 3 | New Room | Add rooms, update room details (price, capacity, amenities, images, status). |
| 4 | Booking | View bookings by status (New / Approved / Cancelled), change status, attach a remark (logged to history). |
| 5 | Reg Users | View registered user details (read-only). |
| 6 | Enquiry | View and manage enquiries; mark as read/unread. |
| 7 | Search | Search enquiries by mobile number, search bookings by booking number. |
| 8 | Reports | View enquiry and booking activity within a selected date range. |
| — | Profile | Admin can update their own profile (name, email, password). |

### 4.2 User Module

| # | Section | Description |
|---|---|---|
| 1 | Home | Public welcome/landing page. |
| 2 | About | About-the-hotel page. |
| 3 | Services | Services offered by the hotel. |
| 4 | Room | Browse available room categories/rooms with details. |
| 5 | Gallery | Photo gallery of the hotel. |
| 6 | Book Room | Book a room — requires the guest to be registered/logged in. |
| 7 | Contact | Contact form → creates an enquiry record. |
| 8 | Sign Up | User registration. |
| 9 | Login | User login. |
| 10 | My Account | Update profile, view own booking history/status. |

---

## 5. Database Design

### 5.1 Design Principles Applied

- **Lookup tables instead of `ENUM`** for all status fields (`booking_statuses`, `enquiry_statuses`, `room_statuses`) — easier to query, extend, and translate than a hardcoded `ENUM` list.
- **Soft delete** (`deleted_at DATETIME NULL`) used on `room_categories`, `rooms`, and `gallery` — records are hidden from the application rather than physically removed, preserving history for bookings that reference them.
- **Soft delete + uniqueness conflict, resolved.** A naive `UNIQUE(name)` breaks once a soft-deleted row exists (you can never reuse that name). This schema uses a **generated, stored column** that collapses to `NULL` whenever a row is soft-deleted, and puts the `UNIQUE` constraint on *that* column instead of the raw name column. MySQL/MariaDB unique indexes treat each `NULL` as distinct, so any number of soft-deleted rows can share a name — but only one *active* row can ever hold it. This is the standard pattern for "unique among active rows" in MySQL, since there is no native partial/filtered unique index like PostgreSQL offers.
- **Soft delete does not get FK protection for free.** A `FOREIGN KEY ... ON DELETE RESTRICT` only fires on a real `DELETE` statement — a soft delete is just an `UPDATE`, so the FK never sees it and never blocks it. This schema compensates with explicit `BEFORE UPDATE` triggers that block a soft-delete when dependent active records still exist (see §5.4).
- **Room status vs. booking status are not the same thing**, and were previously conflated. `room_statuses` now only expresses *administrative* availability (`Available`, `Maintenance`, `Inactive`) — never date-specific occupancy. Whether a room is free for a given date range is **always computed live** from `bookings`, never stored as a static "Occupied" flag. Storing occupancy on the room row was the original bug: it creates a second source of truth that silently goes stale the moment a booking is completed or cancelled and nobody remembers to flip the flag back.
- **No stored price on bookings.** Price is read live from `rooms.price_per_night`. This is a deliberate scope decision (documented in §5.5), acceptable because there is no payment/invoicing requirement.

### 5.2 Entity Summary

| Table | Purpose |
|---|---|
| `booking_statuses` | Lookup: New, Approved, Cancelled, Completed, No-Show |
| `enquiry_statuses` | Lookup: Unread, Read |
| `room_statuses` | Lookup: Available, Maintenance, Inactive (administrative state only) |
| `admins` | Single administrator account |
| `users` | Registered guests |
| `room_categories` | Room categories (soft-deletable) |
| `rooms` | Individual rooms (soft-deletable) |
| `room_images` | Photos belonging to a room |
| `bookings` | Guest room reservations |
| `booking_status_history` | Append-only log of status changes + admin remarks |
| `enquiries` | Public contact-form submissions |
| `gallery` | Hotel photo gallery (soft-deletable) |

### 5.3 Entity Relationships (summary)

- `room_categories (1) ── (N) rooms`
- `rooms (1) ── (N) room_images`
- `users (1) ── (N) bookings`
- `rooms (1) ── (N) bookings`
- `bookings (1) ── (N) booking_status_history`
- Status lookup tables are referenced by `rooms`, `bookings`, and `enquiries` respectively.

### 5.4 Integrity Rules Enforced at the Database Level

1. `check_out_date > check_in_date` — `CHECK` constraint (see version caveat below).
2. `price_per_night > 0`, `capacity > 0` — `CHECK` constraints.
3. **No overlapping bookings** for the same room while status is New/Approved — enforced via `BEFORE INSERT` / `BEFORE UPDATE` triggers on `bookings`.
4. **Guest count within room capacity** — enforced in the same triggers as (3), so both rules run in a single trigger per event instead of stacking multiple triggers on the same table/event.
5. **A category cannot be soft-deleted while active rooms reference it** — `BEFORE UPDATE` trigger on `room_categories`.
6. **A room cannot be soft-deleted while it has New/Approved bookings** — `BEFORE UPDATE` trigger on `rooms`.

> **CHECK constraint caveat:** `CHECK` constraints are only *enforced* in MySQL 8.0.16+ and MariaDB 10.2.1+. On older bundled versions (common in some XAMPP/WAMP installs), they parse without error but silently do nothing. Run `SELECT VERSION();` before relying on them, and validate the same rules in PHP regardless — the database constraint is a safety net, not a substitute for application-level validation.

### 5.5 Documented Assumptions & Known Limitations

State these explicitly in your report — examiners read stated limitations as evidence of understanding, not as weaknesses.

- **No price history.** Because bookings don't store a price snapshot, changing `rooms.price_per_night` retroactively changes the displayed price of *past* bookings too. Acceptable here because there's no invoicing/payment requirement in scope.
- **One room per booking.** A single `bookings` row references exactly one room. Multi-room reservations are out of scope.
- **Concurrency is not fully race-proof.** The overlap-prevention trigger correctly prevents double-booking under normal, sequential use (the realistic case for this project), but two simultaneous `INSERT`s on the same room/date range from different connections could theoretically both pass the check before either commits. Closing this completely requires transaction-level row locking (`SELECT ... FOR UPDATE`), which is beyond the scope of a trigger and unnecessary at this project's expected concurrency (single admin, low simultaneous traffic).
- **`users.phone` is not unique** (only indexed) — intentionally, in case a family books under one shared contact number. Revisit if your requirements say otherwise.
- **Single admin account** — no role/permission table. `booking_status_history` therefore has no "changed by" column, since with one admin it could only ever hold one value.

---

## 6. Security Checklist (for implementation phase)

- [ ] All queries via PDO **prepared statements** — no string-concatenated SQL, anywhere.
- [ ] All output escaped with `htmlspecialchars()` before rendering into HTML.
- [ ] CSRF token on every state-changing form (login, signup, booking, admin actions).
- [ ] Passwords hashed with `password_hash()` / verified with `password_verify()` — never stored plain or reversibly encrypted.
- [ ] POST → redirect → GET (PRG) pattern on all form submissions to prevent duplicate-submit-on-refresh.
- [ ] Session regenerated on login (`session_regenerate_id(true)`) to prevent session fixation.
- [ ] `display_errors` off and debug mode disabled before any submission/demo build.
- [ ] Database credentials outside the web root or in a non-committed `.env`/config file.

---

## 7. Out of Scope (explicit)

- Online payment / payment gateway integration.
- "Forgot password" / password reset flow.
- Multi-admin roles and permissions.
- Multi-room single-reservation bookings.
- Email/SMS notifications (can be a stated future-work item in the report).