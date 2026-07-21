---
name: api-endpoint
description: Create and test RESTful API endpoints in the api/ directory with standard JSON responses and error handling.
---

# API Endpoint Development Skill

This skill provides guidelines and templates for building endpoints under `api/`.

## Response Format Standard
All API endpoints in `api/` MUST return JSON with header `Content-Type: application/json` and the standard envelope:
```json
{
  "status": "success",
  "message": "Operation completed successfully",
  "data": {}
}
```
Or for errors:
```json
{
  "status": "error",
  "message": "Error description",
  "code": 400
}
```

## Security Best Practices
1. **Authentication**: Verify session tokens or API keys before processing requests.
2. **Input Validation**: Sanitize and validate request payloads.
3. **Prepared Statements**: Use PDO prepared statements for database operations.
