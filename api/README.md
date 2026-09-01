# API Documentation

## Submit Rating API

**Endpoint:** `/api/submit_rating.php`

**Method:** POST

**Parameters:**
- `company_id` (required): Integer - ID of the company being rated
- `rating` (required): Integer (1-5) - Rating value
- `customer_name` (required): String - Name of the reviewer
- `customer_email` (required): String - Email of the reviewer
- `comment` (optional): String - Review comment

**Response:**
- Success: HTML confirmation message
- Error: Error message string

**Example:**
```php
POST /api/submit_rating.php
company_id=1&rating=5&customer_name=John Doe&customer_email=john@example.com&comment=Great service!
```
