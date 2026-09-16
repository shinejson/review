# Optibiz Mobile REST API Documentation (`/api/v1/`)

Production-ready REST API suite for Optibiz cross-platform mobile apps (Flutter, React Native, iOS, Android).

## Base URL
- Production: `https://your-domain.com/rate/api/v1`
- Local Development: `http://localhost:8080/rate/api/v1` (or `http://127.0.0.1:8080/rate/api/v1`)

## Global Headers
- `Content-Type: application/json`
- `Accept: application/json`
- `X-Device-Fingerprint: <device_unique_uuid>` (Recommended for anti-spam tracking)
- `Authorization: Bearer <access_token>` (Required for protected endpoints)

---

## 1. Authentication

### `POST /auth/login.php`
Authenticates a tenant administrator and returns JWT access & refresh tokens.

#### Request Body
```json
{
  "username": "owner@acme.com", // Supports username, email, or public_id (e.g. OPT-XXXXXX)
  "password": "SecretPassword123!"
}
```

#### Response (200 OK)
```json
{
  "success": true,
  "status": 200,
  "message": "Authentication successful",
  "data": {
    "user": {
      "id": 5,
      "public_id": "OPT-449193",
      "company_id": 4,
      "company_name": "Acme Bistro",
      "email": "owner@acme.com",
      "username": "acme_bistro",
      "role": "tenant_admin",
      "subscription_status": "active",
      "plan_id": 2,
      "logo": "uploads/logos/acme.png"
    },
    "tokens": {
      "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
      "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
      "token_type": "Bearer",
      "expires_in": 86400
    }
  }
}
```

---

### `POST /auth/refresh.php`
Exchange a refresh token for a fresh access token without re-authenticating.

#### Request Body
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

#### Response (200 OK)
```json
{
  "success": true,
  "status": 200,
  "message": "Token refreshed successfully",
  "data": {
    "tokens": {
      "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
      "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
      "token_type": "Bearer",
      "expires_in": 86400
    }
  }
}
```

---

## 2. Dashboard Analytics

### `GET /dashboard/metrics.php`
Requires: `Authorization: Bearer <access_token>`

Returns real-time rating aggregates, 5-star distribution, 30-day daily review trends, and platform benchmarks.

#### Response (200 OK)
```json
{
  "success": true,
  "status": 200,
  "message": "Dashboard metrics retrieved successfully",
  "data": {
    "company": {
      "tenant_id": 5,
      "company_id": 4,
      "company_name": "Acme Bistro"
    },
    "overview": {
      "total_ratings": 84,
      "average_score": 4.8,
      "verified_reviews": 68,
      "reported_reviews": 1,
      "pending_shields": 3,
      "booster_events": 122
    },
    "breakdown": {
      "5_star": 65,
      "4_star": 14,
      "3_star": 2,
      "2_star": 2,
      "1_star": 1
    },
    "sentiment": {
      "positive": 79,
      "neutral": 2,
      "negative": 3
    },
    "trends_30d": [
      {
        "date": "2026-09-01",
        "count": 4,
        "daily_avg": 4.8
      }
    ],
    "platform_benchmarks": {
      "platform_total_reviews": 1250,
      "platform_average": 4.7
    }
  }
}
```

---

## 3. Customer Reviews

### `GET /reviews/list.php`
Requires: `Authorization: Bearer <access_token>`

#### Query Parameters
- `page`: Page number (default: `1`)
- `limit` / `per_page`: Items per page (default: `15`, max: `100`)
- `rating`: Exact star rating (`1` to `5`)
- `min_rating`: Minimum stars (`1` to `5`)
- `reported`: `0` (clean), `1` (reported), or omit for all
- `is_verified`: `1` or `0`
- `date_from`: `YYYY-MM-DD`
- `date_to`: `YYYY-MM-DD`
- `search`: Keyword in customer name, email, or comment text
- `sort`: `newest` (default), `oldest`, `highest`, `lowest`

#### Response (200 OK)
```json
{
  "success": true,
  "status": 200,
  "message": "Reviews retrieved successfully",
  "data": {
    "reviews": [
      {
        "id": 24,
        "rating": 5,
        "customer_name": "Sarah Jenkins",
        "customer_email": "sarah@example.com",
        "comment": "Incredible dining experience! Staff was super polite.",
        "photos": ["uploads/reviews/plate1.jpg"],
        "created_at": "2026-09-14 18:22:10",
        "admin_reply": "Thank you Sarah! Hope to see you again soon.",
        "responded_at": "2026-09-14 19:10:00",
        "helpful_count": 6,
        "reported": false,
        "is_verified": true,
        "verification_type": "receipt",
        "is_escalated": false,
        "escalation_status": "none"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total_items": 84,
      "total_pages": 6,
      "has_next": true,
      "has_prev": false
    }
  }
}
```

---

## 4. Public Rating Ingestion

### `POST /ratings/submit.php`
**Public endpoint** (No token required). Used by QR counter stands, mobile review forms, and customer apps.

#### Rate Limiting
- Enforced at **5 submissions per 10 minutes** per IP & Device Fingerprint.
- Exceeding limit returns `HTTP 429 Too Many Requests` with `Retry-After: <seconds>` header.
- Repeated duplicate submissions within 180s return `HTTP 409 Conflict`.

#### Request Body
```json
{
  "company_id": 4,
  "rating": 5,
  "customer_name": "Michael Doe",
  "customer_email": "michael@gmail.com",
  "comment": "Excellent food and quick table turnaround!",
  "device_fingerprint": "mobile_app_uuid_99218"
}
```

#### Response (201 Created)
```json
{
  "success": true,
  "status": 201,
  "message": "Review submitted successfully",
  "data": {
    "review_id": 85,
    "company_id": 4,
    "company_name": "Acme Bistro",
    "rating": 5,
    "created_at": "2026-09-16 21:40:00",
    "routing": {
      "routing": "google_booster",
      "score": 5,
      "booster_eligible": true,
      "google_review_url": "https://search.google.com/local/writereview?placeid=ChIJ...",
      "routing_message": "Thank you for your 5-star rating! We would greatly appreciate it if you could share this review on Google as well."
    }
  }
}
```
*(When a low score e.g. 1-3 stars is received, `routing` will be `"private_shield"` with `google_review_url: null` and the review is routed privately to management.)*
