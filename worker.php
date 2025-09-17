<?php
// Intelligence Briefing API - Background Worker
// This script is executed in the background to perform the long-running tasks
// of researching, synthesizing, and generating audio for a briefing.
// It uses a two-step AI process: 1. Research Analyst, 2. Career Coach.

// --- Bootstrap ---
// Set a longer execution time for this script.
set_time_limit(600); // 10 minutes

// Include the Composer autoloader to get access to Guzzle.
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- Configuration ---
$gemini_api_key = getenv('GEMINI_API_KEY');
if (!$gemini_api_key) {
    // We can't send a response, so log to stderr for Render to capture.
    file_put_contents('php://stderr', "Server configuration error: GEMINI_API_KEY is not set.\n");
    exit(1); // Exit with an error code.
}

// File paths (must match index.php)
$data_path = '/data';
$jobs_path = $data_path . '/jobs';
$output_path_briefing = $data_path . '/briefing';
$output_path_podcast = $data_path . '/podcast';

// --- Worker Logic ---

// The job ID is passed as the first command-line argument.
if (!isset($argv[1])) {
    file_put_contents('php://stderr', "Error: No job ID provided to worker.\n");
    exit(1);
}
$job_id = $argv[1];
$job_file_path = $jobs_path . '/' . $job_id . '.json';

if (!file_exists($job_file_path)) {
    file_put_contents('php://stderr', "Error: Job file not found for ID: $job_id\n");
    exit(1);
}

// --- Helper Functions for Worker ---

/**
 * Updates the job status file with new information.
 * @param string $path The path to the job file.
 * @param string $status The new status.
 * @param array|null $results Optional results to add.
 * @param string|null $error Optional error message.
 */
function update_job_status(string $path, string $status, ?array $results = null, ?string $error = null): void {
    if (!file_exists($path)) return;
    $data = json_decode(file_get_contents($path), true);
    $data['status'] = $status;
    $data['updated_at'] = date('c');
    if ($results) {
        $data['results'] = array_merge($data['results'] ?? [], $results);
    }
    if ($error) {
        $data['error'] = $error;
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Makes a call to the Gemini API.
 * @param string $api_key
 * @param array $payload
 * @param string $model
 * @return array The JSON-decoded response.
 * @throws Exception on API error.
 */
function call_gemini_api(string $api_key, array $payload, string $model = 'gemini-2.5-flash-preview-05-20'): array {
    $client = new Client();
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$api_key";

    try {
        $response = $client->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $payload
        ]);
        $body = $response->getBody()->getContents();
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Gemini: " . $body);
        }
        return $decoded;
    } catch (RequestException $e) {
        $error_body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
        throw new Exception("Gemini API request failed: " . $error_body);
    }
}

/**
 * Extracts a JSON object from a string that might contain other text.
 * @param string $text
 * @return string The JSON object as a string.
 */
function extract_json(string $text): string {
    $first_brace = strpos($text, '{');
    $last_brace = strrpos($text, '}');
    if ($first_brace === false || $last_brace === false) {
        return '{}';
    }
    return substr($text, $first_brace, $last_brace - $first_brace + 1);
}


// --- Main Processing ---

// 1. Load job data
$job_data = json_decode(file_get_contents($job_file_path), true);
update_job_status($job_file_path, 'processing-research');

try {
    // --- STEP 1: RESEARCH ANALYST ---
    // Goal: Identify trends, synthesize the factual part of the briefing, AND log the process.
    
    $company_name = $job_data['company_name'];
    
    $research_prompt = "Act as a research analyst. Your task is to create a factual market briefing for '$company_name' and document your thinking process. The final output must be a single JSON object. Do not include any text outside of the JSON object.
The JSON must have two keys: 'research_log' and 'briefing_markdown'.
- 'research_log': An array of strings describing each step of your process (e.g., 'Identifying industry sector for $company_name.', 'Searching for market trends in the identified sector.', 'Analyzing competitor X's latest financial report.').
- 'briefing_markdown': A well-structured Markdown document with sections for: Company Overview, Strategic Response to Trends, Key People, Competitor Analysis, and Recent News. Find at least 10 high-quality sources and cite them clearly.";
    
    $research_payload = [
        'contents' => [['parts' => [['text' => "Generate the briefing for $company_name."]]]],
        'systemInstruction' => ['parts' => [['text' => $research_prompt]]],
        'tools' => [['google_search' => new stdClass()]]
    ];
    
    $research_response = call_gemini_api($gemini_api_key, $research_payload);
    $raw_research_text = $research_response['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $research_json = extract_json($raw_research_text);
    $research_data = json_decode($research_json, true);

    $factual_briefing_text = $research_data['briefing_markdown'] ?? 'Error: Could not generate factual briefing text.';
    $research_log = $research_data['research_log'] ?? ['Error: Could not generate research log.'];

    // Update the job with the research log so the front-end can display it.
    update_job_status($job_file_path, 'processing-career-insights', ['research_log' => $research_log]);

    // --- STEP 2: CAREER COACH ---
    // Goal: Use the factual briefing to generate tailored career advice.

    $job_title = $job_data['job_title'] ?? null;
    $career_coach_prompt = "Act as a career coach. You have been given a factual market briefing about a company. Your task is to add three new sections to the end of this document: 'Potential Industry Disruption', 'Tailored CV Points', and 'Insightful Interview Questions'. All your analysis MUST be based *only* on the provided briefing. ";

    if ($job_title) {
        $career_coach_prompt .= "The CV points and interview questions must be specifically tailored for a candidate applying for the role of '$job_title'. ";
    } else {
        $career_coach_prompt .= "The CV points and interview questions should be suitable for a general professional role at the company. ";
    }
    
    $career_coach_prompt .= "Here is the factual briefing:\n\n" . $factual_briefing_text;

    $career_coach_payload = [
        'contents' => [['parts' => [['text' => $career_coach_prompt]]]],
    ];

    $career_coach_response = call_gemini_api($gemini_api_key, $career_coach_payload);
    $final_briefing_text = $factual_briefing_text . "\n\n" . ($career_coach_response['candidates'][0]['content']['parts'][0]['text'] ?? 'Error: Could not generate career insights.');
    
    // Create directories if they don't exist
    if (!is_dir($output_path_briefing)) mkdir($output_path_briefing, 0775, true);
    if (!is_dir($output_path_podcast)) mkdir($output_path_podcast, 0775, true);

    // Save the final, combined briefing markdown file
    $sanitized_company_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $company_name);
    $briefing_filename = $sanitized_company_name . '_' . $job_id . '.md';
    file_put_contents($output_path_briefing . '/' . $briefing_filename, $final_briefing_text);
    
    // CRITICAL: Mark briefing as complete immediately - don't wait for podcast
    $briefing_results = [
        'briefing_url' => '/briefing/' . $briefing_filename,  
        'briefing_content' => substr($final_briefing_text, 0, 2000), // First 2000 chars for preview
        'podcast_status' => (isset($job_data['include_podcast']) && $job_data['include_podcast']) ? 'processing' : 'disabled'
    ];
    update_job_status($job_file_path, 'complete', $briefing_results);
    
    // Continue with podcast only if requested
    if (isset($job_data['include_podcast']) && $job_data['include_podcast']) {
        // --- STEP 3: PODCAST GENERATION ---
        $podcast_script_prompt = "Convert the following intelligence briefing into a concise, engaging 3-5 minute podcast script between two hosts named Alex and Sarah. Format it as a natural conversation with clear speaker labels. Make it informative and professional. Here is the briefing:\n\n" . $final_briefing_text;

        $script_payload = [
            'contents' => [['parts' => [['text' => $podcast_script_prompt]]]]
        ];
        
        $script_response = call_gemini_api($gemini_api_key, $script_payload);
        $podcast_script = $script_response['candidates'][0]['content']['parts'][0]['text'] ?? 'Error: Podcast script could not be generated.';
        
        $tts_payload = [
            'contents' => [['parts' => [['text' => "TTS the following conversation between Alex and Sarah:\n" . $podcast_script]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'multiSpeakerVoiceConfig' => [
                        'speakerVoiceConfigs' => [
                            ['speaker' => 'Alex', 'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => 'Kore']]],
                            ['speaker' => 'Sarah', 'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => 'Aoede']]]
                        ]
                    ]
                ]
            ]
        ];
        
        $tts_response = call_gemini_api($gemini_api_key, $tts_payload, 'gemini-2.5-flash-preview-tts');
        $audio_base64 = $tts_response['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        
        if (!$audio_base64) {
            throw new Exception("Failed to generate podcast audio data.");
        }

        $raw_audio_data = base64_decode($audio_base64);
        $raw_audio_path = $data_path . '/' . $job_id . '.raw';
        file_put_contents($raw_audio_path, $raw_audio_data);

        $podcast_filename = $sanitized_company_name . '_' . $job_id . '.mp3';
        $output_mp3_path = $output_path_podcast . '/' . $podcast_filename;
        $ffmpeg_command = "ffmpeg -f s16le -ar 24000 -ac 1 -i " . escapeshellarg($raw_audio_path) . " " . escapeshellarg($output_mp3_path);
        exec($ffmpeg_command, $output, $return_var);

        unlink($raw_audio_path);

        if ($return_var !== 0) {
            throw new Exception("ffmpeg failed to convert audio to MP3. Output: " . implode("\n", $output));
        }
        
        // Add podcast to existing results
        $current_job = json_decode(file_get_contents($job_file_path), true);
        $updated_results = $current_job['results'] ?? [];
        $updated_results['podcast_url'] = '/podcast/' . $podcast_filename;
        $updated_results['podcast_status'] = 'ready';
        update_job_status($job_file_path, 'complete', $updated_results);
    }

} catch (Exception $e) {
    // Check if briefing was already completed successfully
    $current_job = json_decode(file_get_contents($job_file_path), true);
    if (isset($current_job['results']['briefing_url'])) {
        // Briefing succeeded, only podcast failed - keep status as complete
        $results = $current_job['results'];
        $results['podcast_status'] = 'failed';
        $results['podcast_error'] = $e->getMessage();
        update_job_status($job_file_path, 'complete', $results);
        file_put_contents('php://stderr', "Podcast generation failed for job ID $job_id, but briefing completed: " . $e->getMessage() . "\n");
    } else {
        // Briefing itself failed
        update_job_status($job_file_path, 'failed', null, $e->getMessage());
        file_put_contents('php://stderr', "Worker failed for job ID $job_id: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Worker finished successfully.
exit(0);

