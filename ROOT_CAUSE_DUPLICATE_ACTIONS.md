# Root Cause: Why Users Received Duplicate Actions for Same Link Across Different Orders

## The Issue (From Screenshots)

**Order #9205:**
- Link: `instagram.com/reel/DRfwMY_AthN/...`
- Total: 10 actions
- Completed: 9 actions
- Users who completed it: Moha222, Mahdi, Real ProMax, Oussama, etc.

**Order #9251:**  
- Link: **SAME** `instagram.com/reel/DRfwMY_AthN/...`
- Total: 20 actions
- These SAME users received Order #9251 again
- They responded "done" (already did it in Order #9205)
- Result: Wasted announcements and duplicate action records

---

## Timeline of What Happened

### Phase 1: Original Code (BEFORE Recent Fixes)
The eligibility check in both `ResumeOrderService::getEligibleUsers()` and the coordinator job **DID check eligibility**, but with a **critical gap**:

```php
// OLD CODE - What it checked:
$eligibleUsers = User::where('type', 'user')
    // ✅ Exclude users who have done/external on THIS order
    ->whereNotIn('id', function ($q) use ($order) {
        $q->select('user_id')
          ->from('actions')
          ->where('order_id', $order->id)  // ❌ ONLY THIS ORDER!
          ->whereIn('status', ['done', 'external']);
    })
    // ✅ Exclude users whose profile_link matches target
    ->whereRaw("TRIM(TRAILING '/' FROM profile_link) != ?", [$normalizedTarget])
    ->get();
```

**What Was Missing:**
- ❌ NO check for users who completed the SAME link on OTHER orders
- Only checked if user completed **THIS specific order**
- Did NOT check if user completed **a different order with the same target URL**

**Result:**
```
User "Moha222" completes Order #9205 (link1) ✅
  → Action created: user_id=123, order_id=9205, status='done'

Coordinator processes Order #9251 (same link1)
  → Checks: "Does Moha222 have done/external on order_id=9251?"
  → Answer: NO (only has it on 9205)
  → Moha222 is considered ELIGIBLE ❌
  → Announcement sent to Moha222
  → Moha222 responds "done" (already did it!)
  → Duplicate action record created
```

---

### Phase 2: Conversation Summary Fix (Added Cross-Order Check to ResumeOrderService)

According to the conversation summary, a fix was applied to `ResumeOrderService::getEligibleUsers()`:

```php
// ADDED in earlier conversation:
->whereNotIn('id', function ($sub) use ($targetHash, $order) {
    $sub->select('a1.user_id')
        ->from('actions as a1')
        ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
        ->whereIn('a1.status', ['done', 'external'])
        ->where(function ($q) use ($targetHash) {
            $q->where('o1.target_url_hash', $targetHash)
              ->orWhereRaw("o1.target_url_hash IS NULL AND SHA1(TRIM(TRAILING '/' FROM o1.target_url)) = ?", [$targetHash]);
        })
        ->where('o1.id', '!=', $order->id); // ✅ Check OTHER orders!
})
```

This fixed `ResumeOrderService`, but...

---

### Phase 3: The Remaining Problem (Why Issue Still Occurred)

**The coordinator job had its OWN duplicated eligibility logic** that was NOT updated!

Looking at line 666 in the current code, I can see it had been updated to use `COALESCE`:
```php
->whereRaw("COALESCE(o1.target_url_hash, SHA1(TRIM(TRAILING '/' FROM o1.target_url))) = ?", [$targetHash])
```

But there were still issues:

1. **Code Duplication:** ~100 lines of eligibility logic duplicated in the coordinator
2. **Inconsistent Updates:** When `ResumeOrderService` was fixed, coordinator logic wasn't always updated
3. **Performance:** Per-user `checkUserEligibility()` calls that internally called `getEligibleUsers()` repeatedly

---

## Why The Fix Works Now

### Our Latest Fix (Today):

1. **Created `batchCheckEligibility()` method:**
   - Single source of truth for eligibility logic
   - Eliminates code duplication
   - Always includes cross-order check

2. **Coordinator now uses batch method:**
   ```php
   // NEW CODE - Clean, fast, correct:
   $eligibleIdsAll = $resumeService->batchCheckEligibility($order, $candidates);
   ```

3. **Removed per-user checks:**
   - No more expensive `checkUserEligibility()` loops
   - Trust the batch result (already authoritative)

---

## The Exact Logic That Prevents Duplicates

```php
// In batchCheckEligibility():
->whereNotIn('users.id', function ($sub) use ($order, $targetHash) {
    $sub->select('a1.user_id')
        ->from('actions as a1')
        ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
        ->whereIn('a1.status', ['done', 'external'])
        ->where(function ($q) use ($targetHash) {
            // Match by hash OR normalized URL
            $q->where('o1.target_url_hash', $targetHash)
              ->orWhereRaw("o1.target_url_hash IS NULL AND SHA1(TRIM(TRAILING '/' FROM o1.target_url)) = ?", [$targetHash]);
        })
        ->where('o1.id', '!=', $order->id); // ← CRITICAL: Check OTHER orders
})
```

**What this does:**
1. Normalizes URLs (ignore trailing slash)
2. Computes SHA1 hash of normalized URL
3. Joins `actions` → `orders` tables
4. Finds users who have `done`/`external` status
5. On **ANY order** (`o1.id != $order->id`) 
6. Where the order's target URL hash matches (`target_url_hash = ? OR SHA1(...) = ?`)
7. Excludes those users from eligibility

---

## Database Insight

Looking at the actions table structure:
```sql
CREATE TABLE actions (
    id bigint,
    order_id bigint,    -- FK to orders
    user_id bigint,     -- FK to users
    type enum('follow', 'like'),
    status enum('pending', 'done', 'external'),
    performed_at timestamp,
    UNIQUE KEY (user_id, order_id)  -- One action per user per order
)
```

**The Problem:**
- Unique constraint is `(user_id, order_id)` 
- Same user CAN have multiple actions for SAME link if it's in different orders
- Without cross-order check, system allows:
  ```
  actions table:
  user_id | order_id | status | target_url (from orders join)
  --------|----------|--------|-----------------------------
  123     | 9205     | done   | instagram.com/reel/DRfwMY...
  123     | 9251     | done   | instagram.com/reel/DRfwMY...  ← Duplicate!
  ```

**After Fix:**
- Cross-order check prevents user 123 from getting announcement for order 9251
- Only one action per user per unique target URL (across all orders)

---

## Summary

**Why eligibility check existed but still had duplicates:**

1. ✅ Eligibility check **DID exist** 
2. ❌ But it only checked actions on **the current order**
3. ❌ Did NOT check if user completed **other orders with same link**
4. ❌ Coordinator had duplicated logic that wasn't always in sync with ResumeOrderService
5. ❌ Per-user checks were slow and called getEligibleUsers repeatedly

**Why it's fixed now:**

1. ✅ Single `batchCheckEligibility()` method (no duplication)
2. ✅ Always checks actions across **all orders** with same target URL
3. ✅ Uses `target_url_hash` for efficient cross-order matching
4. ✅ Fallback to SHA1 comparison for legacy orders
5. ✅ Fast (one query per order instead of N queries per user)

**The key insight:**
> The database allows multiple actions for the same user if they're in different orders (unique constraint is on user_id + order_id). The application logic must prevent this by checking if the user already completed the SAME LINK in ANY order, not just the current one.

---

## What You'll See After Deployment

**Before (as shown in screenshots):**
```
Order #9205: instagram.com/reel/DRfwMY...
✅ Moha222 completes it

Order #9251: SAME instagram.com/reel/DRfwMY...
❌ Moha222 receives announcement again
❌ Moha222 responds "done" → duplicate action
```

**After:**
```
Order #9205: instagram.com/reel/DRfwMY...
✅ Moha222 completes it

Order #9251: SAME instagram.com/reel/DRfwMY...
✅ Moha222 is EXCLUDED from eligibility (already did this link)
✅ Only users who haven't done this link receive announcement
✅ No duplicate actions created
```
