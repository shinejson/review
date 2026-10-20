5. Engineering Quality & Open Next Steps
Package Name & Android Clean-Up:
In optibiz/app/build.gradle.kts, the production applicationId is set to "com.aistudio.optibiz.rvwp". The internal code namespace remains "com.example" and can be refactored prior to production store submission.
External API Keys to Populate in Production:
Set OPTIBIZ_FCM_SERVICE_JSON in .env for production Firebase push dispatches.
Configure Google Web Client ID in OPTIBIZ_GOOGLE_CLIENT_IDS for Google One-Tap/Sign-In.


3. Production Deployment Checklist

Before going live on your selected host:

Database: Import database.sql and run php migrations/migrate_review_trust.php.
Environment File (.env):
Set APP_ENV=production
Set APP_BASE_URL=https://yourdomain.com/rate (or root https://yourdomain.com)
Fill in database credentials (OPTIBIZ_DB_HOST, OPTIBIZ_DB_USER, OPTIBIZ_DB_PASS, OPTIBIZ_DB_NAME)
Set strong production secrets for OPTIBIZ_JWT_SECRET and OPTIBIZ_QR_SECRET
Set OPTIBIZ_FCM_SERVICE_JSON for push notifications
Apache .htaccess: Uncomment lines 101–105 in .htaccess to force HTTPS across the entire domain.
DNS & Robots: Ensure robots.txt and sitemap.xml are accessible at your domain root.