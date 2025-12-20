# FSBHOA Access Control: Permission Compiler (v2.0)

**Filename:** `includes/class-fsbhoa-permission-compiler.php`  
**Class Name:** `Fsbhoa_Permission_Compiler`  
**Version:** 2.0  
**Date:** December 20, 2025

---

## 1. Objective
To convert high-level Access Groups and Schedules into optimized, stable machine code (Time Profiles) for UHPPOTE controllers. The primary goal is to **eliminate downtime** during schedule updates by ensuring that Profile IDs remain stable, allowing for "In-Place" updates rather than "Wipe & Replace" operations.

---

## 2. Core Architecture

### 2.1 The "Snowflake" Strategy (Source-Based Addressing)
Instead of assigning Profile IDs based on *Content* (e.g., "9am-5pm" = ID 10), this engine assigns IDs based on **Source** (User Group Combination + Gate).
* **Definition:** A "Snowflake" is any unique combination of Access Groups assigned to a cardholder (e.g., "Residents + Tennis").
* **Stability:** A Snowflake is assigned a **Stable Profile ID** per gate. This ID persists in the database even if the schedule definition changes.
* **Efficiency:** We only generate profiles for group combinations that are actually active.

### 2.2 "Meet-in-the-Middle" Memory Layout
To maximize the 254 available profile slots per controller, we use a bi-directional allocation strategy:
* **Heads (Stable IDs):** Allocated from the **bottom UP** (starting at ID 2). These represent the entry point for a Snowflake.
* **Tails (Dynamic/Linked):** Allocated from the **top DOWN** (starting at ID 254). These represent the linked-list segments for complex schedules.
* **Collision Detection:** If the Head cursor meets the Tail cursor, the system triggers an automatic "Defragmentation" (Full Rebuild).

---

## 3. The Rules Engine

For every Snowflake (Group Combo) and every Gate, access rules are calculated in this strict order:

### 3.1 Specificity Override (Rule of Supremacy)
Rules defined in the database have a hierarchy. A more specific rule **completely replaces** a less specific one for a given door. They do *not* merge.
1.  **Door Specific (Level 3):** Rules targeting a specific Door ID. (Highest Priority).
2.  **Controller Specific (Level 2):** Rules targeting a specific Controller.
3.  **Global (Level 1):** Rules targeting "All Controllers/Doors".

### 3.2 Union of Groups (The Merge)
If a user belongs to multiple groups (e.g., Residents + Staff), the engine collects the *winning rules* from **all** groups for that door. This creates a union of access rights.

### 3.3 Segment Consolidation
Once active time segments are collected, overlapping or contiguous segments are merged into the simplest possible form to save memory.
* *Input:* `08:00-12:00` and `11:00-15:00`
* *Output:* `08:00-15:00`

---

## 4. Execution Workflow

### 4.1 Discovery Phase
The engine scans all active cardholders to discover which Group Combinations (Signatures) are currently in use.

### 4.2 Allocation Phase
For each active Signature and Gate:
1.  **Check Persistence:** Do we already have a Profile ID assigned for this combo?
2.  **Reuse or Allocate:**
    * If yes, reuse the ID (Stability).
    * If no, allocate the next free ID from the **Base (2)** upwards.
3.  **Generate Chain:** If the schedule requires multiple profiles (linked list), allocate subsequent IDs from the **Top (254)** downwards.

### 4.3 Output Generation
The engine produces two key artifacts:
1.  **Controller Profiles:** `[DeviceID][ProfileID] => ContentSignature`
    * Used by the Sync Service to send `set-time-profile` commands.
2.  **Card Permissions:** `[CardNumber][DeviceID] => PermissionString`
    * Format: `1:14,2:15,3:16,4:17` (Door:ProfileID)
    * Used by the Sync Service to determine if a `put-card` is needed.

---

## 5. Execution Modes

### 5.1 Delta Sync (Daytime Updates)
* **Trigger:** Schedule change, Group change, or User change.
* **Behavior:** Loads the Persistent Map. Reuses existing IDs. Updates the *content* of those IDs on the controller.
* **Benefit:** Zero downtime. Cards do not need to be rewritten unless the user moved groups.

### 5.2 Nightly Sync (Midnight Maintenance)
* **Trigger:** Cron Job.
* **Behavior:** Discards the Persistent Map (Garbage Collection). Re-allocates IDs from scratch based only on currently active users.
* **Benefit:** Defragments memory and removes unused "Ghost" profiles.

---

## 6. Edge Case Handling

### 6.1 "Access All" Optimization ('Y')
If *any* group in a user's Snowflake has the "Access All" flag (e.g., Staff, Board):
* **Action:** The user is NOT assigned a Profile ID.
* **Result:** Permission string becomes `1:Y,2:Y,3:Y,4:Y`.
* **Benefit:** Saves memory slots.

### 6.2 Memory Collision
If the "Head" cursor hits the "Tail" cursor:
1.  **Soft Error:** The engine catches the exception.
2.  **Auto-Defrag:** It forces a recursive Full Rebuild (ignoring persistence) to try and pack the memory tighter.
3.  **Hard Error:** If it still fails, it logs a Critical Error and aborts to prevent corruption.




------------------------------------------
# V2.0 Sync Engine Architecture & Logic (how it works)

## 1. Core Philosophy: "Zero Downtime"
The V1 engine wiped the controller memory and rewrote every card for every update, causing 5-10 minutes of downtime. The V2 engine is designed to be **non-destructive** and **surgical**. It only updates the specific bytes on the controller that have changed.

## 2. The "Smart Skip" System (Delta Sync)
The engine determines *what* to update by maintaining a checksum (Hash) of every card's permission state.

1.  **The Calculation:**
    Before communicating with the hardware, the system compiles a **Target Permission String** for every user based on their groups, schedule, and active status.
    * *Format:* `Door1:ProfileID, Door2:ProfileID, ...` (e.g., `1:14, 2:15`)
    * *Optimization:* Empty doors (no access) are omitted from the string to prevent hardware expansion (adding a new door) from triggering mass updates.

2.  **The Comparison:**
    The system compares this **Target String** against the **Last Known Hash** stored in the database (`active_card_hash`).
    * **Match:** The user is skipped. (Updates Sent: 0).
    * **Mismatch:** The user is added to the update queue.
    * **Critical Feature:** This allows the system to run every 5 minutes with zero performance cost if no data has changed.

## 3. Dynamic Profile Management ("Snowflakes")
The UHPPOTE controller has limited memory slots (Profiles 2-254) for time schedules. V2.0 uses a **Combinatorial Allocation Strategy** to maximize this space.

### The Problem
A user might belong to "Residents" (Schedule A) and "Landscapers" (Schedule B). The controller cannot natively understand "Union of A + B".

### The Solution
The Compiler creates a new, temporary Time Profile on the fly that represents the mathematical union of Schedule A and Schedule B.

### Memory Allocation Strategy: "Heads & Tails"
To prevent fragmentation, Profile IDs are allocated from two ends of the memory map:

* **Stable Profiles (Heads -> Grow Up):**
    * Assigned to common groups (e.g., "Residents", "Staff").
    * IDs start at **2** and increment upwards (2, 3, 4...).
    * These are "Persistent" maps—they try to keep the same ID forever to minimize card updates.

* **Dynamic Profiles (Tails -> Grow Down):**
    * Assigned to "Snowflakes" (Unique individual combinations).
    * IDs start at **254** and decrement downwards (254, 253, 252...).
    * These are volatile; if the user changes groups, this ID is freed for reuse.

**Collision Safety:** If the Heads meet the Tails (Memory Exhaustion), the system triggers a "Defrag" (Full Rebuild) to repack the profiles tightly.

## 4. Hierarchy of Authority (Specificity)
When calculating permissions, rules are applied in a specific order. More specific rules always override general ones:

1.  **Door Specific:** (Highest Priority) "User has access to Door 3".
2.  **Controller Specific:** "User has access to the Pool Controller".
3.  **Global:** (Lowest Priority) "User has access to ALL doors".

## 5. Handling Corner Cases

### A. The "Access All" Optimization
Users with "Access All" privileges (e.g., Board Members, Emergency Staff) do not consume Profile Memory.
* They are assigned the native logic `1:Y, 2:Y...` (Profile 1).
* This bypasses all schedule checks and saves memory slots for complex schedules.

### B. Hardware Expansion (The "Ghost Door" Rule)
When a new door is added to a controller physically:
* The system ignores it in the permission string calculation (`Hash(Door1 + Door2)`) as long as the user has **0/No Access** to it.
* This prevents a "Mass Rewrite" of 700+ cards when installing a new reader that nobody uses yet.

### C. Soft Revocation
When a user is set to `Disabled` in the database:
* The card is **NOT** deleted from the controller (which can be slow/risky).
* Instead, their permissions are updated to an empty string `""`.
* The card exists but opens nothing. This allows for instant reactivation later.

## 6. Sync Modes

| Mode | Trigger | Description |
| :--- | :--- | :--- |
| **Delta Sync** | Button Press / Cron (5m) | Calculates Hashes. Updates only changed cards. usually completes in < 1 second. |
| **High-Impact** | Controller/Schedule Change | Triggered automatically if a "Definition" changes (e.g., changing the hours of "Residents"). Forces a recalculation of all hashes, but only writes to hardware if necessary. |
| **Nightly Rebuild** | Cron (3:00 AM) | A safety net. Wipes the internal "Persistent Map" to clear out unused Snowflake profiles (Garbage Collection) and ensures the controller is perfectly aligned with the DB. |

