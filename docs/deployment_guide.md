# ShortLink Pro - Deployment Guide

## Prerequisites

Before deploying ShortLink Pro, ensure you have:

- ✅ AWS Account with appropriate permissions
- ✅ AWS CLI installed and configured
- ✅ Basic understanding of AWS networking
- ✅ Text editor for configuration files

### Required AWS Permissions
```json
{
  "Services": [
    "VPC (Full Access)",
    "EC2 (Full Access)",
    "RDS (Full Access)",
    "ElastiCache (Full Access)",
    "ELB (Full Access)",
    "Auto Scaling (Full Access)",
    "S3 (Full Access)",
    "CloudFront (Full Access)",
    "IAM (Role Creation)"
  ]
}
```

---

## Deployment Steps

### Phase 1: Network Infrastructure (30-45 minutes)

#### Step 1: Create VPC
```bash
# VPC Configuration
Name: shortlink-vpc
CIDR: 10.0.0.0/16
DNS Hostnames: Enabled
DNS Resolution: Enabled
```

**AWS Console:**
1. VPC Console → Create VPC
2. Enter CIDR block: 10.0.0.0/16
3. Enable DNS hostnames
4. Enable DNS resolution
5. Create VPC

---

#### Step 2: Create Subnets (6 total)

**Public Subnets:**
```bash
# Subnet 1
Name: shortlink-public-1a
VPC: shortlink-vpc
AZ: us-east-1a
CIDR: 10.0.1.0/24

# Subnet 2
Name: shortlink-public-1b
VPC: shortlink-vpc
AZ: us-east-1b
CIDR: 10.0.2.0/24
```

**Private Subnets:**
```bash
# Subnet 3
Name: shortlink-private-1a
VPC: shortlink-vpc
AZ: us-east-1a
CIDR: 10.0.11.0/24

# Subnet 4
Name: shortlink-private-1b
VPC: shortlink-vpc
AZ: us-east-1b
CIDR: 10.0.12.0/24
```

**Database Subnets:**
```bash
# Subnet 5
Name: shortlink-db-1a
VPC: shortlink-vpc
AZ: us-east-1a
CIDR: 10.0.21.0/24

# Subnet 6
Name: shortlink-db-1b
VPC: shortlink-vpc
AZ: us-east-1b
CIDR: 10.0.22.0/24
```

---

#### Step 3: Create Internet Gateway
```bash
Name: shortlink-igw
Attach to: shortlink-vpc
```

**AWS Console:**
1. VPC Console → Internet Gateways → Create
2. Name: shortlink-igw
3. Create Internet Gateway
4. Actions → Attach to VPC → Select shortlink-vpc

---

#### Step 4: Create NAT Gateway
```bash
Name: shortlink-nat
Subnet: shortlink-public-1a (10.0.1.0/24)
Elastic IP: Allocate new EIP
```

**AWS Console:**
1. VPC Console → NAT Gateways → Create
2. Name: shortlink-nat
3. Subnet: shortlink-public-1a
4. Allocate Elastic IP
5. Create NAT Gateway
6. **Wait 2-3 minutes for "Available" status**

---

#### Step 5: Create Route Tables (3 total)

**Public Route Table:**
```bash
Name: shortlink-public-rt
Routes:
  - 10.0.0.0/16 → local
  - 0.0.0.0/0 → Internet Gateway (shortlink-igw)
Associated Subnets:
  - shortlink-public-1a
  - shortlink-public-1b
```

**Private Route Table:**
```bash
Name: shortlink-private-rt
Routes:
  - 10.0.0.0/16 → local
  - 0.0.0.0/0 → NAT Gateway (shortlink-nat)
Associated Subnets:
  - shortlink-private-1a
  - shortlink-private-1b
```

**Database Route Table:**
```bash
Name: shortlink-db-rt
Routes:
  - 10.0.0.0/16 → local
  (No internet route - isolated for security)
Associated Subnets:
  - shortlink-db-1a
  - shortlink-db-1b
```

---

### Phase 2: Security Groups (15 minutes)

#### Security Group 1: ALB Security Group
```bash
Name: shortlink-alb-sg
VPC: shortlink-vpc

Inbound Rules:
  - Type: HTTP, Port: 80, Source: 0.0.0.0/0
  - Type: HTTPS, Port: 443, Source: 0.0.0.0/0

Outbound Rules:
  - All traffic, all destinations
```

---

#### Security Group 2: Application Security Group
```bash
Name: shortlink-app-sg
VPC: shortlink-vpc

Inbound Rules:
  - Type: HTTP, Port: 80, Source: shortlink-alb-sg
  - Type: HTTPS, Port: 443, Source: shortlink-alb-sg

Outbound Rules:
  - All traffic, all destinations
```

---

#### Security Group 3: RDS Security Group
```bash
Name: shortlink-rds-sg
VPC: shortlink-vpc

Inbound Rules:
  - Type: MYSQL/Aurora, Port: 3306, Source: shortlink-app-sg
  - Type: MYSQL/Aurora, Port: 3306, Source: 10.0.11.0/24
  - Type: MYSQL/Aurora, Port: 3306, Source: 10.0.12.0/24

Outbound Rules:
  - All traffic, all destinations
```

---

#### Security Group 4: Redis Security Group
```bash
Name: shortlink-redis-sg
VPC: shortlink-vpc

Inbound Rules:
  - Type: Custom TCP, Port: 6379, Source: shortlink-app-sg
  - Type: Custom TCP, Port: 6379, Source: 10.0.11.0/24
  - Type: Custom TCP, Port: 6379, Source: 10.0.12.0/24

Outbound Rules:
  - All traffic, all destinations
```

---

### Phase 3: Database Layer (45 minutes)

#### Step 6: Create DB Subnet Group
```bash
Name: shortlink-db-subnet-group
VPC: shortlink-vpc
Subnets:
  - shortlink-db-1a (10.0.21.0/24)
  - shortlink-db-1b (10.0.22.0/24)
```

**AWS Console:**
1. RDS Console → Subnet groups → Create
2. Name: shortlink-db-subnet-group
3. Select shortlink-vpc
4. Add both database subnets
5. Create subnet group

---

#### Step 7: Create RDS MySQL Database
```bash
Engine: MySQL 8.0.x
Template: Free tier (or Dev/Test)

Settings:
  DB identifier: shortlink-rds
  Master username: admin
  Master password: [Your secure password]

Instance:
  Instance class: db.t3.micro
  Storage: 20 GB gp2

Connectivity:
  VPC: shortlink-vpc
  Subnet group: shortlink-db-subnet-group
  Public access: No
  Security group: shortlink-rds-sg
  
Multi-AZ: Yes (if available)

Additional Configuration:
  Initial database name: shortlink
  Backup retention: 7 days
  Encryption: Enabled
```

**AWS Console:**
1. RDS Console → Create database
2. Standard create
3. Configure as above
4. Create database
5. **Wait 8-10 minutes for "Available" status**

---

#### Step 8: Create Database Schema

**Connect to RDS from CloudShell:**
```bash
mysql -h [RDS-ENDPOINT] -u admin -p
```

**Execute schema creation:**
```sql
USE shortlink;

CREATE TABLE urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) UNIQUE NOT NULL,
    original_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50) DEFAULT 'anonymous',
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_short_code (short_code),
    INDEX idx_created_at (created_at),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clicks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) NOT NULL,
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer TEXT,
    country VARCHAR(50),
    FOREIGN KEY (short_code) REFERENCES urls(short_code) ON DELETE CASCADE,
    INDEX idx_short_code (short_code),
    INDEX idx_clicked_at (clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE url_stats (
    short_code VARCHAR(10) PRIMARY KEY,
    total_clicks INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    last_clicked TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (short_code) REFERENCES urls(short_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample data for testing
INSERT INTO urls (short_code, original_url) VALUES
('demo01', 'https://aws.amazon.com'),
('demo02', 'https://github.com'),
('demo03', 'https://stackoverflow.com');
```

---

### Phase 4: Cache Layer (30 minutes)

#### Step 9: Create Cache Subnet Group
```bash
Name: shortlink-cache-subnet-group
VPC: shortlink-vpc
Subnets:
  - shortlink-db-1a (10.0.21.0/24)
  - shortlink-db-1b (10.0.22.0/24)
```

---

#### Step 10: Create ElastiCache Redis Cluster
```bash
Engine: Redis OSS
Creation method: Cluster cache
Cluster mode: Disabled

Name: shortlink-redis
Node type: cache.t4g.micro
Number of replicas: 0
Multi-AZ: No (for cost savings)

Connectivity:
  VPC: shortlink-vpc
  Subnet group: shortlink-cache-subnet-group
  Security group: shortlink-redis-sg

Encryption:
  In transit: Yes (TLS)
  At rest: Yes
```

**Wait 5-8 minutes for "Available" status**

---

### Phase 5: Application Layer (60 minutes)

#### Step 11: Create IAM Role for EC2
```bash
Role name: Short-EC2-Role

Trust relationship: EC2

Permissions:
  - AmazonS3ReadOnlyAccess
  - AmazonSSMManagedInstanceCore (optional, for Session Manager)
```

**AWS Console:**
1. IAM Console → Roles → Create role
2. Trusted entity: EC2
3. Add policies: AmazonS3ReadOnlyAccess
4. Name: Short-EC2-Role
5. Create role

---

#### Step 12: Upload Application Code to S3

**Create S3 bucket:**
```bash
aws s3 mb s3://shortlink-code-deployment-[UNIQUE-ID]
```

**Create bucket policy for public read:**
```bash
cat > bucket-policy.json << 'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::shortlink-code-deployment-[UNIQUE-ID]/*"
    }
  ]
}
EOF

aws s3api put-bucket-policy \
  --bucket shortlink-code-deployment-[UNIQUE-ID] \
  --policy file://bucket-policy.json
```

**Upload application code:**
```bash
aws s3 cp index.php s3://shortlink-code-deployment-[UNIQUE-ID]/index.php
```

---

#### Step 13: Create Launch Template
```bash
Name: shortlink-app-template
AMI: Amazon Linux 2023
Instance type: t2.micro

Network:
  Don't include in template (ASG will choose)

Security groups:
  - shortlink-app-sg

IAM instance profile:
  - Short-EC2-Role

User data:
```
```bash
#!/bin/bash
exec > >(tee /var/log/user-data.log)
exec 2>&1

yum update -y
yum install -y httpd php php-mysqlnd

systemctl start httpd
systemctl enable httpd

# Download application from S3
aws s3 cp s3://shortlink-code-deployment-[UNIQUE-ID]/index.php /var/www/html/index.php

echo "OK" > /var/www/html/health.html

chown -R apache:apache /var/www/html
chmod 644 /var/www/html/*

systemctl restart httpd

echo "Deployment complete!"
```

---

#### Step 14: Create Target Group
```bash
Name: shortlink-targets
Protocol: HTTP
Port: 80
VPC: shortlink-vpc

Health checks:
  Protocol: HTTP
  Path: /health.html
  Interval: 30 seconds
  Timeout: 5 seconds
  Healthy threshold: 2
  Unhealthy threshold: 2
  Success codes: 200
```

**AWS Console:**
1. EC2 Console → Target Groups → Create
2. Configure as above
3. Don't register targets yet (ASG will do this)
4. Create target group

---

#### Step 15: Create Application Load Balancer
```bash
Name: shortlink-alb
Scheme: Internet-facing
IP type: IPv4

Network:
  VPC: shortlink-vpc
  Subnets:
    - shortlink-public-1a (10.0.1.0/24)
    - shortlink-public-1b (10.0.2.0/24)

Security group:
  - shortlink-alb-sg

Listener:
  Protocol: HTTP
  Port: 80
  Default action: Forward to shortlink-targets
```

**Wait 2-3 minutes for "Active" status**

---

#### Step 16: Create Auto Scaling Group
```bash
Name: shortlink-asg
Launch template: shortlink-app-template (Latest version)

Network:
  VPC: shortlink-vpc
  Subnets:
    - shortlink-private-1a (10.0.11.0/24)
    - shortlink-private-1b (10.0.12.0/24)

Load balancing:
  Attach to: shortlink-targets

Health checks:
  Type: ELB
  Grace period: 300 seconds

Group size:
  Desired: 2
  Minimum: 2
  Maximum: 4

Scaling policy:
  Target tracking
  Metric: Average CPU utilization
  Target: 70%
```

**Wait 5 minutes for instances to launch and become healthy**

---

### Phase 6: CDN Setup (20 minutes)

#### Step 17: Create CloudFront Distribution
```bash
Origin domain: [ALB DNS name]
Origin protocol: HTTP only (or HTTPS if configured)

Default cache behavior:
  Viewer protocol policy: Redirect HTTP to HTTPS
  Allowed HTTP methods: GET, HEAD, OPTIONS, PUT, POST, PATCH, DELETE
  Cache policy: CachingOptimized
  Origin request policy: AllViewer

Settings:
  Price class: Use all edge locations (best performance)
  Alternate domain names (CNAMEs): [Optional - your custom domain]
  SSL certificate: Default CloudFront certificate
```

**Wait 10-15 minutes for deployment**

**Note the CloudFront domain:** `xxxxxx.cloudfront.net`

---

### Phase 7: Verification & Testing (15 minutes)

#### Step 18: Verify Infrastructure

**Check each component:**
```bash
# Check VPC
aws ec2 describe-vpcs --filters "Name=tag:Name,Values=shortlink-vpc"

# Check subnets
aws ec2 describe-subnets --filters "Name=vpc-id,Values=[VPC-ID]"

# Check RDS
aws rds describe-db-instances --db-instance-identifier shortlink-rds

# Check Redis
aws elasticache describe-cache-clusters --cache-cluster-id shortlink-redis

# Check Auto Scaling
aws autoscaling describe-auto-scaling-groups --auto-scaling-group-names shortlink-asg

# Check ALB
aws elbv2 describe-load-balancers --names shortlink-alb

# Check CloudFront
aws cloudfront list-distributions
```

---

#### Step 19: Test Application

**Access via CloudFront:**
```
https://[YOUR-CLOUDFRONT-ID].cloudfront.net
```

**Test checklist:**
- ✅ Page loads
- ✅ Shows "DB Connected"
- ✅ Shows "Redis Connected"
- ✅ Can shorten URLs
- ✅ Redirects work
- ✅ Recent URLs display
- ✅ Load balancing works (refresh shows different instance IDs)

---

#### Step 20: Test Auto Scaling

**Simulate load to trigger scaling:**
```bash
# Generate traffic (example using Apache Bench)
ab -n 10000 -c 100 http://[ALB-DNS]/
```

**Watch Auto Scaling:**
1. CloudWatch → EC2 metrics → CPU utilization
2. If CPU > 70% for 5 minutes → Scales to 3-4 instances
3. Auto Scaling Groups → Activity history (shows scaling events)

---

## Configuration Details

### Application Configuration

**Update these values in `index.php`:**
```php
// RDS Configuration
$db_host = '[YOUR-RDS-ENDPOINT]';
$db_user = 'admin';
$db_pass = '[YOUR-PASSWORD]';
$db_name = 'shortlink';

// Redis Configuration (if using)
$redis_host = '[YOUR-REDIS-ENDPOINT]';
$redis_port = 6379;
```

---

### Environment Variables (Alternative Approach)

**Store sensitive data in Systems Manager Parameter Store:**
```bash
# Store database password
aws ssm put-parameter \
  --name /shortlink/db/password \
  --value "YourPassword" \
  --type SecureString

# Retrieve in application
aws ssm get-parameter --name /shortlink/db/password --with-decryption
```

---

## Troubleshooting

### Issue 1: Instances Unhealthy in Target Group

**Symptoms:**
- Targets showing "Unhealthy" status
- ALB returns 503 errors

**Solutions:**
1. Check security group allows ALB → EC2 on port 80
2. Verify `/health.html` exists and returns 200
3. Check Apache is running: `sudo systemctl status httpd`
4. Review user data logs: `sudo cat /var/log/user-data.log`

---

### Issue 2: Database Connection Fails

**Symptoms:**
- Application shows "DB Error"
- Can't create short URLs

**Solutions:**
1. Verify RDS security group allows connections from private subnets
2. Test connection: `mysql -h [RDS-ENDPOINT] -u admin -p`
3. Check RDS endpoint in application code matches actual endpoint
4. Verify database "shortlink" exists

---

### Issue 3: Redis Connection Fails

**Symptoms:**
- Application shows "Redis Error"
- No cache performance improvement

**Solutions:**
1. Verify Redis security group allows port 6379 from app tier
2. Check Redis endpoint in application code
3. Test connection: `redis-cli -h [REDIS-ENDPOINT] -p 6379 --tls PING`
4. Verify encryption in transit matches application config

---

### Issue 4: S3 Download Fails in User Data

**Symptoms:**
- Instances launch but show default page
- Application code not deployed

**Solutions:**
1. Verify IAM role (Short-EC2-Role) attached to instances
2. Check S3 bucket policy allows GetObject
3. Verify bucket name in user data matches actual bucket
4. Check user data logs: `/var/log/user-data.log`

---

### Issue 5: CloudFront Not Serving Latest Content

**Symptoms:**
- Changes not reflecting
- Old content cached

**Solutions:**
1. Create CloudFront invalidation: `/*`
2. Wait 5-10 minutes for invalidation
3. Or use versioned URLs: `/app.css?v=2`

---

## Deployment Checklist

**Before going live:**

- [ ] All security groups configured with least privilege
- [ ] RDS automated backups enabled (7-day retention)
- [ ] Redis cluster in Multi-AZ (if production)
- [ ] CloudWatch alarms configured
- [ ] Cost budgets set up
- [ ] NAT Gateway highly available (add second in us-east-1b)
- [ ] HTTPS configured with ACM certificate
- [ ] Custom domain configured (Route 53)
- [ ] Monitoring dashboard created
- [ ] Runbook documentation completed

---

## Deployment Time

| Phase | Duration | Waiting Time |
|-------|----------|--------------|
| VPC Infrastructure | 30 min | - |
| Security Groups | 15 min | - |
| RDS Database | 15 min | 8-10 min |
| Redis Cluster | 10 min | 5-8 min |
| Application Layer | 45 min | 5 min |
| CloudFront CDN | 10 min | 10-15 min |
| **Total** | **~2.5 hours** | **~30 min waiting** |

---

## Post-Deployment

### Monitoring

**Set up CloudWatch alarms for:**
- ALB 4XX/5XX errors
- Target unhealthy count
- RDS CPU utilization
- Redis memory usage
- Auto Scaling group size

### Backup Strategy

- **RDS**: Automated daily backups (7-day retention)
- **Manual snapshots**: Before major changes
- **Code**: Version controlled in Git
- **Infrastructure**: Document as code (future: CloudFormation)

### Maintenance

**Regular tasks:**
- Monitor CloudWatch metrics weekly
- Review CloudWatch Logs for errors
- Update application code via S3 upload + instance refresh
- Apply RDS maintenance updates during low-traffic windows
- Review and optimize costs monthly

---

*Last Updated: December 2025*
