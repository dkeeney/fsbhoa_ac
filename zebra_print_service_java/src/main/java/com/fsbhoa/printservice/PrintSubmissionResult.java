package com.fsbhoa.printservice;

public class PrintSubmissionResult {
    public final String printerJobId;
    public final String sdkImageNameUsed; // Name of the image file passed to saveTemplateImage

    public PrintSubmissionResult(String printerJobId, String sdkImageNameUsed) {
        this.printerJobId = printerJobId;
        this.sdkImageNameUsed = sdkImageNameUsed;
    }
}