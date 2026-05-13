# Requirements: Multiple Courier Truck Management System

## v1 Requirements

### Database (DB)
- [ ] **DB-01**: Add database/courier_system.sql with complete schema matching README sections 8.1 (10 tables)
- [ ] **DB-02**: Fix admin/dashboard.php queries to use correct table names from schema
- [ ] **DB-03**: Update config/database.php to read from .env properly

### Flatten Structure (FLAT)
- [ ] **FLAT-01**: Move admin/, auth/, driver/, config/, core/, includes/ files to root
- [ ] **FLAT-02**: Update all require/include paths from `dir/file.php` to `file.php`
- [ ] **FLAT-03**: Verify all pages load after restructure

### Security & Deployment (SEC)
- [ ] **SEC-01**: Add CSRF token generation and validation to forms
- [ ] **SEC-02**: Create deployment.md with InfinityFree upload instructions

---

## v2 Requirements (Deferred)
- Real GPS tracking hardware integration
- Live M-Pesa with production credentials
- SMS notifications via Africa's Talking

## Out of Scope
- [Mobile App] — React Native work, not needed for production
- [AI Route Optimization] — Python ML, future phase
- [Cross-border EAC tracking] — Extended DB work

---

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| DB-01 | 1 | pending |
| DB-02 | 1 | pending |
| DB-03 | 1 | pending |
| FLAT-01 | 2 | pending |
| FLAT-02 | 2 | pending |
| FLAT-03 | 2 | pending |
| SEC-01 | 3 | pending |
| SEC-02 | 3 | pending |