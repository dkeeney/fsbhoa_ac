package com.fsbhoa.printservice;

import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.net.InetSocketAddress;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

public class PrintServer {

    private static final int DEFAULT_PORT = 8081;
    private static final String PRINT_CONTEXT_PATH = "/print_card";
    private static final long POLLING_INITIAL_DELAY_SECONDS = 10;
    private static final long POLLING_INTERVAL_SECONDS = 15;

    public static void main(String[] args) {
        int port = DEFAULT_PORT;
        if (args.length > 0) {
            try {
                port = Integer.parseInt(args[0]);
            } catch (NumberFormatException e) {
                System.err.println("Invalid port number specified: " + args[0] + ". Using default port " + DEFAULT_PORT);
            }
        }

        // Initialize DbConfig and ZebraPrintJobManager once
        DbConfig dbConfig = new DbConfig();
        ZebraPrintJobManager jobManager = new ZebraPrintJobManager(dbConfig);

        // Initialize scheduler and make it effectively final for the shutdown hook
        final ScheduledExecutorService scheduler = Executors.newSingleThreadScheduledExecutor();

        // Register shutdown hook to gracefully stop the scheduler
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            System.out.println("Shutdown hook initiated: Shutting down poller scheduler...");
            if (!scheduler.isShutdown()) {
                scheduler.shutdown(); // Disable new tasks from being submitted
                try {
                    // Wait a while for existing tasks to terminate
                    if (!scheduler.awaitTermination(5, TimeUnit.SECONDS)) {
                        System.err.println("Poller scheduler did not terminate in 5 seconds, forcing shutdown...");
                        scheduler.shutdownNow(); // Cancel currently executing tasks
                        // Wait a while for tasks to respond to being cancelled
                        if (!scheduler.awaitTermination(5, TimeUnit.SECONDS)) {
                            System.err.println("Poller scheduler did not terminate even after shutdownNow().");
                        }
                    }
                } catch (InterruptedException ie) {
                    // (Re-)Cancel if current thread also interrupted
                    scheduler.shutdownNow();
                    // Preserve interrupt status
                    Thread.currentThread().interrupt();
                }
            }
            System.out.println("Poller scheduler shutdown complete.");
        }));

        // Start the HttpServer
        try {
            HttpServer server = HttpServer.create(new InetSocketAddress(port), 0);
            server.createContext(PRINT_CONTEXT_PATH, new PrintCardHandler(dbConfig, jobManager)); // Pass both
            server.setExecutor(Executors.newFixedThreadPool(10)); // For handling HTTP requests
            server.start();
            System.out.println("FSBHOA Print Service started on port: " + port);
            System.out.println("Listening for POST requests on context: " + PRINT_CONTEXT_PATH);

            // Schedule the poller task
            Runnable pollerTask = new PrintJobStatusPoller(dbConfig, jobManager);
            scheduler.scheduleAtFixedRate(pollerTask, POLLING_INITIAL_DELAY_SECONDS, POLLING_INTERVAL_SECONDS, TimeUnit.SECONDS);
            System.out.println("Print job status poller scheduled to run every " + POLLING_INTERVAL_SECONDS + " seconds.");

        } catch (IOException e) {
            System.err.println("Error starting print server: " + e.getMessage());
            e.printStackTrace();
            // If the server fails to start, the application will likely exit.
            // The shutdown hook for the scheduler will still attempt to run when the JVM exits.
            // We might want to explicitly stop the scheduler here too if the server fails,
            // though the shutdown hook should cover it.
            // For now, let the shutdown hook handle the scheduler.
            System.exit(1); // Exit if server can't start
        }
    }
}