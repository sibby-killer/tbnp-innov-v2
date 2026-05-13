# Deployment Guide - Multiple Courier Truck Management System

## InfinityFree Deployment

### Prerequisites
- InfinityFree account (free)
- FileZilla or similar FTP client
- Domain: `yourproject.infinityfreeapp.com`

---

## Step 1: Prepare Your Local Files

### 1.1 Update .env for Production
Edit `.env` file with your InfinityFree database credentials:

```env
# Application
APP_NAME="Courier Truck Management System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.infinityfreeapp.com
APP_TIMEZONE=Africa/Nairobi

# Database - UPDATE THESE FROM INFINITYFREE PANEL
DB_CONNECTION=mysql
DB_HOST=sqlXXX.infinityfree.com
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_db_username
DB_PASSWORD=your_db_password
DB_CHARSET=utf8mb4

# Session
SESSION_LIFETIME=120
```

### 1.2 Get InfinityFree Database Credentials
1. Login to InfinityFree Control Panel
2. Go to **MySQL Databases**
3. Note your:
   - MySQL Host (sqlXXX.infinityfree.com)
   - Database Name (if0_XXXXXXX_xxx)
   - Username
   - Password (if you didn't set one, create one)

---

## Step 2: Upload Files to InfinityFree

### 2.1 Using FileZilla (Recommended)

1. **Open FileZilla**
2. **Connect to your server:**
   - Host: `ftppay.infinityfree.com`
   - Username: Your InfinityFree email
   - Password: Your InfinityFree password
   - Port: 21

3. **Navigate to htdocs folder** (right panel)

4. **Upload all files:**
   - Drag and drop ALL files from your local project folder
   - OR select all files → Right-click → Upload

   **IMPORTANT:** Upload EVERYTHING including:
   - All PHP files
   - .env file
   - vendor folder
   - All subdirectories (admin, auth, driver, etc.)

5. **Wait for upload to complete** (may take several minutes)

### 2.2 Alternative: Using InfinityFree File Manager

1. Go to Control Panel → File Manager
2. Navigate to htdocs
3. Click Upload button
4. Select all files and upload
5. May need to upload folder by folder

---

## Step 3: Set Up Database

### 3.1 Create Database
1. Go to **MySQL Databases** in InfinityFree panel
2. Create new database (if not already created)
3. Note the database name format: `if0_XXXXXXX_database`

### 3.2 Import SQL Schema
1. Go to **phpMyAdmin** (in InfinityFree panel)
2. Select your database
3. Click **Import** tab
4. Upload files in this order:
   - `database/courier_system.sql` (main tables)
   - `database/supplemental.sql` (dashboard tables)

5. Click **Go** to execute

### 3.3 Verify Tables
After import, you should see these tables:
- users, trucks, drivers, customers, deliveries
- tracking_logs, fuel_records, emergencies, payments
- notifications, settings, orders, clients, couriers
- order_status, activity_logs, order_logs

---

## Step 4: Update Database Connection

### 4.1 If Using .env (Recommended)
The system will automatically read from `.env` file. Just ensure:
- DB_HOST = your InfinityFree MySQL host
- DB_DATABASE = your database name
- DB_USERNAME = your database username
- DB_PASSWORD = your database password

### 4.2 Test Connection
Visit: `https://yourdomain.infinityfreeapp.com`

If you see errors:
- Check phpMyAdmin to confirm database has tables
- Check .env has correct credentials
- Look at error message for specifics

---

## Step 5: Login

### Default Login Credentials
| Role | Email | Password |
|------|-------|----------|
| Admin | admin@courier.co.ke | Admin@2026 |
| Driver | driver@courier.co.ke | Admin@2026 |
| Customer | customer@courier.co.ke | Admin@2026 |

**IMPORTANT:** Change default passwords after first login!

---

## Troubleshooting

### Issue: "Database connection failed"
- Verify DB credentials in .env
- Check database was created in InfinityFree panel
- Confirm database user has permissions

### Issue: "File not found" errors
- Ensure all files uploaded to htdocs (not subfolder)
- Check FileZilla shows successful upload

### Issue: "Cannot modify headers"
- Check for white space before <?php in any file
- Ensure no BOM (Byte Order Mark) in files

### Issue: Page loads but no styling
- Clear browser cache
- Check bootstrap/ folder uploaded
- Verify relative paths work

### Issue: Some pages work, others don't
- Check all PHP files uploaded
- Look at the specific error message

---

## Quick Fix: Flat Structure (If Having Folder Issues)

If InfinityFree has issues reading files from subfolders, you can try:

1. **Copy to htdocs root only** - Upload ALL files to htdocs, keep folder structure
2. **Check permissions** - All files should have read permissions
3. **FileZilla binary mode** - Some servers need binary transfer mode

---

## Security Checklist After Deploy

- [ ] Change default admin password
- [ ] Enable SSL (InfinityFree provides free SSL)
- [ ] Update .env with APP_DEBUG=false
- [ ] Regularly backup database via phpMyAdmin

---

## Backup & Restore

### Backup Database
1. Open phpMyAdmin
2. Select database
3. Click **Export** → **Quick** → **Go**
4. Save .sql file

### Restore Database
1. Open phpMyAdmin
2. Select database
3. Click **Import**
4. Choose .sql file → **Go**

---

## Support

For InfinityFree issues:
- Check InfinityFree knowledge base
- Contact InfinityFree support

For system issues:
- Check error logs in /logs folder
- Verify database tables exist
- Check .env configuration

---

**Good luck with your deployment!** 🚚