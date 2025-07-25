# Gate State Handling

A “gate” in our world is called a “door” on the controller. A controller can have multiple gates, although not all may be configured, and there can be multiple controllers.

The state of a gate can change in one of three ways:

1. Scheduled Task that changes the state.
2. Manual override. Real-time monitor requests a manual change.
3. Actual hardware change (an initial state).

The states a gate can have are Open, Closed, or Controlled (can be opened via a card swipe). Other types of gate events are considered transient and should be recorded in the ac_access_log table of the database but do not affect the gate state.

The Event Service registers for unsolicited events and requests them to arrive at a specific port. These could be card swipe events and they could also be gate state changes as well as other types of events.

When a gate state event arrives, it is sent to the Monitor Service which forwards it on to the real-time display to set the color of the gate dots.

It is also recorded in the ac_access_log as a state change event, but only if the new state is not the same as the most recent state for a gate. So, the Event Service must send gate state changes to the wordpress code to have it checked to see if it is different from the database’s known state for a gate and record it if it is different.

When the real-time screen connects to the Monitor Service, the Monitor Service must send a poll to the Event Service, which in turn sends a real poll to all of the hardware for the current state of all gates. In addition, the Event Service must periodically (every 5 seconds) send a poll to all hardware asking for the current state of each gate. The response to these polls should be handled the same as any unsolicited gate state event; Forwarded to the Monitor Service which forwards it to the real-time display AND recorded in the database if different from its previous known state.
