package com.fsbhoa.printservice;

import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Timestamp; // For updated_at
import java.util.ArrayList;
import java.util.List;

public class PrintJobStatusPoller implements Runnable {

    private final DbConfig dbConfig;
    private final ZebraPrintJobManager jobManager;

    public PrintJobStatusPoller(DbConfig dbConfig, ZebraPrintJobManager jobManager) {
        this.dbConfig = dbConfig;
        this.jobManager = jobManager;
    }

    @Override
    public void run() {
        System.out.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Running status check...");
        List<PrintJobToPoll> jobsToPoll = getActivePrintJobs();

        if (jobsToPoll.isEmpty()) {
            System.out.println("[" + new java.util.Date() + "] PrintJobStatusPoller: No active print jobs to poll.");
            return;
        }

        for (PrintJobToPoll job : jobsToPoll) {
            try {
                PrintJobStatusInfo currentSdkStatus = jobManager.getPrintJobStatus(job.printerJobId, job.sdkImageName);                 
                System.out.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Job " + job.systemJobId + 
                                   ", SDK Status for " + job.printerJobId + ": " + currentSdkStatus.getStatus() + 
                                   " - " + currentSdkStatus.getMessage());

                if (currentSdkStatus.getStatus() != PrintJobStatusInfo.JobStatus.UNKNOWN && 
                    currentSdkStatus.getStatus() != PrintJobStatusInfo.JobStatus.PRINTING) { // Or whatever non-terminal status your stub returns initially
                    updatePrintJobStatusInDb(job.systemJobId, currentSdkStatus);
                } else if (currentSdkStatus.isTerminal()) { // If status is terminal but not UNKNOWN/PRINTING (e.g. straight to complete from stub)
                     updatePrintJobStatusInDb(job.systemJobId, currentSdkStatus);
                }


            } catch (Exception e) {
                System.err.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Error processing job " + job.systemJobId + ": " + e.getMessage());
                e.printStackTrace();
                // Optionally, update the job in DB with an error status related to polling failure
            }
        }
        System.out.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Finished status check.");
    }

    private List<PrintJobToPoll> getActivePrintJobs() {
        List<PrintJobToPoll> activeJobs = new ArrayList<>();
        // Add sdk_image_name to the SELECT
        String sql = "SELECT system_job_id, printer_job_id, sdk_image_name FROM ac_print_log WHERE status = ? OR status = ?"; 

        try (Connection conn = java.sql.DriverManager.getConnection(dbConfig.getDbUrl(), dbConfig.getDbUsername(), dbConfig.getDbPassword());
             PreparedStatement pstmt = conn.prepareStatement(sql)) {

            pstmt.setString(1, "queued"); // Use the new status "queued"
            pstmt.setString(2, "printing");

            ResultSet rs = pstmt.executeQuery();
            while (rs.next()) {
                // Pass the sdk_image_name to the constructor
                activeJobs.add(new PrintJobToPoll(
                    rs.getString("system_job_id"), 
                    rs.getString("printer_job_id"),
                    rs.getString("sdk_image_name") 
                ));
            }
        } catch (SQLException e) {
            // ... your existing error handling ...
        }
        return activeJobs;
    }
    
    private void updatePrintJobStatusInDb(String systemJobId, PrintJobStatusInfo sdkStatus) {
        String sql = "UPDATE ac_print_log SET status = ?, status_message = ?, updated_at = ? WHERE system_job_id = ?";
        
        try (Connection conn = java.sql.DriverManager.getConnection(dbConfig.getDbUrl(), dbConfig.getDbUsername(), dbConfig.getDbPassword());
             PreparedStatement pstmt = conn.prepareStatement(sql)) {

            pstmt.setString(1, sdkStatus.getStatus().name()); // Convert enum to string
            pstmt.setString(2, sdkStatus.getMessage());
            pstmt.setTimestamp(3, new Timestamp(System.currentTimeMillis()));
            pstmt.setString(4, systemJobId);

            int affectedRows = pstmt.executeUpdate();
            if (affectedRows > 0) {
                System.out.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Updated status for system_job_id " + systemJobId + " to " + sdkStatus.getStatus());
            } else {
                System.err.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Failed to update status for system_job_id " + systemJobId);
            }
        } catch (SQLException e) {
            System.err.println("[" + new java.util.Date() + "] PrintJobStatusPoller: Error updating print job status for " + systemJobId + ": " + e.getMessage());
            e.printStackTrace();
        }
    }

    // Helper inner class to hold job IDs for polling
    private static class PrintJobToPoll {
        String systemJobId;
        String printerJobId;
        String sdkImageName; // Add this field

        PrintJobToPoll(String systemJobId, String printerJobId, String sdkImageName) {
            this.systemJobId = systemJobId;
            this.printerJobId = printerJobId;
            this.sdkImageName = sdkImageName; // Initialize it
        }
    }
}