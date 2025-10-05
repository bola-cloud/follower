# API Endpoints Documentation

Complete guide for using the Order Management API endpoints programmatically.

---

## Table of Contents

1. [Authentication](#authentication)
2. [Create Order (POST /api/orders)](#create-order)
3. [Resume Order (POST /api/orders/{orderId}/complete)](#resume-order)
4. [List Orders (GET /api/orders)](#list-orders)
5. [Rate Limiting](#rate-limiting)
6. [Error Handling](#error-handling)
7. [Architecture Flow](#architecture-flow)
8. [Testing Examples](#testing-examples)

---

## Authentication

All API endpoints require **Laravel Sanctum** authentication.

### Getting an API Token

1. **Login via API** to get a Sanctum token:
   ```bash
   curl -X POST https://your-domain.com/api/login \
     -H "Content-Type: application/json" \
     -d '{
       "email": "user@example.com",
       "password": "your-password"
     }'
   ```

2. **Response**:
   ```json
   {
     "token": "1|abc123xyz...",
     "user": {
       "id": 123,
       "name": "John Doe",
       "email": "user@example.com",
       "type": "user"
     }
   }
   ```

3. **Use Token** in subsequent requests:
   ```bash
   curl -X POST https://your-domain.com/api/orders \
     -H "Authorization: Bearer 1|abc123xyz..." \
     -H "Content-Type: application/json" \
     -d '{ ... }'
   ```

### Admin Access

Users with `type: 'admin'` have additional privileges:
- Create orders with **zero cost** (no points deducted)
- Resume **any user's orders** (not just their own)
- Bypass point balance checks

---

## Create Order

**Endpoint**: `POST /api/orders`

Creates a new order and triggers batch processing via MQTT ping system.

### Request

**Headers**:
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Body**:
```json
{
  "type": "follow",
  "total_count": 100,
  "target_url": "https://instagram.com/p/ABC123",
  "cost": 100
}
```

**Parameters**:

| Field        | Type    | Required | Description                                           |
|--------------|---------|----------|-------------------------------------------------------|
| type         | string  | Yes      | Order type: `follow` or `like`                        |
| total_count  | integer | Yes      | Number of actions needed (min: 1)                     |
| target_url   | string  | Yes      | Target Instagram URL or ID                            |
| cost         | integer | No       | Custom cost (defaults to `total_count * points_per_action`) |

### Response

**Success (200 OK)**:
```json
{
  "message": "Order created and Mqtt sent.",
  "order": {
    "id": 456,
    "type": "follow",
    "total_count": 100,
    "done_count": 0,
    "cost": 100,
    "status": "active",
    "target_url": "https://instagram.com/p/ABC123",
    "target_url_hash": "sha1hash...",
    "user_id": 123,
    "created_at": "2025-01-15T10:30:00.000000Z",
    "updated_at": "2025-01-15T10:30:00.000000Z"
  }
}
```

**Error (422 Unprocessable Entity)**:
```json
{
  "errors": {
    "type": ["The type field is required."],
    "total_count": ["The total count must be at least 1."]
  }
}
```

**Error (403 Forbidden)**:
```json
{
  "error": "Insufficient points."
}
```

### Processing Flow

After order creation, the system automatically:

1. **Sends Ping** (`order/ping/req` topic):
   ```json
   {
     "type": "create",
     "order_id": 456,
     "activation": true
   }
   ```

2. **Devices Respond** (`order/ping/res/{order_id}/{user_id}` topic):
   - Online devices send status to MQTT broker

3. **Batch Processing**:
   - Node handler accumulates responses (500ms window)
   - Sends batch to `/api/mqtt/trigger-order-batch`
   - Laravel dispatches `ProcessPingResponseBatchJob`

4. **Action Creation & Publishing**:
   - Job filters eligible users (no duplicate actions)
   - Creates pending actions in chunks of 80
   - Publishes orders to `orders/{user_id}` topic
   - 50ms delay between chunks for rate limiting

5. **Device Execution**:
   - Devices receive orders and perform actions
   - Send completion responses to `order/res/{order_id}/{user_id}`

6. **Completion Updates**:
   - Completion responses batched (500ms window)
   - Processed via `ProcessOrderResponseBatchJob`
   - Updates actions to `done` status
   - Increments `done_count` on orders
   - Marks orders completed when `done_count >= total_count`

### Example: Create Order

```bash
curl -X POST https://your-domain.com/api/orders \
  -H "Authorization: Bearer 1|abc123xyz..." \
  -H "Content-Type: application/json" \
  -d '{
    "type": "follow",
    "total_count": 500,
    "target_url": "https://instagram.com/p/XYZ789"
  }'
```

**Response**:
```json
{
  "message": "Order created and Mqtt sent.",
  "order": {
    "id": 789,
    "type": "follow",
    "total_count": 500,
    "done_count": 0,
    "cost": 500,
    "status": "active",
    "target_url": "https://instagram.com/p/XYZ789",
    "user_id": 123,
    "created_at": "2025-01-15T12:00:00Z"
  }
}
```

---

## Resume Order

**Endpoint**: `POST /api/orders/{orderId}/complete`

Resumes a paused or incomplete order by sending a ping to re-activate it.

### Request

**Headers**:
```
Authorization: Bearer {token}
Content-Type: application/json
```

**URL Parameters**:
- `{orderId}`: Order ID to resume (integer)

**Body**: Empty (no body required)

### Response

**Success (200 OK)**:
```json
{
  "message": "Resume ping sent successfully."
}
```

**Error (401 Unauthorized)**:
```json
{
  "error": "Unauthorized or invalid order."
}
```

**Error (403 Forbidden)**:
```json
{
  "error": "This order has been canceled and cannot be resumed."
}
```

**Error (409 Conflict)**:
```json
{
  "error": "Cannot complete an already completed order."
}
```

### Processing Flow

After calling this endpoint:

1. **Cleanup** stale pending actions (older than 24 hours)
2. **Send Ping** (`order/ping/req` topic):
   ```json
   {
     "type": "resume",
     "order_id": 789,
     "activation": true
   }
   ```
3. **Batch Processing** (same as create order flow)
4. Devices respond and process remaining actions

### Example: Resume Order

```bash
curl -X POST https://your-domain.com/api/orders/789/complete \
  -H "Authorization: Bearer 1|abc123xyz..." \
  -H "Content-Type: application/json"
```

**Response**:
```json
{
  "message": "Resume ping sent successfully."
}
```

---

## List Orders

**Endpoint**: `GET /api/orders`

Retrieves all orders for the authenticated user (or all orders for admins).

### Request

**Headers**:
```
Authorization: Bearer {token}
```

**Query Parameters**: None

### Response

**Success (200 OK)**:
```json
{
  "orders": [
    {
      "id": 789,
      "type": "follow",
      "total_count": 500,
      "done_count": 245,
      "cost": 500,
      "status": "active",
      "target_url": "https://instagram.com/p/XYZ789",
      "target_url_hash": "sha1...",
      "user_id": 123,
      "created_at": "2025-01-15T12:00:00Z",
      "updated_at": "2025-01-15T12:30:00Z"
    },
    {
      "id": 456,
      "type": "like",
      "total_count": 100,
      "done_count": 100,
      "cost": 100,
      "status": "completed",
      "target_url": "https://instagram.com/p/ABC123",
      "user_id": 123,
      "created_at": "2025-01-14T10:00:00Z",
      "updated_at": "2025-01-14T10:15:00Z"
    }
  ]
}
```

### Example: List Orders

```bash
curl -X GET https://your-domain.com/api/orders \
  -H "Authorization: Bearer 1|abc123xyz..."
```

---

## Rate Limiting

API endpoints use Laravel's built-in rate limiting middleware.

### Default Limits

- **Authenticated Users**: 60 requests per minute per user
- **Guest Users**: 10 requests per minute per IP

### Rate Limit Headers

Response includes rate limit information:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 57
X-RateLimit-Reset: 1642254600
```

### Exceeding Limits

**Response (429 Too Many Requests)**:
```json
{
  "message": "Too Many Attempts."
}
```

Wait until `X-RateLimit-Reset` timestamp before retrying.

### Custom Rate Limits

For high-volume integrations, contact admin to adjust rate limits.

---

## Error Handling

All API errors follow consistent JSON format:

### Validation Errors (422)

```json
{
  "errors": {
    "field_name": [
      "Error message 1",
      "Error message 2"
    ]
  }
}
```

### Client Errors (4xx)

```json
{
  "error": "Human-readable error message"
}
```

### Server Errors (5xx)

```json
{
  "error": "Failed to create order.",
  "details": "Exception message (only in development)"
}
```

### Common Error Codes

| Code | Status             | Meaning                                      |
|------|--------------------|----------------------------------------------|
| 400  | Bad Request        | Invalid request format                       |
| 401  | Unauthorized       | Missing or invalid authentication token      |
| 403  | Forbidden          | Insufficient points or permissions           |
| 404  | Not Found          | Resource doesn't exist                       |
| 409  | Conflict           | Order already completed or duplicate         |
| 422  | Unprocessable      | Validation failed                            |
| 429  | Too Many Requests  | Rate limit exceeded                          |
| 500  | Internal Error     | Server-side error                            |

### Error Handling Best Practices

```javascript
try {
  const response = await fetch('https://your-domain.com/api/orders', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(orderData)
  });

  if (!response.ok) {
    const error = await response.json();
    
    switch (response.status) {
      case 422:
        console.error('Validation errors:', error.errors);
        break;
      case 403:
        console.error('Insufficient points:', error.error);
        break;
      case 429:
        console.error('Rate limited, retry after:', response.headers.get('X-RateLimit-Reset'));
        break;
      default:
        console.error('Error:', error.error);
    }
    return;
  }

  const result = await response.json();
  console.log('Order created:', result.order);

} catch (err) {
  console.error('Network error:', err.message);
}
```

---

## Architecture Flow

### Order Creation Flow

```
┌──────────────┐
│ API Request  │
│ POST /orders │
└──────┬───────┘
       │
       ▼
┌──────────────────┐
│ OrderController  │
│ - Validate       │
│ - Deduct Points  │
│ - Create Order   │
│ - Send Ping      │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ PingService      │
│ Publish:         │
│ order/ping/req   │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ MQTT Broker      │
│ Broadcast Ping   │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Devices          │
│ Respond:         │
│ order/ping/res/  │
│ {order_id}/      │
│ {user_id}        │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ mqtt_handler.cjs │
│ Batch Responses  │
│ (500ms window)   │
└──────┬───────────┘
       │
       ▼
┌──────────────────────┐
│ POST /api/mqtt/      │
│ trigger-order-batch  │
└──────┬───────────────┘
       │
       ▼
┌────────────────────────┐
│ MqttResponseController │
│ - Group by order_id    │
│ - Dispatch Jobs        │
└──────┬─────────────────┘
       │
       ▼
┌──────────────────────────┐
│ ProcessPingResponseBatch │
│ Job                      │
│ - Filter Eligible Users  │
│ - Create Actions (x80)   │
│ - Publish orders/{id}    │
│ - 50ms delay per chunk   │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────┐
│ MQTT Broker      │
│ orders/{user_id} │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Devices          │
│ Perform Actions  │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Devices          │
│ Send Completion: │
│ order/res/       │
│ {order_id}/      │
│ {user_id}        │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ mqtt_handler.cjs │
│ Batch Responses  │
│ (500ms window)   │
└──────┬───────────┘
       │
       ▼
┌─────────────────────┐
│ POST /api/mqtt/     │
│ response-batch      │
└──────┬──────────────┘
       │
       ▼
┌────────────────────────────┐
│ ProcessOrderResponseBatch  │
│ Job                        │
│ - Update Actions (x80)     │
│ - Increment done_count     │
│ - Mark completed           │
└────────────────────────────┘
```

### Batch Processing Benefits

- **98% reduction** in HTTP calls (1000 → 20)
- **98.5% reduction** in DB queries (1000 → 12-15)
- **90% faster** processing (15-45s → 1-3s)
- **50% reduction** in MQTT traffic (no duplicates)
- **Zero missing orders** (reliable batch tracking)

---

## Testing Examples

### 1. Create Order Test

```bash
#!/bin/bash
# test_create_order.sh

TOKEN="1|abc123xyz..."
API_URL="https://your-domain.com/api"

# Create order
response=$(curl -s -X POST "${API_URL}/orders" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "follow",
    "total_count": 10,
    "target_url": "https://instagram.com/p/TEST123"
  }')

echo "Response: ${response}"

# Extract order ID
order_id=$(echo "${response}" | jq -r '.order.id')
echo "Created order ID: ${order_id}"
```

### 2. Resume Order Test

```bash
#!/bin/bash
# test_resume_order.sh

TOKEN="1|abc123xyz..."
API_URL="https://your-domain.com/api"
ORDER_ID=789

# Resume order
response=$(curl -s -X POST "${API_URL}/orders/${ORDER_ID}/complete" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json")

echo "Response: ${response}"
```

### 3. List Orders Test

```bash
#!/bin/bash
# test_list_orders.sh

TOKEN="1|abc123xyz..."
API_URL="https://your-domain.com/api"

# List orders
response=$(curl -s -X GET "${API_URL}/orders" \
  -H "Authorization: Bearer ${TOKEN}")

echo "Response: ${response}" | jq '.'
```

### 4. Node.js Integration Example

```javascript
const axios = require('axios');

const API_URL = 'https://your-domain.com/api';
const TOKEN = '1|abc123xyz...';

async function createOrder(type, totalCount, targetUrl) {
  try {
    const response = await axios.post(
      `${API_URL}/orders`,
      {
        type,
        total_count: totalCount,
        target_url: targetUrl
      },
      {
        headers: {
          'Authorization': `Bearer ${TOKEN}`,
          'Content-Type': 'application/json'
        }
      }
    );

    console.log('Order created:', response.data.order);
    return response.data.order;

  } catch (error) {
    if (error.response) {
      console.error('Error:', error.response.status, error.response.data);
    } else {
      console.error('Network error:', error.message);
    }
    throw error;
  }
}

async function resumeOrder(orderId) {
  try {
    const response = await axios.post(
      `${API_URL}/orders/${orderId}/complete`,
      {},
      {
        headers: {
          'Authorization': `Bearer ${TOKEN}`,
          'Content-Type': 'application/json'
        }
      }
    );

    console.log('Order resumed:', response.data.message);
    return response.data;

  } catch (error) {
    if (error.response) {
      console.error('Error:', error.response.status, error.response.data);
    } else {
      console.error('Network error:', error.message);
    }
    throw error;
  }
}

async function listOrders() {
  try {
    const response = await axios.get(
      `${API_URL}/orders`,
      {
        headers: {
          'Authorization': `Bearer ${TOKEN}`
        }
      }
    );

    console.log('Orders:', response.data.orders);
    return response.data.orders;

  } catch (error) {
    if (error.response) {
      console.error('Error:', error.response.status, error.response.data);
    } else {
      console.error('Network error:', error.message);
    }
    throw error;
  }
}

// Usage
(async () => {
  // Create order
  const order = await createOrder('follow', 100, 'https://instagram.com/p/ABC123');
  
  // Wait a bit
  await new Promise(resolve => setTimeout(resolve, 5000));
  
  // Resume order if needed
  await resumeOrder(order.id);
  
  // List all orders
  await listOrders();
})();
```

### 5. Python Integration Example

```python
import requests
import time

API_URL = 'https://your-domain.com/api'
TOKEN = '1|abc123xyz...'

def create_order(type, total_count, target_url):
    """Create a new order"""
    try:
        response = requests.post(
            f'{API_URL}/orders',
            json={
                'type': type,
                'total_count': total_count,
                'target_url': target_url
            },
            headers={
                'Authorization': f'Bearer {TOKEN}',
                'Content-Type': 'application/json'
            }
        )
        response.raise_for_status()
        
        data = response.json()
        print(f"Order created: {data['order']}")
        return data['order']
        
    except requests.exceptions.HTTPError as e:
        print(f"Error: {e.response.status_code} - {e.response.json()}")
        raise
    except requests.exceptions.RequestException as e:
        print(f"Network error: {e}")
        raise

def resume_order(order_id):
    """Resume an existing order"""
    try:
        response = requests.post(
            f'{API_URL}/orders/{order_id}/complete',
            headers={
                'Authorization': f'Bearer {TOKEN}',
                'Content-Type': 'application/json'
            }
        )
        response.raise_for_status()
        
        data = response.json()
        print(f"Order resumed: {data['message']}")
        return data
        
    except requests.exceptions.HTTPError as e:
        print(f"Error: {e.response.status_code} - {e.response.json()}")
        raise
    except requests.exceptions.RequestException as e:
        print(f"Network error: {e}")
        raise

def list_orders():
    """List all orders for authenticated user"""
    try:
        response = requests.get(
            f'{API_URL}/orders',
            headers={
                'Authorization': f'Bearer {TOKEN}'
            }
        )
        response.raise_for_status()
        
        data = response.json()
        print(f"Orders: {data['orders']}")
        return data['orders']
        
    except requests.exceptions.HTTPError as e:
        print(f"Error: {e.response.status_code} - {e.response.json()}")
        raise
    except requests.exceptions.RequestException as e:
        print(f"Network error: {e}")
        raise

# Usage
if __name__ == '__main__':
    # Create order
    order = create_order('follow', 100, 'https://instagram.com/p/ABC123')
    
    # Wait a bit
    time.sleep(5)
    
    # Resume order if needed
    resume_order(order['id'])
    
    # List all orders
    list_orders()
```

---

## Monitoring

### Check Order Progress

```bash
# Get specific order details
curl -X GET "https://your-domain.com/api/orders" \
  -H "Authorization: Bearer ${TOKEN}" \
  | jq '.orders[] | select(.id == 789)'
```

**Response**:
```json
{
  "id": 789,
  "done_count": 245,
  "total_count": 500,
  "status": "active"
}
```

Progress: `245 / 500 = 49%`

### Redis Metrics

Monitor batch processing performance:

```bash
# Ping response batching metrics
redis-cli HGETALL "ping_batch_metrics:2025-01-15T12:30"

# Order response batching metrics
redis-cli HGETALL "order_res_batch_metrics:2025-01-15T12:30"
```

**Output**:
```
1) "total_users"
2) "1000"
3) "eligible"
4) "850"
5) "processed"
6) "850"
7) "published"
8) "850"
9) "batches"
10) "1"
11) "total_duration_ms"
12) "1245.67"
```

---

## Summary

### Quick Reference

| Endpoint                              | Method | Purpose            | Auth     |
|---------------------------------------|--------|--------------------|----------|
| `/api/orders`                         | POST   | Create order       | Required |
| `/api/orders/{orderId}/complete`      | POST   | Resume order       | Required |
| `/api/orders`                         | GET    | List orders        | Required |

### Key Features

✅ **Batch Processing**: 98% reduction in HTTP/DB load  
✅ **Rate Limiting**: 60 requests/min per user  
✅ **Admin Support**: Zero-cost orders, access any order  
✅ **Error Handling**: Consistent JSON error format  
✅ **Monitoring**: Redis metrics per minute  
✅ **Reliable**: Single publishing source (no duplicates)

### Next Steps

1. **Get Authentication Token**: Login via `/api/login`
2. **Create First Order**: POST to `/api/orders`
3. **Monitor Progress**: GET `/api/orders` to check `done_count`
4. **Resume if Needed**: POST to `/api/orders/{id}/complete`

For questions or issues, refer to:
- `ORDER_RESPONSE_BATCHING.md` - Batch processing architecture
- `BATCHING_SOLUTION_SUMMARY.md` - High-volume batching overview
- `DEDUPLICATION_COMPLETE.md` - Duplication fix deployment

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-15
