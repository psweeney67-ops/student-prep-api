<?php
// Intelligence Briefing API - Background Watcher
// This script runs continuously as a Render Background Worker.
// It scans the jobs directory and processes any new 'queued' jobs.

echo "Watcher process started.\n";
set_time_limit(0); // Run indefinitely.

$jobs_path = '/data/jobs';

while (true) {
    try {
        if (!is_dir($jobs_path)) {
            // Create the directory if it doesn't exist, this might happen on first run
            mkdir($jobs_path, 0775, true);
        }

        $files = scandir($jobs_path);
        if ($files === false) {
            echo "Could not scan jobs directory: $jobs_path. Retrying in 10s.\n";
            sleep(10);
            continue;
        }

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            $job_file_path = $jobs_path . '/' . $file;
            $job_data = json_decode(file_get_contents($job_file_path), true);

            if ($job_data && isset($job_data['status']) && $job_data['status'] === 'queued') {
                $job_id = $job_data['job_id'];
                echo "Found queued job: $job_id. Processing...\n";
                
                // Use a separate process to run the worker to isolate failures
                // This command executes the main worker script for the specific job
                $command = "php worker.php " . escapeshellarg($job_id);
                $output = [];
                $return_var = -1;
                exec($command, $output, $return_var);

                if ($return_var !== 0) {
                     echo "Worker for job $job_id failed with exit code $return_var.\n";
                     // The worker's own error handling should have updated the status file.
                } else {
                     echo "Worker for job $job_id completed.\n";
                }
            }
        }
    } catch (Exception $e) {
        echo "An error occurred in the watcher loop: " . $e->getMessage() . "\n";
    }

    // Wait for a few seconds before scanning again to avoid busy-looping.
    sleep(5);
}

