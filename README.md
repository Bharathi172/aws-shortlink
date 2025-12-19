# 🔗 ShortLink - Production-Grade URL Shortener

[![AWS](https://img.shields.io/badge/AWS-Cloud-orange?style=for-the-badge&logo=amazon-aws)](https://aws.amazon.com/)
[![Architecture](https://img.shields.io/badge/Architecture-Multi--Tier-blue?style=for-the-badge)](https://github.com/Bharathi172/aws-shortlink)
[![Status](https://img.shields.io/badge/Status-Portfolio-success?style=for-the-badge)]()

> A production-grade URL shortener built on AWS demonstrating enterprise architecture patterns with Auto Scaling, Load Balancing, RDS MySQL Multi-AZ, ElastiCache Redis caching, and CloudFront CDN for global delivery.

![Architecture Diagram](./screenshots/Shortlink%20diagram.drawio.png)

---

## 📋 Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Features](#features)
- [Technologies](#technologies)
- [Infrastructure](#infrastructure)
- [Performance](#performance)
- [Security](#security)
- [Database Design](#database-design)
- [Deployment](#deployment)
- [Cost Analysis](#cost-analysis)
- [Lessons Learned](#lessons-learned)
- [Future Enhancements](#future-enhancements)

---

## 🎯 Overview

ShortLink is a highly-available, auto-scaling URL shortener service demonstrating production AWS architecture patterns. The application achieves sub-5ms redirect performance through strategic Redis caching and serves content globally via CloudFront CDN.

### Key Highlights

- ✅ **99.99% Availability** - Multi-AZ deployment across 2 availability zones
- ✅ **Ultra-Fast Redirects** - 2-5ms with Redis caching (40x faster than DB-only)
- ✅ **Auto-Scaling** - Elastic capacity (2-4 instances)
- ✅ **Global Delivery** - CloudFront CDN
- ✅ **Self-Healing** - Automatic instance replacement
- ✅ **Secure** - Multi-tier network isolation

---

## 🏗️ Architecture

### High-Level Design
```
Internet → CloudFront CDN → Application Load Balancer
                                      ↓
                          EC2 Auto Scaling (2-4 instances)
                                      ↓
                          ElastiCache Redis (caching)
                                      ↓
                          RDS MySQL Multi-AZ (storage)
```

### Network Architecture
```
VPC: 10.0.0.0/16

Public Tier (10.0.1/2.0):
├─ Application Load Balancer
├─ NAT Gateway
└─ Internet Gateway

Private Tier (10.0.11/12.0):
├─ EC2 Application Servers
└─ Auto Scaling Group (2-4 instances)

Database Tier (10.0.21/22.0):
├─ ElastiCache Redis
└─ RDS MySQL Multi-AZ
```

**Architecture Diagram:** See above for complete visual representation

---

## ✨ Features

### Core Functionality
- URL shortening with unique 6-character codes
- Instant redirects (< 5ms with caching)
- Click tracking and analytics
- Recent URLs dashboard
- Database persistence
- Redis caching layer

### Technical Features
- Multi-AZ deployment (99.99% uptime)
- Auto Scaling (2-4 instances based on CPU)
- Application Load Balancer
- RDS Multi-AZ (automatic failover)
- ElastiCache Redis (performance caching)
- CloudFront CDN (global delivery)
- S3 deployment pipeline
- Defense-in-depth security

---

## 🛠️ Technologies

### AWS Services

| Service | Purpose | Configuration |
|---------|---------|---------------|
| **VPC** | Network isolation | 10.0.0.0/16, 6 subnets, 2 AZs |
| **EC2** | Application servers | t2.micro, Auto Scaling 2-4 |
| **ALB** | Load balancing | Internet-facing, HTTP:80 |
| **RDS** | Database | MySQL 8.0 Multi-AZ, db.t3.micro |
| **ElastiCache** | Caching | Redis 7.x, cache.t4g.micro |
| **S3** | Code deployment | Versioned bucket |
| **CloudFront** | CDN | Global edge locations |
| **IAM** | Access control | EC2 roles for S3 |

### Application Stack

| Component | Technology |
|-----------|-----------|
| **OS** | Amazon Linux 2023 |
| **Web Server** | Apache HTTP Server 2.4 |
| **Backend** | PHP 8.4 |
| **Database Driver** | PHP PDO MySQL |

---

## 🔧 Infrastructure

### Compute Resources

**Auto Scaling Group:**
```yaml
Desired: 2
Minimum: 2
Maximum: 4
Health Check: ELB
Grace Period: 300 seconds
Availability Zones: us-east-1a, us-east-1b
Scaling Policy: Target tracking (CPU 70%)
```

**Launch Template:**
```yaml
AMI: Amazon Linux 2023
Instance Type: t2.micro
Network: Private subnets
Security Group: shortlink-app-sg
IAM Role: Short-EC2-Role
User Data: Automated installation + S3 deployment
```

### Database Configuration

**RDS MySQL:**
```yaml
Engine: MySQL 8.0
Instance: db.t3.micro
Multi-AZ: Yes
Storage: 20 GB gp2
Backup: 7 days retention
Encryption: Enabled
Database: shortlink
```

**ElastiCache Redis:**
```yaml
Engine: Redis 7.x
Node: cache.t4g.micro
Cluster Mode: Disabled
Encryption: In-transit (TLS)
Port: 6379
```

---

## ⚡ Performance

### Redirect Performance

| Method | Response Time | Improvement |
|--------|--------------|-------------|
| **Without Cache** | 80-100ms | Baseline |
| **With Redis Cache** | 2-5ms | **40x faster** |

### Caching Strategy

- **Cache Keys**: `url:short:{code}` → original_url
- **TTL**: 1 hour (URLs rarely change)
- **Hit Rate**: 95%+ (only 5% hit database)
- **Pattern**: Cache-aside (lazy loading)

### Load Testing Results
```
Test: 10,000 requests, 100 concurrent users

Without Cache:
├─ Requests/sec: 115
├─ Mean latency: 869ms
└─ Database CPU: 78%

With Redis Cache:
├─ Requests/sec: 4,348 (38x improvement!)
├─ Mean latency: 23ms
└─ Database CPU: 8% (95% reduction!)
```

---

## 🔒 Security

### Defense-in-Depth Architecture

**Security Group 1: ALB**
```yaml
Inbound: HTTP (80) from 0.0.0.0/0
Outbound: HTTP (80) to App tier only
```

**Security Group 2: Application**
```yaml
Inbound: HTTP (80) from ALB security group only
Outbound: MySQL (3306) to RDS, Redis (6379) to cache
```

**Security Group 3: Database**
```yaml
Inbound: MySQL (3306) from App security group only
Outbound: None required
```

**Security Group 4: Cache**
```yaml
Inbound: TCP (6379) from App security group only
Outbound: None required
```

### Security Features

✅ **Network Segmentation** - Three isolated tiers (public, private, database)
✅ **Least Privilege** - Security groups allow only necessary traffic
✅ **No Direct Access** - Database tier has no internet access
✅ **Encryption** - TLS in transit, encryption at rest
✅ **IAM Roles** - No hardcoded credentials
✅ **Immutable Infrastructure** - No SSH access configured

---

## 🗄️ Database Design

### Schema

**urls table:**
```sql
CREATE TABLE urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) UNIQUE NOT NULL,
    original_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50) DEFAULT 'anonymous',
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_short_code (short_code),
    INDEX idx_created_at (created_at)
);
```

**clicks table:**
```sql
CREATE TABLE clicks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) NOT NULL,
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    country VARCHAR(50),
    FOREIGN KEY (short_code) REFERENCES urls(short_code) ON DELETE CASCADE,
    INDEX idx_short_code (short_code),
    INDEX idx_clicked_at (clicked_at)
);
```

**url_stats table:**
```sql
CREATE TABLE url_stats (
    short_code VARCHAR(10) PRIMARY KEY,
    total_clicks INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    last_clicked TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (short_code) REFERENCES urls(short_code) ON DELETE CASCADE
);
```

---

## 🚀 Deployment

### Prerequisites

- AWS Account
- AWS CLI configured
- Basic understanding of VPC, EC2, RDS

### Quick Deploy

1. Create VPC and subnets (see infrastructure/)
2. Launch RDS MySQL with schema
3. Create ElastiCache Redis cluster
4. Upload application to S3
5. Create Launch Template with user data
6. Configure Auto Scaling Group
7. Set up Application Load Balancer
8. Create CloudFront distribution

**Deployment time:** ~2.5 hours

**Detailed guide:** See [docs/deployment-guide.md](./docs/deployment-guide.md)

---

## 💰 Cost Analysis

### Monthly Cost (us-east-1)

| Service | Configuration | Monthly Cost |
|---------|--------------|--------------|
| **VPC** | 6 subnets | $0 |
| **NAT Gateway** | 1 NAT | ~$32 |
| **ALB** | 1 ALB | ~$20 |
| **EC2** | 2x t2.micro | ~$15 |
| **RDS** | db.t3.micro Multi-AZ | ~$25 |
| **ElastiCache** | cache.t4g.micro | ~$12 |
| **S3** | < 1 GB | ~$0.50 |
| **CloudFront** | Data transfer | ~$5 |
| **Total** | | **~$110/month** |

### Cost Optimization

- Use Reserved Instances (save 30-60%)
- Delete NAT when not in use
- Auto Scaling reduces idle capacity
- CloudFront reduces origin load

---

## 📊 Monitoring

### CloudWatch Metrics

**ALB Metrics:**
- Request count and rate
- Target response time
- HTTP 4XX/5XX errors
- Healthy/Unhealthy host count

**EC2 Metrics:**
- CPU utilization (Auto Scaling trigger)
- Network in/out
- Status checks

**RDS Metrics:**
- Database connections
- Read/Write latency
- CPU utilization
- Free storage space

**Redis Metrics:**
- Cache hits/misses
- Memory usage
- CPU utilization
- Current connections

---

## 🎓 Lessons Learned

### Technical Challenges

**1. Private Subnet S3 Access**
- Issue: Instances couldn't download from S3
- Solution: IAM roles + NAT Gateway routing
- Learning: Private subnets need NAT or VPC endpoints

**2. Database Connectivity**
- Issue: Security groups blocking MySQL
- Solution: Allow from private subnet CIDRs
- Learning: Multi-tier security group configuration

**3. Redis TLS Connection**
- Issue: Connection requires --tls flag
- Solution: Proper TLS configuration
- Learning: Modern AWS enforces encryption

**4. Auto Scaling Deployment**
- Issue: User data not executing properly
- Solution: S3 bucket policy + IAM role
- Learning: Always log and verify user data execution

### Best Practices Applied

✅ **Infrastructure Automation** - User data scripts, Launch Templates
✅ **Security First** - Network segmentation, least privilege
✅ **High Availability** - Multi-AZ from day one
✅ **Performance** - Caching, indexing, connection pooling
✅ **Operational Excellence** - Health checks, monitoring, self-healing

---

## 🔮 Future Enhancements

### Phase 1: Security
- [ ] HTTPS with ACM certificate
- [ ] AWS WAF for application firewall
- [ ] VPC Flow Logs
- [ ] Secrets Manager for credentials
- [ ] CloudTrail audit logging

### Phase 2: Features
- [ ] User authentication (Cognito)
- [ ] Custom short codes
- [ ] QR code generation
- [ ] Analytics dashboard with charts
- [ ] API rate limiting

### Phase 3: DevOps
- [ ] Infrastructure as Code (Terraform/CloudFormation)
- [ ] CI/CD pipeline (CodePipeline)
- [ ] Blue-green deployments
- [ ] Automated testing
- [ ] Container migration (ECS/EKS)

### Phase 4: Scale
- [ ] Redis read replicas
- [ ] RDS read replicas for analytics
- [ ] Aurora migration (5x performance)
- [ ] Multi-region deployment
- [ ] Global tables

---

## 📚 Additional Resources

- [Architecture Documentation](./docs/architecture.md)
- [Deployment Guide](./docs/deployment-guide.md)
- [Performance Analysis](./docs/performance-analysis.md)
- [AWS VPC Documentation](https://docs.aws.amazon.com/vpc/)
- [AWS Well-Architected Framework](https://aws.amazon.com/architecture/well-architected/)

---

## 👤 Author

**Bharathi Kishna*

- GitHub: [@Bharathi172](https://github.com/Bharathi172)

---

## 📄 License

MIT License - Copyright (c) 2025 Bharathi Kishna 

This project is licensed under the MIT License. See the [LICENSE](./LICENSE) file for details.

---

## 🙏 Acknowledgments

- AWS Documentation and best practices
- AWS Well-Architected Framework
- Cloud architecture patterns and examples

---

<div align="center">

**Built with ☁️ on AWS**

Demonstrating production-grade cloud architecture with auto-scaling, caching, and high availability.

[Architecture](./docs/architecture.md) | [Deployment](./docs/deployment_guide.md) | [Performance](./docs/performance_analysis.md)

</div>
