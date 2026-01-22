# Feature Specification: Comment Order Type

## Overview
This document outlines the technical changes required to support a new **Comment Order** type. This feature allows users to create orders where specific comments are assigned to targeted users for posting.

## Goals
1.  **Specific Comments**: Support orders that contain a list of pre-defined comments.
2.  **Assignment Tracking**: Ensure every user who performs an action is assigned a specific comment from the list.
3.  **Persistence**: Store the assigned comment so it can be retrieved later (e.g., for reporting or verification).
4.  **Resume Reliability**: Ensure that if an order is paused and resumed, the user receives the *same* comment they were originally assigned, preventing duplicates or confusion.

---

## Technical Implementation

### 1. Database Schema Changes

We need to store unstructured data (comment lists and assigned comments) without creating complex new relationship tables. We will add a JSON `data` column to both `orders` and `actions`.

#### `orders` Table
-   **New Column**: `data` (JSON, nullable)
-   **Usage**: Stores the pool of comments available for assignment.
    ```json
    {
      "comments": ["Nice photo!", "Great shot", "Love this"]
    }
    ```
-   **Structure Update**: `type` column will be updated to support the string value `'comment'` (if it's currently an enum, it will be modified).

#### `actions` Table
-   **New Column**: `data` (JSON, nullable)
-   **Usage**: Stores the specific comment assigned to this user.
    ```json
    {
      "comment": "Nice photo!"
    }
    ```

### 2. API Updates (`OrderController`)

#### Order Creation (`store` method)
-   **Validation**: Add rule to accept `type: 'comment'`.
-   **Requirement**: If `type` is 'comment', a `comments` array field is required in the request.
-   **Storage**: The `comments` array will be saved into the `orders.data` column.

### 3. Business Logic (Assignment & Persistence)

#### `BatchActionService`
This service handles the creation of pending actions (reservations) for users.

-   **Logic**:
    1.  Check if `order.type === 'comment'`.
    2.  If yes, retrieve the `comments` list from `order.data`.
    3.  **Assignment Strategy**: Select a comment for the user (e.g., Random or Round-Robin).
    4.  **Persistence**: Save the selected comment immediately into the new `actions.data` column.
        -   *Example*: `action->data = ['comment' => 'Selected Text']`

### 4. Resumption Logic (`ResumeOrderService`)

-   **Current Behavior**: Finds users with `status = 'pending'` and re-broadcasts the order.
-   **New Behavior**:
    1.  When re-broadcasting, check if the action has existing `data`.
    2.  **Crucial Step**: Use the *existing* comment stored in `action.data` instead of picking a new one.
    3.  This guarantees that a user is always asked to post the exact same comment, even across retries.

### 5. MQTT Payload Updates (`ProcessPingResponseBatchJob`)

The final step is telling the device *what* to comment.

-   **Current Payload**:
    ```json
    { "order_id": 123, "type": "follow", "url": "..." }
    ```
-   **New Payload (for comments)**:
    ```json
    { "order_id": 123, "type": "comment", "url": "...", "comment": "Nice photo!" }
    ```
-   **Implementation**:
    -   The job will fetch the `data` column from the `actions` table for the target users.
    -   It will inject the `comment` field into the MQTT message payload before publishing to `orders/{user_id}`.

---

## Summary of Work
1.  **Migration**: Add `data` JSON columns to `orders` and `actions`.
2.  **API**: Update validation to accept and store comment lists.
3.  **Services**: Implement assignment logic in `BatchActionService` and preservation logic in `ResumeOrderService`.
4.  **Jobs**: Update MQTT publisher to include the assigned comment in the payload.
