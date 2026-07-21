# Orange Events Workspace Guidelines & Rules

## Project Overview
- **Name**: Orange Events & Catering Management System (`orange/events`)
- **Environment**: PHP >= 8.0 running under XAMPP / Apache.
- **Directory Structure**:
  - `admin/`: Admin dashboard and management interfaces.
  - `api/`: RESTful API endpoints for client and mobile interactions.
  - `assets/`: Front-end static assets (CSS, JS, images, media).
  - `bin/`: CLI utilities and migration scripts (`php bin/migrate.php`).
  - `config/`: Database credentials and application config parameters.
  - `database/`: Database SQL schemas, migrations, and seeders.
  - `includes/`: Core functions, classes, database connections, and reusable components.
  - `uploads/`: Media and generated files storage.

## General Coding Standards
1. **PHP**:
   - Enforce PHP 8.0+ strict typing where applicable (`declare(strict_types=1);`).
   - Use parameterized queries (`PDO` or `mysqli` prepared statements) for all database queries. NEVER concatenate raw user input into SQL statements.
   - Use clean, structured error handling and avoid suppressing errors with `@`.
2. **Security**:
   - Validate and sanitize all user input from `$_POST`, `$_GET`, and API payloads.
   - Enforce CSRF protection on forms and session checks on restricted endpoints.
   - Protect credentials and sensitive data by referencing configuration files rather than hardcoding.
3. **API & Front-End**:
   - Standardize JSON responses from `api/` endpoints with `status`, `message`, and `data` keys.
   - Maintain modern responsive design in `assets/` with accessible UI components.
4. **Database & Migrations**:
   - Run database schema updates via `php bin/migrate.php`.
   - Keep migrations idempotent and structured inside `database/`.
