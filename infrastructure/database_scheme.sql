-- ShortLink Pro - Database Schema
-- MySQL 8.0+
-- Database: shortlink

-- ============================================
-- Table 1: urls
-- Stores shortened URL mappings
-- ============================================

CREATE TABLE IF NOT EXISTS urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) UNIQUE NOT NULL,
    original_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50) DEFAULT 'anonymous',
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Indexes for performance
    INDEX idx_short_code (short_code),
    INDEX idx_created_at (created_at),
    INDEX idx_active (is_active),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 2: clicks
-- Tracks every click for analytics
-- ============================================

CREATE TABLE IF NOT EXISTS clicks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) NOT NULL,
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer TEXT,
    country VARCHAR(50),
    city VARCHAR(100),
    
    -- Foreign key to urls table
    FOREIGN KEY (short_code) REFERENCES urls(short_code) ON DELETE CASCADE,
    
    -- Indexes for analytics queries
    INDEX idx_short_code (short_code),
    INDEX idx_clicked_at (clicked_at),
    INDEX idx_country (country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table 3: url_stats
-- Pre-computed statistics for performance
-- ============================================

CREATE TABLE IF NOT EXISTS url_stats (
    short_code VARCHAR(10) PRIMARY KEY,
    total_clicks INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    last_clicked TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign key to urls table
    FOREIGN KEY (short_code) REFERENCES urls(short_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Sample Data for Testing
-- ============================================

INSERT INTO urls (short_code, original_url, created_by) VALUES
('demo01', 'https://aws.amazon.com', 'system'),
('demo02', 'https://github.com', 'system'),
('demo03', 'https://stackoverflow.com', 'system'),
('demo04', 'https://dev.to', 'system'),
('demo05', 'https://medium.com', 'system');

-- Initialize stats for demo URLs
INSERT INTO url_stats (short_code, total_clicks, unique_ips) VALUES
('demo01', 0, 0),
('demo02', 0, 0),
('demo03', 0, 0),
('demo04', 0, 0),
('demo05', 0, 0);

-- ============================================
-- Useful Queries
-- ============================================

-- Get URL by short code (primary query)
-- SELECT original_url FROM urls WHERE short_code = ? AND is_active = 1;

-- Get recent URLs
-- SELECT short_code, original_url, created_at 
-- FROM urls 
-- ORDER BY created_at DESC 
-- LIMIT 10;

-- Get top URLs by clicks (last 24 hours)
-- SELECT u.short_code, u.original_url, COUNT(c.id) as click_count
-- FROM urls u
-- LEFT JOIN clicks c ON u.short_code = c.short_code
-- WHERE c.clicked_at > NOW() - INTERVAL 24 HOUR
-- GROUP BY u.short_code
-- ORDER BY click_count DESC
-- LIMIT 10;

-- Get click analytics for specific URL
-- SELECT 
--   COUNT(*) as total_clicks,
--   COUNT(DISTINCT ip_address) as unique_visitors,
--   DATE(clicked_at) as date,
--   country
-- FROM clicks
-- WHERE short_code = ?
-- GROUP BY DATE(clicked_at), country
-- ORDER BY date DESC;

-- Get overall statistics
-- SELECT 
--   (SELECT COUNT(*) FROM urls) as total_urls,
--   (SELECT COUNT(*) FROM clicks) as total_clicks,
--   (SELECT COUNT(*) FROM clicks WHERE clicked_at > NOW() - INTERVAL 24 HOUR) as clicks_24h;

-- ============================================
-- Maintenance Queries
-- ============================================

-- Delete expired URLs
-- DELETE FROM urls WHERE expires_at IS NOT NULL AND expires_at < NOW();

-- Clean up old click data (older than 1 year)
-- DELETE FROM clicks WHERE clicked_at < NOW() - INTERVAL 1 YEAR;

-- Update url_stats (run periodically or via trigger)
-- UPDATE url_stats s
-- SET total_clicks = (SELECT COUNT(*) FROM clicks WHERE short_code = s.short_code),
--     unique_ips = (SELECT COUNT(DISTINCT ip_address) FROM clicks WHERE short_code = s.short_code),
--     last_clicked = (SELECT MAX(clicked_at) FROM clicks WHERE short_code = s.short_code);

-- ============================================
-- Performance Notes
-- ============================================

-- Indexes are critical for performance:
-- - idx_short_code: Primary lookup (used in 99% of queries)
-- - idx_created_at: Recent URLs dashboard
-- - idx_clicked_at: Time-based analytics
-- - idx_country: Geographic analytics

-- Foreign keys ensure data integrity:
-- - Deleting a URL automatically deletes associated clicks
-- - Prevents orphaned click records

-- url_stats table is for performance:
-- - Pre-computed counts avoid expensive COUNT(*) queries
-- - Updated incrementally on each click
-- - Dashboard queries are instant

-- ============================================
-- Database Configuration Recommendations
-- ============================================

-- For production RDS:
-- - Instance: db.t3.small or larger
-- - Multi-AZ: Enabled
-- - Backup retention: 7-30 days
-- - Automated backups: Enabled
-- - Enhanced monitoring: Enabled
-- - Slow query log: Enabled (threshold: 1 second)
-- - Max connections: 100+ (based on traffic)

-- ============================================
-- End of Schema
-- ============================================
