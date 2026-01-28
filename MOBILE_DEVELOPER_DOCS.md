# Comment Order Feature - API Changes

## Overview
New order type **`comment`** added. Each user receives a specific pre-assigned comment to post.



## MQTT Payload

### Follow/Like Orders (No Change)
```json
{
  "order_id": 123,
  "type": "follow",
  "url": "https://instagram.com/user/username",
  "mediaId": "123456789",
  "userPk": "987654321"
}
```

### Comment Orders (NEW)
```json
{
  "order_id": 456,
  "type": "comment",
  "url": "https://instagram.com/p/ABC123",
  "mediaId": "123456789",
  "comment": "Nice photo!"
}
```

**New Field:** `comment` - The specific comment text assigned to this user

---

## Key Points

### Assignment Logic
- Each user gets **one specific comment** from the pool
- Assignment is **persistent** (same user always gets the same comment)
- Distribution uses **round-robin**

### Order Completion
Same as existing orders:
```
POST /api/actions/{orderId}/complete
```

### Security
- Only **admins** can create comment orders
- Regular users cannot create this order type

---

## Backward Compatibility
✅ Existing `follow` and `like` orders unchanged
