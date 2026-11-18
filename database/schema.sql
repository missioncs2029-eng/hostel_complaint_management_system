CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(120) NOT NULL,
 email VARCHAR(180) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','user') NOT NULL DEFAULT 'user',
 phone VARCHAR(25) NULL,
 student_id VARCHAR(40) NULL UNIQUE,
 course VARCHAR(100) NULL,
 year_of_study TINYINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rooms (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 room_number VARCHAR(20) NOT NULL UNIQUE,
 block_name VARCHAR(60) NOT NULL,
 floor_number TINYINT NOT NULL DEFAULT 0,
 room_type ENUM('Single','Double Sharing','Triple Sharing','Dormitory') NOT NULL DEFAULT 'Double Sharing',
 capacity TINYINT UNSIGNED NOT NULL DEFAULT 2,
 monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
 status ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS allocations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 room_id INT UNSIGNED NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NULL,
 status ENUM('pending','active','ended','rejected') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_alloc_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_alloc_room FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
 INDEX idx_alloc_status(status), INDEX idx_alloc_user(user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS complaints (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 category ENUM('Maintenance','Housekeeping','Wi-Fi','Electrical','Plumbing','Food','Security','Other') NOT NULL DEFAULT 'Other',
 title VARCHAR(150) NOT NULL,
 description TEXT NOT NULL,
 priority ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
 status ENUM('Open','In Progress','Resolved','Closed') NOT NULL DEFAULT 'Open',
 admin_note TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_complaint_status(status,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 billing_month CHAR(7) NOT NULL,
 payment_method ENUM('UPI','Cash','Bank Transfer','Card','Other') NOT NULL DEFAULT 'UPI',
 transaction_ref VARCHAR(100) NULL,
 status ENUM('Pending','Paid','Rejected') NOT NULL DEFAULT 'Pending',
 paid_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_payment_user(user_id,billing_month), UNIQUE KEY unique_user_month(user_id,billing_month)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notices (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL,
 audience ENUM('All','Students') NOT NULL DEFAULT 'All',
 created_by INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS visitors (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 visitor_name VARCHAR(120) NOT NULL,
 relation VARCHAR(80) NULL,
 visit_date DATE NOT NULL,
 visit_time TIME NOT NULL,
 purpose VARCHAR(255) NULL,
 status ENUM('Pending','Approved','Rejected','Checked In','Checked Out') NOT NULL DEFAULT 'Pending',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_visitors_date(visit_date,status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 details VARCHAR(500) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
