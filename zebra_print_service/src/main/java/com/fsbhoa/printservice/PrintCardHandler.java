package com.fsbhoa.printservice;

import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpHandler;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.SQLException;
import java.util.UUID;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;

public class PrintCardHandler implements HttpHandler {

    private final ObjectMapper objectMapper = new ObjectMapper();
    private final DbConfig dbConfig;
    private final ZebraPrintJobManager jobManager;

    // Constructor to accept DbConfig and ZebraPrintJobManager
    public PrintCardHandler(DbConfig dbConfig, ZebraPrintJobManager jobManager) {
        this.dbConfig = dbConfig;
        this.jobManager = jobManager;
        if (this.dbConfig == null) {
            System.err.println("FATAL: DbConfig is null in PrintCardHandler constructor.");
            // Consider throwing an IllegalArgumentException or similar
        }
        if (this.jobManager == null) {
            System.err.println("FATAL: ZebraPrintJobManager is null in PrintCardHandler constructor.");
            // Consider throwing an IllegalArgumentException or similar
        }
    }

    @Override
    public void handle(HttpExchange exchange) throws IOException {
        String responseMessage = "{\"status\": \"error\", \"message\": \"An unexpected error occurred.\"}"; // Default error
        int statusCode = 500; // Default to Internal Server Error
        String systemJobId = "sysjob_" + UUID.randomUUID().toString(); // Generate system job ID upfront
        String printerJobIdFromSDK = null;
        String statusToLog = "error_unknown"; // Default status if things go very wrong early

        try {
            if (!"POST".equalsIgnoreCase(exchange.getRequestMethod())) {
                responseMessage = "{\"status\": \"error\", \"message\": \"POST method required.\"}";
                statusCode = 405; // Method Not Allowed
                exchange.getResponseHeaders().add("Allow", "POST");
                sendResponse(exchange, responseMessage, statusCode);
                return;
            }

            InputStream requestBodyStream = exchange.getRequestBody();
            StringBuilder sb = new StringBuilder();
            try (java.io.Reader reader = new java.io.BufferedReader(
                    new java.io.InputStreamReader(requestBodyStream, StandardCharsets.UTF_8))) {
                int c;
                while ((c = reader.read()) != -1) {
                    sb.append((char) c);
                }
            }
            String requestJson = sb.toString();
            System.out.println("Received JSON payload: " + requestJson);

            PrintRequestPayload payload = objectMapper.readValue(requestJson, PrintRequestPayload.class);
            System.out.println("Parsed payload: " + payload.toString());

            // --- 1. TODO: Input Validation (Crucial Step) ---
            // Example for rfid_id length, expand for other fields
            if (payload.rfid_id == null || payload.rfid_id.trim().isEmpty() || payload.rfid_id.length() > 8) {
                responseMessage = "{\"status\": \"error\", \"message\": \"Validation failed: rfid_id must be present and max 8 characters.\"}";
                statusCode = 400; // Bad Request
                sendResponse(exchange, responseMessage, statusCode);
                return;
            }
            // Add more validations for other fields (first_name, photo_base64 format/size, dates etc.)
            // If validation fails, set responseMessage, statusCode = 400, and return.
            System.out.println("Input validation placeholder passed for rfid_id: " + payload.rfid_id);


            // --- 2. Call ZebraPrintJobManager to submit job to SDK (stubbed) ---
            boolean sdkSubmissionSuccess = false;
            PrintSubmissionResult submissionResult = null; // To hold printerJobId and sdkImageNameUsed
            // statusToLog was declared earlier, e.g. String statusToLog = "error_unknown";

            try {
                // Pass payload and the systemJobId (which you generate earlier in handle())
                submissionResult = this.jobManager.submitPrintJob(payload, systemJobId); 
                
                if (submissionResult != null && submissionResult.printerJobId != null && !submissionResult.printerJobId.isEmpty()) {
                    sdkSubmissionSuccess = true;
                    statusToLog = "queued"; // Correct initial status for poller
                    System.out.println("Print job submitted via ZebraPrintJobManager, Printer Job ID: " + submissionResult.printerJobId + 
                                       ", SDK Image Name: " + submissionResult.sdkImageNameUsed);
                } else {
                    statusToLog = "sdk_submission_failed_no_id";
                    System.err.println("ZebraPrintJobManager.submitPrintJob returned null or invalid submission result.");
                }
            } catch (Exception sdkEx) { // Catching generic Exception from submitPrintJob
                  statusToLog = "sdk_submission_error";
                  System.err.println("Error calling ZebraPrintJobManager.submitPrintJob: " + sdkEx.getMessage());
                  sdkEx.printStackTrace();
                  // sdkSubmissionSuccess remains false
            }
            
            
            // --- 3. JDBC Logic to insert into ac_print_log ---
            // Make sure your SQL includes the new sdk_image_name column
            String sql = "INSERT INTO ac_print_log (system_job_id, printer_job_id, rfid_id, print_request_data, status, submitted_by_user, cardholder_id, sdk_image_name) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)";
            try (Connection conn = DriverManager.getConnection(dbConfig.getDbUrl(), dbConfig.getDbUsername(), dbConfig.getDbPassword());
                 PreparedStatement pstmt = conn.prepareStatement(sql)) {

                pstmt.setString(1, systemJobId);
                pstmt.setString(2, submissionResult != null ? submissionResult.printerJobId : null);
                pstmt.setString(3, payload.rfid_id);
                pstmt.setString(4, requestJson); // Assuming requestJson (raw JSON string) is still in scope
                pstmt.setString(5, statusToLog); 
                pstmt.setString(6, payload.submitted_by_user);
                pstmt.setString(7, submissionResult != null ? submissionResult.sdkImageNameUsed : null); // New sdk_image_name

                int affectedRows = pstmt.executeUpdate();
                if (affectedRows > 0) {
                    System.out.println("Successfully inserted record in ac_print_log (status: " + statusToLog + ") for system_job_id: " + systemJobId);
                } else {
                    System.err.println("Failed to insert record into ac_print_log for system_job_id: " + systemJobId);
                    if (sdkSubmissionSuccess) { 
                        sdkSubmissionSuccess = false; 
                        statusToLog = "error_logging_to_db";
                    }
                }
            } catch (SQLException dbEx) {
                System.err.println("Database error when logging print job: " + dbEx.getMessage());
                dbEx.printStackTrace();
                sdkSubmissionSuccess = false; 
                statusToLog = "error_db_exception";
                // Re-throw to be caught by the outer catch block to send a 500 error.
                // This ensures the HTTP response reflects the DB failure.
                throw dbEx; 
            }

            // --- 4. Prepare HTTP Response based on sdkSubmissionSuccess ---
            if (sdkSubmissionSuccess) { // This flag now also considers successful DB logging
                responseMessage = String.format(
                    "{\"status\": \"%s\", \"message\": \"Print job successfully queued\", \"system_job_id\": \"%s\", \"printer_job_id\": \"%s\"}",
                    statusToLog, // Should be "queued"
                    systemJobId, 
                    submissionResult != null ? submissionResult.printerJobId : "N/A"
                );
                statusCode = 200; // OK
            } else {
                responseMessage = String.format(
                    "{\"status\": \"%s\", \"message\": \"Failed to process print job. System Job ID: %s\"}",
                    statusToLog, // Will be an error status
                    systemJobId 
                );
                statusCode = 500; // Or a more specific client error if applicable
            }
        } catch (JsonProcessingException jsonEx) {
            System.err.println("JSON parsing error: " + jsonEx.getMessage());
            responseMessage = "{\"status\": \"error\", \"message\": \"Invalid JSON payload: " + jsonEx.getOriginalMessage() + "\"}";
            statusCode = 400; // Bad Request
        } catch (Exception e) { // Catches SQLException re-thrown or other unexpected errors
            System.err.println("Error handling print request: " + e.getMessage());
            e.printStackTrace();
            responseMessage = "{\"status\": \"error\", \"message\": \"Internal server error processing print request for system_job_id " + systemJobId + ": " + e.getMessage() + "\"}";
            statusCode = 500;
        } finally { // Ensure response is always sent
            sendResponse(exchange, responseMessage, statusCode);
        }
    }

    private void sendResponse(HttpExchange exchange, String responseMessage, int statusCode) throws IOException {
        exchange.getResponseHeaders().set("Content-Type", "application/json; charset=UTF-8");
        exchange.sendResponseHeaders(statusCode, responseMessage.getBytes(StandardCharsets.UTF_8).length);
        OutputStream os = exchange.getResponseBody();
        os.write(responseMessage.getBytes(StandardCharsets.UTF_8));
        os.close();
    }
}