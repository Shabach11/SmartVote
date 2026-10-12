-- Smart Voting System schema additions. Run this on a new installation before use.
-- Existing installations are upgraded by ensure_smartvote_schema() in config.php.
CREATE TABLE valid_voters (
  id INT AUTO_INCREMENT PRIMARY KEY, election_id INT NOT NULL, full_name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL, staff_id VARCHAR(100) NOT NULL,
  identifier_label VARCHAR(100) NOT NULL DEFAULT 'Name', identifier_value VARCHAR(190) NOT NULL,
  phone VARCHAR(50) NULL, source_data TEXT NULL,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_valid_voter_election_staff (election_id, staff_id),
  UNIQUE KEY uq_valid_voter_election_email (election_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vote_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY, election_id INT NOT NULL, user_id INT NOT NULL,
  voter_identifier VARCHAR(100) NOT NULL, cast_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_election_user (election_id, user_id),
  UNIQUE KEY uq_receipt_election_identifier (election_id, voter_identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE election_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  election_id INT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_election_review_user (election_id, user_id),
  INDEX idx_election_reviews_election (election_id),
  CONSTRAINT chk_election_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_events (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, election_id INT NULL,
  event_type VARCHAR(100) NOT NULL, event_detail VARCHAR(255) NOT NULL,
  ip_address VARCHAR(64) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, logged_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(64) NULL, INDEX idx_login_user (user_id, logged_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE election_creation_requests (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, full_name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL,
  organization_name VARCHAR(190) NOT NULL, organization_type VARCHAR(100) NULL, organization_email VARCHAR(190) NULL,
  organization_phone VARCHAR(50) NULL, organization_address VARCHAR(255) NULL, organization_country VARCHAR(100) NULL,
  organization_logo VARCHAR(255) NULL, reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected','used') NOT NULL DEFAULT 'pending', reviewed_by INT NULL,
  reviewed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_request_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE election_committee_members (
  id INT AUTO_INCREMENT PRIMARY KEY, election_id INT NOT NULL, full_name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NULL, user_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', invitation_token_hash CHAR(64) NULL,
  confirmation_code_hash CHAR(64) NULL, confirmation_expires_at DATETIME NULL,
  accepted_at DATETIME NULL, confirmed_at DATETIME NULL, expires_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_committee_election (election_id), INDEX idx_committee_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
