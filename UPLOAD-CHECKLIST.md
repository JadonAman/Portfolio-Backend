# 📦 PRODUCTION DEPLOYMENT CHECKLIST

## ✅ **UPLOAD THESE FILES TO YOUR SERVER:**

### 🔥 Core API Files
- `contact.php` - Main contact form endpoint
- `admin-auth.php` - OTP authentication system  
- `admin-dashboard.php` - Admin panel API

### 🎨 Admin Interface
- `admin.html` - Web-based admin dashboard

### ⚙️ Configuration Files
- `.env` - Your environment variables (⚠️ update for production)
- `config.php` - Application configuration
- `database-schema.sql` - Database structure

### 🔧 Core Components  
- `database.php` - PDO database operations
- `security.php` - Authentication & rate limiting
- `email-service.php` - SMTP email handling

### 🛠️ Utilities
- `init-db.php` - Database initialization
- `maintenance.php` - Maintenance mode toggle
- `verify.php` - System verification script

### 📦 Dependencies
- `composer.json` - PHP dependencies list
- `.gitignore` - Git security (if using version control)
- `README.md` - Documentation

---

## ❌ **DO NOT UPLOAD:**

### 🚫 Development Files
- `backup-*/` - Development backups
- `vendor/` - Install fresh on server with `composer install`
- `composer.lock` - Platform-specific, will be generated
- `*.sh` - Shell scripts (local development only)
- Any `test-*.php` or `*-test.*` files

### 🚫 Duplicate/Legacy Files  
- `*-hostinger.*` files (already integrated)
- `PRODUCTION-README.md` (content moved to main README.md)
- `DEPLOYMENT.md`, `README-deployment.md` (consolidated)

---

## 🚀 **DEPLOYMENT STEPS:**

1. **Upload Files** (from checklist above)
2. **On Server, Install Dependencies:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. **Set Permissions:**
   ```bash
   chmod 644 *.php *.html *.json *.sql
   chmod 600 .env
   ```
4. **Initialize Database:**
   ```bash
   php init-db.php  
   ```
5. **Test System:**
   ```bash
   php verify.php
   ```

---

## 📁 **FINAL FILE COUNT: ~15 files**
Your production deployment will be clean and lightweight!
