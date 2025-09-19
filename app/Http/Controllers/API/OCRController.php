<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\WorkEntry;
use Carbon\Carbon;

class OCRController extends Controller
{
    /**
     * Process uploaded screenshot and extract work entries
     */
    public function processScreenshot(Request $request)
    {
        \Log::info("OCR ENDPOINT HIT - THIS IS THE NEW CONTROLLER");

        $request->validate([
            'image' => 'required|image|max:10240', // 10MB max
        ]);

        $userId = auth()->id();

        try {
            // Save uploaded image
            $image = $request->file('image');
            $imagePath = $image->store('ocr_uploads', 'public');

            // FIX: Correct path construction
            $fullPath = Storage::disk('public')->path($imagePath);

            Log::info("OCR: Processing image", ['user_id' => $userId, 'path' => $fullPath]);

            // Validate image file
            $this->validateImageFile($fullPath);

            // Try GPT-4 Vision first
            $result = $this->processWithGPTVision($fullPath);

            if (!$result['success']) {
                Log::warning("GPT-4 Vision failed, falling back to Tesseract", ['error' => $result['error']]);
                // Fallback to Tesseract OCR only if GPT-4 completely fails
                $result = $this->processWithTesseract($fullPath);
            }

            if (!$result['success']) {
                throw new \Exception('All OCR methods failed: ' . $result['error']);
            }

            Log::info("OCR: Processing result", ['result' => $result]);

            Log::info("OCR: Parsed entries", ['count' => count($result['entries']), 'method' => $result['method']]);

            // Save valid entries to database
            $savedEntries = [];
            $duplicateEntries = [];
            $skippedEntries = [];

            foreach ($result['entries'] as $entryData) {
                try {
                    Log::info("Processing entry for save", ['entry' => $entryData]);

                    if ($this->isValidEntry($entryData)) {
                        // Check for duplicate entries
                        $isDuplicate = $this->checkDuplicateEntry($userId, $entryData);

                        if ($isDuplicate) {
                            $duplicateEntries[] = $entryData;
                            Log::info("Duplicate entry skipped", ['entry' => $entryData]);
                            continue;
                        }

                        $hoursWorked = $this->calculateHoursFromTimes($entryData['start_time'], $entryData['end_time']);

                        $workEntry = WorkEntry::create([
                            'user_id' => $userId,
                            'date' => $entryData['date'],
                            'hours_worked' => $hoursWorked,
                            'earnings' => $entryData['total_earnings'],
                            'base_pay' => $entryData['base_pay'],
                            'tips' => $entryData['tips'],
                            'service_type' => $entryData['service_type'] ?? $this->determineServiceType($entryData),
                            'notes' => 'OCR imported via ' . $result['method'],
                        ]);

                        $savedEntries[] = $workEntry;
                        Log::info("Entry saved successfully", ['entry_id' => $workEntry->id]);
                    } else {
                        $skippedEntries[] = $entryData;
                        Log::warning("Entry validation failed", ['entry' => $entryData]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to save entry", ['entry' => $entryData, 'error' => $e->getMessage()]);
                    $skippedEntries[] = $entryData;
                }
            }

            // Clean up uploaded file
            Storage::disk('public')->delete($imagePath);

            return response()->json([
                'success' => true,
                'data' => [
                    'parsed_entries' => $result['entries'],
                    'saved_entries' => count($savedEntries),
                    'duplicate_entries' => count($duplicateEntries),
                    'skipped_entries' => count($skippedEntries),
                    'entries' => $savedEntries,
                    'method' => $result['method'],
                ],
                'message' => $this->buildSummaryMessage($savedEntries, $duplicateEntries, $skippedEntries),
            ]);

        } catch (\Exception $e) {
            Log::error("OCR processing failed", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Clean up file if it exists
            if (isset($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'OCR processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate image file before processing
     */
    private function validateImageFile($imagePath)
    {
        if (!file_exists($imagePath)) {
            throw new \Exception('Image file not found: ' . $imagePath);
        }

        $fileSize = filesize($imagePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new \Exception('Invalid or empty image file');
        }

        // Check if file is too large (20MB limit for OpenAI)
        if ($fileSize > 20 * 1024 * 1024) {
            throw new \Exception('Image file too large (max 20MB)');
        }

        // Validate image dimensions and type
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            throw new \Exception('Invalid image file format');
        }

        Log::info("Image validation passed", [
            'size' => $fileSize,
            'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
            'mime' => $imageInfo['mime']
        ]);
    }

    /**
     * Process image with OpenAI GPT-4 Vision API
     */
    private function processWithGPTVision($imagePath)
    {
        try {
            Log::info("GPT-4 Vision method called", ['path' => $imagePath]);

            if (!env('OPENAI_API_KEY')) {
                Log::error("OpenAI API key not configured");
                return ['success' => false, 'error' => 'OpenAI API key not configured'];
            }

            // Test API key first
            $testResponse = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->get('https://api.openai.com/v1/models');

            if (!$testResponse->successful()) {
                Log::error("OpenAI API key test failed", ['status' => $testResponse->status()]);
                return ['success' => false, 'error' => 'Invalid OpenAI API key or service unavailable'];
            }

            Log::info("Starting GPT-4 Vision processing", ['path' => $imagePath]);

            // FIX: Better file reading with error handling
            $imageContent = file_get_contents($imagePath);
            if ($imageContent === false) {
                throw new \Exception('Failed to read image file: ' . $imagePath);
            }

            $base64Image = base64_encode($imageContent);
            if (!$base64Image) {
                throw new \Exception('Failed to encode image to base64');
            }

            $mimeType = $this->getMimeType($imagePath);

            $response = Http::timeout(120)->connectTimeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4-turbo', // FIX: Updated model name
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Extract ALL work entries from this Amazon Flex earnings screenshot.

Return ONLY a JSON object with this exact structure:

{
  "entries": [
    {
      "date": "2024-09-18",
      "start_time": "9:21 AM",
      "end_time": "10:30 AM",
      "total_earnings": 30.00,
      "base_pay": null,
      "tips": null,
      "service_type": "logistics"
    }
  ]
}

STRICT RULES:
- Date format: YYYY-MM-DD (assume current year 2024)
- Time format: "H:MM AM/PM" (exact format with space)
- total_earnings: THE MAIN DOLLAR AMOUNT shown prominently (e.g. $30, $53.50, $99, $81)
- If you see "Base: $51.00 Tips: $48.00" format: total_earnings = base + tips (e.g. $99.00), base_pay = 51.00, tips = 48.00
- If only one amount shown: that is total_earnings, set base_pay=null, tips=null
- If you see "Tips pending" text: set service_type="whole_foods", base_pay=null, tips=null
- Service type rules:
  * "whole_foods" if base+tips shown separately OR if "Tips pending" appears anywhere
  * "logistics" if just total amount with no base/tips breakdown
- NEVER set total_earnings to 0.00 - it should be the main visible dollar amount
- Include ALL visible entries in the screenshot
- Return ONLY valid JSON, no explanation or additional text

IMPORTANT: The large dollar amounts you see ($51.00, $48.00, $58.00, etc.) are the TOTAL EARNINGS, not components. If you see "Tips pending", this is definitely a grocery/whole_foods entry.'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$base64Image}",
                                    'detail' => 'high' // High detail for better OCR accuracy
                                ]
                            ]
                        ]
                    ]
                ],
                'response_format' => [
                    'type' => 'json_object'
                ],
                'max_tokens' => 2000,
                'temperature' => 0.1 // Low temperature for consistent parsing
            ]);

            Log::info("OpenAI API Response", [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (!isset($data['choices'][0]['message']['content'])) {
                    throw new \Exception('Invalid OpenAI API response structure');
                }

                $content = $data['choices'][0]['message']['content'];
                Log::info("GPT-4 Vision raw response", ['content' => $content]);

                $parsedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON response from GPT-4: ' . json_last_error_msg());
                }

                if (!isset($parsedData['entries']) || !is_array($parsedData['entries'])) {
                    throw new \Exception('Invalid entries structure in GPT-4 response');
                }

                Log::info("GPT-4 Vision successful", [
                    'entries_count' => count($parsedData['entries']),
                    'entries' => $parsedData['entries']
                ]);

                return [
                    'success' => true,
                    'entries' => $parsedData['entries'],
                    'method' => 'gpt4_vision'
                ];
            }

            $errorBody = $response->body();
            $errorData = $response->json();

            Log::error("OpenAI API failed", [
                'status' => $response->status(),
                'body' => $errorBody,
                'error' => $errorData['error'] ?? 'Unknown error',
                'headers' => $response->headers()
            ]);

            return ['success' => false, 'error' => 'OpenAI API failed: ' . ($errorData['error']['message'] ?? $errorBody)];

        } catch (\Exception $e) {
            Log::error('GPT-4 Vision failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fallback: Process with Tesseract OCR and regex parsing
     */
    private function processWithTesseract($imagePath)
    {
        try {
            Log::info("Starting Tesseract fallback processing", ['path' => $imagePath]);

            // Extract text using Tesseract OCR
            $extractedText = $this->extractTextFromImage($imagePath);
            Log::info("Tesseract extracted text", ['text' => $extractedText]);

            // Parse Amazon Flex format with regex
            $parsedEntries = $this->parseAmazonFlexEarnings($extractedText);

            return [
                'success' => count($parsedEntries) > 0,
                'entries' => $parsedEntries,
                'method' => 'tesseract_regex',
                'error' => count($parsedEntries) == 0 ? 'No entries found with regex parsing' : null
            ];

        } catch (\Exception $e) {
            Log::error("Tesseract processing failed", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'method' => 'tesseract_regex'
            ];
        }
    }

    /**
     * Extract text from image using Tesseract OCR
     */
    private function extractTextFromImage($imagePath)
    {
        try {
            Log::info("Starting OCR extraction", ['path' => $imagePath]);

            if (!file_exists($imagePath)) {
                throw new \Exception('Image file not found: ' . $imagePath);
            }

            $command = "tesseract '" . escapeshellarg($imagePath) . "' stdout 2>&1";
            $output = shell_exec($command);

            Log::info("Tesseract output", ['output' => $output]);

            if ($output === null || trim($output) === '') {
                throw new \Exception('Tesseract OCR failed to process image');
            }

            return $this->cleanOCRText($output);
        } catch (\Exception $e) {
            Log::error("extractTextFromImage failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Clean OCR text from artifacts
     */
    private function cleanOCRText($text)
    {
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace('\n', "\n", $text);

        return trim($text);
    }

    /**
     * Parse Amazon Flex earnings format (Tesseract fallback)
     */
    private function parseAmazonFlexEarnings($ocrText)
    {
        try {
            $entries = [];
            $lines = array_filter(array_map('trim', explode("\n", $this->cleanOCRText($ocrText))));

            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];

                if ($this->isDateLine($line)) {
                    $entry = $this->parseEntry($lines, $i);
                    if ($entry) {
                        $entries[] = $entry;
                    }
                }
            }

            return $entries;
        } catch (\Exception $e) {
            Log::error("parseAmazonFlexEarnings failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Parse single entry from Tesseract lines
     */
    private function parseEntry($lines, $startIndex)
    {
        try {
            if ($startIndex >= count($lines)) return null;

            $dateLine = $lines[$startIndex];
            $timeLine = null;
            $totalEarnings = null;
            $basePay = null;
            $tips = null;

            // Look for related data in next few lines
            for ($i = $startIndex + 1; $i < count($lines) && $i < $startIndex + 6; $i++) {
                if ($i >= count($lines)) break;

                $line = $lines[$i];

                if ($this->isTimeLine($line)) {
                    $timeLine = $line;
                }

                if ($this->isEarningsLine($line)) {
                    $totalEarnings = $this->parseEarnings($line);
                }

                if (strpos($line, 'Base:') !== false && strpos($line, 'Tips:') !== false) {
                    if (preg_match('/Base:\s*\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                        $basePay = isset($matches[1]) ? floatval($matches[1]) : null;
                    }
                    if (preg_match('/Tips:\s*\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                        $tips = isset($matches[1]) ? floatval($matches[1]) : null;
                    }
                }

                if ($this->isDateLine($line) && $i > $startIndex) {
                    break;
                }
            }

            if (!$timeLine || $totalEarnings === null) {
                return null;
            }

            $parsedDate = $this->parseDate($dateLine);
            if (!$parsedDate) return null;

            $times = $this->parseTimeRange($timeLine);
            if (!$times) return null;

            $serviceType = ($basePay !== null && $tips !== null) ? 'whole_foods' : 'logistics';

            return [
                'date' => $parsedDate->format('Y-m-d'),
                'start_time' => $times['start'],
                'end_time' => $times['end'],
                'total_earnings' => $totalEarnings,
                'base_pay' => $basePay,
                'tips' => $tips,
                'service_type' => $serviceType,
            ];
        } catch (\Exception $e) {
            Log::error("parseEntry failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse time range from Tesseract line
     */
    private function parseTimeRange($timeLine)
    {
        try {
            // Handle different time range formats
            $patterns = [
                '/(\d{1,2}:\d{2}\s+(?:AM|PM))\s*[-–—]\s*(\d{1,2}:\d{2}\s+(?:AM|PM))/i',
                '/(\d{1,2}:\d{2}(?:AM|PM))\s*[-–—]\s*(\d{1,2}:\d{2}(?:AM|PM))/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $timeLine, $matches)) {
                    return [
                        'start' => trim($matches[1]),
                        'end' => trim($matches[2])
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error("parseTimeRange failed", ['line' => $timeLine, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate entry data
     */
    private function isValidEntry($entry)
    {
        Log::info("Validating entry", ['entry' => $entry]);

        $hasRequiredFields = isset($entry['date'])
            && isset($entry['start_time'])
            && isset($entry['end_time'])
            && isset($entry['total_earnings']);

        $hasValidEarnings = isset($entry['total_earnings']) && $entry['total_earnings'] > 0;

        $isValid = $hasRequiredFields && $hasValidEarnings;

        if (!$isValid) {
            Log::warning("Entry validation failed", [
                'entry' => $entry,
                'has_date' => isset($entry['date']),
                'has_start_time' => isset($entry['start_time']),
                'has_end_time' => isset($entry['end_time']),
                'has_earnings' => isset($entry['total_earnings']),
                'earnings_value' => $entry['total_earnings'] ?? 'null',
                'earnings_positive' => isset($entry['total_earnings']) ? ($entry['total_earnings'] > 0) : false
            ]);
        } else {
            Log::info("Entry validation passed", ['entry' => $entry]);
        }

        return $isValid;
    }

    /**
     * Calculate hours from start and end times
     */
    private function calculateHoursFromTimes($startTime, $endTime)
    {
        try {
            Log::info("Calculating hours", ['start' => $startTime, 'end' => $endTime]);

            // Handle different time formats that GPT-4 might return
            $timeFormats = [
                'g:i A',    // 9:21 AM
                'H:i',      // 09:21
                'g:iA',     // 9:21AM
            ];

            $start = null;
            $end = null;

            foreach ($timeFormats as $format) {
                try {
                    $start = Carbon::createFromFormat($format, $startTime);
                    $end = Carbon::createFromFormat($format, $endTime);
                    break;
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (!$start || !$end) {
                Log::warning("Could not parse times, using fallback", ['start' => $startTime, 'end' => $endTime]);
                return 2.0;
            }

            if ($end->lt($start)) {
                $end->addDay(); // Handle overnight shifts
            }

            $hours = round($start->diffInMinutes($end) / 60, 2);
            Log::info("Hours calculated", ['hours' => $hours]);

            return $hours;
        } catch (\Exception $e) {
            Log::error("calculateHoursFromTimes failed", ['start' => $startTime, 'end' => $endTime, 'error' => $e->getMessage()]);
            return 2.0; // Fallback
        }
    }

    /**
     * Determine service type from entry data
     */
    private function determineServiceType($entry)
    {
        // Check if base_pay and tips are both present and not null
        if (isset($entry['base_pay']) && isset($entry['tips']) &&
            $entry['base_pay'] !== null && $entry['tips'] !== null) {
            return 'whole_foods';
        }

        // Check if this is explicitly marked as whole_foods (from GPT-4 Vision detecting "Tips pending")
        if (isset($entry['service_type']) && $entry['service_type'] === 'whole_foods') {
            return 'whole_foods';
        }

        // Default to logistics for Amazon deliveries
        return 'logistics';
    }

    /**
     * Check if entry already exists for user
     */
    private function checkDuplicateEntry($userId, $entryData)
    {
        try {
            // Parse start and end times to get precise time range
            $startTime = $this->parseTimeToMinutes($entryData['start_time']);
            $endTime = $this->parseTimeToMinutes($entryData['end_time']);

            if ($startTime === null || $endTime === null) {
                Log::warning("Could not parse time for duplicate check", ['entry' => $entryData]);
                return false; // If we can't parse time, allow the entry
            }

            // Calculate hours worked for comparison
            $hoursWorked = $this->calculateHoursFromTimes($entryData['start_time'], $entryData['end_time']);

            // Check for existing entry with same date and similar time range
            $existingEntry = WorkEntry::where('user_id', $userId)
                ->where('date', $entryData['date'])
                ->where('earnings', $entryData['total_earnings'])
                ->where(function ($query) use ($hoursWorked) {
                    // Allow small variance in hours (±0.1 hours = 6 minutes)
                    $query->whereBetween('hours_worked', [$hoursWorked - 0.1, $hoursWorked + 0.1]);
                })
                ->first();

            if ($existingEntry) {
                Log::info("Duplicate entry found", [
                    'new_entry' => $entryData,
                    'existing_entry_id' => $existingEntry->id,
                    'existing_date' => $existingEntry->date,
                    'existing_hours' => $existingEntry->hours_worked,
                    'existing_earnings' => $existingEntry->earnings
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error("Duplicate check failed", ['error' => $e->getMessage(), 'entry' => $entryData]);
            return false; // If check fails, allow the entry to be safe
        }
    }

    /**
     * Parse time string to minutes since midnight
     */
    private function parseTimeToMinutes($timeString)
    {
        try {
            $timeFormats = ['g:i A', 'H:i', 'g:iA'];

            foreach ($timeFormats as $format) {
                try {
                    $time = Carbon::createFromFormat($format, $timeString);
                    return ($time->hour * 60) + $time->minute;
                } catch (\Exception $e) {
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Time parsing failed", ['time' => $timeString, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build summary message for response
     */
    private function buildSummaryMessage($savedEntries, $duplicateEntries, $skippedEntries)
    {
        $messages = [];

        if (count($savedEntries) > 0) {
            $messages[] = count($savedEntries) . ' new entries saved';
        }

        if (count($duplicateEntries) > 0) {
            $messages[] = count($duplicateEntries) . ' duplicates skipped';
        }

        if (count($skippedEntries) > 0) {
            $messages[] = count($skippedEntries) . ' invalid entries skipped';
        }

        if (empty($messages)) {
            return 'No entries processed';
        }

        return implode(', ', $messages);
    }

    /**
     * Validate date format
     */
    private function isValidDate($date)
    {
        try {
            Carbon::createFromFormat('Y-m-d', $date);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate time format
     */
    private function isValidTime($time)
    {
        try {
            Carbon::createFromFormat('g:i A', $time);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // Legacy methods for Tesseract fallback
    private function isDateLine($line)
    {
        $patterns = [
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}$/i',
            '/(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isTimeLine($line)
    {
        $patterns = [
            '/^\d{1,2}:\d{2}\s+(AM|PM)\s*-\s*\d{1,2}:\d{2}\s+(AM|PM)$/i',
            '/\d{1,2}:\d{2}\s+(AM|PM)\s*[-–—]\s*\d{1,2}:\d{2}\s+(AM|PM)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isEarningsLine($line)
    {
        return preg_match('/^\$\d+(?:\.\d{2})?$/', $line) === 1;
    }

    private function parseEarnings($line)
    {
        if (preg_match('/\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
            return isset($matches[1]) ? floatval($matches[1]) : null;
        }
        return null;
    }

    private function parseDate($dateLine)
    {
        $patterns = [
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})$/i',
            '/(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dateLine, $matches)) {
                $monthName = ucfirst(strtolower($matches[2]));
                $day = intval($matches[3]);

                $months = [
                    'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
                    'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
                    'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
                ];

                if (isset($months[$monthName])) {
                    $month = $months[$monthName];
                    $year = date('Y');
                    return Carbon::createFromDate($year, $month, $day);
                }
            }
        }
        return null;
    }

    /**
     * Get MIME type of image - FIXED VERSION
     */
    private function getMimeType($imagePath)
    {
        // Try finfo first
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $imagePath);
        finfo_close($finfo);

        if ($mimeType && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }

        // Fallback to getimagesize
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo && isset($imageInfo['mime'])) {
            return $imageInfo['mime'];
        }

        // Final fallback based on file extension
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp'
        ];

        return $mimeTypes[$extension] ?? 'image/jpeg';
    }
}
