---
name: security-audit
description: Conduct security audits on PHP scripts for SQL injection, CSRF vulnerabilities, XSS, and unauthenticated endpoints.
---

# Security Audit Skill

This skill defines audit routines for scanning and securing `orange-events` PHP scripts.

## Check Points
1. **SQL Injection**:
   - Inspect all DB calls in `admin/`, `api/`, and `includes/`.
   - Ensure dynamic variables are bound via PDO/prepared statements (`$stmt->prepare()` / `$stmt->execute()`).
2. **XSS Protection**:
   - Check front-end rendering for `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.
3. **CSRF Protection**:
   - Ensure POST requests require and validate CSRF tokens.
4. **Session & Auth Checks**:
   - Verify restricted routes in `admin/` include authentication checks.
