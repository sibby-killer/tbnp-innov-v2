# Roadmap: Multiple Courier Truck Management System

**Code:** COURIER | **Phases:** 3 | **Requirements:** 8

## Phase Overview

| # | Phase | Goal | Requirements | Success Criteria |
|---|-------|------|--------------|------------------|
| 1 | Database & Config Fixes | Fix database schema, add SQL file, update .env | DB-01, DB-02, DB-03 | 3 criteria |
| 2 | Flatten File Structure | Restructure all files to root folder for InfinityFree | FLAT-01, FLAT-02, FLAT-03 | 3 criteria |
| 3 | Security & Deployment | Add security hardening, create deployment.md | SEC-01, SEC-02 | 2 criteria |

---

### Phase 1: Database & Config Fixes

**Goal:** Fix database inconsistencies, create SQL file, update configuration

**Requirements:**
- DB-01: Add database/courier_system.sql with complete schema from README
- DB-02: Fix dashboard.php queries to match actual database tables
- DB-03: Update .env with production values and ensure config reads from .env

**Success Criteria:**
1. Database SQL file created and matches README schema exactly
2. Dashboard loads without SQL errors
3. Configuration uses .env properly

---

### Phase 2: Flatten File Structure

**Goal:** Restructure all files to single folder for InfinityFree deployment

**Requirements:**
- FLAT-01: Move all PHP files from subdirectories to root
- FLAT-02: Update all require/include paths to work from root
- FLAT-03: Test that all pages load correctly after restructure

**Success Criteria:**
1. All PHP files in root directory
2. All include paths updated and working
3. No broken links or missing files

---

### Phase 3: Security & Deployment

**Goal:** Add security hardening and create deployment documentation

**Requirements:**
- SEC-01: Add CSRF tokens to all forms
- SEC-02: Create deployment.md with step-by-step InfinityFree instructions

**Success Criteria:**
1. Forms have CSRF protection
2. Deployment guide complete and testable