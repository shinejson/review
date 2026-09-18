# Login Attempt Limiting Implementation

## Overview
Implemented account lockout security feature for tenant admin users and team members. After 3 failed login attempts, accounts are automatically locked for 30 minutes. Only workspace owners (tenants) can manually unlock locked accounts.

## Changes Made

### 1. Database Schema Updates
**File:** `database.sql`
- Added 3 new columns to `team_members` table:
  - `failed_login_attempts` (INT, default 0) - tracks failed login count
  - `last_failed_attempt_at` (DATETIME) - timestamp of last failed attempt
  - `account_locked_until` (DATETIME) - when the account lock expires

- Added same 3 columns to `tenants` table:
  - Tenant accounts (workspace owners) also have login attempt tracking

**Migration:** Lines added at the end of database.sql will auto-apply when database is initialized.

### 2. Login Attempt Tracking Helper
**File:** `includes/login_attempts.php` (NEW)
- `isAccountLocked($conn, $table, $user_id)` - Check if account is currently locked
- `recordFailedLoginAttempt($conn, $table, $user_id)` - Record failed attempt, auto-lock after 3 attempts
- `resetFailedLoginAttempts($conn, $table, $user_id)` - Clear attempts on successful login
- `unlockUserAccount($conn, $table, $user_id)` - Admin unlock function
- `getUserLockStatus($conn, $table, $user_id)` - Get current lock status

### 3. Login Form Updates
**File:** `admin/login.php`
- Added `require_once` for new `login_attempts.php` helper
- **Tenant login flow:**
  - Check if account is locked before password verification
  - Record failed attempt on wrong password (increment counter)
  - Lock account for 30 minutes after 3rd failed attempt
  - Reset counter on successful login

- **Team member login flow:**
  - Same logic as tenants
  - Locked accounts cannot sign in until unlocked by workspace owner

- **Error messages:**
  - Failed attempt: "Invalid credentials. Attempt X of 3."
  - Locked account: "This account is temporarily locked. Please try again in 30 minutes or contact your workspace owner to unlock it."

### 4. Team Members Management UI
**File:** `admin/team.php`
- Added `require_once` for `login_attempts.php`
- **New metric card:** Shows count of "Locked" accounts due to failed login attempts
- **New table column:** "Lock Status" displays 🔒 Locked badge for locked accounts
- **Unlock button:** Appears next to locked accounts for workspace owner to unlock
- **POST action handler:** New "unlock" action to manually unlock accounts
- **Activity logging:** Unlock actions are logged in system activity

## Security Features

✅ **Automatic Account Lockout**
- 3 strikes and you're out for 30 minutes
- Brute force attacks significantly harder

✅ **Workspace Owner Control**
- Only the tenant (workspace owner) can unlock accounts
- Prevents unauthorized access restoration

✅ **Clear User Feedback**
- Users see exact attempt count (helps explain lockout)
- Clear messaging about lock duration

✅ **Audit Trail**
- All unlock actions logged to system activity
- Workspace owners can see who unlocked what and when

## How It Works

### Failed Login Scenario:
1. User enters wrong password → `recordFailedLoginAttempt()` called
2. Attempt counter increments (1/3, 2/3, etc.)
3. After 3rd failed attempt:
   - `account_locked_until` set to 30 minutes in future
   - Account cannot be used until time passes or admin unlocks it
4. Error message tells user when they can try again

### Successful Login After Lock:
1. If account is locked, login rejected immediately
2. User must wait 30 minutes OR ask workspace owner to unlock
3. Workspace owner goes to Team Members page
4. Clicks "Unlock" button on locked account
5. Account immediately available for login, counter reset

## Database Columns Reference

| Column | Type | Purpose |
|--------|------|---------|
| `failed_login_attempts` | INT | Stores count of consecutive failed attempts |
| `last_failed_attempt_at` | DATETIME | Timestamp of most recent failed login |
| `account_locked_until` | DATETIME | When lock expires; NULL if not locked |

## Customization Options

To change the **3-attempt limit**, edit `includes/login_attempts.php`:
```php
// Line ~58 - Change 3 to desired number
if ($attempts >= 3) {
    $is_locked = true;
    // ...
}
```

To change the **30-minute lock duration**, edit `includes/login_attempts.php`:
```php
// Line ~63 - Change 1800 to different seconds
$lock_until = date('Y-m-d H:i:s', time() + 1800); // 30 minutes = 1800 seconds
```

## Testing

1. **Test failed attempts:**
   - Login with correct username but wrong password 3 times
   - Account should be locked
   - Error message should show lock status

2. **Test unlock:**
   - Go to `admin/team.php`
   - Find locked account
   - Click "Unlock" button
   - Try login again - should work

3. **Test reset on success:**
   - Login with correct credentials
   - `failed_login_attempts` should reset to 0

## Files Modified
- ✅ database.sql - Added migration SQL
- ✅ includes/login_attempts.php - NEW helper functions
- ✅ admin/login.php - Integrated attempt tracking
- ✅ admin/team.php - Added unlock UI and actions

## Backwards Compatibility
✅ Fully compatible - columns are optional and default to 0/NULL
✅ Existing accounts automatically work with new system
✅ No breaking changes to existing functionality
