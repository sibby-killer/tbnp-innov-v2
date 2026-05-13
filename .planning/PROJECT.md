# Project: Multiple Courier Truck Management System

## What This Is
A web-based courier and logistics management system built with PHP/MySQL, currently in academic/demo state that needs to be production-ready and deployable to InfinityFree hosting.

## Core Value
Functional courier truck management system with fleet tracking, driver management, delivery scheduling, and M-Pesa payment integration — deployable to InfinityFree.

## Context
- **Origin**: Student project (Bungoma National Polytechnic TVET Innovation 2026)
- **Current State**: Working demo code with live deployment on InfinityFree
- **Problem**: Code has inconsistencies, missing database file, non-flat structure that causes deployment issues

## Key Constraints
1. **Hosting**: InfinityFree (free PHP/MySQL hosting) - has issues with nested folder structures
2. **All files in one folder** - no subdirectories for deployment (htdocs root only)
3. **PHP 8 compatible**
4. **MySQL database**

## Requirements

### Validated
- ✓ Admin dashboard with stats (existing)
- ✓ Authentication system (existing)
- ✓ Fleet management (partial)
- ✓ Driver management (partial)
- ✓ Delivery tracking (partial)
- ✓ M-Pesa sandbox integration (existing)

### Active
- [ ] Fix database inconsistencies (dashboard vs README schema)
- [ ] Add missing database.sql file
- [ ] Flatten file structure for InfinityFree deployment
- [ ] Update .env for production
- [ ] Add deployment.md guide
- [ ] Security hardening (CSRF, rate limiting)
- [ ] Fix broken queries in dashboard.php

### Out of Scope
- [New features] — Just production-readiness fixes
- [Live M-Pesa] — Stay on sandbox
- [Mobile app] — Future Phase 2

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Flat file structure | InfinityFree hdocs root has issues with nested folders | All PHP in root, no subdirs |
| Keep existing tech stack | PHP8 + MySQL + Bootstrap works on InfinityFree | No change needed |
| Single deployment folder | Copy/paste to htdocs should work | deployment.md will explain |

---
*Last updated: 2026-05-13 after initialization*