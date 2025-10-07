
## 1. What is the $all_cardholders_permissions object?
This is a large associative array that acts as a pre-calculated cache for the entire sync process. It is created once at the beginning of the sync.

Key: The cardholder_id.

Value: The result of running the main fsbhoa_calculate_cardholder_permissions() function for that specific cardholder.

The value for each cardholder is one of two things:

A simple flag if they have unrestricted access: ['all_access' => true].

Or, if they have time-based rules, it's a list of final permission objects, one for each door they can access. Each of those objects has a schedules property that lists the final, merged time windows for each day of the week.

Example Data Structure:

PHP

$all_cardholders_permissions = [
    101 => ['all_access' => true],  // Cardholder 101 has the 'all access' flag.
    
    102 => [                        // Cardholder 102 has specific time-based rules.
        (object) [
            'door_id' => 9,
            'schedules' => [
                'mon' => ['08:00-17:00'],
                'tue' => ['08:00-17:00']
            ]
        ],
        (object) [
            'door_id' => 10,
            'schedules' => [
                'sun' => ['10:00-14:00', '18:00-20:00'] // A schedule with two segments
            ]
        ]
    ]
];


##2 What is a signature
The signature is a unique text string that represents a complete schedule. It's the key to making the sync process efficient by preventing duplicate time profiles.

It combines two key pieces of information: which days the schedule applies to, and what times are active on those days.

## The Two Parts of a Signature
A signature is built from two parts, separated by a pipe (|) character.

1. The Days
The first part is a list of all the days of the week that share the exact same time schedule. The three-letter day abbreviations are sorted and joined together with a colon (:).

Example: mon:tue:wed:thu:fri

2. The Times
The second part lists the time segments for that schedule. Each segment is in HH:mm-HH:mm format, and if there are multiple segments, they are joined with a comma (,).

Example 1 (single segment): 09:00-17:00

Example 2 (multiple segments): 08:00-12:00,13:00-17:00

## Putting It Together
By combining these, we get a single string that uniquely identifies a complete schedule.

Example 1: A standard weekday schedule
A permission rule that is active Monday to Friday from 9 AM to 5 PM would generate this signature:

mon:tue:wed:thu:fri|09:00-17:00
Example 2: A complex weekend schedule
A rule for Saturday with two time slots would generate this signature:

sat|08:00-12:00,18:00-20:00
By creating this unique string, the sync process can instantly see if it has already created a Time Profile for that exact schedule, allowing it to efficiently re-use profiles instead of creating duplicates.


##3 The Pre-Process (Before the Cardholder Loop)
Before the sync starts its main cardholder-by-cardholder calculation, it first calls the fsbhoa_get_all_permission_data() function. This function acts as the preprocess step and builds three important data structures from the database:

groups: A list of all your enabled Access Groups, keyed by their ID for fast lookups.

permissions_by_group: An organized list where the key is a group_id and the value is a list of all permission rules belonging to that group.

groups_by_cardholder: Another organized list where the key is a cardholder_id and the value is a list of all the group_ids that cardholder is a member of.

## The Full Sequence
Here is the complete order of operations for the sync:

Pre-Process: The fsbhoa_get_all_permission_data() function runs once to create the three helper arrays listed above.

Pre-Calculation: The sync then loops through every cardholder. For each one, it uses those helper arrays to run the main fsbhoa_calculate_cardholder_permissions() engine and stores the final, complex result in the big $all_cardholders_permissions cache.

Execution: The rest of the sync then uses this powerful cache to efficiently build the time profiles and send the correct commands to the controllers.



## 4. What does fsbhoa_build_unique_schedules_for_sync() do?
The purpose of this function is to look at that huge cache of permissions for all users and distill it down into the smallest possible list of unique Time Profiles that we need to create on the controllers.

It works like this:

It iterates through every cardholder's calculated permissions.

For each door and each day, it finds the final schedule (e.g., "Monday, 8am-5pm").

It creates a unique text "signature" for that schedule (e.g., 'mon:tue:wed:thu:fri|08:00-17:00').

It uses this signature as a key in an array. This automatically de-duplicates the schedules. If it sees ten different people who all need a "Monday-Friday, 8am-5pm" schedule, it only adds that schedule to our list once.

The final result is an array containing every unique schedule needed for the entire sync, with no duplicates.

This "pre-calculation" and "de-duplication" is the optimization you suggested. It does all the heavy thinking up front so that the rest of the sync process can be fast and simple.

