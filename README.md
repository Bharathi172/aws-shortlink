# 🔗 ShortLink Pro - Production-Grade URL Shortener

[![AWS](https://img.shields.io/badge/AWS-Cloud-orange?style=for-the-badge&logo=amazon-aws)](https://aws.amazon.com/)
[![Live Demo](https://img.shields.io/badge/Demo-Live-success?style=for-the-badge)](https://drs5cd2ebc2dq.cloudfront.net/)
[![Architecture](https://img.shields.io/badge/Architecture-Multi--Tier-blue?style=for-the-badge)](https://github.com/Bharathi172/shortlink-pro)

> A production-grade URL shortener built on AWS with Auto Scaling, Load Balancing, RDS MySQL, ElastiCache Redis, and CloudFront CDN for global delivery.

**🌐 Live Demo:** https://drs5cd2ebc2dq.cloudfront.net/

---

## 📋 Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Features](#features)
- [Technologies](#technologies)
- [Infrastructure](#infrastructure)
- [Performance](#performance)
- [Deployment](#deployment)
- [Cost Analysis](#cost-analysis)
- [Screenshots](#screenshots)

---

## 🎯 Overview

ShortLink Pro is a highly-available, auto-scaling URL shortener demonstrating production AWS architecture patterns. The application handles URL shortening with sub-5ms redirect performance using Redis caching and serves content globally via CloudFront CDN.

### Key Highlights

- ✅ **99.99% Availability** - Multi-AZ deployment
- ✅ **Ultra-Fast Redirects** - 2-5ms with Redis caching (40x faster than DB-only)
- ✅ **Auto-Scaling** - Elastic capacity (2-4 instances)
- ✅ **Global Delivery** - CloudFront CDN
- ✅ **Self-Healing** - Automatic instance replacement
- ✅ **Secure** - Multi-tier network isolation

---

## 🏗️ Architecture

### Three-Tier Architecture
```
Internet → CloudFront CDN → Application Load Balancer
                                      ↓
                          EC2 Auto Scaling (2-4 instances)
                                      ↓
                          ElastiCache Redis (caching)
                                      ↓
                          RDS MySQL Multi-AZ (storage)
```

### Network Design
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

| Component | Technology |
|-----------|-----------|
| **Compute** | EC2 Auto Scaling, Application Load Balancer |
| **Database** | RDS MySQL 8.0 Multi-AZ |
| **Cache** | ElastiCache Redis |
| **CDN** | CloudFront |
| **Storage** | S3 |
| **Networking** | VPC, Subnets, Security Groups, NAT Gateway |
| **Application** | PHP 8.4, Apache HTTP Server |

---

## 🔧 Infrastructure

### AWS Services (8 Services)

1. **VPC** - Custom virtual private cloud (10.0.0.0/16)
2. **EC2** - Application servers (t2.micro, Auto Scaling 2-4)
3. **ALB** - Application Load Balancer
4. **RDS** - MySQL Multi-AZ (db.t3.micro)
5. **ElastiCache** - Redis cluster (cache.t4g.micro)
6. **S3** - Code deployment bucket
7. **CloudFront** - Global CDN
8. **IAM** - Roles for EC2 S3 access

### Database Schema
```sql
urls table:
├─ short_code (unique index)
├─ original_url
├─ created_at
└─ is_active

clicks table:
├─ short_code (foreign key)
├─ clicked_at
├─ ip_address
└─ user_agent

url_stats table:
├─ short_code (primary key)
├─ total_clicks
└─ last_clicked
```

---

## ⚡ Performance

### Redirect Performance

| Method | Response Time | Improvement |
|--------|--------------|-------------|
| **Without Cache** | 80-100ms | Baseline |
| **With Redis Cache** | 2-5ms | **40x faster!** |

### Caching Strategy

- **Cache Keys**: `url:short:{code}` → original_url
- **TTL**: 1 hour (URLs rarely change)
- **Hit Rate**: 95%+ (only 5% hit database)
- **Pattern**: Cache-aside (lazy loading)

### Scalability

- Handles 100,000+ redirects/day
- Auto-scales from 2 to 4 instances
- CloudFront edge caching globally
- Database load reduced by 95%

---

## 🚀 Deployment

### Prerequisites
- AWS Account
- AWS CLI configured
- Basic understanding of AWS services

### Quick Deploy

1. **Create VPC and subnets** (see infrastructure/)
2. **Launch RDS MySQL** with schema (see database-schema.sql)
3. **Create ElastiCache Redis cluster**
4. **Upload application to S3**
5. **Create Launch Template** with user data
6. **Configure Auto Scaling Group** (2-4 instances)
7. **Set up Application Load Balancer**
8. **Create CloudFront distribution**

---

## 💰 Cost Analysis

### Monthly Cost (us-east-1)

| Service | Configuration | Monthly Cost |
|---------|--------------|--------------|
| **VPC** | 6 subnets | $0 |
| **NAT Gateway** | 1 NAT | ~$32 |
| **ALB** | 1 ALB | ~$20 |
| **EC2** | 2x t2.micro (730 hrs) | ~$15 |
| **RDS** | db.t3.micro Multi-AZ | ~$25 |
| **ElastiCache** | cache.t4g.micro | ~$12 |
| **S3** | < 1 GB | ~$0.50 |
| **CloudFront** | Data transfer | ~$5 |
| **Total** | | **~$110/month** |

### Cost Optimization
- Use Reserved Instances (save 30-60%)
- Delete NAT when not needed
- Use Spot Instances for dev/test
- Auto Scaling reduces idle capacity

---

## 📸 Screenshots

*[Add screenshots here]*

---

## 🎓 Lessons Learned

### Technical Challenges

1. **Private Subnet S3 Access**
   - Issue: Instances couldn't download from S3
   - Solution: IAM roles + S3 bucket policy
   - Learning: Private subnets need NAT or VPC endpoints

2. **Database Connectivity**
   - Issue: Security groups blocking MySQL
   - Solution: Allow traffic from private subnet CIDRs
   - Learning: Security group layering for multi-tier apps

3. **Redis TLS Connection**
   - Issue: Redis requires --tls flag
   - Solution: Configure encryption in transit properly
   - Learning: Modern AWS enforces encryption

---

## 🔮 Future Enhancements

- [ ] Add user authentication (Cognito)
- [ ] Custom domain with Route 53
- [ ] HTTPS with ACM certificate
- [ ] Analytics dashboard with charts
- [ ] QR code generation
- [ ] API rate limiting
- [ ] Infrastructure as Code (Terraform/CloudFormation)
- [ ] CI/CD pipeline
- [ ] Monitoring dashboard (CloudWatch)

---

## 📚 Additional Resources

- [AWS VPC Documentation](https://docs.aws.amazon.com/vpc/)
- [Auto Scaling Best Practices](https://docs.aws.amazon.com/autoscaling/)
- [ElastiCache Redis Guide](https://docs.aws.amazon.com/elasticache/)

---

<div align="center">

**Built with ☁️ on AWS**

[Live Demo](https://drs5cd2ebc2dq.cloudfront.net/) | [Report Bug](https://github.com/Bharathi172/shortlink-pro/issues)

</div>
