# ShortLink Pro - Architecture Documentation

## System Architecture

### High-Level Overview

ShortLink Pro uses a three-tier architecture deployed across multiple availability zones for high availability and fault tolerance.
```
┌─────────────────────────────────────────────────────────────┐
│                      Internet Users                         │
└────────────────────────┬────────────────────────────────────┘
                         │
              ┌──────────▼──────────┐
              │  CloudFront CDN     │ ← Global edge locations
              │  (drs5cd2ebc2dq)    │
              └──────────┬──────────┘
                         │
              ┌──────────▼──────────┐
              │ Application Load    │ ← Traffic distribution
              │    Balancer         │
              └──────────┬──────────┘
                         │
         ┌───────────────┼───────────────┐
         │               │               │
    ┌────▼────┐     ┌───▼────┐     ┌───▼────┐
    │  EC2    │     │  EC2   │     │  EC2   │ ← Auto Scaling
    │Instance │     │Instance│     │Instance│   (2-4 instances)
    └────┬────┘     └────┬───┘     └────┬───┘
         │               │               │
         └───────────────┼───────────────┘
                         │
              ┌──────────▼──────────┐
              │  ElastiCache Redis  │ ← 2-5ms cached lookups
              └──────────┬──────────┘
                         │
              ┌──────────▼──────────┐
              │   RDS MySQL         │ ← Persistent storage
              │   (Multi-AZ)        │   Auto failover
              └─────────────────────┘
```

## Network Architecture

### VPC Design
- **CIDR Block**: 10.0.0.0/16
- **Availability Zones**: us-east-1a, us-east-1b
- **Total Subnets**: 6 (across 3 tiers)

### Subnet Layout

#### Public Tier (Internet-facing)
- **10.0.1.0/24** (us-east-1a) - ALB, NAT Gateway
- **10.0.2.0/24** (us-east-1b) - ALB

#### Private Tier (Application Layer)
- **10.0.11.0/24** (us-east-1a) - EC2 instances
- **10.0.12.0/24** (us-east-1b) - EC2 instances

#### Database Tier (Data Layer)
- **10.0.21.0/24** (us-east-1a) - RDS, Redis
- **10.0.22.0/24** (us-east-1b) - RDS standby, Redis

### Route Tables

**Public Route Table:**
```
10.0.0.0/16 → local
0.0.0.0/0 → Internet Gateway
```

**Private Route Table:**
```
10.0.0.0/16 → local
0.0.0.0/0 → NAT Gateway
```

**Database Route Table:**
```
10.0.0.0/16 → local
(No internet access - isolated)
```

## Security Architecture

### Defense-in-Depth

#### Layer 1: Internet Gateway
- Single entry point to VPC
- Public traffic filtered by ALB security group

#### Layer 2: Application Load Balancer
- **Security Group**: shortlink-alb-sg
- **Inbound**: Port 80/443 from 0.0.0.0/0
- **Outbound**: Port 80 to application tier

#### Layer 3: Application Servers
- **Security Group**: shortlink-app-sg
- **Inbound**: Port 80 from ALB security group ONLY
- **Outbound**: All traffic (for RDS, Redis, S3 access)

#### Layer 4: Database & Cache
- **RDS Security Group**: shortlink-rds-sg
  - Inbound: Port 3306 from application tier ONLY
- **Redis Security Group**: shortlink-redis-sg
  - Inbound: Port 6379 from application tier ONLY

### Security Features
✅ Multi-tier network isolation  
✅ Security groups with least privilege  
✅ No direct database internet access  
✅ Encryption in transit (Redis TLS)  
✅ Encryption at rest (RDS)  
✅ NAT Gateway for outbound-only internet  

## Data Flow

### URL Creation Flow
```
1. User submits long URL via CloudFront
2. CloudFront → ALB → EC2 instance
3. Application generates 6-char short code
4. Store in RDS: short_code → original_url
5. Cache in Redis: SET url:short:{code} {url} EX 3600
6. Return short URL to user
```

### URL Redirect Flow (Cached - 95% of requests)
```
1. User visits short URL via CloudFront
2. CloudFront → ALB → EC2 instance
3. Check Redis: GET url:short:{code}
4. Cache HIT! (2-5ms)
5. Log click async to RDS
6. 302 Redirect to original URL
```

### URL Redirect Flow (Cache Miss - 5% of requests)
```
1. User visits short URL
2. Check Redis: GET url:short:{code} → NULL
3. Query RDS: SELECT original_url WHERE short_code = {code}
4. Store in Redis for next time
5. Log click to RDS
6. 302 Redirect (80-100ms first time)
```

## Scalability Design

### Horizontal Scaling
- **Auto Scaling Group**: 2-4 instances
- **Trigger**: CPU > 70% → Scale out
- **Cooldown**: CPU < 30% → Scale in
- **Health Checks**: ALB + Auto Scaling

### Database Scaling
- **RDS Multi-AZ**: Automatic failover (< 2 min)
- **Future**: Read replicas for analytics queries
- **Redis**: Single node (can add replicas)

### CDN Scaling
- **CloudFront**: Global edge locations
- **Caching**: Static assets + API responses
- **Origin Shield**: Can be enabled for cost savings

## High Availability

### Multi-AZ Deployment
- **ALB**: Across 2 AZs (us-east-1a, 1b)
- **EC2**: Auto Scaling distributes across 2 AZs
- **RDS**: Multi-AZ with automatic failover
- **Redis**: Can be configured with replicas

### Failure Scenarios

| Failure | Impact | Recovery |
|---------|--------|----------|
| Single EC2 instance fails | ALB routes to healthy instances | Auto Scaling replaces (5 min) |
| Availability Zone fails | Traffic to other AZ | Automatic (< 1 min) |
| RDS primary fails | Standby promoted | Automatic (< 2 min) |
| Redis cache fails | Slower (DB queries) | Application continues |

### SLA
- **Target Availability**: 99.99%
- **Downtime Budget**: 52 minutes/year
- **Achieved Through**: Multi-AZ, Auto Scaling, self-healing

## Technology Decisions

### Why RDS MySQL?
- Need ACID transactions for URL creation
- Relational data (URLs ↔ Clicks)
- Complex analytics queries (joins, aggregations)
- Multi-AZ for automatic failover

### Why ElastiCache Redis?
- Read-heavy workload (100:1 read/write ratio)
- URL mappings don't change (perfect for caching)
- Sub-5ms lookup performance
- 95%+ cache hit rate achievable

### Why CloudFront?
- Global user base
- Edge caching reduces origin load
- HTTPS termination
- DDoS protection (AWS Shield Standard)

### Why Auto Scaling?
- Variable traffic patterns
- Cost optimization (scale down when idle)
- Self-healing infrastructure
- Handle traffic spikes automatically

---

## Architecture Patterns

### Cache-Aside Pattern
```
Application checks cache first:
├─ Cache HIT → Return immediately (2-5ms)
└─ Cache MISS → Query DB → Store in cache → Return (80-100ms)
```

### Database Connection Pooling
```
Each EC2 instance maintains persistent DB connections
Reduces connection overhead
```

### Stateless Application Design
```
No session data on EC2 instances
All state in Redis or RDS
Instances are disposable and replaceable
```

---

## Monitoring & Observability

### CloudWatch Metrics
- ALB request count and latency
- EC2 CPU utilization (triggers Auto Scaling)
- RDS connections and query performance
- Redis cache hit rate and memory usage

### Logging
- ALB access logs (S3)
- Application logs (CloudWatch Logs)
- RDS slow query logs
- VPC Flow Logs (optional)

---

## Cost Optimization Strategies

1. **Auto Scaling**: Scale down during low traffic (nights, weekends)
2. **Reserved Instances**: Save 30-60% on predictable workloads
3. **CloudFront**: Reduces origin requests (lower EC2/RDS load)
4. **Redis Caching**: 95% cache hit rate = 95% fewer DB queries
5. **Right-Sizing**: t2.micro sufficient for moderate traffic

---

*Last Updated: December 2025*
