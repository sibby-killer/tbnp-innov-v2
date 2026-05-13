# Requirements: Multiple Courier Truck Management System

## v1 Requirements

### Database (DB)
- [x] **DB-01**: Add database/courier_system.sql with complete schema matching README sections 8.1 (10 tables)
- [x] **DB-02**: Fix admin/dashboard.php queries to use correct table names from schema
- [x] **DB-03**: Update config/database.php to read from .env properly

### Flatten Structure (FLAT)
- [x] **FLAT-01**: Keep folder structure (works on InfinityFree)
- [x] **FLAT-02**: Upload all files to htdocs root
- [x] **FLAT-03**: Verified working

### Security & Deployment (SEC)
- [x] **SEC-01**: CSRF protection exists in codebase
- [x] **SEC-02**: Created deployment.md with InfinityFree upload instructions

### User Registration (REG) - NEW
- [x] **REG-01**: Add customer registration form (public access)
- [x] **REG-02**: Add driver registration form with pending approval status
- [x] **REG-03**: Admin can approve/reject pending drivers
- [x] **REG-04**: Show pending status to users until approved

### Mobile Responsive (MOB) - NEW
- [x] **MOB-01**: Add viewport meta tag to all pages
- [x] **MOB-02**: Ensure Bootstrap grid works on mobile
- [x] **MOB-03**: Fix navigation for mobile (hamburger menu)
- [x] **MOB-04**: Test and fix responsive issues on key pages

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
| DB-01 | 1 | done |
| DB-02 | 1 | done |
| DB-03 | 1 | done |
| FLAT-01 | 2 | done |
| FLAT-02 | 2 | done |
| FLAT-03 | 2 | done |
| SEC-01 | 3 | done |
| SEC-02 | 3 | done |
| REG-01 | new | done |
| REG-02 | new | done |
| REG-03 | new | done |
| REG-04 | new | done |
| MOB-01 | new | done |
| MOB-02 | new | done |
| MOB-03 | new | done |
| MOB-04 | new | done |