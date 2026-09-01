# API Documentation

## Submit Quote API (Get Started wizard)

**Endpoint:** `/api/submit_quote.php`

**Method:** POST

**Parameters:**
- `company_name` (required): String - Company requesting the quote
- `contact_person` (required): String - Name of the contact person
- `email` (required): String - Contact email
- `phone` (required): String - Contact phone number
- `category` (optional): Integer - ID of the industry category
- `plan_id` (optional): Integer - ID of the subscription plan of interest
- `location` (optional): String - Company location
- `website` (optional): String - Company website
- `num_companies` (optional): Integer - Number of companies to manage
- `expected_ratings` (optional): Integer - Expected ratings per month
- `notes` (optional): String - Additional notes

**Response:** JSON
- Success: `{"success": true, "message": "Quote request submitted successfully"}`
- Error: `{"success": false, "message": "..."}`

Creates the `quote_requests` table automatically if it does not exist.

**Example:**
```
POST /api/submit_quote.php
company_name=Acme Ltd&contact_person=John Doe&email=john@acme.com&phone=555-0100&category=1&plan_id=2
```

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
