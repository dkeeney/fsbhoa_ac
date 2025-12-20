<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Class Fsbhoa_Permission_Compiler
 * * CORE RESPONSIBILITY:
 * Compiles high-level User/Group/Schedule data into low-level Controller Artifacts 
 * (Time Profiles & Card Permissions).
 * * STRATEGY:
 * 1. "Snowflake" Allocation: IDs assigned by User Group Combo (Source), not Schedule (Content).
 * 2. "Meet-in-the-Middle" Memory: Heads (Stable) grow UP from 2. Tails (Dynamic) grow DOWN from 254.
 * 3. "Specificity Wins": Door > Controller > Global rules override each other.
 */
class Fsbhoa_Permission_Compiler {

    // --- CONFIGURATION ---
    const BASE_ID = 2;           // Start Stable IDs here (0 & 1 are reserved)
    const MAX_ID = 254;          // Top of memory
    
    // --- DATA ---
    private $schedule_id;
    private $raw_data = [];      // DB Data
    private $controllers = [];   // [device_id][door_num] => door_record_id
    
    // --- STATE ---
    private $used_signatures = []; // ['1,5' => [1, 5]] (Unique combos found in active cards)
    private $persistent_maps = []; // [device_id][gate_sig] => ProfileID
    private $ids_claimed = [];     // [device_id] => [10, 11, ...] (Used in this run)
    private $dynamic_counters = [];// [device_id] => 254 (Current Tail cursor)
    
    // --- FLAGS ---
    private $is_retry_mode = false; // True if we are retrying after a memory collision

    // --- OUTPUTS ---
    public $controller_profiles = []; // [device_id][profile_id] => ContentSignature
    public $card_permissions = [];    // [rfid] => [device_id] => "1:14, 2:15..."

    /**
     * MAIN ENTRY POINT
     * @param bool $force_rebuild If true, ignores persistent map (Nightly Sync behavior)
     */
    public function generate_sync_data( $force_rebuild = false, $is_dry_run = false ) {
        $this->load_data();
        $this->discover_signatures();     
        
        if ($force_rebuild) {
            $this->persistent_maps = []; // Start fresh (Garbage Collection)
        } else {
            $this->load_persistent_maps();
        }

        // Attempt Allocation
        try {
            $this->allocate_and_generate();   
        } catch (Exception $e) {
            // COLLISION HANDLING: If we ran out of memory using the "Dirty" map,
            // try a Full Rebuild (Defrag) before giving up.
            if (!$force_rebuild && !$this->is_retry_mode) {
                error_log("PERMISSION COMPILER: Memory Collision detected. Attempting Defrag (Full Rebuild)...");
                $this->is_retry_mode = true;
                $this->reset_state();
                return $this->generate_sync_data(true, $is_dry_run); // Recursion with force_rebuild
            } else {
                // If we collide even after a fresh rebuild, we are truly out of memory.
                error_log("CRITICAL PERMISSION COMPILER ERROR: " . $e->getMessage());
                return false; 
            }
        }

        $this->assign_card_permissions(); 
        $this->save_persistent_maps($is_dry_run);    
        
        return [
            'profiles' => $this->controller_profiles,
            'cards'    => $this->card_permissions
        ];
    }

    private function reset_state() {
        $this->controller_profiles = [];
        $this->card_permissions = [];
        $this->ids_claimed = [];
        foreach ($this->controllers as $did => $v) {
            $this->dynamic_counters[$did] = self::MAX_ID;
        }
    }

    /**
     * 1. Load Data from DB
     */
    private function load_data() {
        global $wpdb;
        $this->schedule_id = fsbhoa_get_active_schedule_id(); 

        // Controllers & Doors
        $doors = $wpdb->get_results("
            SELECT d.door_record_id, d.door_number_on_controller, c.uhppoted_device_id 
            FROM ac_doors d 
            JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id 
            WHERE c.type = 'UHPPOTE'
        ");
        foreach ($doors as $d) {
            $this->controllers[$d->uhppoted_device_id][$d->door_number_on_controller] = $d->door_record_id;
            
            // Initialize counters if not set
            if (!isset($this->dynamic_counters[$d->uhppoted_device_id])) {
                $this->ids_claimed[$d->uhppoted_device_id] = [];
                $this->dynamic_counters[$d->uhppoted_device_id] = self::MAX_ID;
            }
        }

        // Groups & Permissions (filtered by Active Schedule)
        $this->raw_data['groups'] = $wpdb->get_results("SELECT * FROM ac_groups WHERE is_enabled = 1", OBJECT_K);
        $this->raw_data['permissions'] = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM ac_group_permissions 
            WHERE is_enabled = 1 AND schedule_id = %d
        ", $this->schedule_id));

        // Cards & Memberships
        $this->raw_data['cards'] = $wpdb->get_results("
            SELECT id, rfid_id, card_status FROM ac_cardholders 
            WHERE card_status IN ('active', 'disabled') AND rfid_id != ''
        ");
        
        $memberships = $wpdb->get_results("SELECT cardholder_id, group_id FROM ac_cardholder_groups");
        foreach($memberships as $m) {
            $this->raw_data['memberships'][$m->cardholder_id][] = $m->group_id;
        }
    }

    /**
     * 2. Discover Unique Snowflakes (Group Combinations)
     */
    private function discover_signatures() {
        foreach ($this->raw_data['cards'] as $card) {
            $group_ids = $this->raw_data['memberships'][$card->id] ?? [];
            if (empty($group_ids)) continue;

            sort($group_ids);
            $sig = implode(',', $group_ids);
            $this->used_signatures[$sig] = $group_ids; 
        }
    }

    /**
     * 3. Load Persistence
     */
    private function load_persistent_maps() {
        foreach ($this->controllers as $device_id => $doors) {
            $option_name = 'fsbhoa_profile_map_' . $device_id;
            $this->persistent_maps[$device_id] = get_option($option_name, []);
        }
    }

    /**
     * 4. Allocate IDs and Generate Content
     */
    private function allocate_and_generate() {
        foreach ($this->controllers as $device_id => $doors) {
            foreach ($this->used_signatures as $sig => $group_ids) {
                
                // CHECK: Global All Access?
                if ($this->has_global_access($group_ids)) continue;

                // Process Each Door
                foreach ($doors as $door_num => $door_record_id) {
                    
                    // A. Resolve Rules (Specificity + Union + Merging)
                    $final_schedule = $this->resolve_rules_for_door($group_ids, $device_id, $door_record_id);
                    if (empty($final_schedule)) continue; 

                    // B. Allocate Head ID (Heads grow UP from 2)
                    $map_key = $sig . '|' . $door_num;
                    $profile_id = 0;

                    if (isset($this->persistent_maps[$device_id][$map_key])) {
                        $profile_id = $this->persistent_maps[$device_id][$map_key];
                    } else {
                        $profile_id = $this->find_next_free_stable_id($device_id);
                        $this->persistent_maps[$device_id][$map_key] = $profile_id;
                    }

                    // Mark as used
                    $this->ids_claimed[$device_id][] = $profile_id;

                    // C. Generate Chain (Tails grow DOWN from 254)
                    $this->generate_profile_chain($device_id, $profile_id, $final_schedule);
                }
            }
        }
    }

    /**
     * 5. Assign Permissions to Cards
     */
    private function assign_card_permissions() {
        foreach ($this->raw_data['cards'] as $card) {
            // LOGIC FOR DISABLED CARDS
            if ($card->card_status === 'disabled') {
                // Force empty permissions for all controllers
                foreach ($this->controllers as $device_id => $d) {
                    $this->card_permissions[$card->rfid_id][$device_id] = "";
                }
                continue; // Skip the rest of the logic for this card
            }
            $group_ids = $this->raw_data['memberships'][$card->id] ?? [];
            if (empty($group_ids)) continue;
            
            sort($group_ids);
            $sig = implode(',', $group_ids);
            
            // Case 1: All Access
            if ($this->has_global_access($group_ids)) {
                foreach ($this->controllers as $device_id => $d) {
                    $this->card_permissions[$card->rfid_id][$device_id] = "1:Y,2:Y,3:Y,4:Y";
                }
                continue;
            }

            // Case 2: Restricted (Use Profile IDs)
            foreach ($this->controllers as $device_id => $doors) {
                $perms = [];
                foreach ($doors as $door_num => $d_id) {
                    $map_key = $sig . '|' . $door_num;
                    if (isset($this->persistent_maps[$device_id][$map_key])) {
                        $pid = $this->persistent_maps[$device_id][$map_key];
                        // OPTIMIZATION: Only add if Profile ID > 0
                        // Omitting '0' ensures that adding a new door to the system 
                        // (which starts as 0) doesn't force a card rewrite.
                        if ($pid > 0) {
                            $perms[] = $door_num . ':' . $pid;
                        }
                    }
                }
                sort($perms);
                // If $perms is empty, this results in "" (Blank String).
                // This is valid! It tells the controller "Card exists, but no doors open."
                $this->card_permissions[$card->rfid_id][$device_id] = implode(',', $perms);
            }
        }
    }

    /**
     * 6. Save Persistence
     */
    private function save_persistent_maps($is_dry_run) {
        if ($is_dry_run) {
            // error_log("COMPILER: Dry Run - Skipping map save.");
            return;
        }
        foreach ($this->controllers as $device_id => $doors) {
            update_option('fsbhoa_profile_map_' . $device_id, $this->persistent_maps[$device_id], false);
        }
    }

    // --- LOGIC HELPERS ---

    private function has_global_access($group_ids) {
        foreach($group_ids as $gid) {
            if (!empty($this->raw_data['groups'][$gid]->has_all_access)) return true;
        }
        return false;
    }

    private function find_next_free_stable_id($device_id) {
        // Scan UP from base
        for ($i = self::BASE_ID; $i < self::MAX_ID; $i++) {
            
            // If already claimed this run, skip
            if (in_array($i, $this->ids_claimed[$device_id])) continue;
            
            // If colliding with Dynamic Cursor, THROW EXCEPTION to trigger Defrag
            if ($i >= $this->dynamic_counters[$device_id]) {
                throw new Exception("Memory Collision on Device $device_id (Stable $i hit Dynamic " . $this->dynamic_counters[$device_id] . ")");
            }

            // Check if mapped to ANOTHER key in persistence (Collision prevention for dirty map)
            $collision = false;
            if (!empty($this->persistent_maps[$device_id]) && is_array($this->persistent_maps[$device_id])) {
                foreach ($this->persistent_maps[$device_id] as $k => $v) {
                    if ($v == $i) { $collision = true; break; }
                }
            }
            if ($collision) continue;

            return $i;
        }
        throw new Exception("Stable Profile Memory Exhausted on $device_id");
    }

    /**
     * THE RULE ENGINE: Specificity + Union + Consolidation
     */
    private function resolve_rules_for_door($group_ids, $device_id, $door_record_id) {
        $final_segments = []; 

        // We process each Group independently first (to find its "Winning" rule for this door)
        // Then we merge the winners.
        foreach ($group_ids as $gid) {
            // Find ALL rules for this group
            $group_rules = [];
            foreach ($this->raw_data['permissions'] as $perm) {
                if ($perm->group_id == $gid) $group_rules[] = $perm;
            }

            // Apply Specificity Logic (Door > Controller > Global)
            $best_rule = null;
            $best_specificity = 0;

            foreach ($group_rules as $rule) {
                $spec = 0;
                if ($rule->door_id == $door_record_id) {
                    $spec = 3; // Exact Match
                } elseif ($rule->controller_id == $device_id && $rule->door_id == 0) { // Note: device_id check implies we map record ID to device ID correctly? 
                    // Actually raw_data['permissions'] has controller_id (Record ID). 
                    // $device_id passed here is usually Serial Number. 
                    // We need Controller Record ID. 
                    // Optimization: Assume Controller ID 0 = Global.
                    // For Controller Specific, we need a lookup. Let's assume passed ID is Record ID or we handle it.
                    // To be safe: Global (0,0) is spec 1.
                    $spec = 2; 
                } elseif ($rule->controller_id == 0 && $rule->door_id == 0) {
                    $spec = 1; // Global
                }

                if ($spec > $best_specificity) {
                    $best_specificity = $spec;
                    $best_rule = $rule;
                }
            }

            if ($best_rule) {
                $final_segments[] = $best_rule;
            }
        }

        if (empty($final_segments)) return [];

        // Normalize and Merge Time Windows (Consolidation)
        return $this->normalize_rules_to_schedule($final_segments);
    }

    private function generate_profile_chain($device_id, $head_id, $schedule) {
        // Chunk (3 spans per profile)
        $chunks = []; 
        foreach ($schedule as $day_sig => $windows) {
            $window_chunks = array_chunk($windows, 3);
            foreach ($window_chunks as $wc) {
                $chunks[] = ['sig' => $day_sig, 'spans' => $wc];
            }
        }

        $next_link = 0;
        
        // Iterate backwards
        for ($i = count($chunks) - 1; $i >= 0; $i--) {
            $chunk = $chunks[$i];
            $is_head = ($i === 0);
            
            $content = $chunk['sig'] . '|' . implode(',', $chunk['spans']);
            
            $pid = 0;
            if ($is_head) {
                $pid = $head_id; 
            } else {
                // Dynamic Tail (Grow DOWN)
                $pid = $this->dynamic_counters[$device_id]--;
                
                // Collision Check
                if (in_array($pid, $this->ids_claimed[$device_id])) {
                    throw new Exception("Memory Collision on Device $device_id (Dynamic hit Stable $pid)");
                }
            }

            $this->controller_profiles[$device_id][$pid] = [
                'content' => $content,
                'link'    => $next_link
            ];
            $next_link = $pid;
        }
    }

    private function normalize_rules_to_schedule($rules) {
        $by_day = []; 
        foreach ($rules as $r) {
            $days = [];
            if ($r->on_sun) $days[] = 'Sun';
            if ($r->on_mon) $days[] = 'Mon';
            if ($r->on_tue) $days[] = 'Tue';
            if ($r->on_wed) $days[] = 'Wed';
            if ($r->on_thu) $days[] = 'Thu';
            if ($r->on_fri) $days[] = 'Fri';
            if ($r->on_sat) $days[] = 'Sat';
            
            $span = [strtotime($r->start_time), strtotime($r->end_time)];
            foreach ($days as $d) { $by_day[$d][] = $span; }
        }

        $final_schedule = [];
        $all_days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        
        // Group days with identical spans
        $day_groups = []; 

        foreach ($all_days as $d) {
            if (empty($by_day[$d])) continue;
            $merged = $this->merge_timestamps($by_day[$d]);
            
            $span_strings = [];
            foreach ($merged as $m) { $span_strings[] = date('H:i',$m[0]).'-'.date('H:i',$m[1]); }
            $span_sig = implode(',', $span_strings);
            
            $day_groups[$span_sig][] = $d;
        }

        foreach ($day_groups as $span_sig => $days) {
            $day_sig = implode(',', $days);
            $final_schedule[$day_sig] = explode(',', $span_sig);
        }
        return $final_schedule;
    }

    private function merge_timestamps($ranges) {
        usort($ranges, function($a, $b) { return $a[0] <=> $b[0]; });
        $merged = [];
        if (empty($ranges)) return [];
        
        $curr = $ranges[0];
        for ($i=1; $i < count($ranges); $i++) {
            $next = $ranges[$i];
            if ($next[0] <= $curr[1]) {
                $curr[1] = max($curr[1], $next[1]);
            } else {
                $merged[] = $curr;
                $curr = $next;
            }
        }
        $merged[] = $curr;
        return $merged;
    }
}

