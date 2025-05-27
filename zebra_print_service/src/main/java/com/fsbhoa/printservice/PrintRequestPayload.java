package com.fsbhoa.printservice;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;

// Allows Jackson to ignore unknown properties in the JSON if PHP sends extra ones
@JsonIgnoreProperties(ignoreUnknown = true)
public class PrintRequestPayload {
    public String rfid_id;
    public String first_name;
    public String last_name;
    public String property_address_text;
    public String photo_base64;
    public String resident_type;
    public String card_issue_date;
    public String card_expiry_date; // Optional, so can be null
    public String submitted_by_user; // Optional, PHP might send this

    // You can add getters and setters if you prefer, or keep it simple with public fields
    // for Jackson's direct binding. For a simple internal DTO, public fields are often fine.

    @Override
    public String toString() {
        return "PrintRequestPayload{" +
                "rfid_id='" + rfid_id + '\'' +
                ", first_name='" + first_name + '\'' +
                ", last_name='" + last_name + '\'' +
                ", property_address_text='" + property_address_text + '\'' +
                ", photo_base64='" + (photo_base64 != null ? photo_base64.substring(0, Math.min(30, photo_base64.length())) + "..." : "null") + '\'' +
                ", resident_type='" + resident_type + '\'' +
                ", card_issue_date='" + card_issue_date + '\'' +
                ", card_expiry_date='" + card_expiry_date + '\'' +
                ", submitted_by_user='" + submitted_by_user + '\'' +
                '}';
    }
}