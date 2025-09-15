<?php
// Intelligence Briefing API - Background Worker
// Long-running tasks: research, synthesis, optional podcast TTS

// --- Bootstrap ---
set_time_limit(600); // 10 minutes max

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- Config ---
$gemini_api_key = getenv('GEMINI_API_KEY');
if (!$gemini_api_key) {
  file_put_contents('php://stderr', "[worker] GEMINI_API_KEY not set\n");
  exit(1);
}

// IMPORTANT: keep these aligned with index.php
$data_path = '/data';
$jobs_path = $data_path . '/jobs';
$briefing_dir = $data_path . '/briefing';
$podcast_dir = $data_path . '/podcast';

// Ensure directories exist (non-fatal if they already exist)
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
      file_put_contents('php://stderr', "[worker] Failed to create dir: {$dir}\n");
      exit(1);
    }
  }
}
ensure_dir($data_path);
ensure_dir($jobs_path);
ensure_dir($briefing_dir);
ensure_dir($podcast_dir);

// --- Inputs ---
if (!isset($argv[1])) {
  file_put_contents('php://stderr', "[worker] Missing job_id argument\n");
  exit(1);
}
$job_id = $argv[1];
$job_file = $jobs_path . '/' . $job_id . '.json';

if (!file_exists($job_file)) {
  file_put_contents('php://stderr', "[worker] Job file not found: {$job_file}\n");
  exit(1);
}

// --- Helpers ---
function update_job_status(string $path, string $status, ?array $results = null, ?string $error = null): void {
  if (!file_exists($path)) return;
  $data = json_decode(file_get_contents($path), true) ?: [];
  $data['status'] = $status;
  $data['updated_at'] = date('c');
  if ($results !== null) {
    $data['results'] = $results;
  }
  if ($error !== null) {
    $data['error'] = $error;
  }
  // Correct: LOCK_EX as file_put_contents flag (not in json_encode)
  file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function call_gemini_api(string $api_key, array $payload, string $model = 'gemini-2.5-flash-preview-05-20'): array {
  $client = new Client();
  $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
  try {
    $resp = $client->post($url, [
      'headers' => ['Content-Type' => 'application/json'],
      'json' => $payload,
      'timeout' => 120,
    ]);
    $body = $resp->getBody()->getContents();
    return json_decode($body, true) ?: [];
  } catch (RequestException $e) {
    $err = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
    throw new Exception("Gemini API failed: {$err}");
  }
}

function validate_content(string $content, int $min = 100): void {
  if (strlen(trim($content)) < $min) {
    throw new Exception("Generated content too short (<{$min} chars)");
  }
}

function safe_name(string $s): string {
  return preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
}

// --- Main ---
$job = json_decode(file_get_contents($job_file), true) ?: [];
$company_name = $job['company_name'] ?? 'Company';
$job_title = $job['job_title'] ?? null;
$include_podcast = (bool)($job['include_podcast'] ?? false);

try {
  file_put_contents('php://stderr', "[worker:{$job_id}] start company=\"{$company_name}\" podcast=" . ($include_podcast ? 'yes' : 'no') . "\n");

  // Step 1: Research Analyst (PRESERVED PROMPT)
  update_job_status($job_file, 'processing-research');
  $research_prompt = "Act as a research analyst. Your task is to create a factual market briefing for the company '$company_name'. First, use your search tool to identify the company's primary industry sector. Then, identify the top 3-5 most important industry trends currently affecting that sector. Find at least 10 recent, high-quality sources (news articles, financial reports, official company statements) to support your analysis. The final output must be a well-structured Markdown document. It must be structured around your analysis of how the company is responding to the key trends you identified. It must include sections for: Company Overview, Strategic Response to Trends, Key People, Competitor Analysis, and Recent News. Cite your sources clearly within the document.";

  $research_payload = [
    'contents' => [['parts' => [['text' => $research_prompt]]]],
    'tools' => [['google_search' => new stdClass()]],
  ];
  $research_res = call_gemini_api($gemini_api_key, $research_payload);
  $factual = $research_res['candidates'][0]['content']['parts'][0]['text'] ?? '';
  validate_content($factual, 200);

  // Step 2: Career Coach (PRESERVED PROMPT)
  update_job_status($job_file, 'processing-career-insights');
  $coach_prompt = "Act as a career coach. You have been given a factual market briefing about a company. Your task is to add three new sections to the end of this document: 'Potential Industry Disruption', 'Tailored CV Points', and 'Insightful Interview Questions'. All your analysis MUST be based *only* on the provided briefing. ";
  if ($job_title) {
    $coach_prompt .= "The CV points and interview questions must be specifically tailored for a candidate applying for the role of '$job_title'. ";
  } else {
    $coach_prompt .= "The CV points and interview questions should be suitable for a general professional role at the company. ";
  }
  $coach_prompt .= "Here is the factual briefing:\n\n{$factual}";

  $coach_payload = ['contents' => [['parts' => [['text' => $coach_prompt]]]]];
  $coach_res = call_gemini_api($gemini_api_key, $coach_payload);
  $insights = $coach_res['candidates'][0]['content']['parts'][0]['text'] ?? '';
  validate_content($insights, 50);

  $final_md = $factual . "\n\n" . $insights;
  validate_content($final_md, 300);

  // Save briefing
  $briefing_filename = safe_name($company_name) . '_' . $job_id . '.md';
  $briefing_path = $briefing_dir . '/' . $briefing_filename;
  if (file_put_contents($briefing_path, $final_md, LOCK_EX) === false) {
    throw new Exception("Failed to save briefing: {$briefing_path}");
  }

  // Mark complete immediately (podcast continues in background if enabled)
  $results = [
    'briefing_url' => '/briefing/' . $briefing_filename,
    'briefing_content' => $final_md, // full content
    'podcast_status' => $include_podcast ? 'processing' : 'disabled',
    'generated_at' => date('c'),
  ];
  update_job_status($job_file, 'complete', $results);
  file_put_contents('php://stderr', "[worker:{$job_id}] briefing ready: {$results['briefing_url']}\n");

  // Optional: Podcast
  if ($include_podcast) {
    try {
      update_job_status($job_file, 'processing-podcast-generation', $results); // transient status
      $podcast_prompt = "You are a podcast host. Convert the following intelligence briefing into a concise, engaging 3-5 minute podcast script. Speak in a clear, professional, and informative tone. Here is the briefing:\n\n{$final_md}";
      $tts_payload = [
        'model' => 'gemini-2.5-flash-preview-tts',
        'contents' => [['parts' => [['text' => $podcast_prompt]]]],
        'generationConfig' => [
          'responseModalities' => ['AUDIO'],
          'speechConfig' => [
            'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => 'Charon']],
          ],
        ],
      ];
      $tts_res = call_gemini_api($gemini_api_key, $tts_payload, 'gemini-2.5-flash-preview-tts');
      $audio_b64 = $tts_res['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
      if (!$audio_b64) {
        throw new Exception('No audio data from TTS');
      }

      $raw_path = $data_path . '/' . $job_id . '.raw';
      if (file_put_contents($raw_path, base64_decode($audio_b64), LOCK_EX) === false) {
        throw new Exception('Failed to write raw audio');
      }

      $mp3_filename = safe_name($company_name) . '_' . $job_id . '.mp3';
      $mp3_path = $podcast_dir . '/' . $mp3_filename;

      // Gemini TTS returns s16le, 24kHz mono
      $cmd = "ffmpeg -f s16le -ar 24000 -ac 1 -i " . escapeshellarg($raw_path) . ' ' . escapeshellarg($mp3_path) . ' 2>&1';
      exec($cmd, $out, $rv);
      if (file_exists($raw_path)) @unlink($raw_path);
      if ($rv !== 0) {
        throw new Exception("ffmpeg failed (code {$rv}): " . implode("\n", $out));
      }

      // Update results with podcast URL but keep status complete
      $current = json_decode(file_get_contents($job_file), true) ?: [];
      $upd = $current['results'] ?? $results;
      $upd['podcast_url'] = '/podcast/' . $mp3_filename;
      $upd['podcast_status'] = 'ready';
      update_job_status($job_file, 'complete', $upd);
      file_put_contents('php://stderr', "[worker:{$job_id}] podcast ready: {$upd['podcast_url']}\n");
    } catch (Exception $pe) {
      // Briefing succeeded—don't regress status
      $current = json_decode(file_get_contents($job_file), true) ?: [];
      $upd = $current['results'] ?? $results;
      $upd['podcast_status'] = 'failed';
      $upd['podcast_error'] = $pe->getMessage();
      update_job_status($job_file, 'complete', $upd);
      file_put_contents('php://stderr', "[worker:{$job_id}] podcast failed: {$pe->getMessage()}\n");
    }
  }

  exit(0);
} catch (Exception $e) {
  // If briefing already produced, keep as complete; otherwise fail job
  $current = json_decode(file_get_contents($job_file), true) ?: [];
  if (!empty($current['results']['briefing_url'])) {
    $upd = $current['results'];
    $upd['error'] = $e->getMessage();
    update_job_status($job_file, 'complete', $upd);
    file_put_contents('php://stderr', "[worker:{$job_id}] error after briefing complete: {$e->getMessage()}\n");
    exit(0);
  }
  update_job_status($job_file, 'failed', null, $e->getMessage());
  file_put_contents('php://stderr', "[worker:{$job_id}] failed: {$e->getMessage()}\n");
  exit(1);
}
?>
