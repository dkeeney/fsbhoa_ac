package com.fsbhoa.printservice;

import java.io.IOException;
import java.io.InputStream;
import java.util.Properties;
import java.nio.file.Paths; // For resolving paths if needed

public class DbConfig {
    private static final String PROPERTIES_FILE = "db.properties";
    private Properties properties = new Properties();
    private final String applicationBaseDir; // For resolving relative paths

    public DbConfig() {
        // Determine application base directory (simplified version)
        String path = "."; // Default to current working directory
        try {
            java.net.URL location = DbConfig.class.getProtectionDomain().getCodeSource().getLocation();
            java.nio.file.Path jarPath = java.nio.file.Paths.get(location.toURI());
            if (jarPath.toString().toLowerCase().endsWith(".jar")) {
                path = jarPath.getParent().toString();
            } else {
                path = jarPath.toString(); // e.g., target/classes
            }
        } catch (Exception e) {
            System.err.println("Warning: Could not determine application base directory. Using CWD.");
            e.printStackTrace();
        }
        this.applicationBaseDir = path;

        try (InputStream input = getClass().getClassLoader().getResourceAsStream(PROPERTIES_FILE)) {
            if (input == null) {
                System.err.println("CRITICAL: Unable to find " + PROPERTIES_FILE + " in classpath.");
                // In a real app, you might throw a RuntimeException here to halt startup
                return;
            }
            properties.load(input);
            System.out.println(PROPERTIES_FILE + " loaded successfully.");
        } catch (IOException ex) {
            System.err.println("CRITICAL: Error loading database configuration from " + PROPERTIES_FILE + ": " + ex.getMessage());
            ex.printStackTrace();
            // In a real app, you might throw a RuntimeException here
        }
    }

    public String getDbUrl() {
        return properties.getProperty("db.url");
    }

    public String getDbUsername() {
        return properties.getProperty("db.username");
    }

    public String getDbPassword() {
        return properties.getProperty("db.password");
    }

    public String getPrinterIp() {
        return properties.getProperty("printer.ip", "127.0.0.1"); // Default if not found
    }

    public String getCardTemplateFilePath() {
        String pathValue = properties.getProperty("card.template.filepath");
        if (pathValue == null || pathValue .trim().isEmpty()) {
            System.err.println("WARNING: card.template.filepath is not set in db.properties. Using default 'hoa_card_template.xml' relative to app base.");
            pathValue  = "templates/hoa_card_template.xml"; // A fallback
        }
        // If card.template.filepath is intended to be absolute, no need to join with applicationBaseDir
        // If it's relative to app (as discussed for portability), then join:
        // return java.nio.file.Paths.get(this.applicationBaseDir, relativePath).toString();
        // For now, let's assume it's an absolute path as per user's last preference (turn 96)
        // or a path that Java can resolve directly (e.g. if relative, from CWD).
        // The user last said "I like putting the full path in the db.properties file."
        // So, just return the property directly.
        return pathValue.trim(); 
    }

    public String getPrinterSdkImageTempDir() {
        String relativePath = properties.getProperty("printer.sdk.image_temp_dir", "zebra_sdk_temp_images");
        // This one *should* be relative to where the JAR runs
        return java.nio.file.Paths.get(this.applicationBaseDir, relativePath).toString();
    }
}