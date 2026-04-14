#mms
# Madrasa # School # Management # ManagementSystem

# Madrasa & Academic Management System (v3.0 Flash)
A comprehensive, web-based ERP solution designed specifically for Madrasas, religious institutes, and schools. This system manages the entire student lifecycle—from dynamic age-based enrollment to exam-linked prize budget calculations and automated financial ledgering.
## 🚀 Key Features
### 1. Intelligent Student Management
 * **Auto-Enrollment Logic:** A 4-way automated system that assigns students to sessions (Kids Boys, Kids Girls, Adult Boys, Adult Girls) based on gender and age calculated against a configurable cut-off date.
 * **Sibling Finder:** Automatically groups students by paternal contact numbers to manage family records.
 * **Ghost Record Detection:** Identifies unenrolled students and "orphan" records to maintain database integrity.
 * **WhatsApp Integration:** Generates one-click automated messages for parents regarding class timings, session starts, and group joining links.
### 2. Advanced Academic & Attendance Tracking
 * **Multi-Teacher Class Assignment:** Allows multiple teachers to be assigned to a single class section.
 * **Role-Based Attendance:** Teachers can mark attendance (Present, Absent, Late, Leave) with restrictions on editing past dates unless authorized.
 * **Attendance Analytics:** Detailed reporting with "Full Attendance" and "Ziyarat Ballot" eligibility tracking.
### 3. The "Hasanaat" & Financial Suite
 * **Hasanaat Card System:** A unique reward-based financial module. Issue digital/physical cards to students, track total value, and manage cash redemptions.
 * **General Ledger:** A double-entry inspired system that automatically syncs admission fees, monthly fees, tabarruk (donations/niaz), and general expenses.
 * **Monthly Fee Automation:** Generate monthly invoices for the entire student body with one click.
 * **Voucher Generation:** Professional print-ready receipts for all income and expense types.
### 4. Exam & Automated Prize Budgeting
 * **Dynamic Payout Formula:** Automatically calculates student rewards based on:
   * Attendance count (Present vs. Late rates).
   * Exam percentage performance.
   * Daily "Round Table" game wins.
 * **Gift Deduction Logic:** Set thresholds (e.g., if total reward > 1500 PKR, deduct for a physical gift) to automate prize distribution logistics.
 * **Denomination Calculator:** Calculates exactly how many currency notes (5000, 1000, 500, etc.) are required from the bank to pay out the entire class.
### 5. Professional Certification
 * **Dynamic Certificate Engine:** Generate highly customizable PDF certificates.
   * Upload custom background images and logos.
   * Support for Google Fonts (Cinzel, Pinyon Script, etc.).
   * Override specific text fields for "Season" or "Event" titles.
 * **Bulk ID Printing:** Generate A6-sized student ID cards and admission slips in bulk for an entire session.
### 6. System Administration
 * **Granular Permissions:** Over 40+ specific permissions to control exactly what Admins, Managers, and Teachers can see or do.
 * **Database Self-Healing:** The system automatically checks and upgrades the database schema on load (dbUpgrade function).
 * **Security & Logs:** Tracks every action (Login, Fee Edit, Student Delete) with IP addresses and timestamps.
 * **Backup & Restore:** One-click SQL backup generation and a "Danger Zone" restore feature.
## 🛠 Technical Stack
 * **Backend:** PHP 8.x (PDO for secure database interaction).
 * **Database:** MySQL (InnoDB with utf8mb4 support).
 * **Frontend:** Bootstrap 5, FontAwesome 6, Select2.
 * **Data Handling:** Server-side processed DataTables (optimized for 10,000+ student records).
 * **Time Management:** Multi-timezone support (Defaulted to Asia/Karachi).
## 📦 Installation
 1. **Database Setup:** Create a MySQL database and import the schema.
 2. **Configuration:** Edit the CONFIGURATION section in index.php with your credentials:
   ```php
   $host = 'localhost';
   $dbname = 'your_db_name';
   $user = 'your_user';
   $pass = 'your_password';
   
   ```
 3. **Permissions:** Ensure the uploads/ directory is writable (755 or 777) for storing logos and background images.
 4. **First Run:** The system will automatically create the necessary tables and default settings on the first load.
## 🔒 Security Recommendations
 * Change the default admin password immediately upon first login.
 * Use HTTPS to protect login credentials and sensitive student data.
 * Regularly use the built-in **Backup DB** feature before performing bulk deletions or restores.
## 📝 License
This software is intended for educational and institutional management. Please refer to the specific institutional guidelines for data privacy and student records management.

