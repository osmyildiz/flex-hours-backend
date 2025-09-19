<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\WorkEntry;
use Carbon\Carbon;

class OCRController extends Controller
{
    /**
     * Process uploaded screenshot and extract work entries
     */
    public function processScreenshot(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240', // 10MB max
        ]);
        $userId = auth()->id();

        try {
            // Save uploaded image
            $image = $request->file('image');
            $imagePath = $image->store('ocr_uploads', 'public');
            $fullPath = storage_path('app/public/' . $imagePath);

            Log::info("OCR: Processing image", ['user_id' => $userId, 'path' => $fullPath]);

            // Extract text using Tesseract OCR
            $extractedText = $this->extractTextFromImage($fullPath);

            Log::info("OCR: Extracted text", ['text' => $extractedText]);

            // Parse Amazon Flex format
            $parsedEntries = $this->parseAmazonFlexEarnings($extractedText);

            Log::info("OCR: Parsed entries", ['count' => count($parsedEntries)]);

            // Save valid entries to database
            $savedEntries = [];
            foreach ($parsedEntries as $entryData) {
                if ($entryData['is_valid']) {
                    $workEntry = WorkEntry::create([
                        'user_id' => $userId,
                        'date' => $entryData['date'],
                        'hours_worked' => $entryData['hours_worked'],
                        'earnings' => $entryData['earnings'],
                        'base_pay' => $entryData['base_pay'],
                        'tips' => $entryData['tips'],
                        'service_type' => $entryData['service_type'],
                        'notes' => 'OCR imported: ' . $entryData['original_text'],
                    ]);

                    $savedEntries[] = $workEntry;
                }
            }

            // Clean up uploaded file
            Storage::disk('public')->delete($imagePath);

            return response()->json([
                'success' => true,
                'data' => [
                    'extracted_text' => $extractedText,
                    'parsed_entries' => $parsedEntries,
                    'saved_entries' => count($savedEntries),
                    'entries' => $savedEntries,
                ],
                'message' => count($savedEntries) . ' work entries saved successfully',
            ]);

        } catch (\Exception $e) {
            Log::error("OCR processing failed", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'OCR processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract text from image using Tesseract OCR
     */
    private function extractTextFromImage($imagePath)
    {
        try {
            Log::info("Starting OCR extraction", ['path' => $imagePath]);

            $command = "tesseract '$imagePath' stdout 2>&1";
            $output = shell_exec($command);

            Log::info("Tesseract output", ['output' => $output]);

            if ($output === null || trim($output) === '') {
                throw new \Exception('Tesseract OCR failed to process image');
            }

            return trim($output);
        } catch (\Exception $e) {
            Log::error("extractTextFromImage failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Parse Amazon Flex earnings format
     */
    private function parseAmazonFlexEarnings($ocrText)
    {
        try {
            Log::info("OCR text received", ['text' => $ocrText]);

            $entries = [];
            $lines = array_filter(array_map('trim', explode("\n", $ocrText)));

            Log::info("Lines parsed", ['count' => count($lines), 'lines' => $lines]);

            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];

                try {
                    if ($this->isDateLine($line)) {
                        Log::info("Processing date line", ['line' => $line, 'index' => $i]);
                        $entry = $this->parseEntry($lines, $i);
                        if ($entry) {
                            $entries[] = $entry;
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Error parsing line", [
                        'line' => $line,
                        'index' => $i,
                        'error' => $e->getMessage()
                    ]);
                    continue; // Skip problematic line
                }
            }

            return $entries;
        } catch (\Exception $e) {
            Log::error("parseAmazonFlexEarnings failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Check if line contains date pattern
     */
    private function isDateLine($line)
    {
        try {
            $pattern = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}$/';
            return preg_match($pattern, $line);
        } catch (\Exception $e) {
            Log::error("isDateLine failed", ['line' => $line, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Parse single entry from lines starting at date
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

                // Check for time pattern: "9:21 AM - 10:30 AM"
                if ($this->isTimeLine($line)) {
                    $timeLine = $line;
                }

                // Check for total earnings: "$30", "$53.50"
                if ($this->isEarningsLine($line)) {
                    $totalEarnings = $this->parseEarnings($line);
                }

                // Check for base/tips: "Base: $51 Tips: $48"
                if (strpos($line, 'Base:') !== false && strpos($line, 'Tips:') !== false) {
                    if (preg_match('/Base:\s*\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                        $basePay = isset($matches[1]) ? floatval($matches[1]) : null;
                    }
                    if (preg_match('/Tips:\s*\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                        $tips = isset($matches[1]) ? floatval($matches[1]) : null;
                    }
                }

                // Stop if we hit another date
                if ($this->isDateLine($line) && $i > $startIndex) {
                    break;
                }
            }

            // Validate entry
            if (!$timeLine || $totalEarnings === null) {
                return null;
            }

            // Parse date
            $parsedDate = $this->parseDate($dateLine);
            if (!$parsedDate) return null;

            // Calculate hours
            $hoursWorked = $this->calculateHours($timeLine);

            // Determine service type
            $serviceType = ($basePay !== null && $tips !== null) ? 'whole_foods' : 'logistics';

            return [
                'date' => $parsedDate->format('Y-m-d'),
                'time_range' => $timeLine,
                'hours_worked' => $hoursWorked,
                'earnings' => $totalEarnings,
                'base_pay' => $basePay,
                'tips' => $tips,
                'service_type' => $serviceType,
                'original_text' => $dateLine . ' ' . $timeLine,
                'is_valid' => $totalEarnings > 0 && $hoursWorked > 0,
            ];
        } catch (\Exception $e) {
            Log::error("parseEntry failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Check if line contains time pattern
     */
    private function isTimeLine($line)
    {
        try {
            $pattern = '/^\d{1,2}:\d{2}\s+(AM|PM)\s*-\s*\d{1,2}:\d{2}\s+(AM|PM)$/';
            return preg_match($pattern, $line);
        } catch (\Exception $e) {
            Log::error("isTimeLine failed", ['line' => $line, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if line contains earnings
     */
    private function isEarningsLine($line)
    {
        try {
            $pattern = '/^\$\d+(?:\.\d{2})?$/';
            return preg_match($pattern, $line);
        } catch (\Exception $e) {
            Log::error("isEarningsLine failed", ['line' => $line, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Parse earnings from line
     */
    private function parseEarnings($line)
    {
        try {
            if (preg_match('/\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                return isset($matches[1]) ? floatval($matches[1]) : null;
            }
            return null;
        } catch (\Exception $e) {
            Log::error("parseEarnings failed", ['line' => $line, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse date from Amazon Flex format
     */
    private function parseDate($dateLine)
    {
        try {
            Log::info("Parsing date line: " . $dateLine);

            $pattern = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})$/';

            if (preg_match($pattern, $dateLine, $matches)) {
                Log::info("Date matches", ['matches' => $matches]);

                // Safe array access
                $monthName = $matches[2] ?? null;
                $day = isset($matches[3]) ? intval($matches[3]) : null;

                if (!$monthName || !$day) {
                    Log::error("Invalid date parts", ['month' => $monthName, 'day' => $day]);
                    return null;
                }

                $months = [
                    'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
                    'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
                    'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
                ];

                $month = $months[$monthName] ?? 1;
                $year = date('Y');

                return Carbon::createFromDate($year, $month, $day);
            }

            Log::info("Date pattern did not match");
            return null;
        } catch (\Exception $e) {
            Log::error("parseDate failed", ['dateLine' => $dateLine, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Calculate hours from time range
     */
    private function calculateHours($timeRange)
    {
        try {
            $pattern = '/^(\d{1,2}):(\d{2})\s+(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s+(AM|PM)$/';

            if (preg_match($pattern, $timeRange, $matches)) {
                // Safe array access
                $startHour = isset($matches[1]) ? intval($matches[1]) : 0;
                $startMinute = isset($matches[2]) ? intval($matches[2]) : 0;
                $startPeriod = $matches[3] ?? 'AM';

                $endHour = isset($matches[4]) ? intval($matches[4]) : 0;
                $endMinute = isset($matches[5]) ? intval($matches[5]) : 0;
                $endPeriod = $matches[6] ?? 'AM';

                // Convert to 24-hour format
                if ($startPeriod === 'PM' && $startHour !== 12) $startHour += 12;
                if ($startPeriod === 'AM' && $startHour === 12) $startHour = 0;

                if ($endPeriod === 'PM' && $endHour !== 12) $endHour += 12;
                if ($endPeriod === 'AM' && $endHour === 12) $endHour = 0;

                // Calculate duration in minutes
                $startMinutes = ($startHour * 60) + $startMinute;
                $endMinutes = ($endHour * 60) + $endMinute;

                // Handle overnight shifts
                if ($endMinutes < $startMinutes) {
                    $endMinutes += (24 * 60);
                }

                $durationMinutes = $endMinutes - $startMinutes;
                return round($durationMinutes / 60, 2);
            }

            return 2.0; // Fallback
        } catch (\Exception $e) {
            Log::error("calculateHours failed", ['timeRange' => $timeRange, 'error' => $e->getMessage()]);
            return 2.0;
        }
    }
}
