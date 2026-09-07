# Logout System Review & Fix

## Issue Reported
User sessions logout not working for both admin and superadmin panels.

## Investigation Findings

### What Was Working ✓
1. **Logout URLs**: Both shells correctly generate logout URLs using `auth_logout_url()`
2. **Logout Token**: Token generation works correctly (only creates once per session)
3. **JavaScript Confirmations**: Both `admin.js` and `superadmin.js` properly implement confirmation dialogs
4. **Session Management**: Core session functions in `includes/session.php` are correct

### What Was Fixed ✗

#### 1. **Missing Session Start**
Both logout files didn't explicitly ensure session was started before checking tokens.

**Fixed in `admin/logout.php` and `superadmin/logout.php`:**
```php
// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
```

#### 2. **Missing Function Includes**
`admin/logout.php` didn't include `functions.php` needed for `sa_flash()` and other helpers.

**Fixed in `admin/logout.php`:**
```php
require_once dirname(__DIR__) . '/includes/functions.php';
```

#### 3. **Better Error Handling**
Added flash messages when logout fails instead of just URL parameters.

**Before:**
```php
header('Location: ' . (isLoggedIn() ? 'index.php?logout=invalid' : 'login.php?signed_out=invalid'));
```

**After:**
```php
if (isLoggedIn()) {
    sa_flash('warning', 'That sign-out link is not valid. Please try again.');
    header('Location: index.php');
} else {
    header('Location: login.php?signed_out=invalid');
}
```

## How Logout Works

### Flow Diagram:
```
1. User clicks "Sign out" link
   ↓
2. Browser shows confirmation dialog (JavaScript)
   ↓
3. If confirmed → Navigate to logout.php?t=[token]
   ↓
4. Logout page checks:
   - Is session started? ✓
   - Does token match? → auth_logout_request_ok()
   ↓
5. If token valid:
   - Mark session as logged_out in database
   - Destroy PHP session and cookies
   - Redirect to login.php?signed_out=1
   ↓
6. If token invalid:
   - Show flash message
   - Redirect back to dashboard
```

### Security Features:
- **CSRF Protection**: Logout requires one-time token
- **Token Validation**: Uses `hash_equals()` for timing-attack resistance
- **Session Regeneration**: New session ID after login
- **Database Tracking**: All sessions recorded in `user_sessions` table

## Diagnostic Tools Created

### Test Pages:
1. `admin/test_logout.php` - Admin logout diagnostics
2. `superadmin/test_logout.php` - Superadmin logout diagnostics

### What They Show:
- Current session data
- Logout token status
- Generated logout URL
- Token matching verification
- Full session contents
- Step-by-step testing instructions

### How to Use:
1. Login to admin or superadmin
2. Navigate to `/admin/test_logout.php` or `/superadmin/test_logout.php`
3. View session data and logout token
4. Click the test logout link
5. Verify redirect to login page

## Common Logout Issues & Solutions

### Issue: "That sign-out link is not valid"
**Cause**: Token mismatch between session and URL
**Solution**: Check if session is persisting correctly

### Issue: Stays logged in after clicking logout
**Cause 1**: JavaScript confirmation canceled
**Solution**: User must click "OK" on confirmation dialog

**Cause 2**: Session not starting properly
**Solution**: Now fixed with explicit session_start() check

### Issue: Redirects but still logged in
**Cause**: Session cookies not being cleared
**Solution**: Check browser cookies and `auth_destroy_session()` function

## Testing Checklist

- [ ] Admin logout works from dashboard
- [ ] Admin logout works from any page
- [ ] Superadmin logout works from dashboard
- [ ] Superadmin logout works from any page
- [ ] Confirmation dialog appears
- [ ] Canceling confirmation keeps user logged in
- [ ] Accepting confirmation logs user out
- [ ] Redirects to login page after logout
- [ ] Cannot access protected pages after logout
- [ ] Session is properly destroyed (check cookies)
- [ ] Database marks session as logged_out

## Files Modified

1. `admin/logout.php` - Added session start check, includes, better error handling
2. `superadmin/logout.php` - Added session start check, includes, better error handling
3. `admin/test_logout.php` - Created diagnostic tool
4. `superadmin/test_logout.php` - Created diagnostic tool

## Next Steps

1. Test logout on both admin and superadmin panels
2. Use diagnostic pages to verify session data
3. Check browser console for JavaScript errors
4. Verify database `user_sessions` table is being updated
5. Remove test pages after confirming functionality

## Notes

- Logout requires confirmation for security (prevents accidental logouts)
- Sessions are tracked in database for audit trail
- Both admin and superadmin use same underlying session system
- Token is one-time use (regenerated after successful login)
