<?php
/**
 * Global Utility Functions
 */
require_once __DIR__ . '/../config/database.php';

/**
 * Format currency to match Indian Rupee display (e.g. Rs. 15,000)
 * 
 * @param float $number
 * @param bool $include_symbol
 * @return string
 */
function format_price($number, $include_symbol = true)
{
    // Format number to local layout (e.g. 15000 -> 15,000)
    $formatted = number_format((float) $number, 0, '.', ',');

    if ($include_symbol) {
        return "Rs. " . $formatted;
    }
    return $formatted;
}

/**
 * Format Date to readable format (e.g. 16/09/2026)
 * 
 * @param string $date_str (YYYY-MM-DD)
 * @return string
 */
function format_date($date_str)
{
    if (empty($date_str))
        return '';
    return date('d/m/Y', strtotime($date_str));
}

/**
 * Format Time to 12-hour format (e.g. 10:00 AM)
 * 
 * @param string $time_str
 * @return string
 */
function format_time($time_str)
{
    if (empty($time_str))
        return '';
    return date('h:i A', strtotime($time_str));
}

/**
 * Helper to escape output for safe HTML rendering
 * 
 * @param string $str
 * @return string
 */
function h($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Convert number into words (English)
 * Supports Indian numbering system (Lakhs and Crores)
 * 
 * @param float|int $number
 * @return string
 */
function convert_number_to_words($number)
{
    $hyphen = ' ';
    $conjunction = ' and ';
    $separator = ', ';
    $negative = 'negative ';
    $decimal = ' point ';
    $dictionary = [
        0 => 'zero',
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
        14 => 'fourteen',
        15 => 'fifteen',
        16 => 'sixteen',
        17 => 'seventeen',
        18 => 'eighteen',
        19 => 'nineteen',
        20 => 'twenty',
        30 => 'thirty',
        40 => 'forty',
        50 => 'fifty',
        60 => 'sixty',
        70 => 'seventy',
        80 => 'eighty',
        90 => 'ninety',
        100 => 'hundred',
        1000 => 'thousand',
        100000 => 'lakh',
        10000000 => 'crore'
    ];

    if (!is_numeric($number)) {
        return false;
    }

    $number = (float) $number;

    if ($number < 0) {
        return $negative . convert_number_to_words(abs($number));
    }

    $string = $fraction = null;

    if (strpos((string) $number, '.') !== false) {
        list($number, $fraction) = explode('.', (string) $number);
        $number = (int) $number;
        $fraction = (int) $fraction;
    } else {
        $number = (int) $number;
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens = ((int) ($number / 10)) * 10;
            $units = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $number < 1000:
            $hundreds = $number / 100;
            $remainder = $number % 100;
            $string = $dictionary[(int) $hundreds] . ' ' . $dictionary[100];
            if ($remainder) {
                $string .= $conjunction . convert_number_to_words($remainder);
            }
            break;
        default:
            $baseUnit = 1000;
            $unitName = 'thousand';

            if ($number >= 10000000) {
                $baseUnit = 10000000;
                $unitName = 'crore';
            } elseif ($number >= 100000) {
                $baseUnit = 100000;
                $unitName = 'lakh';
            }

            $numBaseUnits = (int) ($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = convert_number_to_words($numBaseUnits) . ' ' . $unitName;
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= convert_number_to_words($remainder);
            }
            break;
    }

    $result = ucwords(trim($string));

    if (null !== $fraction && $fraction > 0) {
        // limit fraction to 2 digits for cents/paise
        $fraction_str = substr((string) $fraction, 0, 2);
        $fraction_val = (int) $fraction_str;
        if ($fraction_val > 0) {
            $result .= ' and ' . convert_number_to_words($fraction_val) . ' Paise';
        }
    }

    return $result;
}

/**
 * Fetch all system settings from database
 * 
 * @return array
 */
function get_settings()
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $db = get_db_connection();
            $stmt = $db->query("SELECT `key`, `value` FROM `settings`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $settings[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            // Fallback to defaults
        }
    }
    return $settings;
}

/**
 * Fetch single setting by key
 * 
 * @param string $key
 * @param string $default
 * @return string
 */
function get_setting($key, $default = '')
{
    $settings = get_settings();
    if (isset($settings[$key]) && trim($settings[$key]) !== '') {
        return $settings[$key];
    }
    
    // Default legal and merchant verification fallbacks
    $defaults = [
        'company_name' => 'Orange Events',
        'trade_name' => 'Orange Events',
        'owner_full_name' => 'Ebin Benny',
        'registered_address' => 'Thumpoly, Alappuzha, Kerala - 688008',
        'company_phone' => '+91 99467 31720',
        'company_email' => 'orangedecorations@gmail.com',
        'policy_cancellation_duration' => 'Up to 48 hours prior to scheduled event date',
        'policy_refund_duration' => '5 to 7 business days',
        'policy_refund_mode' => 'Original Mode of Payment (UPI / Net Banking / Credit or Debit Card)'
    ];

    if (isset($defaults[$key])) {
        return $defaults[$key];
    }

    return $default;
}

/**
 * Calculate HMAC security checksum for Store Payment UPI VPA ID
 */
function get_upi_checksum($upi_id)
{
    $salt = 'ORANGE_EVENTS_UPI_SECURE_SALT_2026';
    return hash_hmac('sha256', strtolower(trim($upi_id)), $salt);
}

/**
 * Validate and verify security checksum of configured Store UPI ID
 */
function is_upi_secure_and_valid()
{
    $settings = get_settings();
    $upi_id = trim($settings['company_upi_id'] ?? '');
    if (empty($upi_id))
        return false;

    // Check format
    if (!preg_match('/^[a-zA-Z0-9\.\-_]{2,256}@[a-zA-Z0-9]{2,64}$/', $upi_id)) {
        return false;
    }

    // Check security HMAC checksum (if stored)
    $stored_checksum = $settings['company_upi_checksum'] ?? '';
    if (!empty($stored_checksum)) {
        $expected_checksum = get_upi_checksum($upi_id);
        if (!hash_equals($expected_checksum, $stored_checksum)) {
            return false; // Security mismatch / tampered database!
        }
    }
    return true;
}

/**
 * Generate UPI payment string for QR code
 */
function generate_upi_payment_url($amount, $invoice_number, $note = '')
{
    $settings = get_settings();
    $upi_id = trim($settings['company_upi_id'] ?? '');
    $merchant_name = trim($settings['company_name'] ?? 'Orange Events');

    if (empty($upi_id) || !is_upi_secure_and_valid()) {
        return false;
    }

    $clean_amount = number_format((float) $amount, 2, '.', '');
    $clean_note = !empty($note) ? $note : "Invoice-{$invoice_number}";

    $params = [
        'pa' => $upi_id,
        'pn' => $merchant_name,
        'am' => $clean_amount,
        'tr' => $invoice_number,
        'tn' => $clean_note,
        'cu' => 'INR'
    ];

    return 'upi://pay?' . http_build_query($params);
}

/**
 * Generate UPI QR Code image URL for invoices
 * Returns QR code image URL or false if UPI is not configured/secure
 */
function generate_upi_qr_code_url($amount, $invoice_number, $size = '150x150', $note = '')
{
    $upi_url = generate_upi_payment_url($amount, $invoice_number, $note);
    if (!$upi_url) {
        return false;
    }

    if (!class_exists('\splitbrain\phpQRCode\QRCode')) {
        $vendor_autoload = __DIR__ . '/../vendor/autoload.php';
        $direct_src = __DIR__ . '/../vendor/splitbrain/php-qrcode/src/QRCode.php';
        if (file_exists($vendor_autoload)) {
            require_once $vendor_autoload;
        } elseif (file_exists($direct_src)) {
            require_once $direct_src;
        }
    }

    if (class_exists('\splitbrain\phpQRCode\QRCode')) {
        try {
            $svg = \splitbrain\phpQRCode\QRCode::svg($upi_url);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable $e) {
            // Fallback if SVG generation fails
        }
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . urlencode($size) . '&data=' . urlencode($upi_url);
}

/**
 * Extract clean numeric barcode for an invoice (e.g. OE-B-20260725-0003 -> 202607250003)
 * Creates a short, high-density numeric barcode for instant 1D/2D scanning
 */
function get_numeric_invoice_barcode($invoice_number, $order_id = 0) {
    $digits = preg_replace('/[^0-9]/', '', $invoice_number);
    if (empty($digits) && $order_id > 0) {
        $digits = '200' . str_pad($order_id, 9, '0', STR_PAD_LEFT);
    }
    // Mode C requires even number of digits for digit pairing
    if (strlen($digits) % 2 !== 0) {
        $digits = '0' . $digits;
    }
    return !empty($digits) ? $digits : '202600000001';
}

/**
 * Generate 1D Code 128 Barcode SVG (Mode C / Auto for ultra-high density and 100% instant scanning)
 */
function generate_barcode_svg($text, $height = 42, $min_bar_width = 2.0) {
    $patterns = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322', 5 => '131222',
        6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213', 10 => '221312', 11 => '231212',
        12 => '112232', 13 => '122132', 14 => '122231', 15 => '113222', 16 => '123122', 17 => '123221',
        18 => '223211', 19 => '221132', 20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131',
        24 => '311222', 25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123', 35 => '131321',
        36 => '112313', 37 => '132113', 38 => '132311', 39 => '211312', 40 => '231112', 41 => '231311',
        42 => '112133', 43 => '112331', 44 => '132131', 45 => '113123', 46 => '113321', 47 => '133121',
        48 => '313121', 49 => '211331', 50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131',
        54 => '311123', 55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422', 65 => '121124',
        66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214', 70 => '112412', 71 => '122114',
        72 => '122411', 73 => '142112', 74 => '142211', 75 => '241211', 76 => '221114', 77 => '413111',
        78 => '241112', 79 => '134111', 80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212',
        84 => '124112', 85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141', 95 => '114113',
        96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141', 100 => '114131', 101 => '311141',
        102 => '411131', 103 => '211412', 104 => '211214', 105 => '211232', 106 => '2331112'
    ];

    $cleanText = trim($text);
    if (empty($cleanText)) return '';

    $is_pure_digits = ctype_digit($cleanText) && (strlen($cleanText) % 2 === 0);
    $codes = [];

    if ($is_pure_digits) {
        // Mode C (Numeric pairs - 50% narrower with thick, high-contrast bars)
        $codes[] = 105; // Start C
        $checksum = 105;
        $multiplier = 1;

        for ($i = 0; $i < strlen($cleanText); $i += 2) {
            $val = (int)substr($cleanText, $i, 2);
            $codes[] = $val;
            $checksum += $val * $multiplier;
            $multiplier++;
        }
    } else {
        // Mode B (Alphanumeric)
        $codes[] = 104; // Start B
        $checksum = 104;
        $multiplier = 1;

        for ($i = 0; $i < strlen($cleanText); $i++) {
            $char = $cleanText[$i];
            $val = ord($char) - 32;
            if ($val >= 0 && $val <= 95) {
                $codes[] = $val;
                $checksum += $val * $multiplier;
                $multiplier++;
            }
        }
    }

    $checksum_val = $checksum % 103;
    $codes[] = $checksum_val;
    $codes[] = 106; // Stop code

    $x = 0;
    $svg = '';
    $bar_width = max(2.0, (float)$min_bar_width);

    // Quiet zone (10 modules left/right)
    $quiet_zone = 10 * $bar_width;
    $x += $quiet_zone;

    foreach ($codes as $code_idx) {
        if (!isset($patterns[$code_idx])) continue;
        $pattern = $patterns[$code_idx];
        for ($j = 0; $j < strlen($pattern); $j++) {
            $width = (int)$pattern[$j] * $bar_width;
            $is_bar = ($j % 2 === 0);
            if ($is_bar) {
                $svg .= "<rect x='{$x}' y='0' width='{$width}' height='{$height}' fill='#000000' />";
            }
            $x += $width;
        }
    }
    $x += $quiet_zone;

    return "<svg width='{$x}' height='{$height}' viewBox='0 0 {$x} {$height}' xmlns='http://www.w3.org/2000/svg' style='max-width: 100%; height: auto;'>{$svg}</svg>";
}


