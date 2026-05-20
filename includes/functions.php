<?php
/**
 * Global Utility Functions
 */

/**
 * Format currency to match Indian Rupee display (e.g. Rs. 15,000)
 * 
 * @param float $number
 * @param bool $include_symbol
 * @return string
 */
function format_price($number, $include_symbol = true) {
    // Format number to local layout (e.g. 15000 -> 15,000)
    $formatted = number_format((float)$number, 0, '.', ',');
    
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
function format_date($date_str) {
    if (empty($date_str)) return '';
    return date('d/m/Y', strtotime($date_str));
}

/**
 * Format Time to 12-hour format (e.g. 10:00 AM)
 * 
 * @param string $time_str
 * @return string
 */
function format_time($time_str) {
    if (empty($time_str)) return '';
    return date('h:i A', strtotime($time_str));
}

/**
 * Helper to escape output for safe HTML rendering
 * 
 * @param string $str
 * @return string
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Convert number into words (English)
 * Supports Indian numbering system (Lakhs and Crores)
 * 
 * @param float|int $number
 * @return string
 */
function convert_number_to_words($number) {
    $hyphen      = ' ';
    $conjunction = ' and ';
    $separator   = ', ';
    $negative    = 'negative ';
    $decimal     = ' point ';
    $dictionary  = [
        0                   => 'zero',
        1                   => 'one',
        2                   => 'two',
        3                   => 'three',
        4                   => 'four',
        5                   => 'five',
        6                   => 'six',
        7                   => 'seven',
        8                   => 'eight',
        9                   => 'nine',
        10                  => 'ten',
        11                  => 'eleven',
        12                  => 'twelve',
        13                  => 'thirteen',
        14                  => 'fourteen',
        15                  => 'fifteen',
        16                  => 'sixteen',
        17                  => 'seventeen',
        18                  => 'eighteen',
        19                  => 'nineteen',
        20                  => 'twenty',
        30                  => 'thirty',
        40                  => 'forty',
        50                  => 'fifty',
        60                  => 'sixty',
        70                  => 'seventy',
        80                  => 'eighty',
        90                  => 'ninety',
        100                 => 'hundred',
        1000                => 'thousand',
        100000              => 'lakh',
        10000000            => 'crore'
    ];

    if (!is_numeric($number)) {
        return false;
    }

    $number = (float)$number;

    if ($number < 0) {
        return $negative . convert_number_to_words(abs($number));
    }

    $string = $fraction = null;

    if (strpos((string)$number, '.') !== false) {
        list($number, $fraction) = explode('.', (string)$number);
        $number = (int)$number;
        $fraction = (int)$fraction;
    } else {
        $number = (int)$number;
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens   = ((int) ($number / 10)) * 10;
            $units  = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $number < 1000:
            $hundreds  = $number / 100;
            $remainder = $number % 100;
            $string = $dictionary[(int)$hundreds] . ' ' . $dictionary[100];
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
        $fraction_str = substr((string)$fraction, 0, 2);
        $fraction_val = (int)$fraction_str;
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
function get_settings() {
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
        } catch (Exception $e) {
            // Fallback
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
function get_setting($key, $default = '') {
    $settings = get_settings();
    return isset($settings[$key]) ? $settings[$key] : $default;
}
