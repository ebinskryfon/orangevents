---
name: db-migration
description: Manage, generate, and run database migrations for the Orange Events system using php bin/migrate.php.
---

# Database Migration Skill

This skill guides the creation and execution of database migrations for `orange-events`.

## Key Commands
- **Run Pending Migrations**: `php bin/migrate.php`
- **Migration Location**: `database/migrations/`
- **Base Schema**: `database/schema.sql`

## Creating New Migrations
1. Place SQL migration files in `database/migrations/` following the naming convention:
   `YYYY_MM_DD_HHMMSS_description.sql`
2. Ensure queries use standard MySQL syntax (InnoDB engine, utf8mb4 collation).
3. Test migration execution by running:
   ```bash
   php bin/migrate.php
   ```
4. Verify table schema updates using `check_db.php` or `check_db2.php` if needed.
