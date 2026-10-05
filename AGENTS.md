# Uni Vet Care - AGENTS.md

## 1. Project Identity

Project: Uni Vet Care
Business: Veterinary Clinic
Application: Uni Vet Care Web Application

Current MVP:
Doctor Appointment Booking

Current development environment:
- Windows
- XAMPP
- PHP 8.2.12
- Laravel 12
- Composer 2.8.1
- MySQL
- Blade
- HTML5
- CSS3
- JavaScript
- Git / GitHub
- Visual Studio Code
- Codex

Public website template:
HTML5 UP - Directive

---

## 2. Core Development Principles

1. Preserve existing working code.
2. Do not modify unrelated files.
3. Do not rewrite existing implementations unnecessarily.
4. Work in small, isolated tasks.
5. Inspect the repository before making changes.
6. Never assume the current implementation without checking the actual files.
7. Never invent database structure when the project specification already defines it.
8. Prefer simple, maintainable Laravel architecture.
9. Avoid unnecessary packages and dependencies.
10. Do not introduce architectural changes without explicit approval.
11. Keep changes incremental and easy to review.
12. Every meaningful change must be testable.
13. Do not perform destructive operations without explicit approval.

---

## 3. Current Product Scope

The current implementation scope is ONLY:

Doctor Appointment Booking

Current MVP features:

Customer:
- View doctors
- View doctor details
- View available schedules
- Select appointment date
- Select available appointment slot
- Manage own pets
- Create booking
- View own bookings
- View booking status
- Cancel booking according to business rules

Admin:
- Manage doctors
- Manage doctor schedules
- Manage schedule exceptions
- View bookings
- Confirm bookings
- Manage booking status

Doctor:
- View own schedules
- View own bookings

Owner:
- Role and access structure may be prepared
- Full owner dashboard/reporting is OUT OF CURRENT MVP SCOPE

DO NOT implement payment, medical records, inventory, prescriptions, reports, or unrelated features unless explicitly requested.

---

## 4. Technology Rules

Backend:
- Laravel 12
- PHP 8.2.12

Database:
- MySQL
- Database name: uni_vet_care

Frontend:
- Blade
- HTML5
- CSS3
- JavaScript

Public UI:
- HTML5 UP Directive

Use Laravel conventions whenever possible.

Do not introduce React, Vue, Inertia, Livewire, Alpine, or another frontend framework unless explicitly approved.

---

## 5. Public Website and Template Rules

HTML5 UP Directive is used primarily for the public-facing website.

Public areas may include:
- Home
- About
- Services
- Doctors
- Booking
- Contact

Application areas should use dedicated application layouts:
- Customer
- Admin
- Doctor
- Owner

Application layouts should preserve Uni Vet Care branding and visual consistency with the Directive template.

Do not blindly copy the entire HTML5 UP template into every application page.

Split reusable UI into Blade layouts/components where appropriate.

Do not alter the visual identity of Directive unnecessarily.

---

## 6. Database Blueprint

The current MVP database consists of:

1. roles
2. users
3. doctor_profiles
4. customer_profiles
5. pets
6. doctor_schedules
7. schedule_exceptions
8. bookings
9. booking_status_histories
10. audit_logs

Laravel infrastructure tables may also remain:
- password_reset_tokens
- sessions
- cache
- cache_locks
- jobs
- job_batches
- failed_jobs
- migrations

Do not manually create application tables in phpMyAdmin.

Use Laravel migrations.

---

## 7. Database Rules

Use:
- BIGINT UNSIGNED primary keys
- Foreign keys
- Proper indexes
- Unique constraints
- InnoDB
- UTF8MB4

Important rules:

roles:
- name is unique

users:
- role_id references roles.id
- email is unique
- phone is nullable
- status is indexed

doctor_profiles:
- user_id is unique
- license_number is unique when present

customer_profiles:
- user_id is unique

pets:
- customer_id references customer_profiles.id

doctor_schedules:
- doctor_id references doctor_profiles.id
- day_of_week:
  1 = Monday
  2 = Tuesday
  3 = Wednesday
  4 = Thursday
  5 = Friday
  6 = Saturday
  7 = Sunday
- start_time must be before end_time
- slot_duration_minutes must be greater than 0

schedule_exceptions:
- one exception per doctor per date in the MVP
- UNAVAILABLE does not require start_time/end_time
- MODIFIED requires start_time/end_time

bookings:
- booking_code is unique
- customer_id references customer_profiles.id
- pet_id references pets.id
- doctor_id references doctor_profiles.id
- doctor_schedule_id references doctor_schedules.id
- appointment_start and appointment_end are DATETIME
- status uses:
  PENDING
  CONFIRMED
  COMPLETED
  CANCELLED
  NO_SHOW

booking_status_histories:
- append-only history
- records status changes
- changed_by may be nullable for system-generated changes

audit_logs:
- records important system actions
- should preserve old and new values where applicable

---

## 8. Booking Rules

Booking is the core business domain.

Before creating a booking, the server MUST verify:

1. Customer is authenticated.
2. Customer is authorized to create the booking.
3. Selected pet belongs to the authenticated customer.
4. Doctor exists and is active.
5. Schedule exists and belongs to the selected doctor.
6. Appointment date is valid.
7. Appointment is not in the past.
8. Day of week matches the doctor schedule.
9. Appointment does not conflict with an active schedule exception.
10. Appointment is inside the doctor's working time.
11. Appointment slot is still available.
12. Double booking is prevented.
13. Booking code is unique.

Never trust doctor_id, customer_id, pet_id, schedule_id, or appointment time simply because the browser submitted them.

---

## 9. Available Slot Rules

Do NOT create a permanent appointment_slots table for the MVP.

Available slots are derived from:

Doctor Schedule
+
Schedule Exception
+
Existing Active Bookings
+
Current Date/Time

The availability engine must distinguish at minimum:

- AVAILABLE
- BOOKED
- UNAVAILABLE

Cancelled bookings must not permanently occupy a slot.

---

## 10. Double Booking Protection

Double booking is a critical business rule.

Use layered protection:

- Application-level availability check
- Database transaction
- Concurrency-safe booking process
- Unique slot_key constraint

slot_key represents an active doctor/time combination.

Cancelled bookings must release the slot by clearing slot_key.

Never rely only on frontend availability.

The backend MUST validate availability again during booking creation.

---

## 11. Booking Status Lifecycle

Normal lifecycle:

PENDING
-> CONFIRMED
-> COMPLETED

Allowed alternative transitions may include:

PENDING -> CANCELLED
CONFIRMED -> CANCELLED
CONFIRMED -> NO_SHOW

Do not permit arbitrary status transitions.

A COMPLETED booking must not be silently changed back to PENDING.

Status changes should be recorded in booking_status_histories.

---

## 12. Customer Authorization

A customer must only be able to access:

- their own profile
- their own pets
- their own bookings

Never trust IDs from request parameters.

Use:
- Policies
- Authorization checks
- Ownership verification

A customer must not be able to access another customer's booking by changing an ID in a URL.

---

## 13. Doctor Authorization

A doctor may access only data within their authority.

A doctor may:
- view own schedule
- view own bookings

A doctor must not automatically gain access to:
- other doctors' bookings
- admin functions
- owner functions
- system configuration

Use policies and authorization.

---

## 14. Admin Authorization

Admin may:
- manage doctors
- manage doctor schedules
- manage schedule exceptions
- view/manage bookings
- manage operational booking statuses

Admin access must be protected by authentication and authorization.

---

## 15. Owner Authorization

Owner is a separate role from admin.

Owner may eventually have:
- business dashboard
- reports
- operational analytics
- administrative management

These features are NOT part of the current MVP unless explicitly requested.

---

## 16. Laravel Architecture

Preferred structure:

app/
  Http/
    Controllers/
      Public/
      Customer/
      Admin/
      Doctor/
    Requests/
    Middleware/

  Models/
  Policies/
  Services/
  Notifications/

resources/
  views/
    layouts/
      public/
      customer/
      admin/
      doctor/
    public/
    customer/
    admin/
    doctor/

database/
  migrations/
  seeders/
  factories/

routes/
  web.php
  api.php

tests/
  Feature/
  Unit/

Do not put complex business logic directly into controllers.

Use service classes where business rules are complex.

---

## 17. Validation

Use Laravel Form Requests for significant request validation.

Examples:
- StoreBookingRequest
- UpdateBookingRequest
- StoreDoctorRequest
- StoreDoctorScheduleRequest

Frontend validation is NOT sufficient.

All critical business validation must happen server-side.

---

## 18. Authorization

Use Policies whenever object-level authorization is required.

Important resources:
- Booking
- Pet
- Doctor
- DoctorSchedule
- ScheduleException

Do not rely only on route prefixes or hidden frontend buttons.

---

## 19. Security

Required security practices:

- Password hashing
- Authentication
- Authorization
- CSRF protection
- Server-side validation
- XSS-safe output
- SQL injection protection
- Rate limiting where appropriate
- Secure session handling
- Ownership validation
- Database constraints
- Transactions for critical operations
- Audit logging for important mutations

Secrets must never be committed to Git.

Never commit:
- .env
- API secrets
- payment keys
- SMTP passwords
- application secrets

---

## 20. Environment Rules

Project database:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uni_vet_care
DB_USERNAME=root

Development timezone:
Asia/Jakarta

Do not overwrite .env with guessed values.

Do not expose credentials in source code.

Do not change the local PHP/XAMPP installation unless explicitly instructed.

---

## 21. Migration Safety

NEVER run destructive commands such as:

- php artisan migrate:fresh
- php artisan db:wipe
- destructive database resets
- deleting production data

unless explicitly approved.

Before modifying migrations:
- inspect current migration state
- inspect the existing migration file
- understand dependencies
- verify whether migrations have already been executed

Avoid rewriting executed migrations in a shared or production environment.

---

## 22. Testing Requirements

Critical booking rules must eventually have automated tests.

At minimum test:

- customer can create booking
- customer cannot use another customer's pet
- customer cannot access another customer's booking
- inactive doctor cannot be booked
- invalid schedule cannot be booked
- past appointment cannot be booked
- unavailable date cannot be booked
- booked slot cannot be booked twice
- cancelled slot becomes available again
- doctor can only view own bookings
- unauthorized users cannot access admin functions

Critical concurrency/double-booking behavior must be tested.

---

## 23. Code Quality

Prefer:
- Laravel conventions
- clear naming
- single responsibility
- small methods
- meaningful classes
- type declarations where practical
- clear validation rules
- readable queries

Avoid:
- duplicated business logic
- giant controllers
- hidden side effects
- unnecessary abstractions
- premature optimization

Run Laravel Pint when appropriate.

---

## 24. Codex Working Protocol

Before modifying code:

1. Inspect relevant files.
2. Understand the current implementation.
3. Identify dependencies.
4. State the intended changes.
5. Make only the necessary changes.

During implementation:
- keep scope narrow
- do not modify unrelated files
- do not create unnecessary dependencies
- preserve working functionality

After implementation:
1. Review the diff.
2. Run relevant tests.
3. Run formatting/linting where applicable.
4. Report changed files.
5. Report test results.
6. Report any remaining risks.

Never assume a feature works without testing it.

---

## 25. Prompt Discipline

Tasks should be small and specific.

Bad:
"Build the entire clinic booking system."

Good:
"Implement doctor schedule CRUD based on the existing doctor_schedules migration."

For larger tasks use:

INSPECT
-> PLAN
-> IMPLEMENT
-> TEST
-> REVIEW
-> COMMIT

---

## 26. Git Rules

Use Git from the beginning.

Preferred commit style:

feat: add doctor schedule management
feat: add appointment availability service
feat: add customer appointment booking
fix: prevent duplicate appointment booking
fix: validate unavailable doctor schedules
test: cover booking ownership rules

Do not use meaningless commit messages such as:
- update
- fix
- test
- final
- changes

A commit should represent a real, reviewable change.

---

## 27. Change Control

Do not:
- rename unrelated files
- delete working modules
- replace the whole frontend
- change database architecture
- install large packages
- change authentication architecture
- change Laravel version

unless the task explicitly requires it.

---

## 28. Current Project Priority

Priority order:

1. Laravel foundation
2. Authentication
3. Roles
4. Doctor management
5. Doctor schedules
6. Schedule exceptions
7. Availability engine
8. Customer profile
9. Pet management
10. Booking
11. Admin booking management
12. Doctor booking view
13. Security testing
14. Responsive testing

Payment/DP comes AFTER the booking engine is stable.

---

## 29. Future Payment Architecture

Payment will be a separate domain.

Keep these concepts separate:

booking_status
payment_status

Future payment architecture should support:
- DP
- payment gateway
- webhook/notification
- transaction validation
- payment expiry
- refund handling

Do not implement payment unless explicitly requested.

---

## 30. Responsive Requirements

The application must work on:
- mobile phones
- tablets
- laptops
- desktops

Do not consider responsiveness complete after checking only the homepage.

Test:
- booking
- forms
- slot selection
- login
- dashboards
- tables
- navigation
- notifications

On small screens, prioritize usable UX over simply shrinking desktop layouts.

---

## 31. Definition of Done

A feature is not considered complete until:

- implementation exists
- validation works
- authorization works
- relevant business rules are enforced
- error handling exists
- relevant tests pass
- no unrelated functionality is broken
- responsive behavior is checked where applicable
- code is reviewed
- Git diff is understood

---

## 32. Final Rule

The repository's actual source code is the source of truth for implementation details.

The product requirements, ERD, business rules, and this file are the source of truth for intended architecture.

When they appear to conflict:
- do not guess
- inspect first
- identify the conflict
- preserve existing working behavior
- request/await explicit architectural approval before making a broad change
