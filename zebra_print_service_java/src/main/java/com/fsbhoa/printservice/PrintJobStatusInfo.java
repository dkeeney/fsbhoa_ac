package com.fsbhoa.printservice;

public class PrintJobStatusInfo {
    public enum JobStatus {
        UNKNOWN,
        PRINTING,
        COMPLETED_OK,
        FAILED_ERROR,
        CANCELLED
        // Add other relevant statuses as needed
    }

    private JobStatus status;
    private String message; // For error messages or other info
    private boolean isTerminal; // Is the job in a final state (completed, failed, cancelled)?

    public PrintJobStatusInfo(JobStatus status, String message, boolean isTerminal) {
        this.status = status;
        this.message = message;
        this.isTerminal = isTerminal;
    }

    // Getters
    public JobStatus getStatus() {
        return status;
    }

    public String getMessage() {
        return message;
    }

    public boolean isTerminal() {
        return isTerminal;
    }

    @Override
    public String toString() {
        return "PrintJobStatusInfo{" +
               "status=" + status +
               ", message='" + message + '\'' +
               ", isTerminal=" + isTerminal +
               '}';
    }
}